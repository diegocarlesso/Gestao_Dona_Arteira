<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfis fiscais — docs/13 §3, BR-606.
 *
 * A matriz que responde "qual CFOP e qual CSOSN" sem perguntar ao operador:
 * **tipo de operação × destino (dentro/fora UF) × tipo de cliente** → CFOP +
 * CSOSN. O objetivo declarado da pasta 13 é que ninguém escolha CFOP à mão,
 * porque escolher errado é autuação e a tela não tem como saber.
 *
 * **Versionado por vigência desde o primeiro dia** (`valid_from`/`valid_to`),
 * e não porque um dia talvez mude: a reforma tributária (IBS/CBS) já começou
 * em 2026 e a doc 13 §4 classifica a mudança de layout como risco de
 * probabilidade *certa*. Sem vigência, absorver isso seria reescrever
 * histórico — e nota emitida no ano passado precisa continuar explicável
 * pelo perfil que valia no ano passado.
 *
 * A tabela **nasce vazia**. Populá-la é decisão do contador (a doc 13 está
 * bloqueada por validação), não da migração: um seed com as hipóteses 💡
 * pareceria dado confirmado seis meses depois, quando ninguém lembrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            // venda / devolucao / remessa… — vocabulário da pasta 13.
            $table->string('operation_type', 32);

            // dentro_uf / fora_uf
            $table->string('destination', 16);

            // pf / pj
            $table->string('customer_type', 16);

            $table->string('cfop', 8);
            $table->string('csosn', 8);

            // Observações que vão para o campo de informações complementares
            // da nota (ex.: o texto obrigatório do Simples Nacional).
            $table->text('notes')->nullable();

            $table->date('valid_from');
            $table->date('valid_to')->nullable();

            $table->timestamps();

            // A resolução sempre entra pelas três dimensões + data. Sem
            // UNIQUE: dois perfis com a mesma chave são legítimos desde que
            // as vigências não se sobreponham, e sobreposição é validação de
            // cadastro, não de índice.
            $table->index(
                ['operation_type', 'destination', 'customer_type', 'valid_from'],
                'idx_tax_profiles_matriz'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_profiles');
    }
};
