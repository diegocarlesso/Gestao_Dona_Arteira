<?php

declare(strict_types=1);

use App\Modules\Finance\DTO\DadosDoPagador;
use App\Modules\Finance\Enums\StatusCobranca;
use App\Modules\Finance\Enums\TipoCobranca;
use App\Modules\Finance\Exceptions\CobrancaInvalida;
use App\Modules\Finance\Jobs\ProcessarCancelamentoCobranca;
use App\Modules\Finance\Jobs\ProcessarEmissaoCobranca;
use App\Modules\Finance\Models\BillingCharge;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\CancelarCobrancaService;
use App\Modules\Finance\Services\EmitirCobrancaService;
use App\Modules\Finance\Services\ProcessarNotificacaoCobrancaService;
use App\Modules\Finance\Services\RegisterSettlementService;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\Finance\FinanceAccountSeeder;
use Database\Seeders\Finance\FinanceCategorySeeder;
use Database\Seeders\Identity\RolePermissionSeeder;
use Illuminate\Support\Facades\Bus;

/*
|--------------------------------------------------------------------------
| Cobrança (PIX com vencimento e boleto) — ADR-0018, BR-505...510
|--------------------------------------------------------------------------
|
| O que promete: emitir sempre a partir de um título aberto (BR-505), nunca
| duas cobranças ativas ao mesmo tempo, boleto exige endereço do pagador,
| a emissão/cancelamento nunca travam a requisição (viram job), pagamento
| baixa o título e registra a tarifa como despesa já baixada, cancelada/
| vencida nunca baixam (BR-510), e o mesmo evento do provedor reentregue
| nunca duplica a baixa (BR-507).
|
*/

function pagador(bool $comEndereco = false): DadosDoPagador
{
    return new DadosDoPagador(
        name: 'Cliente Teste',
        email: 'cliente@example.com',
        documentType: 'CPF',
        documentNumber: '12345678901',
        addressZip: $comEndereco ? '90000-000' : null,
        addressStreet: $comEndereco ? 'Rua Teste' : null,
        addressNumber: $comEndereco ? '123' : null,
        addressNeighborhood: $comEndereco ? 'Centro' : null,
        addressCity: $comEndereco ? 'Porto Alegre' : null,
        addressState: $comEndereco ? 'RS' : null,
    );
}

it('solicitar cria a cobrança em pending e enfileira o job de emissão, sem chamar o provedor na hora', function () {
    Bus::fake();

    $titulo = Receivable::factory()->create(['amount' => '250.00']);

    $cobranca = app(EmitirCobrancaService::class)->solicitar(
        receivableId: $titulo->id,
        type: TipoCobranca::Pix,
        payer: pagador(),
    );

    expect($cobranca->status)->toBe(StatusCobranca::Pendente)
        ->and($cobranca->amount)->toBe('250.00')
        ->and($cobranca->provider_charge_id)->toBeNull();

    Bus::assertDispatched(ProcessarEmissaoCobranca::class, fn (ProcessarEmissaoCobranca $job): bool => $job->chargePublicId === $cobranca->public_id
    );
});

it('recusa boleto sem endereço completo do pagador', function () {
    $titulo = Receivable::factory()->create();

    expect(fn () => app(EmitirCobrancaService::class)->solicitar(
        receivableId: $titulo->id,
        type: TipoCobranca::Boleto,
        payer: pagador(comEndereco: false),
    ))->toThrow(CobrancaInvalida::class);
});

it('aceita boleto quando o endereço do pagador está completo', function () {
    Bus::fake();
    $titulo = Receivable::factory()->create();

    $cobranca = app(EmitirCobrancaService::class)->solicitar(
        receivableId: $titulo->id,
        type: TipoCobranca::Boleto,
        payer: pagador(comEndereco: true),
    );

    expect($cobranca->type)->toBe(TipoCobranca::Boleto);
});

it('recusa emitir uma segunda cobrança ativa para o mesmo título (BR-506)', function () {
    Bus::fake();
    $titulo = Receivable::factory()->create();
    BillingCharge::factory()->create(['receivable_id' => $titulo->id, 'status' => StatusCobranca::Pendente]);

    expect(fn () => app(EmitirCobrancaService::class)->solicitar(
        receivableId: $titulo->id,
        type: TipoCobranca::Pix,
        payer: pagador(),
    ))->toThrow(CobrancaInvalida::class);
});

it('recusa emitir cobrança para título já quitado', function () {
    $titulo = Receivable::factory()->create(['amount' => '100.00']);
    $conta = FinanceAccount::factory()->create();
    app(RegisterSettlementService::class)->handle('receivable', $titulo->id, $conta->id, '100.00', now()->toDateString());

    expect(fn () => app(EmitirCobrancaService::class)->solicitar(
        receivableId: $titulo->fresh()->id,
        type: TipoCobranca::Pix,
        payer: pagador(),
    ))->toThrow(CobrancaInvalida::class);
});

it('processar() com o gateway não configurado marca a cobrança como failed, com motivo', function () {
    $cobranca = BillingCharge::factory()->create(['status' => StatusCobranca::Pendente]);

    app(EmitirCobrancaService::class)->processar($cobranca->public_id);

    $fresh = $cobranca->fresh();
    expect($fresh->status)->toBe(StatusCobranca::Falhou)
        ->and($fresh->failure_reason)->not->toBeNull();
});

it('processar() é idempotente: cobrança que já saiu de pending não é reprocessada', function () {
    $cobranca = BillingCharge::factory()->registrada()->create();
    $providerChargeIdOriginal = $cobranca->provider_charge_id;

    app(EmitirCobrancaService::class)->processar($cobranca->public_id);

    expect($cobranca->fresh()->status)->toBe(StatusCobranca::Registrada)
        ->and($cobranca->fresh()->provider_charge_id)->toBe($providerChargeIdOriginal);
});

it('cancelar: cobrança que nunca chegou a registrar no provedor cancela local, sem despachar job', function () {
    Bus::fake();
    $cobranca = BillingCharge::factory()->create(['status' => StatusCobranca::Pendente, 'provider_charge_id' => null]);

    app(CancelarCobrancaService::class)->solicitar($cobranca->public_id);

    expect($cobranca->fresh()->status)->toBe(StatusCobranca::Cancelada);
    Bus::assertNotDispatched(ProcessarCancelamentoCobranca::class);
});

it('cancelar: cobrança registrada no provedor despacha o job de cancelamento', function () {
    Bus::fake();
    $cobranca = BillingCharge::factory()->registrada()->create();

    app(CancelarCobrancaService::class)->solicitar($cobranca->public_id);

    Bus::assertDispatched(ProcessarCancelamentoCobranca::class, fn (ProcessarCancelamentoCobranca $job): bool => $job->chargePublicId === $cobranca->public_id
    );
});

it('recusa cancelar uma cobrança já paga (estado terminal)', function () {
    $cobranca = BillingCharge::factory()->create(['status' => StatusCobranca::Paga]);

    expect(fn () => app(CancelarCobrancaService::class)->solicitar($cobranca->public_id))
        ->toThrow(CobrancaInvalida::class);
});

it('notificação de pagamento total baixa o título e registra a tarifa do provedor como despesa já baixada', function () {
    $this->seed(FinanceCategorySeeder::class);
    $this->seed(FinanceAccountSeeder::class);

    $titulo = Receivable::factory()->create(['amount' => '250.00']);
    $cobranca = BillingCharge::factory()->registrada()->create([
        'receivable_id' => $titulo->id,
        'amount' => '250.00',
    ]);

    app(ProcessarNotificacaoCobrancaService::class)->handle(
        providerChargeId: (string) $cobranca->provider_charge_id,
        providerEventId: 'evt-1',
        eventType: 'payment.updated',
        status: StatusCobranca::Paga,
        paidAmount: '250.00',
        feeAmount: '2.48',
        paidAt: now(),
        rawPayload: ['status' => 'approved'],
    );

    expect($titulo->fresh()->status)->toBe(Receivable::STATUS_SETTLED)
        ->and($cobranca->fresh()->status)->toBe(StatusCobranca::Paga);

    $tarifa = Payable::query()->where('origin_type', 'billing_charge')->where('origin_id', $cobranca->id)->first();
    expect($tarifa)->not->toBeNull()
        ->and($tarifa->amount)->toBe('2.48')
        ->and($tarifa->status)->toBe(Payable::STATUS_SETTLED);
});

it('o mesmo evento do provedor reentregue não duplica a baixa (BR-507)', function () {
    $this->seed(FinanceCategorySeeder::class);
    $this->seed(FinanceAccountSeeder::class);

    $titulo = Receivable::factory()->create(['amount' => '250.00']);
    $cobranca = BillingCharge::factory()->registrada()->create([
        'receivable_id' => $titulo->id,
        'amount' => '250.00',
    ]);

    $processar = fn () => app(ProcessarNotificacaoCobrancaService::class)->handle(
        providerChargeId: (string) $cobranca->provider_charge_id,
        providerEventId: 'evt-repetido',
        eventType: 'payment.updated',
        status: StatusCobranca::Paga,
        paidAmount: '250.00',
        feeAmount: null,
        paidAt: now(),
        rawPayload: [],
    );

    $processar();
    $processar();

    expect(FinanceSettlement::query()->where('settleable_type', 'receivable')->where('settleable_id', $titulo->id)->count())->toBe(1);
});

it('cobrança cancelada pelo provedor não baixa o título — ele continua aberto (BR-510)', function () {
    $titulo = Receivable::factory()->create(['amount' => '250.00']);
    $cobranca = BillingCharge::factory()->registrada()->create(['receivable_id' => $titulo->id]);

    app(ProcessarNotificacaoCobrancaService::class)->handle(
        providerChargeId: (string) $cobranca->provider_charge_id,
        providerEventId: 'evt-cancel',
        eventType: 'payment.updated',
        status: StatusCobranca::Cancelada,
        paidAmount: null,
        feeAmount: null,
        paidAt: null,
        rawPayload: [],
    );

    expect($cobranca->fresh()->status)->toBe(StatusCobranca::Cancelada)
        ->and($titulo->fresh()->status)->toBe(Receivable::STATUS_OPEN);
});

it('HTTP: emite cobrança pelo formulário do título (finance.settle)', function () {
    Bus::fake();
    $this->seed(RolePermissionSeeder::class);
    $titulo = Receivable::factory()->create(['amount' => '150.00']);
    $usuario = User::factory()->comDoisFatores()->create()
        ->assignRoleEnum(Role::Finance);

    \Pest\Laravel\actingAs($usuario)
        ->post("/financeiro/titulos/receivable/{$titulo->public_id}/cobranca", [
            'type' => 'pix',
            'payer_name' => 'Cliente Teste',
            'payer_email' => 'cliente@example.com',
            'payer_document_type' => 'CPF',
            'payer_document_number' => '12345678901',
        ])
        ->assertRedirect();

    $cobranca = BillingCharge::query()->where('receivable_id', $titulo->id)->firstOrFail();
    expect($cobranca->status)->toBe(StatusCobranca::Pendente);
    Bus::assertDispatched(ProcessarEmissaoCobranca::class);
});

it('HTTP: recusa emitir cobrança de quem só tem finance.view', function () {
    $this->seed(RolePermissionSeeder::class);
    $titulo = Receivable::factory()->create();
    $usuario = User::factory()->comDoisFatores()->create()
        ->assignRoleEnum(Role::Accountant);

    \Pest\Laravel\actingAs($usuario)
        ->post("/financeiro/titulos/receivable/{$titulo->public_id}/cobranca", [
            'type' => 'pix',
            'payer_name' => 'Cliente Teste',
            'payer_email' => 'cliente@example.com',
            'payer_document_type' => 'CPF',
            'payer_document_number' => '12345678901',
        ])
        ->assertForbidden();
});

it('HTTP: cancela cobrança pela rota dedicada', function () {
    Bus::fake();
    $this->seed(RolePermissionSeeder::class);
    $cobranca = BillingCharge::factory()->registrada()->create();
    $usuario = User::factory()->comDoisFatores()->create()
        ->assignRoleEnum(Role::Finance);

    \Pest\Laravel\actingAs($usuario)
        ->post("/financeiro/cobrancas/{$cobranca->public_id}/cancelar")
        ->assertRedirect();

    Bus::assertDispatched(ProcessarCancelamentoCobranca::class);
});
