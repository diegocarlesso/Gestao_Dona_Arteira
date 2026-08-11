<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Services\CancelChannelOrderService;
use App\Modules\Sales\Services\RegisterChannelOrderService;
use App\Modules\Sales\Services\ResolveCustomerService;
use App\Modules\Sales\Services\SaveOrderAddressesService;
use Illuminate\Support\Carbon;
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
        private readonly SaveOrderAddressesService $enderecos,
    ) {}

    /**
     * @param  array<string, mixed>  $order  payload cru do pedido, formato REST v3
     * @param  bool  $historico  puxada de histórico (docs/16 §4): pedido `completed` entra direto
     *                           como `Entregue`, sem reserva — a peça já saiu antes do ERP existir.
     *                           O webhook em tempo real nunca passa `true` aqui.
     */
    public function handle(array $order, bool $historico = false): ResultadoDaImportacao
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

        return DB::transaction(function () use ($order, $wooId, $status, $mapeados, $naoMapeados, $historico): ResultadoDaImportacao {
            $customerId = $this->resolverCliente($order);

            $reservavel = $naoMapeados === [] && in_array($status, self::RESERVAVEIS, true);

            // `completed` nunca é reservável (self::RESERVAVEIS), então
            // `$entregueSemBaixa` e `$reservavel` nunca são true ao mesmo
            // tempo — não há caso de "histórico entregue com reserva" para
            // tratar.
            $entregueSemBaixa = $historico && $status === 'completed';

            $resultado = $this->pedidos->handle(
                channel: OrderChannel::WooCommerce,
                customerId: $customerId,
                channelOrderRef: $this->referencia($order),
                itens: $mapeados,
                shipping: (string) ($order['shipping_total'] ?? '0'),
                channelTotal: isset($order['total']) ? (string) $order['total'] : null,
                tentarReservar: $reservavel,
                notaInicial: $this->pendenciaInicial($status, $naoMapeados),
                customerNote: $this->customerNoteDe($order),
                shippingMethod: $this->shippingMethodDe($order),
                entregueSemBaixa: $entregueSemBaixa,
                orderedAt: $this->dataDe($order, 'date_created'),
                entregueEm: $entregueSemBaixa ? ($this->dataDe($order, 'date_completed') ?? $this->dataDe($order, 'date_created')) : null,
                paymentMethod: $this->formaDePagamentoDe($order),
                paidAt: $this->dataDePagamentoDe($order, $status),
            );

            IntegrationMapping::registrar('order', $resultado->orderId, $wooId);

            $this->enderecos->handle($resultado->orderId, $this->enderecosDoPedido($order));

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
            // O Woo às vezes guarda um array em `value` (campo multivalor de
            // outro plugin) — não é CPF nem CNPJ, então pula.
            if (! is_array($meta) || ! is_string($meta['value'] ?? null)) {
                continue;
            }

            $chave = (string) ($meta['key'] ?? '');
            $valor = $meta['value'];

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

    /**
     * O que o cliente digitou no checkout ("entregar nos fundos", "presente,
     * não incluir nota") — BR-707. String vazia (campo do Woo sempre
     * presente, raramente preenchido) vira nulo, não fica registrada como
     * observação vazia.
     *
     * @param  array<string, mixed>  $order
     */
    private function customerNoteDe(array $order): ?string
    {
        $nota = trim((string) ($order['customer_note'] ?? ''));

        return $nota === '' ? null : $nota;
    }

    /**
     * A forma de entrega escolhida no checkout — BR-707. O Woo manda
     * `shipping_lines` como lista (múltiplos métodos no mesmo carrinho é
     * possível); só a primeira linha é capturada — mais de uma forma de
     * entrega por pedido é caso de borda fora de escopo aqui.
     *
     * @param  array<string, mixed>  $order
     */
    private function shippingMethodDe(array $order): ?string
    {
        $linhas = (array) ($order['shipping_lines'] ?? []);
        $primeira = $linhas[0] ?? null;

        if (! is_array($primeira)) {
            return null;
        }

        $metodo = trim((string) ($primeira['method_title'] ?? ''));

        return $metodo === '' ? null : $metodo;
    }

    /**
     * A forma de pagamento escolhida no checkout (`payment_method_title`)
     * — BR-501. Texto livre, mesmo tratamento do `shippingMethodDe()`.
     *
     * @param  array<string, mixed>  $order
     */
    private function formaDePagamentoDe(array $order): ?string
    {
        $metodo = trim((string) ($order['payment_method_title'] ?? ''));

        return $metodo === '' ? null : $metodo;
    }

    /**
     * Quando o Woo confirmou o pagamento — BR-501.
     *
     * `processing` e `completed` são os únicos status do de-para (pasta 16)
     * em que o próprio Woo já considera o pedido pago; `on-hold` é
     * "aguardando" (boleto/PIX ainda não compensado). Sem essa data, o
     * título a receber que o Financeiro cria não saberia nascer aberto ou
     * já baixado. `date_paid` é o campo específico do Woo para isso; cai
     * para `date_created` só se o Woo não tiver mandado `date_paid` (raro,
     * mas mais correto que ficar sem nenhuma data num pedido que o próprio
     * Woo diz estar pago).
     *
     * @param  array<string, mixed>  $order
     */
    private function dataDePagamentoDe(array $order, string $status): ?Carbon
    {
        if (! in_array($status, ['processing', 'completed'], true)) {
            return null;
        }

        return $this->dataDe($order, 'date_paid') ?? $this->dataDe($order, 'date_created');
    }

    /**
     * A data real do pedido no Woo (`date_created`) ou da sua conclusão
     * (`date_completed`) — BR-703/BR-707.
     *
     * Sem isso, `RegisterChannelOrderService` deixaria o Eloquent carimbar
     * `created_at` com o instante da importação: um pedido de anos atrás
     * pareceria vendido hoje, e o dashboard (que soma vendas por
     * `created_at`) contaria a puxada histórica inteira como se fosse do
     * mês corrente. Usa sempre a variante `_gmt` (o Woo manda as duas, e
     * essa já vem em UTC — a sem sufixo é o fuso local do site, ambíguo
     * sem saber qual é); cai para a variante sem sufixo só se a `_gmt`
     * vier ausente ou vazia. Devolve `null` quando nenhuma das duas existe
     * (nunca acontece na prática, mas o retorno nulo do
     * `RegisterChannelOrderService` já sabe manter o comportamento padrão
     * do Eloquent nesse caso).
     *
     * @param  array<string, mixed>  $order
     * @param  'date_created'|'date_completed'|'date_paid'  $campo
     */
    private function dataDe(array $order, string $campo): ?Carbon
    {
        $valor = $order["{$campo}_gmt"] ?? $order[$campo] ?? null;

        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor, 'UTC')->setTimezone(config('app.timezone'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Os endereços de cobrança/entrega do pedido, prontos para o
     * `SaveOrderAddressesService` — BR-707.
     *
     * Mesma regra do `LoadWooCustomers::enderecoDe()`/`campoBr()` (bloco
     * vazio não é erro — o Woo deixa `shipping` em branco quando a entrega é
     * no endereço de cobrança; endereço incompleto não vira registro). A
     * diferença é a origem: aqui é o array cru do pedido (`$order['billing']`
     * /`$order['shipping']`/`$order['meta_data']`), não o staging de
     * cliente — duplicar essas ~40 linhas adaptadas é aceitável, a fronteira
     * entre migração de cliente e importação de pedido não vale a pena
     * forçar por um helper compartilhado agora.
     *
     * @param  array<string, mixed>  $order
     * @return list<array{type: string, zip: string, street: string, number: ?string, complement: ?string, district: ?string, city: string, state: string}>
     */
    private function enderecosDoPedido(array $order): array
    {
        $enderecos = [];

        foreach (['billing', 'shipping'] as $bloco) {
            $endereco = $this->enderecoDoPedido($order, $bloco);

            if ($endereco !== null) {
                $enderecos[] = $endereco;
            }
        }

        return $enderecos;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{type: string, zip: string, street: string, number: ?string, complement: ?string, district: ?string, city: string, state: string}|null
     */
    private function enderecoDoPedido(array $order, string $bloco): ?array
    {
        /** @var array<string, mixed> $dados */
        $dados = (array) ($order[$bloco] ?? []);

        $rua = trim((string) ($dados['address_1'] ?? ''));
        $cidade = trim((string) ($dados['city'] ?? ''));
        $uf = mb_strtoupper(trim((string) ($dados['state'] ?? '')));

        if ($rua === '' && $cidade === '' && $uf === '') {
            // Bloco inteiramente vazio é o normal, não um problema — mesma
            // regra do `LoadWooCustomers`.
            return null;
        }

        if ($rua === '' || $cidade === '' || mb_strlen($uf) !== 2) {
            // Incompleto: não vira registro. Best-effort de importação de
            // pedido, não migração de cliente — não há relatório de recusa
            // aqui, o pedido entra do mesmo jeito sem este endereço.
            return null;
        }

        return [
            'type' => $bloco,
            'zip' => (string) ($dados['postcode'] ?? ''),
            'street' => $rua,
            // O número mora num campo do plugin brasileiro, não em
            // `address_1` — mesma ressalva do `LoadWooCustomers`.
            'number' => $this->campoBrDoPedido($order, $dados, $bloco, 'number'),
            'complement' => trim((string) ($dados['address_2'] ?? '')),
            'district' => $this->campoBrDoPedido($order, $dados, $bloco, 'neighborhood'),
            'city' => $cidade,
            'state' => $uf,
        ];
    }

    /**
     * Campo que o plugin de checkout brasileiro acrescenta (número, bairro),
     * onde quer que ele o guarde — mesma varredura empírica do
     * `LoadWooCustomers::campoBr()`, lendo direto do pedido.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $dados
     */
    private function campoBrDoPedido(array $order, array $dados, string $bloco, string $campo): ?string
    {
        if (isset($dados[$campo]) && trim((string) $dados[$campo]) !== '') {
            return trim((string) $dados[$campo]);
        }

        $conhecidos = ["_{$bloco}_{$campo}", "{$bloco}_{$campo}"];

        foreach ((array) ($order['meta_data'] ?? []) as $meta) {
            // O Woo às vezes guarda um array em `value` (campo multivalor de
            // outro plugin) — não é número de casa nem bairro, então pula.
            if (! is_array($meta) || ! is_string($meta['value'] ?? null)) {
                continue;
            }

            $chave = (string) ($meta['key'] ?? '');
            $valor = trim($meta['value']);

            if ($valor !== '' && in_array($chave, $conhecidos, true)) {
                return $valor;
            }
        }

        return null;
    }
}
