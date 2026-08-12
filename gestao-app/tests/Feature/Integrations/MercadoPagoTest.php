<?php

declare(strict_types=1);

use App\Modules\Finance\DTO\DadosDoPagador;
use App\Modules\Finance\DTO\EmitirCobrancaComando;
use App\Modules\Finance\Enums\StatusCobranca;
use App\Modules\Finance\Enums\TipoCobranca;
use App\Modules\Finance\Exceptions\CobrancaGatewayIndisponivel;
use App\Modules\Finance\Models\BillingCharge;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\ProcessarNotificacaoCobrancaService;
use App\Modules\Integrations\MercadoPago\Adapters\MercadoPagoGateway;
use App\Modules\Integrations\MercadoPago\Client;
use App\Modules\Integrations\MercadoPago\Jobs\ProcessarWebhookMercadoPago;
use App\Modules\Integrations\MercadoPago\Mappers\StatusMap;
use App\Modules\Integrations\MercadoPago\Services\VerificaAssinaturaMercadoPago;
use Database\Seeders\Finance\FinanceAccountSeeder;
use Database\Seeders\Finance\FinanceCategorySeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Integração Mercado Pago — ADR-0018 §3.1, docs/12-Financeiro/01-cobranca-e-boletos.md
|--------------------------------------------------------------------------
|
| O que promete: `StatusMap` traduz sem inventar significado para status
| desconhecido; a assinatura do webhook é conferida por HMAC, recusando
| sem segredo; o gateway monta o payload certo por tipo (boleto exige
| endereço), nunca deixa exceção de transporte vazar como está (sempre
| `CobrancaGatewayIndisponivel`); e o caminho completo webhook → job →
| Financeiro baixa o título e registra a tarifa, fechando o loop do BR-507.
|
*/

function ligarMercadoPago(): void
{
    config()->set('integrations.mercadopago', [
        'enabled' => true,
        'access_token' => 'TEST-token',
        'webhook_secret' => 'segredo-teste',
        'timeout' => 5,
    ]);
}

function pagadorMp(bool $comEndereco = false): DadosDoPagador
{
    return new DadosDoPagador(
        name: 'João Silva',
        email: 'joao@example.com',
        documentType: 'CPF',
        documentNumber: '12345678901',
        addressZip: $comEndereco ? '90000-000' : null,
        addressStreet: $comEndereco ? 'Rua Teste' : null,
        addressNumber: $comEndereco ? '10' : null,
        addressNeighborhood: $comEndereco ? 'Centro' : null,
        addressCity: $comEndereco ? 'Porto Alegre' : null,
        addressState: $comEndereco ? 'RS' : null,
    );
}

it('StatusMap traduz os status do Mercado Pago para o vocabulário interno', function () {
    expect(StatusMap::de('approved'))->toBe(StatusCobranca::Paga)
        ->and(StatusMap::de('pending'))->toBe(StatusCobranca::Registrada)
        ->and(StatusMap::de('in_process'))->toBe(StatusCobranca::Registrada)
        ->and(StatusMap::de('cancelled'))->toBe(StatusCobranca::Cancelada)
        ->and(StatusMap::de('rejected'))->toBe(StatusCobranca::Falhou)
        ->and(StatusMap::de('refunded'))->toBe(StatusCobranca::Cancelada);
});

it('StatusMap recusa status desconhecido em vez de mapear silenciosamente', function () {
    expect(fn () => StatusMap::de('algo_novo_da_api'))->toThrow(ValueError::class);
});

it('confere assinatura HMAC correta do webhook', function () {
    ligarMercadoPago();
    $manifest = 'id:123;request-id:req-1;ts:1000;';
    $v1 = hash_hmac('sha256', $manifest, 'segredo-teste');

    expect(app(VerificaAssinaturaMercadoPago::class)->confere("ts=1000,v1={$v1}", 'req-1', '123'))->toBeTrue();
});

it('recusa assinatura incorreta do webhook', function () {
    ligarMercadoPago();

    expect(app(VerificaAssinaturaMercadoPago::class)->confere('ts=1000,v1=errada', 'req-1', '123'))->toBeFalse();
});

it('recusa conferir assinatura sem segredo configurado', function () {
    config()->set('integrations.mercadopago.webhook_secret', null);

    expect(app(VerificaAssinaturaMercadoPago::class)->confere('ts=1000,v1=x', 'req-1', '123'))->toBeFalse();
});

it('emite PIX e traduz a resposta do Mercado Pago', function () {
    ligarMercadoPago();
    Http::fake(['*' => Http::response([
        'id' => 123456,
        'status' => 'pending',
        'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020126copiaecola']],
    ], 201)]);

    $resultado = app(MercadoPagoGateway::class)->emitir(new EmitirCobrancaComando(
        chargePublicId: 'charge-pub-1',
        type: TipoCobranca::Pix,
        amount: '150.50',
        dueDate: '2026-08-20',
        payer: pagadorMp(),
    ));

    expect($resultado->providerChargeId)->toBe('123456')
        ->and($resultado->status)->toBe(StatusCobranca::Registrada)
        ->and($resultado->pixPayload)->toBe('00020126copiaecola');

    Http::assertSent(fn ($r) => $r->hasHeader('X-Idempotency-Key', 'charge-pub-1')
        && $r['payment_method_id'] === 'pix'
        && $r['transaction_amount'] === 150.5
        && $r['payer']['first_name'] === 'João'
        && $r['payer']['last_name'] === 'Silva'
        && ! isset($r['payer']['address'])
    );
});

it('emite boleto incluindo o endereço completo do pagador no payload', function () {
    ligarMercadoPago();
    Http::fake(['*' => Http::response(['id' => 1, 'status' => 'pending'], 201)]);

    app(MercadoPagoGateway::class)->emitir(new EmitirCobrancaComando(
        chargePublicId: 'charge-pub-2',
        type: TipoCobranca::Boleto,
        amount: '100.00',
        dueDate: null,
        payer: pagadorMp(comEndereco: true),
    ));

    Http::assertSent(fn ($r) => $r['payment_method_id'] === 'bolbradesco'
        && $r['payer']['address']['zip_code'] === '90000-000'
        && $r['payer']['address']['state'] === 'RS'
    );
});

it('converte falha do provedor em CobrancaGatewayIndisponivel, nunca deixa o tipo interno vazar', function () {
    ligarMercadoPago();
    Http::fake(['*' => Http::response(['message' => 'invalid access token'], 401)]);

    expect(fn () => app(MercadoPagoGateway::class)->emitir(new EmitirCobrancaComando(
        chargePublicId: 'charge-pub-3',
        type: TipoCobranca::Pix,
        amount: '10.00',
        dueDate: null,
        payer: pagadorMp(),
    )))->toThrow(CobrancaGatewayIndisponivel::class);
});

it('cancela a cobrança no provedor', function () {
    ligarMercadoPago();
    Http::fake(['*' => Http::response(['id' => 999, 'status' => 'cancelled'])]);

    app(MercadoPagoGateway::class)->cancelar('999');

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), 'v1/payments/999')
        && $r['status'] === 'cancelled'
    );
});

it('webhook: recusa com assinatura inválida, sem despachar job', function () {
    ligarMercadoPago();
    Bus::fake();

    $this->postJson('/webhooks/mercado-pago', ['id' => 'evt-1', 'type' => 'payment', 'data' => ['id' => '999']], [
        'x-signature' => 'ts=1000,v1=errada',
        'x-request-id' => 'req-1',
    ])->assertStatus(401);

    Bus::assertNotDispatched(ProcessarWebhookMercadoPago::class);
});

it('webhook: assinatura válida sobre pagamento despacha o job e responde 202', function () {
    ligarMercadoPago();
    Bus::fake();
    $manifest = 'id:999;request-id:req-1;ts:1000;';
    $v1 = hash_hmac('sha256', $manifest, 'segredo-teste');

    $this->postJson('/webhooks/mercado-pago', ['id' => 'evt-1', 'type' => 'payment', 'data' => ['id' => '999']], [
        'x-signature' => "ts=1000,v1={$v1}",
        'x-request-id' => 'req-1',
    ])->assertStatus(202);

    Bus::assertDispatched(ProcessarWebhookMercadoPago::class, fn (ProcessarWebhookMercadoPago $job): bool => $job->paymentId === '999' && $job->providerEventId === 'evt-1'
    );
});

it('webhook: evento assinado que não é sobre pagamento é aceito e ignorado', function () {
    ligarMercadoPago();
    Bus::fake();
    $manifest = 'id:999;request-id:req-1;ts:1000;';
    $v1 = hash_hmac('sha256', $manifest, 'segredo-teste');

    $this->postJson('/webhooks/mercado-pago', ['id' => 'evt-1', 'type' => 'merchant_order', 'data' => ['id' => '999']], [
        'x-signature' => "ts=1000,v1={$v1}",
        'x-request-id' => 'req-1',
    ])->assertOk();

    Bus::assertNotDispatched(ProcessarWebhookMercadoPago::class);
});

it('o job do webhook busca o pagamento e baixa o título quando aprovado, com a tarifa como despesa', function () {
    ligarMercadoPago();
    $this->seed(FinanceCategorySeeder::class);
    $this->seed(FinanceAccountSeeder::class);

    $titulo = Receivable::factory()->create(['amount' => '99.90']);
    $cobranca = BillingCharge::factory()->registrada()->create([
        'receivable_id' => $titulo->id,
        'amount' => '99.90',
        'provider_charge_id' => '778899',
    ]);

    Http::fake(['*' => Http::response([
        'id' => 778899,
        'status' => 'approved',
        'transaction_amount' => 99.90,
        'date_approved' => now()->toIso8601String(),
        'fee_details' => [['type' => 'mercadopago_fee', 'amount' => 1.50]],
    ])]);

    (new ProcessarWebhookMercadoPago('778899', 'evt-approve'))
        ->handle(app(Client::class), app(ProcessarNotificacaoCobrancaService::class));

    expect($titulo->fresh()->status)->toBe(Receivable::STATUS_SETTLED)
        ->and($cobranca->fresh()->status)->toBe(StatusCobranca::Paga);

    $tarifa = Payable::query()->where('origin_type', 'billing_charge')->where('origin_id', $cobranca->id)->first();
    expect($tarifa)->not->toBeNull()
        ->and($tarifa->amount)->toBe('1.50')
        ->and($tarifa->status)->toBe(Payable::STATUS_SETTLED);
});
