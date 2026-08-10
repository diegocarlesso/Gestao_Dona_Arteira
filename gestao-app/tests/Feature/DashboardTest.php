<?php

use App\Modules\Identity\Models\User;
use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Models\Order;
use App\Queries\GetDashboardMetricsQuery;
use Illuminate\Support\Facades\Cache;

it('redireciona visitantes para o login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('permite acesso de usuarios autenticados ao dashboard', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/dashboard')->assertOk();
});

it('calcula vendas corretamente seguindo o glossario', function () {
    Cache::flush();

    // Venda válida hoje
    Order::factory()->create([
        'status' => OrderStatus::Confirmed,
        'total' => 100.50,
        'channel' => OrderChannel::Erp,
        'created_at' => now(),
    ]);

    // Venda válida ontem (entra no mes, nao no dia)
    Order::factory()->create([
        'status' => OrderStatus::Expedido,
        'total' => 200.00,
        'channel' => OrderChannel::WooCommerce,
        'created_at' => now()->subDay(),
    ]);

    // Rascunho (nao deve contar)
    Order::factory()->create([
        'status' => OrderStatus::Draft,
        'total' => 50.00,
        'created_at' => now(),
    ]);

    // Cancelado (nao deve contar)
    Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'total' => 500.00,
        'created_at' => now(),
    ]);

    $query = new GetDashboardMetricsQuery;
    $metrics = $query->execute();

    // Vendas do dia
    expect($metrics['vendas']['dia']['total'])->toEqual(100.50)
        ->and($metrics['vendas']['dia']['pedidos'])->toBe(1)
        ->and($metrics['vendas']['dia']['ticket_medio'])->toEqual(100.50);

    // Vendas do mes
    expect($metrics['vendas']['mes']['total'])->toEqual(300.50)
        ->and($metrics['vendas']['mes']['pedidos'])->toBe(2)
        ->and($metrics['vendas']['mes']['ticket_medio'])->toEqual(150.25);
});

it('calcula o funil de pedidos e fulfillment corretamente', function () {
    Cache::flush();

    Order::factory()->count(2)->create(['status' => OrderStatus::Confirmed]);
    Order::factory()->count(3)->create(['status' => OrderStatus::Separando]);
    Order::factory()->count(1)->create(['status' => OrderStatus::Embalado]);
    Order::factory()->count(4)->create(['status' => OrderStatus::Cancelled]);

    $query = new GetDashboardMetricsQuery;
    $metrics = $query->execute();

    expect($metrics['funil'][OrderStatus::Confirmed->value])->toBe(2)
        ->and($metrics['funil'][OrderStatus::Separando->value])->toBe(3)
        ->and($metrics['funil'][OrderStatus::Embalado->value])->toBe(1)
        ->and($metrics['funil'][OrderStatus::Cancelled->value])->toBe(4)
        ->and($metrics['fulfillment']['a_separar'])->toBe(2) // Confirmados
        ->and($metrics['fulfillment']['a_embalar'])->toBe(3) // Separando
        ->and($metrics['fulfillment']['a_expedir'])->toBe(1); // Embalado
});
