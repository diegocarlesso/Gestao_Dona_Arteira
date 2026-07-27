<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Repositories\DuplicateProductNames;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\Identity\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Relatório "Produtos com nome repetido" — ficha na pasta 20 §3.2
|--------------------------------------------------------------------------
|
| Pré-requisito da contagem física: uma peça cadastrada sob dois códigos
| tem o saldo inicial dividido ao acaso entre eles, dentro de um ledger
| imutável (ADR-0008). O relatório existe para ser zerado.
|
*/

function usuarioCom(Role $papel): User
{
    return User::factory()->comDoisFatores()->create()->assignRoleEnum($papel);
}

function relatorio(): DuplicateProductNames
{
    return app(DuplicateProductNames::class);
}

// ------------------------------------------------------ a definição

it('agrupa os produtos que dividem o mesmo nome', function () {
    Product::factory()->create(['name' => 'Trio da sabedoria 14 cm']);
    Product::factory()->create(['name' => 'Trio da sabedoria 14 cm']);
    Product::factory()->create(['name' => 'Buda sidarta 11 cm']);

    $grupos = relatorio()->grupos();

    expect($grupos)->toHaveCount(1)
        ->and($grupos->first()['nome'])->toBe('Trio da sabedoria 14 cm')
        ->and($grupos->first()['produtos'])->toHaveCount(2);
});

it('não acusa grupo onde o nome aparece uma vez só', function () {
    // Um a um, e não `->count(3)`: o factory tira o SKU do gerador de
    // verdade (BR-002), que lê o maior código já gravado — em lote, os
    // três são calculados antes de qualquer insert e saem `DA-0001`.
    Product::factory()->create();
    Product::factory()->create();
    Product::factory()->create();

    expect(relatorio()->grupos())->toHaveCount(0);
});

it('tira o grupo da lista quando o repetido é arquivado', function () {
    // O comportamento que faz a lista encolher conforme alguém decide —
    // e é o que permite "zero" significar terminado.
    Product::factory()->create(['name' => 'Elefante manto 22 cm']);
    $repetido = Product::factory()->create(['name' => 'Elefante manto 22 cm']);

    expect(relatorio()->grupos())->toHaveCount(1);

    $repetido->forceFill(['status' => ProductStatus::Archived])->save();

    expect(relatorio()->grupos())->toHaveCount(0);
});

it('junta nomes que só diferem na caixa', function () {
    // Quem cadastra duas vezes por distração raramente repete a
    // capitalização. Este teste falhou de verdade na primeira escrita: o
    // `GROUP BY` do banco juntava os dois (colação `utf8mb4_unicode_ci`) e
    // o `groupBy` do Collection tornava a separá-los em PHP. Guarda as
    // duas metades concordando, não só a colação.
    Product::factory()->create(['name' => 'Buda sidarta 11 cm']);
    Product::factory()->create(['name' => 'BUDA SIDARTA 11 CM']);

    expect(relatorio()->grupos())->toHaveCount(1);
});

// ------------------------------------------------------------- a tela

it('mostra os grupos e o tamanho do trabalho restante', function () {
    Product::factory()->create(['name' => 'Trio da sabedoria 14 cm']);
    Product::factory()->create(['name' => 'Trio da sabedoria 14 cm']);

    actingAs(usuarioCom(Role::Admin))
        ->get('/relatorios/produtos-repetidos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/reports/duplicate-names')
            ->where('totais.grupos', 1)
            ->where('totais.produtos', 2)
            ->where('podeArquivar', true)
        );
});

it('esconde o botão de arquivar de quem só pode ver relatório', function () {
    Product::factory()->create(['name' => 'Trio da sabedoria 14 cm']);
    Product::factory()->create(['name' => 'Trio da sabedoria 14 cm']);

    // Financeiro tem `reports.view` e não tem `catalog.manage`. Sem esta
    // separação a tela ofereceria um botão que devolve 403.
    actingAs(usuarioCom(Role::Finance))
        ->get('/relatorios/produtos-repetidos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('podeArquivar', false));
});

it('barra quem não tem permissão de relatório', function () {
    // Produção enxerga o catálogo (`catalog.view`) e mesmo assim não
    // entra: relatório é família própria de permissão (pasta 19).
    actingAs(usuarioCom(Role::Production))
        ->get('/relatorios/produtos-repetidos')
        ->assertForbidden();
});

// --------------------------------------------------------- exportação

it('exporta CSV que o Excel brasileiro abre sem estragar acento nem número', function () {
    Product::factory()->create(['name' => 'Incensário cascata 12 cm']);
    Product::factory()->create(['name' => 'Incensário cascata 12 cm']);

    $resposta = actingAs(usuarioCom(Role::Admin))->get('/relatorios/produtos-repetidos/csv');

    $resposta->assertOk();
    $csv = $resposta->streamedContent();

    // O `fputcsv` cita o que tem espaço, daí as aspas no primeiro campo.
    expect($csv)->toStartWith("\xEF\xBB\xBF")             // sem BOM o Excel come o "á"
        ->and($csv)->toContain('"Nome repetido";Código')   // vírgula jogaria tudo numa coluna
        ->and($csv)->toContain('Incensário cascata 12 cm');
});

it('não deixa quem não pode ver o relatório baixar o CSV', function () {
    actingAs(usuarioCom(Role::Production))
        ->get('/relatorios/produtos-repetidos/csv')
        ->assertForbidden();
});
