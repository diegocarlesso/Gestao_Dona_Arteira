# ADR-0024: Quarentena de secagem no recebimento

> **Status:** Proposto — decisão do dono (premissa) + arquitetura
> **Data:** 2026-07-27 · **Decisores:** chief-architect, inventory-specialist, business-analyst (Compras)
> **Módulos afetados:** 11 (Compras), 09 (Estoque), 04 (Banco), 01 (Regras), 30 (Domínio), 29 (Glossário)
> **Relacionado:** [ADR-0023](ADR-0023-producao-e-pintura-nao-fundicao.md) (produção é pintura), [ADR-0008](ADR-0008-ledger-estoque.md) (ledger)

## Contexto

As peças chegam do fornecedor **cruas e nem sempre secas** (premissa
confirmada pelo dono em 2026-07-27 — ver [ADR-0023](ADR-0023-producao-e-pintura-nao-fundicao.md)).
Pintar uma peça ainda úmida arruína o acabamento e arrisca mofo. Logo existe
uma **espera obrigatória**: a peça recebida já é patrimônio no estoque, mas
**não pode ir para a bancada de pintura até secar**.

Os relatórios de 2026-07-27 (`RELATORIO_ANALISE_CEO.md` §2.1/§2.2,
`RELATORIO-PARA-CLAUDE.md` §4.2) recomendam tratar isso como uma
**"quarentena de secagem"** no recebimento, com previsão de liberação e
rastreabilidade por lote/fornecedor para medir a **taxa de quebra** — insumo
de decisão de compra.

Restrição dura: o **ledger de estoque ([ADR-0008](ADR-0008-ledger-estoque.md))
não pode mudar** — nenhum saldo é alterado fora de movimento imutável, e o
módulo de Estoque já está implementado e em produção (`locations`,
`inventory_movements`, `inventory_balances`, transferências).

## Decisão

A quarentena de secagem é modelada como uma **localização de estoque
dedicada** ("Quarentena/Secagem"), distinta da localização disponível
("Ateliê"). Não há tabela nova nem coluna nova de saldo — reusa `locations`,
os tipos de movimento `transfer_in/out` e o `reference` polimórfico que já
existem no ledger.

1. **Entrada:** o recebimento de compra ([BR-401](../01-Regras-de-Negocio/01-registro-de-regras.md))
   de peça crua gera `purchase_receipt` (+) **na localização Quarentena**,
   com custo unitário (alimenta custo médio) e conta a pagar ([BR-402](../01-Regras-de-Negocio/01-registro-de-regras.md)).

2. **Disponibilidade para pintar = saldo na localização Ateliê**, nunca o
   saldo em Quarentena. A peça em quarentena **conta no saldo físico total**
   (é patrimônio, existe), mas é **indisponível para consumo de produção** e
   **nunca é publicada como disponível no site** (o site já só publica peça
   acabada — [ADR-0023](ADR-0023-producao-e-pintura-nao-fundicao.md)). A
   localização de quarentena é marcada pelo seu `type` (a coluna já existe no
   modelo de `locations`); produção e o sync do canal ignoram esse `type`.

3. **Liberação = transferência que carrega o custo.** Quando a peça seca, uma
   ação de **liberação** registra `transfer_out` (Quarentena −) + `transfer_in`
   (Ateliê +), referenciando o recebimento. Como o `avg_cost` é por
   **produto×local**, a transferência **carrega o custo médio da Quarentena**
   (`transfer_in` com `unit_cost = avg_cost` de origem): sem isso a peça crua
   chegaria ao Ateliê a **custo zero** e a OP a consumiria a zero, furando o
   custeio ABC ([BR-108](../01-Regras-de-Negocio/01-registro-de-regras.md)). A
   partir daí a peça está disponível para OP de pintura
   ([ADR-0023](ADR-0023-producao-e-pintura-nao-fundicao.md)).
   - **Previsão de liberação** = `received_at + product.drying_days`
     (default; `drying_days` já existe no catálogo). O painel de recebimento
     lista os lotes "prontos para liberar" na data prevista.
   - **Gatilho da liberação:** confirmação **manual** por padrão (alguém
     confirma que secou — a secagem depende de clima e do estado de chegada,
     então uma liberação automática cega poderia soltar peça ainda úmida); a
     liberação **automática por data** fica disponível como opção
     parametrizável para quem preferir.

4. **Quebra na quarentena:** peça úmida quebra ou mofa antes de secar — é um
   `loss` (−) na localização Quarentena, referenciando o recebimento. Não é
   perda de produção (não houve OP); é perda de recebimento/manuseio.

5. **Rastreabilidade por lote/fornecedor:** cada recebimento (`goods_receipt`)
   é o **lote de recebimento** — fornecedor + `received_at` + previsão/real
   de liberação. A **taxa de quebra por fornecedor/lote** é derivada dos
   movimentos `loss` que referenciam recebimentos. Rastreio por lote ao longo
   de toda a vida da peça (além do recebimento) é a **extensão `batch`**
   já prevista como gatilho no [ADR-0008](ADR-0008-ledger-estoque.md), a
   adotar se/quando o atacado exigir — não é pré-requisito desta decisão.

## Alternativas consideradas

### Alternativa A — Dimensão de `estado` no saldo/movimento
Adicionar uma coluna `state` (`recebido_umido`/`em_secagem`/`cru_seco`…) a
`inventory_balances`/`inventory_movements`, como esboçava o
`RELATORIO-PARA-CLAUDE.md` §4.1. Prós: o estado fica explícito sem a peça
"mudar de lugar" (ela seca parada). Contras: **muda o esquema de um ledger
já implementado e em produção** — a chave do saldo viraria
produto×local×estado e **toda** consulta/read-model do módulo de Estoque
(posição, extrato, contagem) teria de passar a considerar estado. Contraria a
restrição "ADR-0008 não muda" e amplia o raio de impacto sobre código que já
roda. Descartada.

### Alternativa B — Data de liberação no recebimento, sem mover estoque
Manter a peça numa só localização e derivar "disponível para pintar" =
saldo − (recebimentos ainda não liberados). Prós: nenhuma transferência.
Contras: cria uma **segunda noção de disponibilidade fora do ledger**
(calculada a partir de recebimentos, não de movimentos), que diverge do
saldo materializado e quebra a auditabilidade que é o motivo do ledger
existir. Descartada.

### Alternativa C — Sem quarentena (peça disponível ao receber)
Simplesmente entrar a peça como disponível. Contradiz a premissa (peça chega
úmida) e provoca o dano real que o dono descreveu (pintar peça úmida). Além
disso, faria o sistema **prometer prazo** com peça que ainda não pode ser
trabalhada. Descartada.

## Consequências

**Positivas:**
- Zero mudança de **esquema de dados**: reusa `locations` (+ seu `type`),
  `transfer_in/out`, `loss` e o `reference` polimórfico do ledger. (Há mudança
  de **código** no Inventory — ver dívidas abaixo.)
- A liberação é um **movimento auditável** como qualquer outro — o extrato
  explica quando e por quem a peça ficou disponível.
- O painel "chão de ateliê" ganha de graça a coluna "o que está secando / com
  previsão / pronto para liberar".
- A taxa de quebra por fornecedor sai dos mesmos movimentos, sem tabela nova.

**Negativas / dívidas assumidas:**
- "Liberar" é semanticamente uma **mudança de estado** modelada como
  **mudança de localização** — a peça pode não sair fisicamente do lugar. É
  um overload consciente da localização, aceitável numa operação de local
  único e reversível para a Alternativa A se um dia houver
  produto×local×estado real.
- Requer semear uma localização de sistema "Quarentena" e **adicionar o case
  `LocationType::quarantine` ao enum** — o cast de `Location::type` recusa valor
  fora do enum, logo é dívida de **código** obrigatória (expand-only, sem
  afetar locais existentes), espelhando a dívida do `raw_piece` no
  [ADR-0023](ADR-0023-producao-e-pintura-nao-fundicao.md). Produção e sync do
  canal devem ignorar esse `type` — regra a documentar em Estoque (pasta 09) e
  a cobrir por teste.
- A liberação **precisa propagar o custo médio** da origem (`transfer_in` com
  `unit_cost = avg_cost` da Quarentena). Hoje o `RecordMovementService` só
  propaga custo no `transfer_in` se o chamador passar `unit_cost`; passar `null`
  zeraria o custo da peça crua no Ateliê. Cobrir por teste no Gate 03.
- O comentário da migration de `drying_days` ("insumo do planejamento de
  produção") ficou defasado — agora alimenta a **previsão de liberação** da
  quarentena (`received_at + drying_days`); follow-up de código, sem efeito de
  esquema.
- A liberação automática por data, se ligada, pode soltar peça ainda úmida em
  clima ruim — por isso o default é manual.

**Gatilhos de revisão:**
- Necessidade de rastrear **estado × local físico** simultaneamente (ex.:
  quarentena numa loja E num depósito distintos) → migrar para a dimensão de
  estado (Alternativa A) via expand/contract.
- Exigência de rastreio por **lote** ao longo de toda a vida da peça (atacado,
  recall) → adotar a extensão `batch` do [ADR-0008](ADR-0008-ledger-estoque.md).
- Se a operação passar a comprar **só peça seca** (a premissa "nem sempre
  secas" deixar de valer) → a quarentena vira opcional; não se apaga o
  modelo, desliga-se o passo.
