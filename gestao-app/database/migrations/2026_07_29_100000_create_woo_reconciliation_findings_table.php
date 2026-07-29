<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achados da reconciliação de pedidos — ADR-0025, docs/16 §5.
 *
 * A classe de divergência que **não tem outro lar**: um pedido já importado
 * cujo total mudou no Woo depois (BR-702/BR-304). Um achado por
 * `(entity_type, remote_id, kind)`, reusado ao longo do ciclo
 * aberto→resolvido; `occurrences` é o sinal de **recorrência** que a §5 pede.
 *
 * Pedido **ausente** não entra aqui: ele é importado e deixa um
 * `woo_webhook_events` (`order.pulled`) natural — o log de eventos já é o seu
 * registro. Sem FK para o registro local, no mesmo espírito de
 * `integration_mappings`: a tabela serve a qualquer `entity_type`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woo_reconciliation_findings', function (Blueprint $table) {
            $table->id();

            // `order` hoje; `product`/`stock` quando a reconciliação deles
            // entrar (gatilho de revisão do ADR-0025).
            $table->string('entity_type', 32);

            // Id do registro no Woo (pedido). Casado com `integration_mappings`.
            $table->string('remote_id', 64);

            // Id local, se mapeado — nulável por simetria com a tabela de
            // mapeamento (um achado pode preceder o casamento).
            $table->unsignedBigInteger('local_id')->nullable();

            // Enum de domínio: VARCHAR + Enum PHP (TipoDeDivergencia).
            $table->string('kind', 32);

            $table->string('detail', 1000)->nullable();

            // A âncora congelada e o valor corrente que divergiu. Para pedido,
            // ambos são o total canônico (ADR-0025): legíveis e o
            // `remote_checksum` é o que o `resolver` copia para re-baselizar.
            $table->string('baseline_checksum', 64)->nullable();
            $table->string('remote_checksum', 64)->nullable();

            // Quantas rodadas noturnas viram esta mesma divergência aberta.
            $table->unsignedInteger('occurrences')->default(1);

            // useCurrent(): default explícito CURRENT_TIMESTAMP. Sem ele, a
            // 2ª coluna TIMESTAMP NOT NULL cai em '0000-00-00' (1067 sob o
            // sql_mode) e a 1ª ganharia ON UPDATE implícito — que zeraria
            // `first_detected_at` a cada recorrência. O app grava o valor;
            // o default é só rede.
            $table->timestamp('first_detected_at')->useCurrent();
            $table->timestamp('last_detected_at')->useCurrent();

            // Ciclo de vida do achado (distinto do de um evento): resolvido
            // quando a operação aceita/corrige, ou auto-resolvido na
            // convergência. `resolved_by` sem FK (estilo integration_mappings).
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('resolution_note', 500)->nullable();

            $table->timestamps();

            // Um achado por pedido/tipo: a rodada faz upsert (recorrência no
            // contador), nunca insere repetido. Ciclo aberto/resolvido mora
            // na mesma linha — evita o NULL-distinct de um único parcial.
            $table->unique(['entity_type', 'remote_id', 'kind'], 'uq_woo_recon_findings');

            // Lista de abertos e as contagens do painel filtram por isto.
            $table->index('resolved_at', 'idx_woo_recon_findings_resolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_reconciliation_findings');
    }
};
