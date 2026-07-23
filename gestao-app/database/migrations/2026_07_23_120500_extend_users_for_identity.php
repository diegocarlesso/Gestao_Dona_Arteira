<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Estende `users` para o ciclo de vida de conta de docs/18-Usuarios §3.
 *
 * A tabela do starter kit só sabia autenticar. O ERP precisa saber
 * também quem está suspenso, quem ainda não ativou a conta, quem tem 2FA
 * e quando a senha foi trocada pela última vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ID público ULID: o `id` sequencial não sai daqui. Um
            // identificador incremental em URL entrega a contagem de
            // usuários e permite varredura (convenção da pasta 04).
            $table->char('public_id', 26)->nullable()->after('id');

            // VARCHAR + enum PHP, nunca ENUM do MySQL (pasta 04): mudar
            // um ENUM de coluna é ALTER TABLE com lock; mudar o enum PHP
            // é deploy. O valor default cobre as linhas já existentes.
            $table->string('status', 20)
                ->default(UserStatus::Active->value)
                ->after('email_verified_at');

            // Segredo TOTP e códigos de recuperação (BR-804). Guardados
            // cifrados pelo cast do model — nunca em claro, nem em log.
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            // A primeira conta é criada por seeder com troca forçada
            // (pasta 18 §5). NULL = nunca trocou desde a criação.
            $table->timestamp('password_changed_at')->nullable()->after('two_factor_confirmed_at');
            $table->boolean('must_change_password')->default(false)->after('password_changed_at');

            $table->timestamp('last_login_at')->nullable()->after('must_change_password');

            $table->index('status', 'idx_users_status');
        });

        // Backfill do public_id para quem já existia, antes de exigir
        // unicidade. Idempotente: só toca linhas ainda sem valor.
        DB::table('users')->whereNull('public_id')->orderBy('id')->each(
            static fn (object $usuario) => DB::table('users')
                ->where('id', $usuario->id)
                ->update(['public_id' => (string) Str::ulid()])
        );

        Schema::table('users', function (Blueprint $table) {
            $table->unique('public_id', 'uq_users_public_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('uq_users_public_id');
            $table->dropIndex('idx_users_status');

            $table->dropColumn([
                'public_id',
                'status',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'password_changed_at',
                'must_change_password',
                'last_login_at',
            ]);
        });
    }
};
