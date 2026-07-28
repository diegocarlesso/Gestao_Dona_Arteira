# ADR-0023: Produção é pintura, não fundição

> **Status:** Proposto — **premissa de negócio, decisão do dono**
> **Data:** 2026-07-27 · **Decisores:** dono (premissa), production-specialist, chief-architect
> **Módulos afetados:** 08 (Produção), 30 (Domínio), 04 (Banco), 09 (Estoque), 02 (Domínio/eventos), 01 (Regras), 29 (Glossário); recebimento em [ADR-0024](ADR-0024-quarentena-de-secagem.md)

## Contexto

Toda a documentação de Produção (pasta 08), o modelo de dados (pasta 04) e o
domínio (pasta 30) foram escritos sobre a premissa de que a Dona Arteira
**funde as peças** — gesso + água despejados em molde, molde como ativo com
vida útil, secagem como etapa pós-fundição, consumo de matéria-prima de
moldagem.

Em **2026-07-27 o dono confirmou que essa premissa é falsa.** A Dona Arteira
**não fabrica** as peças: compra-as **prontas, mas cruas** (sem pintura, e
nem sempre secas) de fornecedores, e o que ela faz é **pintar à mão**. Não
há fundição, não há moldes, não há despejo de gesso. Os dois relatórios
externos de 2026-07-27 na raiz do projeto (`RELATORIO-PARA-CLAUDE.md`,
`RELATORIO_ANALISE_CEO.md`) corroboram e recomendam, ainda, que o **custeio
inclua a mão de obra de pintura** — o maior custo oculto da operação, hoje
invisível.

Sem esta correção, qualquer código de Produção construído no Gate 03
implementaria um subsistema inteiro (moldes, fundição, consumo de moldagem)
que não corresponde a nada no negócio real.

## Decisão

**Produção = pintura + acabamento + controle de qualidade.** A Ordem de
Produção (OP) parte de uma **peça crua seca** e produz uma **peça acabada**.
Fica decidido:

1. **A peça crua é um produto** (`kind = raw_piece`): comprada de fornecedor
   (fluxo da pasta 11), **não vendável**, **não fabricada pela casa**. É
   componente da ficha técnica (`bom_items`) da peça acabada. A peça acabada
   permanece `kind = finished_good` (pintada, vendável, "fabricada" pela casa
   no sentido de que a pintura é o trabalho da casa).

2. **A pintura roda sobre o ledger de estoque sem mudá-lo — [ADR-0008](ADR-0008-ledger-estoque.md)
   permanece inalterado.** A OP registra:
   - `production_input` (−) da **peça crua** consumida;
   - `production_input` (−) de **tinta/verniz** consumidos;
   - `production_output` (+) da **peça acabada** aprovada no CQ.

   Peça crua e peça acabada são **SKUs distintos** (coerente com
   [BR-009](../01-Regras-de-Negocio/01-registro-de-regras.md): cada cor é um
   produto próprio). Uma peça crua costuma ser o substrato de **várias**
   peças acabadas (a "Coruja crua" vira "Coruja azul", "Coruja vermelha"…),
   o que a ficha técnica já expressa: o componente de moldagem some, entra a
   **peça crua + tintas** como componentes do acabado. Nenhuma tabela nova,
   nenhuma coluna nova de estoque.

3. **Moldes e fundição são removidos por completo:** a tabela `molds`, a
   coluna `production_orders.mold_id`, a etapa `casting`, o evento de
   fundição e a regra de vida útil de molde ([BR-105](../01-Regras-de-Negocio/01-registro-de-regras.md),
   **revogada**) deixam de existir no modelo. A secagem sai das etapas de
   produção e passa a ser **quarentena de recebimento** ([ADR-0024](ADR-0024-quarentena-de-secagem.md)).

4. **Etapas da OP:** `painting → finishing → qc`. A OP nasce a partir de peça
   crua **já seca e liberada**; a secagem não é mais responsabilidade da
   produção.

5. **Custeio ABC incluindo mão de obra de pintura.** O custo da peça acabada
   é:

   ```
   custo = custo da peça crua (custo médio)
         + insumos consumidos (tinta, verniz — custo médio)
         + mão de obra de pintura (minutos de bancada × custo/hora)
         + rateio de overhead configurável
   ```

   A pintura manual é o maior componente de valor e o que torna a margem
   sobre varejo/atacado real; deixá-la fora (como um custo genérico de peça)
   esconde justamente o que a operação vende. O detalhamento e o faseamento
   ficam em [BR-108](../01-Regras-de-Negocio/01-registro-de-regras.md).

## Alternativas consideradas

### Alternativa A — Manter o modelo de fundição
É a própria premissa falsa. Modelaria moldes, consumo de gesso de moldagem e
secagem pós-fundição que **não existem** na operação. Descartada — é o erro
que este ADR corrige.

### Alternativa B — Pintura como "transformação de estado" do mesmo produto
Tratar peça crua e peça acabada como **um único produto** mudando de estado
(crua → pintada), sem OP e sem SKUs distintos. Prós: menos cadastros.
Contras: perde o consumo de tinta/verniz, a perda por etapa, o custo de mão
de obra e **colide com [BR-009](../01-Regras-de-Negocio/01-registro-de-regras.md)**
(cada cor é um SKU com saldo próprio — não dá para uma "Coruja crua" única
virar cinco cores sem multiplicar SKU). Descartada.

### Alternativa C — Custeio sem mão de obra de pintura
Custo = peça crua + insumos, com a mão de obra diluída como overhead fixo.
Mais simples de implementar, mas **esconde o maior custo real** (o tempo de
pintura manual), que é exatamente o que os relatórios apontam como o furo de
margem. Adotamos o ABC como **alvo**; o faseamento (começar com custo/hora
padrão configurável por peça e evoluir para apontamento de tempo real por
peça) fica registrado em [BR-108](../01-Regras-de-Negocio/01-registro-de-regras.md)
e será validado com o dono.

## Consequências

**Positivas:**
- A documentação passa a corresponder ao negócio real antes de qualquer
  código do Gate 03.
- O custeio revela a margem real (peça crua + insumos + **pintura**), em vez
  de uma remarcação arbitrária sobre o custo de compra.
- Reaproveita **integralmente** o ledger ([ADR-0008](ADR-0008-ledger-estoque.md))
  e a ficha técnica (`bom_items`) — a correção é de premissa de domínio, não
  de arquitetura de dados.
- Elimina um subsistema falso inteiro (moldes/fundição) do escopo.

**Negativas / dívidas assumidas:**
- O enum `App\Modules\Catalog\Enums\ProductKind` precisará do caso
  `raw_piece` (mudança de código **expand-only**; as 754 linhas migradas
  como `finished_good` não são afetadas). Ao adicionar o caso, `isSellable()`
  e `isManufactured()` retornam **`false`** para `raw_piece` (comprada, não
  vendável). Fica para o Gate 02/03 — este ADR é documentação.
- O custeio com mão de obra exige um parâmetro de **custo/hora** e, na versão
  fina, **apontamento de tempo** por peça/lote — complexidade adicional,
  faseável.
- Cada peça acabada (cor) precisa ter a **peça crua substrato** ligada na
  ficha técnica — trabalho de dados do catálogo no Gate 03.

**Gatilhos de revisão:**
- Se o dono **passar a fundir** peças internamente (reverte a premissa) →
  novo ADR reintroduzindo fundição/moldes.
- Se o apontamento de minutos de bancada se mostrar caro/impraticável e a
  operação ficar num custo/hora padrão fixo → revisar [BR-108](../01-Regras-de-Negocio/01-registro-de-regras.md)
  (não este ADR).
- Se surgir peça **vendida crua** (sem pintura) como item de catálogo →
  tratá-la como `resale`/`finished_good` sem OP; não reabre este ADR.
