<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\SetProductPriceService;
use App\Modules\Integrations\Email\Notifications\PedidoConfirmadoNotification;
use App\Modules\Integrations\Email\Notifications\PedidoEnviadoNotification;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Services\RecordMovementService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\ConfirmOrderService;
use App\Modules\Sales\Services\FulfillmentService;
use App\Modules\Sales\Services\SaveOrderService;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| E-mail transacional — docs/15-Integracoes/01-email-transacional.md, BR-310
|--------------------------------------------------------------------------
|
| Confirmação e rastreio, disparados por OrderConfirmed/OrderShipped e
| ouvidos em Integrations (ADR-0020 — Vendas não sabe que existe e-mail).
| A ausência de cliente ou de e-mail é o caminho mais comum na operação
| de balcão de hoje, e precisa ser silêncio, não erro.
|
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Location::factory()->padrao()->create();
});

function produtoComEstoqueParaEmail(string $preco = '89.90', string $estoque = '5'): Product
{
    $produto = Product::factory()->create();
    app(SetProductPriceService::class)->handle($produto, PriceList::Retail, $preco);

    $local = Location::query()->where('is_default', true)->firstOrFail();
    app(RecordMovementService::class)->handle($produto->id, $local->id, MovementType::ProductionOutput, $estoque);

    return $produto;
}

function pedidoConfirmadoComCliente(?Customer $cliente, ?array $itens = null): Order
{
    $itens ??= [['product' => produtoComEstoqueParaEmail()->public_id, 'qty' => '2']];

    $pedido = app(SaveOrderService::class)->handle(null, ['customer_id' => $cliente?->id], $itens);

    return app(ConfirmOrderService::class)->handle($pedido);
}

describe('confirmação do pedido', function () {
    it('envia o e-mail de confirmação para o cliente do pedido', function () {
        Notification::fake();
        $cliente = Customer::factory()->create(['name' => 'Mariana Gesso', 'email' => 'mariana@exemplo.test']);

        $pedido = pedidoConfirmadoComCliente($cliente, [['product' => produtoComEstoqueParaEmail('50.00')->public_id, 'qty' => '3']]);

        Notification::assertSentOnDemand(
            PedidoConfirmadoNotification::class,
            fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'mariana@exemplo.test'
        );
    });

    it('não envia nada quando o pedido não tem cliente (balcão)', function () {
        Notification::fake();

        pedidoConfirmadoComCliente(null);

        Notification::assertNothingSent();
    });

    it('não envia nada quando o cliente não tem e-mail cadastrado', function () {
        Notification::fake();
        $cliente = Customer::factory()->create(['email' => null]);

        pedidoConfirmadoComCliente($cliente);

        Notification::assertNothingSent();
    });
});

describe('expedição do pedido', function () {
    it('envia o e-mail de rastreio com o código e a transportadora', function () {
        Notification::fake();
        $cliente = Customer::factory()->create(['email' => 'rita@exemplo.test']);
        $pedido = pedidoConfirmadoComCliente($cliente);
        app(FulfillmentService::class)->iniciarSeparacao($pedido);
        app(FulfillmentService::class)->embalar($pedido);

        app(FulfillmentService::class)->expedir($pedido, 'BR123456789BR', 'Correios');

        Notification::assertSentOnDemand(
            PedidoEnviadoNotification::class,
            fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'rita@exemplo.test'
        );
    });

    it('envia o e-mail de rastreio mesmo sem código informado', function () {
        Notification::fake();
        $cliente = Customer::factory()->create(['email' => 'sem-rastreio@exemplo.test']);
        $pedido = pedidoConfirmadoComCliente($cliente);
        app(FulfillmentService::class)->iniciarSeparacao($pedido);
        app(FulfillmentService::class)->embalar($pedido);

        app(FulfillmentService::class)->expedir($pedido);

        Notification::assertSentOnDemand(PedidoEnviadoNotification::class);
    });

    it('não envia nada quando o pedido de balcão não tem cliente', function () {
        Notification::fake();
        $pedido = pedidoConfirmadoComCliente(null);
        app(FulfillmentService::class)->iniciarSeparacao($pedido);
        app(FulfillmentService::class)->embalar($pedido);

        app(FulfillmentService::class)->expedir($pedido, 'BR1', 'Correios');

        Notification::assertNothingSent();
    });
});
