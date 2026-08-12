<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil de cobrança (multa, juros, desconto) — BR-508, docs/12-Financeiro/
 * 01-cobranca-e-boletos.md §4.
 *
 * Percentuais nascem `nullable`, sem valor padrão chutado: a doc marca
 * BR-508 como "confirmar percentuais com o contador" — mesmo princípio já
 * aplicado ao grupo IBS/CBS (ADR-0027, campos sem alíquota fixada). Uma
 * cobrança pode ser emitida sem perfil (`billing_charges.profile_id`
 * nullable) enquanto isso não for confirmado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->string('name');

            $table->decimal('fine_pct', 7, 4)->nullable();
            $table->decimal('interest_pct_month', 7, 4)->nullable();
            $table->decimal('discount_pct', 7, 4)->nullable();
            $table->unsignedSmallInteger('discount_days')->nullable();

            $table->unsignedSmallInteger('days_to_due')->nullable();
            $table->text('instructions')->nullable();

            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_profiles');
    }
};
