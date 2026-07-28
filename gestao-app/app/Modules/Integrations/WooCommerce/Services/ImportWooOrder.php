<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Services\CancelChannelOrderService;
use App\Modules\Sales\Services\RegisterChannelOrderService;
use App\Modules\Sales\Services\ResolveCustomerService;
use Illuminate\Support\Facades\DB;

/**
 * Transforma um pedido do WooCommerce em pedido do ERP — corte 4 (docs/16 §4).
 *
 * O miolo que webhook e puxada compartilham (ADR-0007): os dois entregam o
 * mesmo array bruto do Woo aqui, e aqui é o único lugar que conhece o
 * formato do site — os módulos de domínio não (BR-701, ADR-0020). Fala com
 * Vendas só pela superfície de Services, com ids e DTOs; nunca toca `Order`
 * nem `Customer` (fronteira verificada no CI).
 *
 * A entrega deste corte é só a **entrada** (Woo→ERP). A saída (status e
 * rastreio de volta) nasce no corte 3 (fulfillment), em standby (pasta 10
 * §2.1).
 *
 * Três decisões que a realidade do catálogo obrigou:
 *
 * - **Casamento por id do Woo, não por SKU.** 716 de 716 produtos vieram
 *   sem SKU; o de-para da pasta 16 dizia "por SKU" e não fecharia. O que
 *   liga item de pedido a produto do ERP é o `integration_mappings` que a
 *   carga do catálogo gravou (id/variação do Woo → produto local).
 * - **Item sem casar → pedido em rascunho + pendência, nunca venda
 *   perdida.** Um item não mapeado não vira `OrderItem` (não há produto), e
 *   reservar pela metade seria pior. O pedido entra em rascunho com a
 *   pendência anotada; a operação mapeia o produto e a próxima passada
 *   confirma.
 * - **"Pago" não entra agora.** O pedido do site chega pago, mas o ERP não
 *   modela pagamento até o Gate 04 (Financeiro). Importa-se como confirmado
 *   (estoque reservado); o status e o pagamento do Woo ficam no bruto
 *   (`woo_webhook_events`) para o Financeiro depois.
 */
class ImportWooOrder
{
    /** Status do Woo que viram pedido no ERP (de-para, pasta 16). */
    private const IMPORTAVEIS = ['processing', 'on-hold', 'completed'];

    /**
     * Status que reservam estoque. `completed` fica de fora: a peça já saiu
     * do site, reservá-la contaria a mesma peça duas vezes — entra como
     * rascunho, para o corte 3 tratar o histórico já expedido.
     */
    private const RESERVAVEIS = ['processing', 'on-hold'];

    /**
     * Status do Woo que cancelam um pedido **já importado** — o `order.updated`
     * que reflete o cancelamento/reembolso do site no ERP (sync-pedidos §5).
     * Público para a puxada saber que estes não são "pedido já importado,
     * pula", e sim "reconciliar o cancelamento".
     *
     * @var list<string>
     */
    public const CANCELAMENTO = ['cancelled', 'refunded', 'failed'];

    public function __construct(
        private readonly ResolveCustomerService $clientes,
        private readonly RegisterChannelOrderService $pedidos,
        private readonly CancelChannelOrderService $cancelamentos,
    ) {}

    /**
     * @param  array<string, mixed>  $order  payload cru do pedido, formato REST v3
     */
    public function handle(array $order): ResultadoDaImportacao
    {
        $wooId = (string) ($order['id'] ?? '');
        $status = (string) ($order['status'] ?? '');

        if ($wooId === '') {
            return ResultadoDaImportacao::ignorado('pedido sem id');
        }

        // Idempotência (BR-703/704): pedido já importado não se recria nem
        // se reedita. Cobre o reenvio do webhook e a sobreposição com a
        // puxada — o mapeamento é a fonte única da verdade sobre "já entrou".
        $localId = IntegrationMapping::localDe('order', $wooId);

        if ($localId !== null) {
            // A única atualização de pedido já importado que o ERP reflete é
            // o **cancelamento** (BR-304: itens/valores não mudam). Qualquer
            // outro `order.updated` é no-op — inclusive o eco do próprio
            // cancelamento que o ERP acabou de empurrar ao Woo.
            if (in_array($status, self::CANCELAMENTO, true)) {
                return $this->refletirCancelamento($localId, $status);
            }

            return ResultadoDaImportacao::duplicado();
        }

        if (! in_array($status, self::IMPORTAVEIS, true)) {
            // pending (aguarda pagamento), cancelled, refunded, failed: não
            // são pedido a cumprir. Registrados no bruto, fora do ERP.
            return ResultadoDaImportacao::ignorado("status '{$status}' não importável");
        }

        [$mapeados, $naoMapeados] = $this->casarItens($order);

        if ($mapeados === []) {
            // Nenhum item casou: não há pedido a criar (sem itens). Fica no
            // bruto com o aviso; mapeado o produto, a próxima passada importa.
            return ResultadoDaImportacao::semItens(
                $naoMapeados,
                'nenhum item do pedido casou com o catálogo',
            );
        }

        return DB::transaction(function () use ($order, $wooId, $status, $mapeados, $naoMapeados): ResultadoDaImportacao {
            $customerId = $this->resolverCliente($order);

            $reservavel = $naoMapeados === [] && in_array($status, self::RESERVAVEIS, true);

            $resultado = $this->pedidos->handle(
                channel: OrderChannel::WooCommerce,
                customerId: $customerId,
                channelOrderRef: $this->referencia($order),
                itens: $mapeados,
                shipping: (string) ($order['shipping_total'] ?? '0'),
                channelTotal: isset($order['total']) ? (string) $order['total'] : null,
                tentarReservar: $reservavel,
                notaInicial: $this->pendenciaInicial($status, $naoMapeados),
            );

            IntegrationMapping::registrar('order', $resultado->orderId, $wooId);

            if ($resultado->confirmed) {
                return ResultadoDaImportacao::importado($resultado->orderId);
            }

            return ResultadoDaImportacao::pendente(
                $resultado->orderId,
                $this->motivoDaPendencia($status, $naoMapeados),
                $naoMapeados,
            );
        });
    }

    /**
     * Reflete no ERP o cancelamento que veio do site (sync-pedidos §5).
     *
     * Passa pela superfície de Vendas (id, não model — ADR-0020), que
     * resolve os casos da regra de "quem vence" (docs/16 §3): já cancelado
     * é no-op (anti-eco); já expedido é conflito (alerta, não desfaz).
     */
    private function refletirCancelamento(int $localId, string $status): ResultadoDaImportacao
    {
        $desfecho = $this->cancelamentos->cancelarPorId($localId, "Cancelado no site (Woo: {$status})");

        return match ($desfecho) {
            CancelChannelOrderService::CANCELADO => ResultadoDaImportacao::cancelado($localId),
            CancelChannelOrderService::CONFLITO_EXPEDIDO => ResultadoDaImportacao::conflito(
                $localId,
                'O site cancelou um pedido que o ERP já expediu — resolver por devolução.',
            ),
            // Já cancelado (anti-eco do próprio ERP) ou sumido: nada a fazer.
            default => ResultadoDaImportacao::duplicado(),
        };
    }

    /**
     * Casa cada `line_item` com um produto do ERP pelo id do Woo.
     *
     * A chave é a **variação** quando há (`variation_id`), senão o produto
     * (`product_id`) — pelo ADR-0022 cada variação virou produto próprio no
     * ERP, com mapeamento próprio.
     *
     * @param  array<string, mixed>  $order
     * @return array{0: list<array{product_id: int, qty: string, unit_price: string, note?: string|null}>, 1: list<string>}
     */
    private function casarItens(array $order): array
    {
        $mapeados = [];
        $naoMapeados = [];

        foreach ((array) ($order['line_items'] ?? []) as $linha) {
            if (! is_array($linha)) {
                continue;
            }

            $variacao = (int) ($linha['variation_id'] ?? 0);
            $produto = (int) ($linha['product_id'] ?? 0);
            $chave = $variacao > 0 ? $variacao : $produto;

            $localId = $chave > 0 ? IntegrationMapping::localDe('product', $chave) : null;

            if ($localId === null) {
                $nome = (string) ($linha['name'] ?? "item Woo #{$chave}");
                $naoMapeados[] = "{$nome} (Woo #{$chave})";

                continue;
            }

            $mapeados[] = [
                'product_id' => $localId,
                'qty' => $this->quantidade($linha),
                'unit_price' => $this->precoUnitario($linha),
            ];
        }

        return [$mapeados, $naoMapeados];
    }

    /**
     * O id do cliente do pedido, resolvido ou criado.
     *
     * Cliente com conta no Woo (`customer_id` > 0) casa primeiro pelo id
     * mapeado — é o dedupe mais forte, imune a quem troca o e-mail. Sem
     * conta (convidado), resolve por e-mail/documento. O convidado não ganha
     * mapeamento: não tem id de conta no Woo para mapear.
     *
     * @param  array<string, mixed>  $order
     */
    private function resolverCliente(array $order): int
    {
        $wooCustomerId = (int) ($order['customer_id'] ?? 0);

        if ($wooCustomerId > 0) {
            $local = IntegrationMapping::localDe('customer', $wooCustomerId);

            if ($local !== null) {
                return $local;
            }
        }

        /** @var array<string, mixed> $billing */
        $billing = (array) ($order['billing'] ?? []);

        $nome = trim(((string) ($billing['first_name'] ?? '')).' '.((string) ($billing['last_name'] ?? '')));

        $customerId = $this->clientes->paraCanal(
            name: $nome === '' ? null : $nome,
            email: isset($billing['email']) ? (string) $billing['email'] : null,
            doc: $this->documentoDe($order),
            phone: isset($billing['phone']) ? (string) $billing['phone'] : null,
        );

        if ($wooCustomerId > 0) {
            IntegrationMapping::registrar('customer', $customerId, $wooCustomerId);
        }

        return $customerId;
    }

    /**
     * A pendência anotada já na criação do rascunho.
     *
     * @param  list<string>  $naoMapeados
     */
    private function pendenciaInicial(string $status, array $naoMapeados): ?string
    {
        if ($naoMapeados !== []) {
            return 'Itens do site sem produto no catálogo: '.implode(', ', $naoMapeados);
        }

        if (! in_array($status, self::RESERVAVEIS, true)) {
            return "Pedido do site já '{$status}' — entrou sem reserva (a peça já saiu).";
        }

        return null;
    }

    /**
     * @param  list<string>  $naoMapeados
     */
    private function motivoDaPendencia(string $status, array $naoMapeados): string
    {
        if ($naoMapeados !== []) {
            return 'itens não mapeados';
        }

        if (! in_array($status, self::RESERVAVEIS, true)) {
            return "status '{$status}' não reserva";
        }

        return 'sem saldo';
    }

    /**
     * CPF/CNPJ do pedido, onde o plugin de checkout brasileiro o guardar.
     *
     * Qual plugin (e o nome exato do metadado) é pendência de inventário da
     * pasta 16 — por isso varre os nomes conhecidos em vez de fixar um. Sem
     * documento o pedido entra do mesmo jeito: o documento é nulável e a
     * pendência aparece no cadastro (BR-001).
     *
     * @param  array<string, mixed>  $order
     */
    private function documentoDe(array $order): ?string
    {
        /** @var array<string, mixed> $billing */
        $billing = (array) ($order['billing'] ?? []);

        foreach (['cpf', 'cnpj'] as $campo) {
            if (isset($billing[$campo]) && (string) $billing[$campo] !== '') {
                return (string) $billing[$campo];
            }
        }

        $conhecidos = ['_billing_cpf', 'billing_cpf', '_billing_cnpj', 'billing_cnpj'];

        foreach ((array) ($order['meta_data'] ?? []) as $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $chave = (string) ($meta['key'] ?? '');
            $valor = (string) ($meta['value'] ?? '');

            if ($valor !== '' && in_array($chave, $conhecidos, true)) {
                return $valor;
            }
        }

        return null;
    }

    /**
     * O número visível do pedido no site ("#727"), com recuo para o id.
     *
     * @param  array<string, mixed>  $order
     */
    private function referencia(array $order): ?string
    {
        $numero = (string) ($order['number'] ?? '');

        if ($numero !== '') {
            return mb_substr($numero, 0, 64);
        }

        return isset($order['id']) ? (string) $order['id'] : null;
    }

    /**
     * @param  array<string, mixed>  $linha
     */
    private function quantidade(array $linha): string
    {
        $qty = (float) ($linha['quantity'] ?? 0);

        return number_format($qty, 3, '.', '');
    }

    /**
     * Preço unitário congelado do site. O `price` do Woo já é o valor por
     * unidade efetivamente cobrado (pós-desconto); na falta dele, divide o
     * total da linha pela quantidade.
     *
     * @param  array<string, mixed>  $linha
     */
    private function precoUnitario(array $linha): string
    {
        if (isset($linha['price']) && (string) $linha['price'] !== '') {
            return number_format((float) $linha['price'], 2, '.', '');
        }

        $total = (string) ($linha['total'] ?? '0');
        $qty = (float) ($linha['quantity'] ?? 0);

        return $qty > 0 ? bcdiv($total, (string) $qty, 2) : '0.00';
    }
}
