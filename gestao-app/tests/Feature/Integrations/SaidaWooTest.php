<?php

declare(strict_types=1);

use App\Modules\Integrations\WooCommerce\Client;
use App\Modules\Integrations\WooCommerce\Jobs\PushCancellationToWoo;
use App\Modules\Integrations\WooCommerce\Jobs\PushShipmentToWoo;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Events\OrderShipped;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\CancelOrderService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Saída ERP→Woo — devolução de status/rastreio (docs/16 §4, sync-pedidos §7)
|--------------------------------------------------------------------------
|
| A expedição no ERP (OrderShipped) devolve ao site o status (completed) e
| o rastreio (nota ao cliente). Assíncrono (BR-705) e só para pedido do
| site — o de balcão não tem contraparte no Woo. A fronteira ADR-0020 é
| coberta pelo teste de arquitetura; aqui se prova o comportamento.
|
*/

function ligarWoo(): void
{
    config()->set('integrations.woocommerce', [
        'enabled' => true,
        'url' => 'https://loja.test',
        'key' => 'ck_teste',
        'secret' => 'cs_teste',
        'timeout' => 5,
    ]);
}

function pedidoWooMapeado(int $wooId, ?string $tracking = null, ?string $carrier = null): Order
{
    $pedido = Order::factory()->create([
        'channel' => OrderChannel::WooCommerce,
        'tracking_code' => $tracking,
        'carrier' => $carrier,
    ]);
    IntegrationMapping::registrar('order', $pedido->id, $wooId);

    return $pedido;
}

describe('saída ERP→Woo', function () {
    it('devolve status completed e o rastreio como nota ao cliente', function () {
        ligarWoo();
        Http::fake(['*' => Http::response([])]);
        $pedido = pedidoWooMapeado(555, 'BR9', 'Correios');

        (new PushShipmentToWoo($pedido->id, 'BR9', 'Correios'))->handle(app(Client::class));

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains($r->url(), '/orders/555')
            && $r['status'] === 'completed');

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/orders/555/notes')
            && $r['customer_note'] === true
            && str_contains($r['note'], 'BR9')
            && str_contains($r['note'], 'Correios'));
    });

    it('atualiza o status mesmo sem rastreio, sem criar nota', function () {
        ligarWoo();
        Http::fake(['*' => Http::response([])]);
        $pedido = pedidoWooMapeado(556);

        (new PushShipmentToWoo($pedido->id))->handle(app(Client::class));

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/orders/556'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/notes'));
    });

    it('não chama o Woo para pedido de balcão (sem mapeamento)', function () {
        ligarWoo();
        Http::fake();
        $pedido = Order::factory()->create(); // canal ERP, sem mapeamento

        (new PushShipmentToWoo($pedido->id, 'BR9'))->handle(app(Client::class));

        Http::assertNothingSent();
    });

    it('fica quieto com a integração desligada', function () {
        config()->set('integrations.woocommerce.enabled', false);
        Http::fake();
        $pedido = pedidoWooMapeado(557, 'BR9');

        (new PushShipmentToWoo($pedido->id, 'BR9'))->handle(app(Client::class));

        Http::assertNothingSent();
    });

    it('ao expedir, dispara o push só para pedido do site', function () {
        Bus::fake([PushShipmentToWoo::class]);
        $woo = Order::factory()->create(['channel' => OrderChannel::WooCommerce, 'tracking_code' => 'BR9']);
        $erp = Order::factory()->create(['channel' => OrderChannel::Erp]);

        event(new OrderShipped($woo));
        event(new OrderShipped($erp));

        Bus::assertDispatchedTimes(PushShipmentToWoo::class, 1);
        Bus::assertDispatched(PushShipmentToWoo::class, fn ($j) => $j->orderId === $woo->id && $j->trackingCode === 'BR9');
    });
});

describe('cancelamento ERP→Woo', function () {
    it('devolve status cancelled e o motivo como nota interna', function () {
        ligarWoo();
        Http::fake(['*' => Http::response([])]);
        $pedido = pedidoWooMapeado(600);

        (new PushCancellationToWoo($pedido->id, 'cliente desistiu'))->handle(app(Client::class));

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains($r->url(), '/orders/600')
            && $r['status'] === 'cancelled');

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/orders/600/notes')
            && $r['customer_note'] === false
            && str_contains($r['note'], 'cliente desistiu'));
    });

    it('cancelar um pedido do site dispara o push; um de balcão não', function () {
        Bus::fake([PushCancellationToWoo::class]);
        $woo = pedidoWooMapeado(601);
        $erp = Order::factory()->create(['channel' => OrderChannel::Erp]);

        app(CancelOrderService::class)->handle($woo, 'sem estoque');
        app(CancelOrderService::class)->handle($erp, 'desistência');

        Bus::assertDispatchedTimes(PushCancellationToWoo::class, 1);
        Bus::assertDispatched(PushCancellationToWoo::class, fn ($j) => $j->orderId === $woo->id && $j->motivo === 'sem estoque');
    });
});
