<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\IbgeMunicipality;

/**
 * Traduz `cidade + UF` no código IBGE de 7 dígitos — BR-607, ADR-0026.
 *
 * É o `enderDest/cMun` da NF-e. Sem ele o `SpedNfeGateway` (ADR-0025)
 * recusa a emissão de propósito, e é essa recusa que este Service existe
 * para tornar rara — **sem nunca imitá-la ao contrário**: aqui também se
 * prefere não responder a responder por aproximação.
 *
 * ## O que este Service NÃO faz
 *
 * Não usa `LIKE`, não mede distância de edição, não tenta "a cidade mais
 * parecida", não cai para a capital da UF, não ignora a UF. Errar o
 * município produz uma NF-e **autorizada** com endereço fiscal errado —
 * uma falha que passa por todos os controles e só aparece quando alguém
 * de fora reclama. Nulo, por outro lado, vira pendência visível na tela do
 * cliente e alguém resolve em dez segundos escolhendo o município na
 * própria tela. Entre a falha silenciosa e a falha barulhenta, o desenho
 * escolhe a barulhenta.
 *
 * ## Por que a UF é obrigatória
 *
 * Municípios homônimos existem, e não como curiosidade distante: há uma
 * **Jacutinga em MG e outra no RS**, e a do RS é a cidade da própria loja
 * — a maioria dos clientes mora no entorno dela. Casar só por nome
 * mandaria metade da base para Minas Gerais. A chave de busca é sempre
 * `(uf, name_normalized)`, que a migration garante ser `UNIQUE`.
 *
 * ## A normalização precisa bater com a do banco
 *
 * `ibge_municipalities.name_normalized` é uma coluna STORED GENERATED que
 * aplica `UPPER()` e troca os acentos por `REPLACE` aninhado. O
 * {@see self::normalizar()} repete o **mesmo mapa de caracteres**, e é essa
 * igualdade que faz o casamento ser comparação de bytes em vez de depender
 * da collation configurada em `.env`. `IbgeMunicipalitySeederTest` compara
 * as duas pontas nas 5.571 linhas oficiais e reprova se divergirem.
 *
 * @see docs/27-ADR/ADR-0026-codigo-ibge-municipio.md
 */
class ResolveIbgeCityCode
{
    /**
     * O mesmo mapa da migration `create_ibge_municipalities_table`.
     *
     * Copiado, e não importado: a migration é um arquivo de classe anônima
     * que não expõe a constante, e migrations não são API de aplicação —
     * depois de rodadas em produção elas não deveriam ser lidas por código
     * vivo. Quem trava as duas cópias juntas é o teste que compara os 5.571
     * nomes contra a coluna gerada; mexer aqui sem mexer lá reprova o CI.
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

    /**
     * O código do município, ou `null` quando não houver casamento exato.
     *
     * `null` é resposta, não erro: o chamador grava nulo, o endereço fica
     * com pendência e a operação escolhe o município à mão na tela.
     */
    public function resolver(?string $cidade, ?string $uf): ?string
    {
        $uf = mb_strtoupper(trim((string) $uf), 'UTF-8');
        $chave = $this->normalizar((string) $cidade);

        // UF fora do formato oficial não é "quase certa" — é entrada que
        // não identifica estado nenhum. Sair aqui evita varrer a tabela
        // inteira por um nome sem UF, que é justamente o casamento que
        // mandaria o cliente para a Jacutinga errada.
        if ($chave === '' || mb_strlen($uf) !== 2) {
            return null;
        }

        // `limit(2)` de propósito: a chave é UNIQUE, então dois resultados
        // são impossíveis — mas "impossível pelo esquema" vira "possível"
        // no dia em que alguém rodar a aplicação contra um banco cuja
        // constraint não subiu. Trazer duas linhas custa nada e transforma
        // esse dia em "ficou pendente" em vez de "pegou a primeira".
        $codigos = IbgeMunicipality::query()
            ->where('uf', $uf)
            ->where('name_normalized', $chave)
            ->orderBy('ibge_code')
            ->limit(2)
            ->pluck('ibge_code');

        return $codigos->count() === 1 ? (string) $codigos->first() : null;
    }

    /**
     * Caixa alta, sem acento — exatamente o que a coluna gerada produz.
     *
     * O `trim()` é a única liberdade tomada com o que o usuário digitou, e
     * é higiene, não aproximação: " Porto Alegre " é a mesma cidade escrita
     * com um espaço sobrando. Espaço duplicado no meio, abreviação e
     * hífen ausente **não** são consertados — cada um desses "conserto"
     * seria um palpite sobre o que a pessoa quis dizer, e palpite é o que
     * este desenho recusa. Quem digitar assim cai no fallback manual.
     */
    public function normalizar(string $cidade): string
    {
        return strtr(mb_strtoupper(trim($cidade), 'UTF-8'), self::ACENTOS);
    }

    /**
     * Municípios de uma UF cujo nome contém o termo — para a escolha manual.
     *
     * Aqui o `LIKE` é legítimo, e não contradiz a recusa de aproximar: o
     * resultado não vira código gravado sozinho, vira **lista para uma
     * pessoa escolher**. A diferença entre buscar e resolver é quem decide.
     *
     * @return list<array{codigo: string, nome: string, uf: string}>
     */
    public function buscar(?string $uf, string $termo, int $limite = 15): array
    {
        $uf = mb_strtoupper(trim((string) $uf), 'UTF-8');
        $termo = $this->normalizar($termo);

        if (mb_strlen($uf) !== 2 || mb_strlen($termo) < 2) {
            return [];
        }

        // Compara contra a coluna normalizada, não contra `name`: assim
        // quem digita "sao gabriel" acha "São Gabriel" sem depender de a
        // collation do banco ignorar acento — a mesma independência que a
        // resolução automática tem.
        return IbgeMunicipality::query()
            ->where('uf', $uf)
            ->where('name_normalized', 'like', '%'.$this->escaparLike($termo).'%')
            ->orderBy('name')
            ->limit($limite)
            ->get(['ibge_code', 'name', 'uf'])
            ->map(fn (IbgeMunicipality $m): array => [
                'codigo' => $m->ibge_code,
                'nome' => $m->name,
                'uf' => $m->uf,
            ])
            ->all();
    }

    /**
     * `%`, `_` e `\` digitados são texto, não curinga.
     */
    private function escaparLike(string $termo): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $termo);
    }
}
