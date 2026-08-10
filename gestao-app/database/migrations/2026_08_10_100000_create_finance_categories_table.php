<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorias gerenciais do Financeiro — BR-503, docs/12-Financeiro §4.
 *
 * **Fatia mínima adiantada para o P0** (nota de escopo da pasta 12): nasce
 * só a árvore, com uma categoria semeada, para que o título a pagar do
 * Compras tenha onde ser classificado. O plano de categorias completo
 * (receitas/custos/despesas/investimentos) é Gate 04.
 *
 * A árvore é `parent_id` para si mesma — profundidade livre, sem tabela de
 * fechamento: o plano gerencial da Dona Arteira tem dezenas de linhas, não
 * milhares, e a consulta recursiva nunca vai doer nesse volume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->string('name');

            // payable / receivable — uma categoria não serve aos dois lados:
            // "Fornecedores" não é receita, e misturar os dois faria o
            // fluxo de caixa somar entrada com saída na mesma linha.
            $table->string('type', 16);

            $table->unsignedBigInteger('parent_id')->nullable();

            $table->timestamps();

            // `restrictOnDelete`, não `nullOnDelete`: apagar uma categoria-pai
            // promoveria as filhas a raiz em silêncio, reescrevendo o plano
            // gerencial sem ninguém perceber. Quem quiser apagar reorganiza
            // as filhas antes — de propósito.
            $table->foreign('parent_id', 'fk_finance_categories_parent')
                ->references('id')->on('finance_categories')->restrictOnDelete();

            // O par (tipo, nome) é único porque é por ele que a categoria
            // padrão de cada origem é resolvida (ver RegisterPayableService):
            // duas "Compras/Fornecedores" partiriam os títulos em duas
            // linhas do relatório sem aviso. Se o Gate 04 precisar de nomes
            // repetidos em ramos diferentes da árvore, isso vira uma
            // migration deliberada — não um acidente de cadastro.
            $table->unique(['type', 'name'], 'uq_finance_categories_tipo_nome');

            $table->index('parent_id', 'idx_finance_categories_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_categories');
    }
};
