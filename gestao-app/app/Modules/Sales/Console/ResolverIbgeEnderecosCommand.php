<?php

declare(strict_types=1);

namespace App\Modules\Sales\Console;

use App\Modules\Sales\Models\CustomerAddress;
use App\Modules\Sales\Services\ResolveIbgeCityCode;
use Illuminate\Console\Command;

/**
 * Resolve o código IBGE dos endereços já cadastrados — ADR-0026.
 *
 * A coluna `city_code` nasceu nula para todo mundo (era o estado honesto:
 * o município ainda não fora resolvido). Este comando passa a resolução
 * automática nos endereços que continuam sem código e **relata**: quantos
 * resolveu e, um a um, quais ficaram pendentes com cidade e UF, para
 * conferência humana.
 *
 * Roda em `--dry-run` por padrão de propósito. Backfill que grava sem
 * pedir é backfill que já gravou quando alguém descobre que a lista de
 * pendentes tinha algo estranho — e aqui "estranho" significa cliente que
 * vai receber nota. Ver primeiro, gravar depois.
 *
 * Idempotente: só toca em quem está nulo, então rodar de novo depois de
 * corrigir grafias no cadastro pega exatamente os que sobraram.
 *
 * @see docs/27-ADR/ADR-0026-codigo-ibge-municipio.md
 */
class ResolverIbgeEnderecosCommand extends Command
{
    protected $signature = 'erp:enderecos:resolver-ibge
        {--gravar : Grava os códigos resolvidos (sem esta opção, só relata)}
        {--pendentes= : Limita quantos pendentes são listados (padrão: todos)}';

    protected $description = 'Preenche o código IBGE do município nos endereços que ainda estão sem (ADR-0026)';

    public function handle(ResolveIbgeCityCode $municipios): int
    {
        $gravar = (bool) $this->option('gravar');

        $total = CustomerAddress::query()->whereNull('city_code')->count();

        if ($total === 0) {
            $this->components->info('Nenhum endereço sem código IBGE — nada a fazer.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Endereços sem código</>', (string) $total);
        $this->components->twoColumnDetail(
            '<fg=gray>Modo</>',
            $gravar ? '<fg=yellow>gravando</>' : '<fg=cyan>simulação (--dry-run implícito)</>',
        );
        $this->newLine();

        $resolvidos = 0;

        /** @var list<array{cidade: string, uf: string, cliente: string}> $pendentes */
        $pendentes = [];

        // `chunkById` em vez de `get()`: a tabela é pequena hoje, mas um
        // backfill que carrega tudo na memória é o tipo de comando que
        // funciona no dia em que foi escrito e estoura no dia em que
        // precisa rodar. `chunkById` também é seguro com a escrita no meio
        // do laço — pagina por id, não por OFFSET, então gravar não faz
        // linha pular de página.
        CustomerAddress::query()
            ->whereNull('city_code')
            ->with('customer:id,name')
            ->orderBy('id')
            ->chunkById(500, function ($enderecos) use ($municipios, $gravar, &$resolvidos, &$pendentes): void {
                foreach ($enderecos as $endereco) {
                    $codigo = $municipios->resolver($endereco->city, $endereco->state);

                    if ($codigo === null) {
                        $pendentes[] = [
                            'cidade' => $endereco->city,
                            'uf' => $endereco->state,
                            // Sem `?->`: `customer_id` é obrigatório e tem
                            // FK, então endereço órfão não existe.
                            'cliente' => $endereco->customer->name,
                        ];

                        continue;
                    }

                    $resolvidos++;

                    if ($gravar) {
                        // `saveQuietly`: este comando não é alguém editando
                        // o cadastro de um cliente, é a mesma dedução
                        // automática aplicada em lote. Registrar milhares
                        // de linhas na auditoria (LGPD, pasta 25 §3)
                        // enterraria as alterações que **pessoas** fizeram,
                        // que é o que a trilha existe para mostrar.
                        $endereco->city_code = $codigo;
                        $endereco->saveQuietly();
                    }
                }
            });

        $this->components->twoColumnDetail(
            $gravar ? 'resolvidos e gravados' : 'resolveriam',
            "<fg=green>{$resolvidos}</>",
        );
        $this->components->twoColumnDetail('continuam pendentes', '<fg=yellow>'.count($pendentes).'</>');

        $this->listarPendentes($pendentes);

        $this->newLine();

        if (! $gravar) {
            $this->line('  Nada foi gravado. Para aplicar: <fg=cyan>php artisan erp:enderecos:resolver-ibge --gravar</>');

            return self::SUCCESS;
        }

        if ($pendentes !== []) {
            $this->line('  Os pendentes ficam com <fg=yellow>city_code</> nulo e aparecem como');
            $this->line('  "sem código IBGE do município" na tela do cliente. Resolvem-se');
            $this->line('  escolhendo o município na busca do formulário de endereço —');
            $this->line('  a resolução automática <fg=red>não</> deve ser afrouxada para pegá-los.');
        }

        return self::SUCCESS;
    }

    /**
     * Os pendentes agrupados por cidade+UF, com quantos e para quem.
     *
     * Agrupado porque a mesma grafia divergente costuma repetir-se em
     * dezenas de endereços (é uma cidade, não um endereço, que está com o
     * nome fora do padrão do IBGE): a lista agrupada mostra o problema, a
     * lista linha a linha mostra o volume dele.
     *
     * @param  list<array{cidade: string, uf: string, cliente: string}>  $pendentes
     */
    private function listarPendentes(array $pendentes): void
    {
        if ($pendentes === []) {
            return;
        }

        $limite = $this->option('pendentes');
        $limite = $limite === null || $limite === '' ? PHP_INT_MAX : max(1, (int) $limite);

        $agrupados = [];

        foreach ($pendentes as $p) {
            $chave = "{$p['cidade']}/{$p['uf']}";
            $agrupados[$chave]['total'] = ($agrupados[$chave]['total'] ?? 0) + 1;
            $agrupados[$chave]['clientes'][] = $p['cliente'];
        }

        uasort($agrupados, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $this->newLine();
        $this->line('  <fg=yellow>Sem casamento na tabela do IBGE</> (conferir a grafia da cidade):');
        $this->newLine();

        foreach (array_slice($agrupados, 0, $limite, true) as $chave => $dados) {
            $clientes = array_slice(array_unique($dados['clientes']), 0, 3);
            $sufixo = count(array_unique($dados['clientes'])) > 3 ? ', …' : '';

            $this->components->twoColumnDetail(
                "<fg=yellow>·</> {$chave}",
                "<fg=gray>{$dados['total']} endereço(s) — ".implode(', ', $clientes).$sufixo.'</>',
            );
        }

        if (count($agrupados) > $limite) {
            $this->line('  <fg=gray>… e mais '.(count($agrupados) - $limite).' cidade(s). Use --pendentes= para ver mais.</>');
        }
    }
}
