<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispositivos que dispensam o desafio TOTP por 30 dias — ADR-0021.
 *
 * Tabela, e não cookie assinado autocontido, por um motivo só: revogação.
 * Um cookie que se valida sozinho pela APP_KEY não pode ser cancelado
 * individualmente — cancelar exigiria trocar a chave e derrubar todos os
 * dispositivos de todos os usuários de uma vez. O ADR-0021 exige poder
 * esquecer um dispositivo (e todos, na troca de senha), e isso pede
 * registro por dispositivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_remembered_devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id');

            // Identificador público do dispositivo, não sequencial
            // (convenção da pasta 04): é ele que aparece na tela de
            // "esquecer este dispositivo", e um id sequencial contaria
            // quantos dispositivos existem no sistema.
            $table->string('device_id', 64)->unique();

            // O HASH do token, nunca o token. Um vazamento desta tabela
            // não autentica ninguém — quem tem o banco não tem o cookie.
            // Mesma razão pela qual `password` é hash e não texto.
            $table->string('token_hash', 64);

            // De onde o dispositivo foi confiado pela primeira vez. Serve
            // à leitura humana da trilha ("confiei isso de onde?"), não à
            // validação: IP muda o tempo todo e amarrar a sessão a ele
            // quebraria quem usa 4G e Wi-Fi no mesmo dia.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1023)->nullable();

            $table->timestamps();

            // Renovado a cada uso, para a tela mostrar o que está vivo.
            $table->timestamp('last_used_at')->nullable();

            // Os 30 dias contam da confirmação do TOTP, NÃO do último
            // uso: renovar a validade a cada acesso daria vida perpétua a
            // um cookie roubado que fosse usado com frequência.
            //
            // `useCurrent()` não é enfeite para satisfazer o MariaDB, que
            // recusa TIMESTAMP NOT NULL sem default: o default é o agora,
            // ou seja, **já vencido**. Uma linha que chegasse aqui sem
            // `expires_at` explícito nasce inválida em vez de eterna —
            // falha fechado, que é o lado certo para errar.
            $table->timestamp('expires_at')->useCurrent();

            // RESTRICT pelo mesmo motivo da `security_events`: conta não
            // se apaga (pasta 18 §3), se encerra com `disabled`.
            $table->foreign('user_id', 'fk_2fa_devices_user')
                ->references('id')->on('users')->restrictOnDelete();

            // "Os dispositivos vivos desta conta" — a pergunta da tela de
            // configuração e da revogação em massa.
            $table->index(['user_id', 'expires_at'], 'idx_2fa_devices_user_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_remembered_devices');
    }
};
