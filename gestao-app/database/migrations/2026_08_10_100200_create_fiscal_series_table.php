<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Séries de numeração da NF-e — BR-602, docs/14 §3.
 *
 * Uma linha por série **e por ambiente**. A separação por ambiente não é
 * organização: homologação e produção têm numerações independentes na
 * SEFAZ, e compartilhar o contador faria os testes furarem a sequência
 * fiscal de verdade — furo que depois só se resolve inutilizando número na
 * SEFAZ.
 *
 * `next_number` é o próximo a usar, e quem o lê **trava a linha**
 * (`SELECT ... FOR UPDATE`, ver `FiscalSeries::proximoNumero()`). Sem o
 * lock, duas emissões simultâneas leriam o mesmo número e a segunda nota
 * seria rejeitada por duplicidade — ou pior, autorizada com número repetido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_series', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->string('series', 8);

            // O próximo número a emitir. Começa em 1 numa série nova; numa
            // série herdada de outro emissor, começa onde o outro parou.
            $table->unsignedInteger('next_number')->default(1);

            // homologacao / producao
            $table->string('environment', 16)->default('homologacao');

            $table->timestamps();

            // A mesma série existe nos dois ambientes com contadores
            // próprios; duas linhas para o mesmo par seriam duas verdades
            // sobre qual é o próximo número.
            $table->unique(['series', 'environment'], 'uk_fiscal_series_ambiente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_series');
    }
};
