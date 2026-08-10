<?php

declare(strict_types=1);

use App\Modules\Finance\Enums\FinanceCategoryType;
use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Services\RegisterPayableService;
use Database\Seeders\Finance\FinanceCategorySeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Títulos a pagar — BR-501/BR-503, docs/12-Financeiro (fatia mínima do P0.1)
|--------------------------------------------------------------------------
|
| O que a fatia promete: o Compras chama um Service e sai uma dívida com
| categoria, valor exato e origem rastreável. E promete uma coisa que não
| se vê na tela mas segura o resto: o título participa da transação de quem
| chamou — pedido de compra que falhou no meio não deixa dívida órfã.
|
| A baixa (BR-502), o fluxo de caixa e a cobrança são Gate 04 e não estão
| aqui de propósito.
|
*/

function registrarTitulo(
    string $originType = 'purchase_order',
    int $originId = 42,
    string $supplierName = 'Gesso Bom Ltda',
    string $amount = '1250.00',
    ?string $dueDate = null,
    ?int $categoryId = null,
): Payable {
    return app(RegisterPayableService::class)->registrar(
        originType: $originType,
        originId: $originId,
        supplierName: $supplierName,
        amount: $amount,
        dueDate: $dueDate,
        categoryId: $categoryId,
    );
}

// ------------------------------------------------- categoria (BR-503)

it('usa a categoria padrão de compras quando nenhuma é informada (BR-503)', function () {
    $this->seed(FinanceCategorySeeder::class);

    $titulo = registrarTitulo();

    expect($titulo->category->name)->toBe('Compras/Fornecedores')
        ->and($titulo->category->type)->toBe(FinanceCategoryType::Payable);
});

it('semeia a categoria padrão sem duplicar quando o deploy roda de novo', function () {
    // Regra 5 da pasta 04: o seeder roda em todo deploy. Duplicar aqui
    // partiria os títulos em duas linhas iguais do relatório — e o índice
    // único (tipo, nome) transformaria o deploy seguinte em erro.
    $this->seed(FinanceCategorySeeder::class);
    $this->seed(FinanceCategorySeeder::class);

    expect(FinanceCategory::query()->count())->toBe(1);
});

it('recusa registrar sem categoria padrão semeada, em vez de criá-la por conta própria', function () {
    // Sem o seeder de propósito: inventar linha do plano gerencial em
    // tempo de execução é como ele vira uma lista de nomes parecidos.
    expect(fn () => registrarTitulo())
        ->toThrow(TituloInvalido::class, 'Compras/Fornecedores');

    expect(Payable::query()->count())->toBe(0);
});

it('aceita uma categoria informada explicitamente', function () {
    $categoria = FinanceCategory::factory()->create(['name' => 'Embalagens']);

    $titulo = registrarTitulo(categoryId: $categoria->id);

    expect($titulo->category_id)->toBe($categoria->id);
});

it('recusa classificar um a pagar numa categoria de receita', function () {
    $categoria = FinanceCategory::factory()->aReceber()->create(['name' => 'Vendas Site']);

    expect(fn () => registrarTitulo(categoryId: $categoria->id))
        ->toThrow(TituloInvalido::class);

    expect(Payable::query()->count())->toBe(0);
});

it('recusa uma categoria inexistente', function () {
    expect(fn () => registrarTitulo(categoryId: 999_999))
        ->toThrow(TituloInvalido::class, '999999');
});

// ------------------------------------------------- valor (ADR-0013)

it('grava o valor exatamente como informado, sem arredondar', function () {
    $this->seed(FinanceCategorySeeder::class);

    $titulo = registrarTitulo(amount: '1234.56');

    expect($titulo->amount)->toBe('1234.56')
        ->and($titulo->fresh()->amount)->toBe('1234.56')
        ->and((string) $titulo->money()->getAmount())->toBe('1234.56');
});

it('preserva os centavos de um valor grande, que o float arredondaria (ADR-0013)', function () {
    $this->seed(FinanceCategorySeeder::class);

    $titulo = registrarTitulo(amount: '19999999.99');

    expect($titulo->fresh()->amount)->toBe('19999999.99');
});

it('normaliza o valor para duas casas', function () {
    $this->seed(FinanceCategorySeeder::class);

    expect(registrarTitulo(amount: '89.9')->amount)->toBe('89.90')
        ->and(registrarTitulo(amount: '15')->amount)->toBe('15.00');
});

it('recusa título com valor zero ou negativo', function (string $valor) {
    $this->seed(FinanceCategorySeeder::class);

    expect(fn () => registrarTitulo(amount: $valor))
        ->toThrow(TituloInvalido::class);

    expect(Payable::query()->count())->toBe(0);
})->with(['0.00', '-10.00']);

// ------------------------------------------------- origem (ADR-0020)

it('grava a origem como par tipo/id, sem FK cross-módulo', function () {
    $this->seed(FinanceCategorySeeder::class);

    $titulo = registrarTitulo(originType: 'purchase_order', originId: 77);

    expect($titulo->origin_type)->toBe('purchase_order')
        ->and($titulo->origin_id)->toBe(77);

    // A consulta que o Compras faz para mostrar o título no PC — e que
    // sustenta a idempotência da confirmação (não duplicar título).
    expect(Payable::query()->daOrigem('purchase_order', 77)->count())->toBe(1)
        ->and(Payable::query()->daOrigem('purchase_order', 78)->count())->toBe(0);
});

it('guarda o nome do fornecedor como snapshot', function () {
    $this->seed(FinanceCategorySeeder::class);

    $titulo = registrarTitulo(supplierName: 'Gesso Bom Ltda');

    expect($titulo->supplier_name)->toBe('Gesso Bom Ltda');
});

// ------------------------------------------------- estado inicial

it('nasce aberto, com public_id e com vencimento opcional', function () {
    $this->seed(FinanceCategorySeeder::class);

    $semVencimento = registrarTitulo();
    $comVencimento = registrarTitulo(originId: 43, dueDate: '2026-09-15');

    expect($semVencimento->status)->toBe(Payable::STATUS_OPEN)
        ->and($semVencimento->aberto())->toBeTrue()
        ->and($semVencimento->public_id)->toHaveLength(26)
        ->and($semVencimento->due_date)->toBeNull()
        ->and($comVencimento->due_date->toDateString())->toBe('2026-09-15');
});

// ------------------------------------------------- transação de quem chama

it('participa da transação de quem chama: rollback não deixa dívida órfã', function () {
    $this->seed(FinanceCategorySeeder::class);

    // O Compras confirma o PC dentro de uma transação e chama este Service
    // por dentro dela. Se a confirmação falhar depois, o título tem de
    // voltar junto — senão o dono passa a dever por uma compra que não
    // existe. Por isso o Service não abre transação própria.
    try {
        DB::transaction(function (): void {
            registrarTitulo();

            throw new RuntimeException('o pedido de compra falhou depois do título');
        });
    } catch (RuntimeException) {
        // esperado
    }

    expect(Payable::query()->count())->toBe(0);
});
