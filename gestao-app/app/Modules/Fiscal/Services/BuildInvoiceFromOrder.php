<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Services;

use App\Modules\Fiscal\Enums\InvoiceStatus;
use App\Modules\Fiscal\Exceptions\EmissaoInvalida;
use App\Modules\Fiscal\Exceptions\PerfilFiscalAusente;
use App\Modules\Fiscal\Models\FiscalSeries;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\TaxProfile;
use App\Modules\Sales\DTO\OrderInvoiceAddress;
use App\Modules\Sales\DTO\OrderInvoiceItem;
use App\Modules\Sales\DTO\OrderInvoiceSnapshot;
use App\Modules\Sales\Services\BuildOrderInvoiceSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Monta o registro da nota a partir de um pedido — docs/14 §3, ADR-0025.
 *
 * A ordem dos passos é a regra, não detalhe de implementação:
 *
 * 1. **Pré-validação** (cliente com documento, endereço, produtos com dados
 *    fiscais — BR-001/BR-606) e **resolução do perfil** (docs/13 §3);
 * 2. só então **reserva o número** (BR-602) e grava a nota.
 *
 * Invertê-la seria queimar número em nota que nem podia ser montada — e
 * número queimado exige inutilização na SEFAZ. Cadastro incompleto não deve
 * gerar burocracia fiscal.
 *
 * **Idempotente por pedido.** O job roda em fila com retry; sem isto, uma
 * segunda tentativa criaria uma segunda nota para a mesma venda, cada uma
 * com seu número. Uma nota já autorizada é devolvida intacta (imutável,
 * docs/14 §3); uma pendente ou rejeitada é reaproveitada, **mantendo o
 * número** — rejeição se corrige e reemite com o mesmo (BR-602).
 *
 * Conversa com Vendas só pelo `BuildOrderInvoiceSnapshot` (ADR-0020);
 * `Sales\Models\Order` não aparece em lugar nenhum daqui.
 */
class BuildInvoiceFromOrder
{
    public function __construct(
        private readonly BuildOrderInvoiceSnapshot $pedidos,
        private readonly ResolveTaxProfile $perfis,
    ) {}

    /**
     * @throws EmissaoInvalida
     * @throws PerfilFiscalAusente
     */
    public function handle(int $orderId): Invoice
    {
        $existente = $this->notaCorrente($orderId);

        // Autorizada é imutável: nada a remontar, nada a renumerar.
        if ($existente !== null && $existente->status === InvoiceStatus::Authorized) {
            return $existente;
        }

        $snapshot = $this->pedidos->handle($orderId);

        if ($snapshot === null) {
            throw EmissaoInvalida::pedidoNaoEncontrado($orderId);
        }

        // A pré-validação **devolve** o endereço que provou existir: assim a
        // garantia viaja no tipo, e não numa suposição de quem lê o código
        // (nem numa inferência do analisador estático).
        $endereco = $this->preValidar($snapshot);

        $perfil = $this->perfis->handle(
            operationType: TaxProfile::OPERACAO_VENDA,
            destination: $this->destino($endereco),
            customerType: $this->tipoDeCliente($snapshot),
        );

        $serie = $this->serie();

        return DB::transaction(function () use ($existente, $snapshot, $perfil, $serie): Invoice {
            $nota = $existente ?? new Invoice([
                'order_id' => $snapshot->orderId,
                'status' => InvoiceStatus::Pending,
            ]);

            $nota->series_id = $serie->id;
            $nota->tax_profile_id = $perfil->id;
            $nota->total = $snapshot->total;

            // O número só é reservado uma vez por nota. Reaproveitar o da
            // tentativa anterior é o que garante que rejeição não queime
            // numeração (BR-602, docs/14 §3).
            $nota->number ??= $serie->proximoNumero();

            // A tentativa nova começa sem o motivo da anterior — se falhar
            // de novo, o motivo atual é gravado por quem transmite.
            $nota->rejection_reason = null;
            $nota->status = InvoiceStatus::Pending;

            $nota->save();

            return $nota;
        });
    }

    /**
     * Registra (ou atualiza) a nota como **pendente com motivo**, sem
     * número — para as falhas que não adianta tentar de novo.
     *
     * Perfil fiscal inexistente, produto sem NCM, cliente sem documento:
     * nenhuma se resolve na quinta tentativa da fila, todas se resolvem com
     * alguém preenchendo um cadastro. Deixá-las só no log esconderia a
     * pendência de quem pode resolvê-la; registrá-las aqui as coloca no
     * painel fiscal (docs/14 §5) com o texto do que fazer.
     *
     * Sem número, de propósito (ver o docblock da classe).
     */
    public function registrarPendencia(int $orderId, string $motivo): Invoice
    {
        $nota = $this->notaCorrente($orderId) ?? new Invoice([
            'order_id' => $orderId,
            'status' => InvoiceStatus::Pending,
        ]);

        // Não sobrescreve nota já autorizada: se ela existe, a pendência é
        // de outra coisa e a nota boa não pode ser depreciada por um erro
        // de reprocessamento.
        if ($nota->exists && $nota->status === InvoiceStatus::Authorized) {
            return $nota;
        }

        $nota->series_id ??= $this->serieOuNulo()?->id;
        $nota->status = InvoiceStatus::Pending;
        $nota->rejection_reason = $motivo;
        $nota->save();

        return $nota;
    }

    /**
     * A nota "de agora" do pedido: a última que não foi cancelada.
     *
     * Cancelada fica no histórico e não estorva — um pedido cuja nota foi
     * cancelada dentro do prazo (BR-604) pode ser refaturado.
     */
    public function notaCorrente(int $orderId): ?Invoice
    {
        return Invoice::query()
            ->where('order_id', $orderId)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * O que impede esta venda de virar nota — BR-001, BR-606, docs/14 §3.
     *
     * Devolve o endereço de entrega validado, para que quem chama não
     * precise reconferir o que já foi conferido aqui.
     *
     * @throws EmissaoInvalida
     */
    private function preValidar(OrderInvoiceSnapshot $snapshot): OrderInvoiceAddress
    {
        if ($snapshot->items === []) {
            throw EmissaoInvalida::semItens($snapshot->orderNumber);
        }

        if ($snapshot->customerId === null) {
            throw EmissaoInvalida::semCliente($snapshot->orderNumber);
        }

        $nome = $snapshot->customerName ?? "cliente {$snapshot->customerId}";

        if ($snapshot->customerDocument === null || $snapshot->customerDocument === '') {
            throw EmissaoInvalida::clienteSemDocumento($nome);
        }

        $endereco = $snapshot->shippingAddress;

        if ($endereco === null) {
            throw EmissaoInvalida::clienteSemEndereco($nome);
        }

        foreach ($snapshot->items as $item) {
            $this->preValidarItem($item);
        }

        return $endereco;
    }

    /**
     * @throws EmissaoInvalida
     */
    private function preValidarItem(OrderInvoiceItem $item): void
    {
        $pendencias = [];

        if ($item->ncm === null || $item->ncm === '') {
            $pendencias[] = 'sem NCM';
        }

        if ($item->origin === null || $item->origin === '') {
            $pendencias[] = 'sem origem da mercadoria';
        }

        if ($pendencias === []) {
            return;
        }

        // Identifica pelo SKU quando o Catálogo respondeu; pelo id quando o
        // produto sumiu do catálogo entre a venda e o faturamento.
        $identificacao = $item->sku ?? "produto {$item->productId}";

        throw EmissaoInvalida::produtoSemDadosFiscais($identificacao, $pendencias);
    }

    /**
     * Dentro ou fora do estado — o que separa CFOP 5xxx de 6xxx (docs/13 §3).
     *
     * @throws EmissaoInvalida
     */
    private function destino(OrderInvoiceAddress $endereco): string
    {
        $ufEmitente = config('fiscal.nfe.uf_emitente');

        if (! is_string($ufEmitente) || trim($ufEmitente) === '') {
            throw EmissaoInvalida::ufEmitenteNaoConfigurada();
        }

        return strtoupper(trim($endereco->state)) === strtoupper(trim($ufEmitente))
            ? TaxProfile::DESTINO_DENTRO_UF
            : TaxProfile::DESTINO_FORA_UF;
    }

    /**
     * Traduz o vocabulário de Vendas (`individual`/`company`) para o da
     * matriz fiscal (`pf`/`pj`).
     *
     * A tradução mora aqui, e não no DTO de Vendas, porque PF/PJ é conceito
     * fiscal: Vendas não deve aprender o vocabulário do fisco para poder
     * responder a uma pergunta sobre cliente.
     */
    private function tipoDeCliente(OrderInvoiceSnapshot $snapshot): string
    {
        return $snapshot->customerType === 'company'
            ? TaxProfile::CLIENTE_PJ
            : TaxProfile::CLIENTE_PF;
    }

    /**
     * A série configurada para o ambiente corrente — BR-602.
     *
     * @throws EmissaoInvalida
     */
    private function serie(): FiscalSeries
    {
        $serie = $this->serieOuNulo();

        if ($serie === null) {
            throw EmissaoInvalida::serieNaoConfigurada(
                (string) config('fiscal.nfe.series'),
                (string) config('fiscal.nfe.environment'),
            );
        }

        return $serie;
    }

    private function serieOuNulo(): ?FiscalSeries
    {
        return FiscalSeries::query()
            ->where('series', (string) config('fiscal.nfe.series'))
            ->where('environment', (string) config('fiscal.nfe.environment'))
            ->first();
    }
}
