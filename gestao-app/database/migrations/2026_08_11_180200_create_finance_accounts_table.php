<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contas financeiras — caixa, banco, gateway — docs/12-Financeiro §3.
 *
 * **Sem coluna de saldo.** O saldo de uma conta é sempre a soma das suas
 * baixas (`finance_settlements`) — mesmo princípio do saldo de estoque
 * (ADR-0008): derivar de um ledger imutável, nunca guardar um número que
 * pode dessincronizar do que o gerou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->string('name');

            // cash | bank | gateway
            $table->string('type', 16);

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_accounts');
    }
};
