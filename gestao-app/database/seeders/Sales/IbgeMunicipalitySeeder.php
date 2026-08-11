<?php

declare(strict_types=1);

namespace Database\Seeders\Sales;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carrega os municípios do IBGE — ADR-0026.
 *
 * Lê `database/seeders/data/ibge_municipios.json`, que é a resposta da API
 * oficial do IBGE congelada no repositório. **Não faz chamada de rede**:
 * essa é a decisão do ADR-0026 (Alternativa B descartada) — o dado é
 * estático por natureza, e emissão de NF-e não vai depender do SLA de um
 * terceiro nem de o servidor ter saída para a internet.
 *
 * Idempotente (regra 5 da pasta 04): roda em todo deploy. Faz `upsert` em
 * lote, não `firstOrCreate` linha a linha — 5.571 idas ao banco a cada
 * deploy transformariam um seeder de referência num gargalo de release.
 */
class IbgeMunicipalitySeeder extends Seeder
{
    /**
     * 500 linhas por INSERT: ~2.000 parâmetros por statement, bem abaixo
     * do limite de 65.535 do protocolo, e o suficiente para resolver a
     * tabela inteira em 12 idas ao banco.
     */
    private const TAMANHO_DO_LOTE = 500;

    public function run(): void
    {
        $agora = now();

        $linhas = array_map(
            fn (array $municipio): array => [
                'ibge_code' => $municipio['codigo'],
                'uf' => $municipio['uf'],
                'name' => $municipio['nome'],
                'created_at' => $agora,
                'updated_at' => $agora,
            ],
            $this->municipios(),
        );

        foreach (array_chunk($linhas, self::TAMANHO_DO_LOTE) as $lote) {
            // Só `uf` e `name` entram no UPDATE. `updated_at` fica de fora
            // de propósito: incluí-lo faria todo deploy reescrever as 5.571
            // linhas mesmo sem nada ter mudado, sujando o binlog e o
            // backup incremental por nada. Assim, um reseed sem alteração
            // no arquivo é literalmente zero escrita.
            //
            // `name_normalized` também não aparece — é coluna gerada, e o
            // MariaDB recusa INSERT que traga valor para ela.
            DB::table('ibge_municipalities')->upsert($lote, ['ibge_code'], ['uf', 'name']);
        }
    }

    /**
     * @return list<array{codigo: string, uf: string, nome: string}>
     */
    private function municipios(): array
    {
        $caminho = database_path('seeders/data/ibge_municipios.json');

        if (! is_file($caminho)) {
            throw new RuntimeException("Arquivo de municípios do IBGE não encontrado em [{$caminho}].");
        }

        $conteudo = json_decode((string) file_get_contents($caminho), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($conteudo) || ! isset($conteudo['municipios']) || ! is_array($conteudo['municipios'])) {
            throw new RuntimeException("Arquivo [{$caminho}] não tem a chave 'municipios'.");
        }

        // O arquivo declara quantos municípios deveria conter. Conferir
        // aqui custa nada e é o que separa "o arquivo foi truncado por um
        // merge ruim" de "o Brasil tem menos municípios agora" — sem esta
        // linha, um arquivo cortado pela metade semearia meio país em
        // silêncio e só apareceria como nota recusada meses depois.
        $declarado = $conteudo['_total'] ?? null;

        if (is_int($declarado) && $declarado !== count($conteudo['municipios'])) {
            throw new RuntimeException(sprintf(
                'Arquivo [%s] declara %d municípios mas contém %d — arquivo truncado ou corrompido.',
                $caminho,
                $declarado,
                count($conteudo['municipios']),
            ));
        }

        /** @var list<array{codigo: string, uf: string, nome: string}> */
        return $conteudo['municipios'];
    }
}
