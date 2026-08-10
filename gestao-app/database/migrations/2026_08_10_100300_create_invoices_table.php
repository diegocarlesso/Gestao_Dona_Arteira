<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas fiscais — docs/14, ADR-0025.
 *
 * `order_id` é coluna inteira com FK de banco, **sem relação Eloquent**: a
 * integridade referencial é do banco (o mesmo arranjo de
 * `order_items.product_id` apontando para `products`), mas em PHP o módulo
 * Fiscal nunca faz `Order::find()` — pergunta a Vendas pelo
 * `BuildOrderInvoiceSnapshot` (ADR-0020).
 *
 * `number` **nulável** de propósito, e isso é regra e não frouxidão: uma
 * nota que nem chegou a passar da pré-validação (produto sem NCM, perfil
 * fiscal inexistente) fica registrada como pendência **sem consumir
 * número**. Reservar número para uma nota que não pode ser montada criaria
 * furo na sequência, e furo na sequência exige inutilização na SEFAZ
 * (BR-602) — burocracia gerada por um cadastro incompleto.
 *
 * `restrictOnDelete` na série: apagar uma série com notas emitidas apagaria
 * a prova de qual sequência gerou quais números.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->unsignedBigInteger('order_id');

            // Nulável pelo mesmo motivo de `number`: uma pendência
            // registrada antes de a série existir (ambiente novo, ninguém
            // cadastrou `fiscal_series` ainda) precisa aparecer no painel
            // fiscal, e não só no log. Nota que chega a ser transmitida
            // sempre tem série.
            $table->foreignId('series_id')->nullable();

            // Só existe depois que a numeração é reservada (BR-602).
            $table->unsignedInteger('number')->nullable();

            // Os 44 dígitos da chave, quando a SEFAZ autoriza.
            $table->string('access_key', 44)->nullable();

            // pending / authorized / rejected / cancelled
            $table->string('status', 16)->default('pending');

            // Caminho do XML no storage — guarda de 5 anos (BR-603).
            $table->string('xml_path', 500)->nullable();

            $table->string('protocol', 40)->nullable();

            // Motivo da rejeição **ou** a pendência que impede a emissão.
            // Um campo só porque a pergunta é uma só ("por que esta nota
            // não está autorizada?") e dois campos convidariam a metade da
            // resposta ficar em cada um.
            $table->text('rejection_reason')->nullable();

            // Valor total da nota, DECIMAL(15,2) (ADR-0013). Congelado no
            // momento da montagem: o pedido pode ser corrigido depois, a
            // nota autorizada não muda (docs/14 §3, imutabilidade).
            $table->decimal('total', 15, 2)->default(0);

            // Qual perfil da matriz gerou esta nota. Guardado porque o
            // perfil é versionado por vigência: sem registrar qual valia,
            // uma auditoria daqui a dois anos não conseguiria reconstruir
            // por que o CFOP era aquele.
            $table->foreignId('tax_profile_id')->nullable();

            $table->timestamps();

            $table->foreign('order_id', 'fk_invoices_order')
                ->references('id')->on('orders')->cascadeOnDelete();

            $table->foreign('series_id', 'fk_invoices_series')
                ->references('id')->on('fiscal_series')->restrictOnDelete();

            $table->foreign('tax_profile_id', 'fk_invoices_tax_profile')
                ->references('id')->on('tax_profiles')->nullOnDelete();

            // "Qual a nota deste pedido?" é a consulta do gate de expedição
            // e do painel. Sem UNIQUE: uma nota cancelada pode ser
            // substituída por outra do mesmo pedido.
            $table->index('order_id', 'idx_invoices_order');

            // Painel fiscal: pendências e rejeições primeiro (docs/14 §5).
            $table->index(['status', 'created_at'], 'idx_invoices_status');

            // Busca por chave de acesso — como o contador procura.
            $table->index('access_key', 'idx_invoices_access_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
