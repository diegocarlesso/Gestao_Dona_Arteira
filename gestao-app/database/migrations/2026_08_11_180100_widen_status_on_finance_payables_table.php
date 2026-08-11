<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alarga `finance_payables.status` de 16 para 20 caracteres — BR-502.
 *
 * A fatia mínima só previa `open` (4 chars). A baixa (`RegisterSettlement
 * Service`) usa o mesmo vocabulário de três estados dos dois lados
 * (pagável e recebível): `open`/`partially_settled`/`settled` —
 * `partially_settled` tem 18 caracteres, não cabia na coluna original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_payables', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->change();
        });
    }

    public function down(): void
    {
        Schema::table('finance_payables', function (Blueprint $table) {
            $table->string('status', 16)->default('open')->change();
        });
    }
};
