<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Municípios do IBGE — tabela de referência embarcada (ADR-0026).
 *
 * Existe para uma coisa só: traduzir `cidade + UF` de um endereço no
 * código IBGE de 7 dígitos que a NF-e exige em `enderDest/cMun`. Os dados
 * vêm da API oficial (`servicodados.ibge.gov.br/api/v1/localidades/
 * municipios`), congelados em `database/seeders/data/ibge_municipios.json`
 * — nunca digitados à mão, nunca consultados por rede em produção.
 *
 * Não tem `public_id` ULID, e isso é deliberado (a convenção da pasta 04
 * reserva o ULID para "entidades expostas na API"). Um município já nasce
 * com um identificador público, estável, oficial e mundialmente aceito:
 * o próprio `ibge_code`. Pendurar um ULID ao lado seria criar uma segunda
 * identidade para a mesma linha e ter que escolher qual delas a API
 * mostra. Isto não é cadastro do ERP — é uma tabela-dicionário.
 *
 * Sem `deleted_at` (BR-008 restringe soft delete a cadastros com
 * inativação) e sem `created_by`/`updated_by`: nenhuma pessoa cria linha
 * aqui, quem escreve é o seeder.
 *
 * ## Por que existe `name_normalized`
 *
 * A consulta real da resolução automática é "dado 'São paulo' + 'SP',
 * qual o código?" — casamento por nome, insensível a caixa e acento.
 *
 * O MariaDB *pareceria* resolver isso sozinho: a collation do projeto
 * (`utf8mb4_unicode_ci`) já é insensível a caixa **e a acento**, então um
 * índice em `(uf, name)` com `WHERE name = 'SAO PAULO'` casaria. Foi
 * medido e funciona. Mesmo assim a coluna normalizada existe, por três
 * motivos que pesam mais que a economia de 120 bytes por linha:
 *
 * 1. **A collation é uma variável de ambiente.** `config/database.php` lê
 *    `env('DB_COLLATION', 'utf8mb4_unicode_ci')`. Amarrar a correção de um
 *    campo fiscal a um `.env` que ninguém revisa é apostar a nota fiscal
 *    num detalhe de configuração — troque para `utf8mb4_bin` (ou para uma
 *    collation `_as_cs`) e o casamento passa a falhar em silêncio, sem
 *    erro, só devolvendo "não achei" para o país inteiro.
 * 2. **O PHP não consegue reproduzir a comparação da collation.** Com a
 *    coluna explícita, a aplicação normaliza a entrada com exatamente o
 *    mesmo mapa de caracteres usado aqui, e o casamento vira uma igualdade
 *    de bytes — testável em PHP, sem depender de como o banco compara.
 * 3. **Torna a unicidade uma regra do banco.** Com o nome normalizado
 *    materializado, `uq_ibge_municipalities_uf_nome` garante que
 *    (UF + nome normalizado) nunca aponta para dois municípios — ou seja,
 *    o resolvedor nunca terá que escolher entre dois resultados. Isso foi
 *    conferido contra a base oficial: 5.571 municípios geram 5.571 chaves
 *    normalizadas distintas, zero colisões.
 *
 * A coluna é **STORED GENERATED**, não preenchida pela aplicação: assim
 * nenhum caminho de escrita — seeder, tinker, correção manual em produção
 * — consegue gravar uma linha com o normalizado fora de sincronia com o
 * nome. O banco recalcula sempre.
 *
 * Se algum dia entrar um nome com acento fora do mapa abaixo, a falha é
 * segura: o caractere sobrevive à normalização, o casamento não acontece,
 * o endereço fica com `city_code` nulo e vira pendência visível — que é
 * literalmente o comportamento que o ADR-0026 pede ("nunca aproxima").
 * `IbgeMunicipalityNormalizacaoTest` compara as 5.571 linhas contra o
 * equivalente em PHP e reprova se as duas pontas divergirem.
 *
 * @see docs/27-ADR/ADR-0026-codigo-ibge-municipio.md
 */
return new class extends Migration
{
    /**
     * Acentos que ocorrem de fato nos nomes oficiais, em caixa alta.
     *
     * Levantado da resposta da API, não de suposição: os nomes dos 5.571
     * municípios usam exatamente Á Â Ã Ç É Ê Í Ó Ô Õ Ú Ü (mais espaço,
     * apóstrofo e hífen, que a normalização preserva de propósito —
     * removê-los aproximaria grafias diferentes, e aproximar é o que este
     * desenho recusa). À Ä Ë Î Ï Ö Û Ñ entram por robustez: não aparecem
     * hoje, e custam um `REPLACE` cada caso o IBGE crie um município que
     * os use.
     *
     * @var array<string, string>
     */
    private const ACENTOS = [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
        'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N',
    ];

    public function up(): void
    {
        Schema::create('ibge_municipalities', function (Blueprint $table) {
            $table->id();

            // O código do IBGE tem 7 dígitos, sempre — conferido nos 5.571
            // registros da fonte. CHAR, não inteiro: é identificador, não
            // número. Somar ou ordenar aritmeticamente não faz sentido, e
            // como inteiro o valor perderia um eventual zero à esquerda.
            $table->char('ibge_code', 7);

            $table->char('uf', 2);

            // 120 para casar com `customer_addresses.city`, que é o campo
            // de onde o nome vem na hora de comparar. O maior nome oficial
            // ("Vila Bela da Santíssima Trindade") tem 32 caracteres.
            $table->string('name', 120);

            $table->string('name_normalized', 120)
                ->storedAs($this->expressaoDeNormalizacao())
                ->comment('Gerada pelo banco: nome em caixa alta e sem acento. Não escrever.');

            $table->timestamps();

            // O código é a identidade do município — e é por ele que o
            // seeder faz upsert e que `customer_addresses` aponta.
            $table->unique('ibge_code', 'uq_ibge_municipalities_codigo');

            // A chave pela qual a resolução automática consulta. Sendo
            // UNIQUE, ela não é só um índice: é a garantia de que o
            // casamento por (UF + nome) devolve no máximo uma linha, então
            // nenhum endereço pode receber um código "escolhido entre dois".
            $table->unique(['uf', 'name_normalized'], 'uq_ibge_municipalities_uf_nome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ibge_municipalities');
    }

    /**
     * `UPPER(name)` com os acentos trocados por REPLACE aninhado.
     *
     * O MariaDB não tem `unaccent` (é do PostgreSQL) nem uma collation que
     * dê para materializar numa coluna, então a troca é explícita. Montar
     * a expressão a partir do mapa acima evita que alguém acrescente um
     * caractere na lista e esqueça de mexer no SQL.
     */
    private function expressaoDeNormalizacao(): string
    {
        $expressao = 'UPPER(`name`)';

        // Interpolar direto é seguro aqui e só aqui: as duas pontas do mapa
        // são constantes desta classe, letras únicas, sem aspas nem barra
        // invertida. Nada nesta expressão vem de fora do arquivo.
        foreach (self::ACENTOS as $comAcento => $semAcento) {
            $expressao = "REPLACE({$expressao}, '{$comAcento}', '{$semAcento}')";
        }

        return $expressao;
    }
};
