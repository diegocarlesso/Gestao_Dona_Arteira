<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Console;

use App\Modules\Integrations\WooCommerce\Enums\DestinoDaTriagem;
use App\Modules\Integrations\WooCommerce\Enums\DestinoDoCliente;
use App\Modules\Integrations\WooCommerce\Models\StgWooCustomer;
use App\Modules\Integrations\WooCommerce\Models\StgWooProduct;
use App\Modules\Integrations\WooCommerce\Services\TriageWooCustomers;
use App\Modules\Integrations\WooCommerce\Services\TriageWooProducts;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saneamento do staging — docs/17-Migracao F3.
 *
 * Classifica o que veio do site e propõe o que a carga vai usar. Nada é
 * carregado aqui, para nenhuma entidade.
 *
 * As duas entidades pedem coisas diferentes da fase, e o comando reflete
 * isso: no **catálogo** a triagem existe para *propor* SKU, que depois
 * alguém aprova (a BR-002 torna o código imutável); nos **clientes** ela
 * existe para *recusar* — 136 dos 198 cadastros do site não migram por
 * minimização de dados (LGPD).
 */
class TriageWooCommand extends Command
{
    protected $signature = 'erp:migrate:triage
        {entidade=catalogo : catalogo ou clientes}
        {--duplicados : lista os títulos repetidos, que precisam de olhos (catálogo)}
        {--rejeitados : lista quem não migra, com o motivo (clientes)}';

    protected $description = 'Classifica o staging do Woo e prepara a carga (fase 4)';

    public function handle(TriageWooProducts $triagem, TriageWooCustomers $deClientes): int
    {
        return (string) $this->argument('entidade') === 'clientes'
            ? $this->triarClientes($deClientes)
            : $this->triarCatalogo($triagem);
    }

    private function triarCatalogo(TriageWooProducts $triagem): int
    {
        if (StgWooProduct::query()->doesntExist()) {
            $this->components->error('O staging está vazio. Rode `erp:migrate:extract` antes.');

            return self::FAILURE;
        }

        if ($this->option('duplicados')) {
            $this->listarDuplicados();

            return self::SUCCESS;
        }

        $contagem = $triagem->executar();

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Destino na carga</>', '<fg=gray>itens</>');

        foreach (DestinoDaTriagem::cases() as $destino) {
            $total = $contagem[$destino->value] ?? 0;

            if ($total === 0) {
                continue;
            }

            $this->components->twoColumnDetail(
                $destino->viraProduto() ? "<fg=green>{$destino->label()}</>" : $destino->label(),
                (string) $total,
            );
        }

        $viraProduto = $contagem[DestinoDaTriagem::Produto->value] ?? 0;
        $primeiro = StgWooProduct::query()->whereNotNull('sku_proposto')->orderBy('sku_proposto')->value('sku_proposto');
        $ultimo = StgWooProduct::query()->whereNotNull('sku_proposto')->orderByDesc('sku_proposto')->value('sku_proposto');

        $this->newLine();
        $this->components->info("SKUs propostos: {$primeiro} … {$ultimo} ({$viraProduto} produtos)");

        $this->pendencias();

        $this->newLine();
        $this->line('  Nada foi carregado. A carga (fase 4) só aceita itens aprovados —');
        $this->line('  o SKU é imutável depois de criado (BR-002), então a conferência');
        $this->line('  humana acontece antes, não depois.');
        $this->newLine();
        $this->line('  Para ver o que exige decisão: <fg=cyan>erp:migrate:triage --duplicados</>');

        return self::SUCCESS;
    }

    /**
     * Triagem dos clientes — onde a rejeição é o produto principal.
     *
     * O relatório mostra quem **não** migra com o mesmo destaque de quem
     * migra: são 136 pessoas cujo dado o ERP decidiu não guardar, e essa
     * decisão precisa estar visível para quem a assina.
     */
    private function triarClientes(TriageWooCustomers $triagem): int
    {
        if (StgWooCustomer::query()->doesntExist()) {
            $this->components->error('O staging de clientes está vazio. Rode `erp:migrate:extract clientes` antes.');

            return self::FAILURE;
        }

        if ($this->option('rejeitados')) {
            $this->listarRejeitados();

            return self::SUCCESS;
        }

        $contagem = $triagem->executar();

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Destino na carga</>', '<fg=gray>cadastros</>');

        foreach (DestinoDoCliente::cases() as $destino) {
            $total = $contagem[$destino->value] ?? 0;

            if ($total === 0) {
                continue;
            }

            $this->components->twoColumnDetail(
                $destino->viraCliente() ? "<fg=green>{$destino->label()}</>" : $destino->label(),
                (string) $total,
            );
        }

        $migram = $contagem[DestinoDoCliente::Cliente->value] ?? 0;
        $pendentes = $contagem[DestinoDoCliente::Pendente->value] ?? 0;

        $this->newLine();
        $this->components->info("{$migram} clientes migram — o inventário da pasta 31 §2 esperava 62.");

        if ($migram !== 62) {
            $this->line('  <fg=yellow>O número mudou desde a medição.</> Não é necessariamente erro (o');
            $this->line('  site continuou vendendo), mas confira antes de carregar.');
        }

        if ($pendentes > 0) {
            $this->newLine();
            $this->components->warn("{$pendentes} cadastros ficaram pendentes — a origem não trouxe `orders_count`.");
            $this->line('  Eles NÃO carregam. Rejeitar por dado ausente descartaria um comprador');
            $this->line('  em silêncio, então a decisão fica com você.');
        }

        $semDoc = StgWooCustomer::query()
            ->where('status_triagem', DestinoDoCliente::Cliente->value)
            ->whereNull('doc_proposto')
            ->count();

        if ($semDoc > 0) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=yellow>sem CPF/CNPJ na origem</>', (string) $semDoc);
            $this->line('  <fg=gray>Não bloqueia a migração: o cliente entra com pendência fiscal');
            $this->line('  (BR-001), que aparece na listagem e barra só a emissão de NF-e</>');
        }

        $this->newLine();
        $this->line('  Nada foi carregado. Para ver quem fica de fora e por quê:');
        $this->line('  <fg=cyan>erp:migrate:triage clientes --rejeitados</>');
        $this->line('  A carga é <fg=cyan>erp:migrate:load clientes --dry-run</> primeiro.');

        return self::SUCCESS;
    }

    /**
     * Quem não migra, com o motivo — princípio 4 da pasta 17.
     *
     * O e-mail sai **mascarado**. São 136 pessoas que nunca compraram, e o
     * motivo de não migrá-las é justamente não espalhar o dado delas; o
     * `woo_id` basta para achar o cadastro no site quando for preciso.
     */
    private function listarRejeitados(): void
    {
        $rejeitados = StgWooCustomer::query()
            ->whereIn('status_triagem', [
                DestinoDoCliente::SemPedido->value,
                DestinoDoCliente::Duplicado->value,
                DestinoDoCliente::Pendente->value,
            ])
            ->orderBy('status_triagem')
            ->orderBy('woo_id')
            ->get();

        $this->newLine();
        $this->components->info("{$rejeitados->count()} cadastros do site não migram.");
        $this->line('  E-mail mascarado de propósito: o motivo de não migrá-los é não');
        $this->line('  espalhar o dado deles (LGPD, pasta 25 §3). O `woo_id` identifica.');
        $this->newLine();

        foreach ($rejeitados->groupBy('status_triagem') as $status => $grupo) {
            $destino = DestinoDoCliente::tryFrom((string) $status);

            $this->line("  <fg=yellow>{$grupo->count()}×</> ".($destino?->label() ?? (string) $status));

            foreach ($grupo as $linha) {
                $this->line(sprintf(
                    '      woo=%-6d  %-28s  %s',
                    $linha->woo_id,
                    $this->mascarar($linha->email),
                    $linha->motivo_triagem ?? '—',
                ));
            }

            $this->newLine();
        }
    }

    /**
     * `maria.silva@gmail.com` → `m***a@gmail.com`.
     */
    private function mascarar(?string $email): string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return '(sem e-mail)';
        }

        [$usuario, $dominio] = explode('@', $email, 2);

        $visivel = mb_strlen($usuario) <= 2
            ? mb_substr($usuario, 0, 1)
            : mb_substr($usuario, 0, 1).'***'.mb_substr($usuario, -1);

        return "{$visivel}@{$dominio}";
    }

    /**
     * O que a triagem automática não resolve.
     *
     * Números, não adjetivos: a equipe precisa saber quanto trabalho tem
     * pela frente antes de começar.
     */
    private function pendencias(): void
    {
        $produtos = StgWooProduct::query()->where('status_triagem', DestinoDaTriagem::Produto->value);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Exige decisão humana</>', '<fg=gray>itens</>');

        // Duplicata de verdade e variação com rótulo genérico são coisas
        // diferentes, e misturá-las inflaria o trabalho manual: a segunda
        // o saneamento já resolve compondo o nome.
        $reais = $this->gruposDuplicados(apenasAnuncios: true);

        $this->components->twoColumnDetail(
            '<fg=yellow>anúncios repetidos</>',
            $reais->sum('n').' produtos em '.$reais->count().' grupos',
        );

        $this->components->twoColumnDetail(
            '<fg=yellow>sem peso</>',
            (string) (clone $produtos)->whereNull('weight')->count(),
        );

        $this->components->twoColumnDetail(
            '<fg=yellow>sem preço</>',
            (string) (clone $produtos)->where(function ($q) {
                $q->whereNull('regular_price')->orWhere('regular_price', '');
            })->count(),
        );
    }

    private function listarDuplicados(): void
    {
        $grupos = $this->gruposDuplicados(apenasAnuncios: true);

        $this->newLine();
        $this->components->info("{$grupos->count()} anúncios aparecem mais de uma vez ({$grupos->sum('n')} produtos)");
        $this->line('  Cada grupo é a mesma peça recriada no site, com cores diferentes.');
        $this->line('  Manter separados é a decisão de 2026-07-27 — fundir depois é arquivar os repetidos.');
        $this->newLine();
        $this->line('  <fg=gray>Variações de kit com nome repetido ("KIT COMPLETO" ×18) não entram');
        $this->line('  nesta lista: não são duplicata, e a triagem já compõe o nome delas</>');
        $this->newLine();

        foreach ($grupos as $grupo) {
            $this->line("  <fg=yellow>{$grupo->n}×</> {$grupo->name}");

            $itens = StgWooProduct::query()
                ->where('name', $grupo->name)
                ->where('type', '!=', 'variation')
                ->where('status_triagem', DestinoDaTriagem::Produto->value)
                ->orderBy('woo_id')
                ->get();

            foreach ($itens as $item) {
                $this->line(sprintf(
                    '      %s  woo=%-6d  %s',
                    $item->sku_proposto ?? '—',
                    $item->woo_id,
                    $this->coresDe($item) ?: '(sem cor)',
                ));
            }
        }
    }

    /**
     * As cores que o anúncio lista, já legíveis.
     *
     * Percorrido à mão em vez de `collect()->firstWhere()`: o payload é
     * JSON solto, então cada nível pode ser qualquer coisa, e o laço
     * explícito é o que deixa isso verdadeiro para quem lê e para a
     * análise estática.
     */
    private function coresDe(StgWooProduct $item): string
    {
        foreach ((array) ($item->payload['attributes'] ?? []) as $atributo) {
            if (is_array($atributo) && ($atributo['slug'] ?? '') === 'pa_cor') {
                return implode(', ', (array) ($atributo['options'] ?? []));
            }
        }

        return '';
    }

    /**
     * Agregação, não entidade — daí o query builder e não o Eloquent.
     *
     * `StgWooProduct::selectRaw('name, count(*)')` devolveria models
     * meio-preenchidos, fingindo ser linhas de staging que não são. O
     * `stdClass` diz a verdade sobre o que isto é: contagem por título.
     *
     * @param  bool  $apenasAnuncios  exclui variações, cujos nomes repetidos
     *                                são rótulo de opção, não duplicata
     * @return Collection<int, \stdClass>
     */
    private function gruposDuplicados(bool $apenasAnuncios = false): Collection
    {
        return DB::table('stg_woo_products')
            ->selectRaw('name, count(*) as n')
            ->where('status_triagem', DestinoDaTriagem::Produto->value)
            ->when($apenasAnuncios, fn ($q) => $q->where('type', '!=', 'variation'))
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->orderByDesc('n')
            ->get();
    }
}
