<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha de segurança — docs/26-Auditoria/README.md §2.1.
 *
 * Separada da `audits` porque o laravel-auditing precisa de um model que
 * mudou, e os fatos mais importantes daqui não têm model nenhum: um
 * login que falhou com e-mail inexistente não altera linha alguma, e é
 * exatamente o que se quer ver quando alguém tenta entrar à força.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();

            // VARCHAR + enum PHP, nunca ENUM MySQL (convenção da pasta
            // 04): acrescentar um tipo de evento não pode exigir ALTER
            // TABLE numa tabela que só cresce.
            $table->string('event', 64);

            // Sobre QUEM é o fato. Nulo quando não há conta — tentativa
            // de login contra e-mail que não existe.
            $table->foreignId('user_id')->nullable();

            // Quem CAUSOU o fato. Difere de user_id quando um admin
            // suspende a conta de outra pessoa. Nulo em ação de sistema
            // ou de quem ainda não se autenticou.
            $table->foreignId('actor_id')->nullable();

            // O que foi digitado num login que falhou — único jeito de
            // enxergar tentativa contra conta inexistente. Dado pessoal
            // de quem pode nem ser usuário; ver a ressalva de LGPD na
            // pasta 26 §2.1.
            $table->string('identifier')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1023)->nullable();

            // Detalhe específico do evento: de/para do status, papéis
            // somados e removidos, habilidade negada.
            $table->json('context')->nullable();

            // `created_at` É a hora do fato. Um `occurred_at` ao lado
            // seria uma segunda coluna dizendo o mesmo, e duas colunas
            // que deveriam concordar acabam discordando.
            $table->timestamps();

            // RESTRICT, não CASCADE: a trilha segura a conta. Apagar um
            // usuário e levar junto o registro de que ele existiu é
            // justamente o que uma trilha de segurança não pode
            // permitir. Conta se encerra com `disabled` (pasta 18 §3).
            $table->foreign('user_id', 'fk_security_events_user')
                ->references('id')->on('users')->restrictOnDelete();

            $table->foreign('actor_id', 'fk_security_events_actor')
                ->references('id')->on('users')->restrictOnDelete();

            $table->index(['user_id', 'created_at'], 'idx_security_events_user_created');
            $table->index(['event', 'created_at'], 'idx_security_events_event_created');

            // "Quantas falhas vieram deste IP na última hora" — a
            // pergunta da detecção de força bruta.
            $table->index(['ip_address', 'created_at'], 'idx_security_events_ip_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
