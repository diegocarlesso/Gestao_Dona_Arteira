<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heartbeat da reconciliação — ADR-0025, docs/16 §5.
 *
 * Uma linha por rodada. Existe porque uma rodada **saudável e sem achados**
 * não deixaria traço em lugar nenhum — e aí "rodou e estava tudo certo"
 * ficaria indistinguível de "o cron morreu em silêncio", o modo de falha que
 * já mordeu este projeto (P-15/P-16). A **ausência** de uma rodada recente é,
 * ela mesma, o alerta; `finished_at` nulo numa linha antiga = rodada que
 * começou e não terminou (processo morto no meio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woo_reconciliation_runs', function (Blueprint $table) {
            $table->id();

            // useCurrent() no default explícito: impede o ON UPDATE
            // CURRENT_TIMESTAMP implícito que a 1ª coluna TIMESTAMP ganharia
            // e que zeraria `started_at` quando `concluir()` grava o
            // `finished_at`. O app define o valor na abertura.
            $table->timestamp('started_at')->useCurrent();
            // Nulo = começou e não concluiu (crash/timeout) — sinal, não erro.
            $table->timestamp('finished_at')->nullable();

            $table->unsignedSmallInteger('window_days')->default(7);

            $table->unsignedInteger('orders_checked')->default(0);
            $table->unsignedInteger('orders_missing_imported')->default(0);
            $table->unsignedInteger('orders_divergent')->default(0);
            $table->unsignedInteger('findings_resolved')->default(0);

            // Preenchido só quando a rodada falha; a situação (ok/falha/
            // incompleta) é derivada disto + `finished_at` no model.
            $table->string('error', 1000)->nullable();

            $table->timestamps();

            $table->index('started_at', 'idx_woo_recon_runs_started');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_reconciliation_runs');
    }
};
