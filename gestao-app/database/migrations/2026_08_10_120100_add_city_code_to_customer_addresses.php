<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código IBGE do município do endereço — ADR-0026.
 *
 * É o `enderDest/cMun` da NF-e 4.00. Sem ele o `SpedNfeGateway` (ADR-0025)
 * recusa a emissão de propósito, em vez de aproximar pelo nome da cidade —
 * e hoje `customer_addresses` não tem de onde tirar esse número.
 *
 * **Nulável, e vai nascer nulo para todo mundo.** É o estado honesto: o
 * endereço ainda não teve o município resolvido. A resolução automática e
 * o backfill vêm em seguida; o que não pode acontecer é a coluna nascer
 * com um valor plausível e errado. Nulo é pendência visível — que é o que
 * o ADR-0026 pede. Expand-only (pasta 04, regra 3): coluna nova, nulável,
 * nada reescrito, nenhum lock relevante (a tabela tem centenas de linhas).
 *
 * ## A FK é o ponto principal desta migration
 *
 * `city_code` referencia `ibge_municipalities.ibge_code`, e isso é o que
 * torna impossível gravar um código que não existe. O ADR-0026 descartou
 * a "Alternativa A — digitação manual" justamente porque ninguém sabe o
 * código de cabeça e a operação acabaria digitando qualquer número para o
 * formulário aceitar. O ADR mantém a digitação manual como *fallback* para
 * grafia divergente — a FK preserva esse fallback (dá para escolher outro
 * município) e elimina o abuso (não dá para inventar um código).
 *
 * `RESTRICT` na deleção, como manda a pasta 04: apagar um município que
 * algum endereço usa passaria a nota a emitir com destino inexistente. Na
 * prática nunca dispara — o seeder faz upsert, nunca delete.
 *
 * Consequência para quem escrever teste daqui em diante: um endereço com
 * `city_code` preenchido exige o município semeado. `RefreshDatabase` não
 * roda seeder; semeie a linha que o teste usa.
 *
 * @see docs/27-ADR/ADR-0026-codigo-ibge-municipio.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            // Junto de `state`, porque é a mesma informação vista de outro
            // ângulo: onde o destinatário fica.
            $table->char('city_code', 7)->nullable()->after('state');

            // O InnoDB cria sozinho o índice que a FK exige sobre
            // `city_code`, então não há `$table->index()` aqui de
            // propósito — um segundo índice na mesma coluna só ocuparia
            // espaço e trabalho de escrita.
            $table->foreign('city_code', 'fk_customer_addresses_municipio')
                ->references('ibge_code')->on('ibge_municipalities')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            // A ordem importa: o MariaDB recusa derrubar uma coluna que
            // ainda sustenta uma constraint.
            $table->dropForeign('fk_customer_addresses_municipio');
            $table->dropColumn('city_code');
        });
    }
};
