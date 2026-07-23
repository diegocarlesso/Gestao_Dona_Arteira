<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use OwenIt\Auditing\Models\Audit;

/*
|--------------------------------------------------------------------------
| Auditoria do usuário — ADR-0012, pasta 26
|--------------------------------------------------------------------------
|
| "Quem mudou isso, quando e de que valor para qual?" precisa ter resposta
| desde a primeira entidade do sistema. Se a trilha só for ligada quando
| houver estoque e dinheiro, o período em que os acessos foram definidos
| — o mais sensível de todos — fica sem registro.
|
*/

it('registra a criação do usuário', function () {
    $usuario = User::factory()->create(['name' => 'Ana Ateliê']);

    $auditoria = Audit::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $usuario->id)
        ->where('event', 'created')
        ->sole();

    expect($auditoria->new_values)->toHaveKey('name', 'Ana Ateliê');
});

it('registra o antes e o depois de uma mudança de status', function () {
    $usuario = User::factory()->create(['status' => UserStatus::Active]);

    $usuario->update(['status' => UserStatus::Suspended]);

    $auditoria = Audit::query()
        ->where('auditable_id', $usuario->id)
        ->where('event', 'updated')
        ->sole();

    expect($auditoria->old_values)->toHaveKey('status', UserStatus::Active->value);
    expect($auditoria->new_values)->toHaveKey('status', UserStatus::Suspended->value);
});

it('nunca grava senha nem segredo de 2FA na trilha', function () {
    $usuario = User::factory()->create();

    $usuario->update(['password' => 'uma-senha-nova-bem-longa']);

    // 2FA fica fora de $fillable de propósito — segredo não entra por
    // mass assignment de request. Aqui se atribui direto, como faria o
    // serviço que ativa o 2FA.
    $usuario->two_factor_secret = 'SEGREDO-TOTP';
    $usuario->two_factor_recovery_codes = ['codigo-1', 'codigo-2'];
    $usuario->save();

    $registradas = Audit::query()
        ->where('auditable_id', $usuario->id)
        ->get()
        ->flatMap(static fn (Audit $a): array => array_merge(
            array_keys((array) $a->old_values),
            array_keys((array) $a->new_values),
        ))
        ->unique()
        ->all();

    expect($registradas)->not->toContain('password');
    expect($registradas)->not->toContain('remember_token');
    expect($registradas)->not->toContain('two_factor_secret');
    expect($registradas)->not->toContain('two_factor_recovery_codes');
});

it('atribui a mudança a quem a fez', function () {
    $admin = User::factory()->create();
    $alvo = User::factory()->create();

    $this->actingAs($admin);
    $alvo->update(['name' => 'Nome corrigido']);

    $auditoria = Audit::query()
        ->where('auditable_id', $alvo->id)
        ->where('event', 'updated')
        ->sole();

    expect($auditoria->user_id)->toBe($admin->id);
});

it('guarda os diffs em coluna que comporta mais que os 64 KB do TEXT', function () {
    // A migration troca TEXT por JSON justamente por isto: um pedido
    // com muitos itens gera um diff maior que 64 KB, e com TEXT o
    // MariaDB truncaria — registrando uma versão mutilada do que
    // aconteceu. Como nenhuma coluna de `users` chega perto desse
    // tamanho hoje, o que se verifica é a capacidade da coluna.
    $tipo = DB::selectOne(
        'SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['audits', 'old_values'],
    );

    // No MariaDB, `json()` do Laravel materializa como LONGTEXT (4 GB)
    // com validação de JSON — o que importa é não ser `text` (64 KB).
    expect(strtolower((string) $tipo->DATA_TYPE))->toBeIn(['json', 'longtext']);
});
