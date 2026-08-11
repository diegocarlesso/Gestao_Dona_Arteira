<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Services\RegisterReceivableService;
use App\Modules\Finance\Services\RegisterSettlementService;
use App\Modules\Sales\Events\OrderConfirmed;
use Illuminate\Support\Facades\Log;

/**
 * Pedido confirmado → título a receber — BR-501, docs/12-Financeiro §3.
 *
 * Mesmo desenho do `EmitirNfeAoConfirmarPedido` (Fiscal): só importa o
 * Event e o Enum de canal, nunca `Sales\Models\Order` (fronteira
 * verificada no CI) — lê primitivos de `$event->pedido` por acesso
 * dinâmico de propriedade, mesmo precedente que o listener do Fiscal já
 * usa para `channel`/`id`.
 *
 * **Todo canal gera título.** Balcão, site, atacado — não há filtro de
 * canal aqui como no Fiscal (BR-601 ainda é hipótese bloqueada; BR-501
 * não tem essa mesma trava). Se `paid_at` já veio preenchido (balcão à
 * vista, ou pedido do Woo com `processing`/`completed`), o título nasce
 * **e já é baixado na mesma chamada** — "venda balcão à vista: título já
 * nasce baixado" (docs/12 §3).
 *
 * A conta de baixa é sempre `Conta padrão` por enquanto — qual conta cada
 * canal deveria baixar (dinheiro do balcão vs. gateway do site) é
 * detalhe de configuração que ainda não tem tela; a Fase C (cobrança
 * Mercado Pago) é onde isso ganha granularidade real.
 *
 * **Nunca desfaz a confirmação do pedido.** Este listener roda síncrono,
 * dentro da mesma transação do `ConfirmOrderService` (`OrderConfirmed::
 * dispatch()` acontece por dentro do `DB::transaction()` de lá) — uma
 * exceção daqui reverteria a venda inteira por causa de cadastro
 * financeiro incompleto, o mesmo risco que o Fiscal já evita (BR-705).
 * Por isso todo o corpo roda dentro de um `try/catch(TituloInvalido)`:
 * cadastro incompleto vira aviso no log, nunca pedido desfeito.
 */
class RegistrarRecebivelAoConfirmarPedido
{
    public const ORIGEM = 'order';

    /** Nasce no seeder, mesmo padrão de `RegisterPayableService::CATEGORIA_PADRAO_COMPRAS`. */
    public const CONTA_PADRAO = 'Conta padrão';

    public function __construct(
        private readonly RegisterReceivableService $titulos,
        private readonly RegisterSettlementService $baixas,
    ) {}

    public function handle(OrderConfirmed $event): void
    {
        $pedido = $event->pedido;

        if (bccomp($pedido->total, '0.00', 2) !== 1) {
            // Pedido de valor zero (100% desconto, ou dado incompleto de
            // um canal): não é dívida do cliente, e um título de R$ 0,00
            // só enfeitaria a lista de abertos sem significar nada.
            return;
        }

        try {
            $this->registrarERodarBaixa($event);
        } catch (TituloInvalido $e) {
            Log::warning('Financeiro: pedido confirmado sem gerar título a receber (cadastro incompleto).', [
                'order_id' => $pedido->id,
                'motivo' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Recebe o `Event` inteiro, não o pedido tipado — o tipo `Order` não
     * pode aparecer neste módulo nem como parâmetro de método privado
     * (fronteira ADR-0020, verificada por `use`/referência em todo o
     * arquivo, não só no `handle()` público).
     *
     * @throws TituloInvalido
     */
    private function registrarERodarBaixa(OrderConfirmed $event): void
    {
        $pedido = $event->pedido;
        $cliente = $pedido->customer;

        $titulo = $this->titulos->registrar(
            originType: self::ORIGEM,
            originId: $pedido->id,
            customerName: $cliente !== null ? $cliente->name : "Cliente do pedido #{$pedido->number}",
            amount: $pedido->total,
            dueDate: null,
        );

        if ($pedido->paid_at === null) {
            return;
        }

        $conta = $this->contaPadrao();

        if ($conta === null) {
            // Pendência visível: o título já existe (aberto), só a baixa
            // automática que não rolou — mesmo espírito do catch acima,
            // mas este caso é esperado o bastante (conta ainda não
            // cadastrada) para ter mensagem própria em vez de reaproveitar
            // o aviso genérico.
            Log::warning('Financeiro: conta padrão ausente, título nasceu aberto apesar de o pedido já estar pago.', [
                'order_id' => $pedido->id,
                'receivable_id' => $titulo->id,
            ]);

            return;
        }

        $this->baixas->handle(
            titleType: 'receivable',
            titleId: $titulo->id,
            accountId: $conta->id,
            amount: $titulo->amount,
            settledAt: $pedido->paid_at->toDateString(),
            notes: $pedido->payment_method,
        );
    }

    private function contaPadrao(): ?FinanceAccount
    {
        return FinanceAccount::query()
            ->where('name', self::CONTA_PADRAO)
            ->first();
    }
}
