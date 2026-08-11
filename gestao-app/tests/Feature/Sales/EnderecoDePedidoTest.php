<?php

declare(strict_types=1);

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use App\Modules\Sales\Models\IbgeMunicipality;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderAddress;
use App\Modules\Sales\Services\BuildOrderInvoiceSnapshot;
use App\Modules\Sales\Services\SaveOrderAddressesService;

/*
|--------------------------------------------------------------------------
| Endereço do pedido — BR-707, docs/10-Vendas §2.0.2
|--------------------------------------------------------------------------
|
| O endereço de entrega/cobrança agora é do PEDIDO, não só do cliente: a
| entrega às vezes é para outra pessoa (presente). `SaveOrderAddressesService`
| grava esse endereço; `BuildOrderInvoiceSnapshot` (a porta com o Fiscal)
| passa a priorizar o `OrderAddress` do pedido sobre o endereço padrão do
| cliente, só caindo para o do cliente quando o pedido não tiver nenhum.
|
*/

// -------------------------------------------------- SaveOrderAddressesService

it('grava billing e shipping distintos para o mesmo pedido', function () {
    $pedido = Order::factory()->create();

    app(SaveOrderAddressesService::class)->handle($pedido->id, [
        [
            'type' => 'billing',
            'zip' => '90000000',
            'street' => 'Rua da Cobrança',
            'number' => '10',
            'complement' => null,
            'district' => 'Centro',
            'city' => 'Cidade Inventada',
            'state' => 'RS',
        ],
        [
            'type' => 'shipping',
            'zip' => '91000000',
            'street' => 'Rua da Entrega',
            'number' => '20',
            'complement' => 'Fundos',
            'district' => 'Bairro Novo',
            'city' => 'Outra Cidade Inventada',
            'state' => 'RS',
        ],
    ]);

    expect($pedido->addresses()->count())->toBe(2);

    $billing = $pedido->addresses()->where('type', 'billing')->firstOrFail();
    $shipping = $pedido->addresses()->where('type', 'shipping')->firstOrFail();

    expect($billing->street)->toBe('Rua da Cobrança')
        ->and($billing->zip)->toBe('90000000')
        ->and($shipping->street)->toBe('Rua da Entrega')
        ->and($shipping->complement)->toBe('Fundos');
});

it('roda duas vezes sem duplicar — idempotência via updateOrCreate', function () {
    $pedido = Order::factory()->create();

    $enderecos = [[
        'type' => 'shipping',
        'zip' => '91000000',
        'street' => 'Rua da Entrega',
        'number' => '20',
        'complement' => null,
        'district' => 'Bairro Novo',
        'city' => 'Outra Cidade Inventada',
        'state' => 'RS',
    ]];

    $service = app(SaveOrderAddressesService::class);
    $service->handle($pedido->id, $enderecos);
    $service->handle($pedido->id, $enderecos);

    expect($pedido->addresses()->count())->toBe(1);
});

it('atualiza o endereco existente em vez de duplicar quando o conteudo muda', function () {
    $pedido = Order::factory()->create();
    $service = app(SaveOrderAddressesService::class);

    $service->handle($pedido->id, [[
        'type' => 'shipping',
        'zip' => '91000000',
        'street' => 'Rua Antiga',
        'number' => '20',
        'complement' => null,
        'district' => null,
        'city' => 'Outra Cidade Inventada',
        'state' => 'RS',
    ]]);

    $service->handle($pedido->id, [[
        'type' => 'shipping',
        'zip' => '91000000',
        'street' => 'Rua Nova',
        'number' => '25',
        'complement' => null,
        'district' => null,
        'city' => 'Outra Cidade Inventada',
        'state' => 'RS',
    ]]);

    expect($pedido->addresses()->count())->toBe(1)
        ->and($pedido->addresses()->firstOrFail()->street)->toBe('Rua Nova');
});

it('resolve o city_code quando cidade e UF casam contra ibge_municipalities', function () {
    IbgeMunicipality::query()->create([
        'ibge_code' => '4314902',
        'uf' => 'RS',
        'name' => 'Porto Alegre',
    ]);

    $pedido = Order::factory()->create();

    app(SaveOrderAddressesService::class)->handle($pedido->id, [[
        'type' => 'shipping',
        'zip' => '90000000',
        'street' => 'Rua X',
        'number' => '1',
        'complement' => null,
        'district' => null,
        'city' => 'Porto Alegre',
        'state' => 'RS',
    ]]);

    expect($pedido->addresses()->firstOrFail()->city_code)->toBe('4314902');
});

it('fica com city_code nulo quando a cidade nao casa contra ibge_municipalities', function () {
    $pedido = Order::factory()->create();

    app(SaveOrderAddressesService::class)->handle($pedido->id, [[
        'type' => 'shipping',
        'zip' => '90000000',
        'street' => 'Rua X',
        'number' => '1',
        'complement' => null,
        'district' => null,
        'city' => 'Cidade Que Nao Existe',
        'state' => 'RS',
    ]]);

    expect($pedido->addresses()->firstOrFail()->city_code)->toBeNull();
});

it('trata lista vazia como no-op', function () {
    $pedido = Order::factory()->create();

    app(SaveOrderAddressesService::class)->handle($pedido->id, []);

    expect($pedido->addresses()->count())->toBe(0);
});

// -------------------------------------------------- BuildOrderInvoiceSnapshot

it('o endereco de entrega do pedido vence o endereco padrao do cliente — o caso do presente', function () {
    $cliente = Customer::factory()->create();
    CustomerAddress::factory()->for($cliente)->create([
        'street' => 'Endereço A do Cliente',
        'is_default_shipping' => true,
        'is_default_billing' => true,
    ]);

    $pedido = Order::factory()->create(['customer_id' => $cliente->id]);
    OrderAddress::factory()->for($pedido)->shipping()->create([
        'street' => 'Endereço B do Pedido (presente)',
    ]);

    $snapshot = app(BuildOrderInvoiceSnapshot::class)->handle($pedido->id);

    expect($snapshot?->shippingAddress?->street)->toBe('Endereço B do Pedido (presente)');
});

it('cai para o endereco padrao do cliente quando o pedido nao tem OrderAddress', function () {
    $cliente = Customer::factory()->create();
    CustomerAddress::factory()->for($cliente)->create([
        'street' => 'Endereço do Cliente',
        'is_default_shipping' => true,
        'is_default_billing' => true,
    ]);

    $pedido = Order::factory()->create(['customer_id' => $cliente->id]);

    $snapshot = app(BuildOrderInvoiceSnapshot::class)->handle($pedido->id);

    expect($snapshot?->shippingAddress?->street)->toBe('Endereço do Cliente');
});

it('usa o billing do pedido quando nao ha shipping', function () {
    $cliente = Customer::factory()->create();
    CustomerAddress::factory()->for($cliente)->create([
        'street' => 'Endereço do Cliente',
        'is_default_shipping' => true,
        'is_default_billing' => true,
    ]);

    $pedido = Order::factory()->create(['customer_id' => $cliente->id]);
    OrderAddress::factory()->for($pedido)->billing()->create([
        'street' => 'Endereço de Cobrança do Pedido',
    ]);

    $snapshot = app(BuildOrderInvoiceSnapshot::class)->handle($pedido->id);

    expect($snapshot?->shippingAddress?->street)->toBe('Endereço de Cobrança do Pedido');
});
