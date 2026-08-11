<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Integrations\WooCommerce\Enums\DestinoDoCliente;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Models\StgWooCustomer;
use App\Modules\Sales\DTO\CustomerAddressSnapshot;
use App\Modules\Sales\DTO\CustomerSnapshot;
use App\Modules\Sales\Services\CustomerLookupService;
use Illuminate\Support\Collection;

/**
 * Validação da migração de clientes — docs/17-Migracao F5.
 *
 * Confere se o cadastro de Vendas (destino) reflete o staging (origem, o
 * payload cru do Woo). Tudo aqui é **re-derivado da origem por uma
 * implementação independente** da carga: se a validação chamasse os
 * métodos de `LoadWooCustomers`, um bug de leitura passaria nos dois lados
 * e a conferência daria verde sobre erro. É a mesma regra que a validação
 * do catálogo segue, e é o que torna a assinatura do dono significar
 * alguma coisa.
 *
 * ## Duas listas, porque nem toda diferença é erro
 *
 * A migração de clientes encontra cadastro já povoado, e a carga
 * **preserva o valor do ERP** quando ele existe (regra 5 do projeto). Uma
 * conferência que chamasse isso de divergência acusaria a carga de errar
 * justamente onde ela acertou. Então cada cliente da amostra sai com:
 *
 * - **divergências** — a migração está errada: cliente sumido, lacuna que
 *   deveria ter sido preenchida e não foi, endereço que não bate com o
 *   site, marca de atacado num canal que é 100% varejo.
 * - **preservados** — o ERP já tinha valor próprio e ele venceu. Não é
 *   erro; é informação que a operação precisa ver para decidir qual dos
 *   dois está certo.
 */
class ValidateWooCustomers
{
    /** O texto que o pedido do site grava quando o comprador não tem nome. */
    private const NOME_RECUO = 'Cliente do site';

    public function __construct(private readonly CustomerLookupService $clientes) {}

    /**
     * @return array{
     *   contagens: array{triados: int, mapeados: int, bate: bool, mapeados_total: int, pendentes: int},
     *   rejeitados: array<string, int>,
     *   amostra: list<array{woo_id: int, nome: string, divergencias: list<string>, preservados: list<string>}>,
     *   conferidos: int,
     *   divergentes: int,
     * }
     */
    public function executar(int $tamanhoAmostra = 20): array
    {
        $triados = $this->doDestino(DestinoDoCliente::Cliente);

        $mapeados = $triados
            ->filter(fn (StgWooCustomer $l): bool => IntegrationMapping::localDe('customer', $l->woo_id) !== null)
            ->count();

        $amostra = [];
        $divergentes = 0;

        foreach ($this->amostrar($triados, $tamanhoAmostra) as $linha) {
            $preservados = [];
            $divergencias = $this->conferir($linha, $preservados);

            if ($divergencias !== []) {
                $divergentes++;
            }

            $amostra[] = [
                'woo_id' => (int) $linha->woo_id,
                'nome' => $this->nomeEsperado($linha),
                'divergencias' => $divergencias,
                'preservados' => $preservados,
            ];
        }

        return [
            'contagens' => [
                'triados' => $triados->count(),
                'mapeados' => $mapeados,
                'bate' => $triados->count() === $mapeados,

                // Pode ser **maior** que os triados, e isso não é erro: o
                // pedido do site mapeia o comprador desde o Gate 02, e um
                // cliente que comprou e depois apagou a conta no Woo tem
                // mapeamento sem linha correspondente no staging. Separado
                // do número que precisa bater, para não produzir vermelho
                // sobre um fato normal.
                'mapeados_total' => IntegrationMapping::query()
                    ->where('remote_system', IntegrationMapping::SISTEMA_WOO)
                    ->where('entity_type', 'customer')
                    ->count(),

                // Linha que a triagem não conseguiu decidir não carrega e
                // não aparece em contagem nenhuma — sumiria calada. Aqui
                // ela é um número que o comando trata como falha.
                'pendentes' => $this->doDestino(DestinoDoCliente::Pendente)->count(),
            ],
            'rejeitados' => [
                DestinoDoCliente::SemPedido->value => $this->doDestino(DestinoDoCliente::SemPedido)->count(),
                DestinoDoCliente::Duplicado->value => $this->doDestino(DestinoDoCliente::Duplicado)->count(),
            ],
            'amostra' => $amostra,
            'conferidos' => count($amostra),
            'divergentes' => $divergentes,
        ];
    }

    /**
     * Amostra **estável e espaçada**: ordena por `woo_id` e pega a cada
     * passo fixo. Rodar de novo devolve exatamente as mesmas pessoas — uma
     * conferência assinada não pode depender de sorteio que muda.
     *
     * @param  Collection<int, StgWooCustomer>  $triados
     * @return Collection<int, StgWooCustomer>
     */
    private function amostrar(Collection $triados, int $tamanho): Collection
    {
        if ($triados->count() <= $tamanho) {
            return $triados;
        }

        $passo = (int) floor($triados->count() / $tamanho);

        return $triados
            ->filter(fn (StgWooCustomer $_, int $i): bool => $i % $passo === 0)
            ->take($tamanho)
            ->values();
    }

    /**
     * O que não bate entre a origem e o cadastro.
     *
     * @param  list<string>  $preservados  saída: diferenças em que o ERP venceu
     * @return list<string>
     */
    private function conferir(StgWooCustomer $linha, array &$preservados): array
    {
        $local = IntegrationMapping::localDe('customer', $linha->woo_id);

        if ($local === null) {
            return ['não está no cadastro (sem mapeamento)'];
        }

        $atual = $this->clientes->snapshot($local);

        if ($atual === null) {
            return ['mapeado, mas o cliente não existe'];
        }

        $divergencias = [];

        $this->conferirTexto('nome', $this->nomeEsperado($linha), $atual->name, $divergencias, $preservados, recuo: self::NOME_RECUO);
        $this->conferirTexto('e-mail', $this->emailEsperado($linha), $atual->email, $divergencias, $preservados);
        $this->conferirTexto('telefone', $this->telefoneEsperado($linha), $atual->phone, $divergencias, $preservados);
        $this->conferirDocumento($linha, $atual, $divergencias, $preservados);
        $this->conferirEnderecos($linha, $atual, $divergencias);

        if ($atual->isWholesale) {
            // A pasta 31 §3 mediu 85 pedidos, 100% pessoa física, zero
            // CNPJ: o atacado acontece fora do site. Um cliente do Woo
            // marcado como atacadista significa que a carga inventou uma
            // dimensão que a origem não tem.
            $divergencias[] = 'marcado como atacadista — o canal do site é 100% varejo';
        }

        return $divergencias;
    }

    /**
     * @param  list<string>  $divergencias
     * @param  list<string>  $preservados
     */
    private function conferirTexto(
        string $campo,
        ?string $esperado,
        ?string $atual,
        array &$divergencias,
        array &$preservados,
        ?string $recuo = null,
    ): void {
        if ($esperado === null) {
            return;
        }

        $vazio = $atual === null || trim($atual) === '' || ($recuo !== null && $atual === $recuo);

        if ($vazio) {
            // A origem tinha o dado e o ERP ficou sem: é lacuna que a carga
            // prometeu preencher e não preencheu.
            $divergencias[] = "{$campo}: origem tem “{$esperado}”, cadastro está vazio";

            return;
        }

        if ($atual !== $esperado) {
            $preservados[] = "{$campo}: ERP “{$atual}” × site “{$esperado}”";
        }
    }

    /**
     * @param  list<string>  $divergencias
     * @param  list<string>  $preservados
     */
    private function conferirDocumento(
        StgWooCustomer $linha,
        CustomerSnapshot $atual,
        array &$divergencias,
        array &$preservados,
    ): void {
        $esperado = $this->documentoEsperado($linha);

        if ($esperado === null) {
            return;
        }

        if ($atual->doc === null || $atual->doc === '') {
            // Documento em branco só é aceitável se o número pertencer a
            // outro cadastro — a recusa que a carga documenta. Conferido
            // aqui de forma independente: se ninguém mais o tem, a carga
            // simplesmente perdeu o dado.
            $dono = $this->clientes->donoDoDocumento($esperado);

            if ($dono === null) {
                $divergencias[] = "documento: origem tem “{$esperado}”, cadastro está vazio";
            } else {
                $preservados[] = "documento “{$esperado}” recusado: já pertence ao cliente local #{$dono}";
            }

            return;
        }

        if ($atual->doc !== $esperado) {
            $preservados[] = "documento: ERP “{$atual->doc}” × site “{$esperado}”";
        }
    }

    /**
     * Cada endereço que a origem trouxe completo tem de estar no cadastro.
     *
     * Só nesta direção: o cliente pode ter ganhado endereços por outros
     * caminhos, e exigir que o ERP tenha *exatamente* os do site
     * transformaria um cadastro mais rico em erro.
     *
     * @param  list<string>  $divergencias
     */
    private function conferirEnderecos(StgWooCustomer $linha, CustomerSnapshot $atual, array &$divergencias): void
    {
        foreach (['billing' => 'cobrança', 'shipping' => 'entrega'] as $bloco => $rotulo) {
            $esperado = $this->enderecoEsperado($linha, $bloco);

            if ($esperado === null) {
                continue;
            }

            $achou = false;

            foreach ($atual->enderecos as $endereco) {
                if ($this->mesmoEndereco($esperado, $endereco)) {
                    $achou = true;

                    break;
                }
            }

            if (! $achou) {
                $divergencias[] = sprintf(
                    'endereço de %s não está no cadastro: %s, %s — %s/%s',
                    $rotulo,
                    $esperado['street'],
                    $esperado['number'] ?? 's/n',
                    $esperado['city'],
                    $esperado['state'],
                );
            }
        }
    }

    /**
     * @param  array<string, string|null>  $esperado
     */
    private function mesmoEndereco(array $esperado, CustomerAddressSnapshot $atual): bool
    {
        return $this->digitos((string) $esperado['zip']) === $this->digitos($atual->zip)
            && mb_strtolower((string) $esperado['street']) === mb_strtolower($atual->street)
            && mb_strtolower((string) $esperado['city']) === mb_strtolower($atual->city)
            && mb_strtoupper((string) $esperado['state']) === mb_strtoupper($atual->state);
    }

    /**
     * O endereço que a origem traz, relido aqui — sem passar pela carga.
     *
     * @return array<string, string|null>|null
     */
    private function enderecoEsperado(StgWooCustomer $linha, string $bloco): ?array
    {
        /** @var array<string, mixed> $dados */
        $dados = (array) ($linha->payload[$bloco] ?? []);

        $rua = trim((string) ($dados['address_1'] ?? ''));
        $cidade = trim((string) ($dados['city'] ?? ''));
        $uf = mb_strtoupper(trim((string) ($dados['state'] ?? '')));

        // Mesma exigência mínima da carga, escrita de novo de propósito: é
        // a segunda opinião sobre o que conta como endereço.
        if ($rua === '' || $cidade === '' || mb_strlen($uf) !== 2) {
            return null;
        }

        return [
            'zip' => trim((string) ($dados['postcode'] ?? '')),
            'street' => $rua,
            'number' => $this->metaDe($linha, ["_{$bloco}_number", "{$bloco}_number"])
                ?? (isset($dados['number']) ? trim((string) $dados['number']) : null),
            'city' => $cidade,
            'state' => $uf,
        ];
    }

    private function nomeEsperado(StgWooCustomer $linha): string
    {
        /** @var array<string, mixed> $payload */
        $payload = $linha->payload;
        /** @var array<string, mixed> $billing */
        $billing = (array) ($payload['billing'] ?? []);

        $nome = trim(((string) ($payload['first_name'] ?? '')).' '.((string) ($payload['last_name'] ?? '')));

        if ($nome === '') {
            $nome = trim(((string) ($billing['first_name'] ?? '')).' '.((string) ($billing['last_name'] ?? '')));
        }

        return $nome === '' ? self::NOME_RECUO : mb_substr($nome, 0, 255);
    }

    private function emailEsperado(StgWooCustomer $linha): ?string
    {
        $email = mb_strtolower(trim((string) ($linha->payload['email'] ?? '')));

        return $email === '' ? null : $email;
    }

    private function telefoneEsperado(StgWooCustomer $linha): ?string
    {
        /** @var array<string, mixed> $billing */
        $billing = (array) ($linha->payload['billing'] ?? []);
        $telefone = trim((string) ($billing['phone'] ?? ''));

        return $telefone === '' ? null : mb_substr($telefone, 0, 32);
    }

    /**
     * O CPF/CNPJ relido da origem — varredura própria, não a da triagem.
     */
    private function documentoEsperado(StgWooCustomer $linha): ?string
    {
        /** @var array<string, mixed> $billing */
        $billing = (array) ($linha->payload['billing'] ?? []);

        $bruto = null;

        foreach (['cpf', 'cnpj'] as $campo) {
            if ($bruto === null && isset($billing[$campo]) && (string) $billing[$campo] !== '') {
                $bruto = (string) $billing[$campo];
            }
        }

        $bruto ??= $this->metaDe($linha, ['_billing_cpf', 'billing_cpf', '_billing_cnpj', 'billing_cnpj']);

        if ($bruto === null) {
            return null;
        }

        $digitos = $this->digitos($bruto);

        return $digitos === '' || mb_strlen($digitos) > 14 ? null : $digitos;
    }

    /**
     * @param  list<string>  $chaves
     */
    private function metaDe(StgWooCustomer $linha, array $chaves): ?string
    {
        foreach ((array) ($linha->payload['meta_data'] ?? []) as $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $valor = trim((string) ($meta['value'] ?? ''));

            if ($valor !== '' && in_array((string) ($meta['key'] ?? ''), $chaves, true)) {
                return $valor;
            }
        }

        return null;
    }

    private function digitos(string $bruto): string
    {
        return preg_replace('/\D/', '', $bruto) ?? '';
    }

    /**
     * @return Collection<int, StgWooCustomer>
     */
    private function doDestino(DestinoDoCliente $destino): Collection
    {
        return StgWooCustomer::query()
            ->where('status_triagem', $destino->value)
            ->orderBy('woo_id')
            ->get();
    }
}
