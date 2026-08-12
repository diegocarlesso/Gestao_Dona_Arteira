# 20 — Relatórios

> **Status:** Em construção — quatro fichas implementadas · **Última atualização:** 2026-08-12 · **Responsável:** business-analyst
> **Fase:** Gate 06 (essenciais antecipados por módulo) · Dashboards: [pasta 21](../21-Dashboards/README.md)

## 1. Objetivo

Catálogo canônico dos relatórios do ERP e padrões de construção: todo relatório é uma **consulta documentada, testada e exportável** — nunca uma query solta que ninguém sabe explicar.

## 2. Padrões de construção (skill `criar-relatorio`)

1. Cada relatório tem ficha neste catálogo: pergunta de negócio que responde, filtros, colunas, fonte (módulos), permissão, dono.
2. Consulta implementada como classe de leitura dedicada (query object) — sem impacto em telas transacionais; consultas pesadas rodam com limites e são candidatas a snapshot noturno (fase 6).
3. Teste com dataset fixo (factory) que valida totais — relatório errado é pior que sem relatório.
4. Exportação CSV sempre; PDF quando for documento de trabalho (ex.: lista de separação).
5. Datas com visão competência × caixa explícita nos financeiros (pasta 12).

## 3. Catálogo inicial

| Relatório | Pergunta que responde | Módulos | Fase |
|---|---|---|---|
| Vendas por período/canal (sem categoria ainda) 🔧 | quanto vendemos, onde? | Vendas | 2 |
| Curva ABC de produtos | quais peças sustentam o faturamento? | Vendas | 6 |
| Margem por produto | qual peça dá lucro de verdade? (preço − custo médio) | Vendas+Estoque | 6 |
| Encomendas em aberto por data prometida | o que precisa ser produzido primeiro? | Vendas+Produção | 3 |
| Posição de estoque (por tipo/local) | o que temos agora? | Estoque | 1 |
| Extrato de movimentos por produto | por que o saldo está assim? | Estoque | 1 |
| Estoque abaixo do mínimo | o que repor/produzir? | Estoque | 2 |
| Giro de estoque | o que está parado? | Estoque+Vendas | 6 |
| Perdas por etapa/motivo | onde estamos quebrando peças? | Produção | 3 |
| Produtividade por etapa/pessoa | gargalos do ateliê? | Produção | 6 |
| Consumo de MP por período | quanto de peça crua e tinta/verniz consumimos? | Produção+Estoque | 3 |
| Taxa de quebra por fornecedor/lote | qual fornecedor entrega peça que quebra? | Compras/Estoque | 3 |
| Contas a receber/pagar com vencimento (aging) 🔧 | quem nos deve / a quem devemos? | Financeiro | 4 |
| Fluxo de caixa realizado × projetado 🔧 | vamos fechar o mês? | Financeiro | 4 |
| DRE gerencial simplificada | resultado do mês por categoria | Financeiro | 6 |
| NF-e emitidas por período (+ XMLs em lote) | obrigação com contador | Fiscal | 5 |
| Divergências de sincronização | ERP e Woo estão iguais? | Integrações | 2 |
| Pedidos por status (funil operacional) 🔧 | o que está travado? | Vendas | 2 |
| **Cadastro incompleto** | que produtos ainda não podem ser vendidos direito? | Catálogo | 1 |
| **Produtos com nome repetido** | temos a mesma peça cadastrada duas vezes? | Catálogo | 1 |

### 3.1 Cadastro incompleto — por que é relatório, e não só aviso de tela

Pedido do dono em 2026-07-27, durante o saneamento da migração.

A tela de produtos já sinaliza a lacuna item a item (pasta 32 §3.6): sem
peso, sem preço de varejo, sem preço de atacado. Isso serve para quem
está com **aquele** produto aberto — e não serve para quem precisa saber
**quanto falta** e **por onde começar**.

São perguntas diferentes, e a segunda é de relatório: lista filtrável,
com contagem e exportação em CSV, para a equipe dividir o trabalho de
preencher. A migração entrega os números iniciais — 35 produtos sem peso
e 21 sem preço entre os 754 —, mas o relatório continua útil depois dela:
todo produto novo nasce podendo ter lacuna.

Fica na fase 1 junto com o catálogo, e não na 6 com os demais, porque é
disso que a equipe precisa **enquanto** completa a carga inicial.

**Produtos com nome repetido** responde à outra pergunta do saneamento —
os 37 anúncios que o WooCommerce tinha em duplicidade
([17/F3](../17-Migracao/README.md)). Fica na fase 2 porque, terminada a
migração, vira zeladoria: pega o cadastro feito em duplicidade por
distração, que é o mesmo problema chegando por outra porta.

### 3.2 Ficha — Produtos com nome repetido

> **Antecipado para a fase 1 em 2026-07-27.** A ficha previa a fase 2,
> mas o relatório passou a ser **pré-requisito da contagem física**: o
> saldo inicial do estoque nasce de contagem, e uma peça que existe sob
> dois códigos tem o saldo dividido ao acaso entre eles — dentro de um
> ledger imutável ([ADR-0008](../27-ADR/ADR-0008-ledger-estoque.md)),
> onde corrigir depois exige movimento de ajuste com motivo.

| | |
|---|---|
| **Pergunta** | Temos a mesma peça cadastrada duas vezes? |
| **Fonte** | Catálogo (`products`) |
| **Permissão** | `reports.view` para ver; arquivar exige `catalog.manage` |
| **Dono** | Catálogo |

**Definição de "repetido": mesmo `name`, entre produtos ativos.** Três
consequências que a definição carrega de propósito:

- **Só ativos.** Arquivar o repetido faz o grupo sair do relatório
  sozinho — a lista encolhe à medida que o trabalho anda, e chegar a zero
  significa terminado. Um relatório que continuasse mostrando o grupo
  resolvido não teria como sinalizar progresso.
- **Diferença de caixa não separa.** "Buda Sidarta" e "BUDA SIDARTA" caem
  no mesmo grupo — quem cadastra duas vezes por distração raramente
  repete a capitalização. A colação do banco (`utf8mb4_unicode_ci`) faz
  isso no `GROUP BY`, mas **o agrupamento em PHP precisa normalizar
  também**: `Collection::groupBy` compara string do jeito do PHP, que é
  sensível à caixa, e tornaria a separar o que o banco uniu. Consulta em
  duas metades exige que as duas concordem sobre o que é "o mesmo nome".
- **Cor não entra na chave.** Pelo [ADR-0022](../27-ADR/ADR-0022-modelo-de-produto-e-sku.md)
  cada acabamento é produto próprio, e o nome migrado já traz a cor
  (`… — rosa e capuccino`). Duas cores diferentes têm nomes diferentes e
  não formam grupo; o que forma grupo é o mesmo nome, cor incluída.

**Colunas:** código (SKU), nome, cor, categoria, preço de varejo, situação
de cadastro (pendências), imagem. A imagem não é enfeite — com 754 peças
de nomes parecidos, a foto é o que decide qual dos dois fica.

**Sem filtros e sem paginação, por decisão.** O relatório é limitado por
natureza (hoje 14 grupos, 37 produtos) e existe para ser **zerado**, não
navegado. Paginar esconderia o tamanho do trabalho restante, que é
justamente o número que interessa.

**Ação na própria tela:** arquivar o produto repetido, pelo caminho já
existente (`catalog.products.archive`, BR-008 — produto nunca é excluído).
Relatório que aponta o problema e obriga a procurar a tela onde se
resolve é relatório que ninguém usa duas vezes.

### 3.3 Ficha — Vencimentos (a receber/pagar)

> **Implementado em 2026-08-12**, logo depois do núcleo do Financeiro
> (Gate 04) fechar — era o próximo item natural do catálogo: os dados já
> existiam na tela operacional de `/financeiro`, faltava só a consulta
> dedicada, exportável e sem paginação.
>
> Nome do relatório na tela e no menu é **"Vencimentos"**, em português —
> o termo em inglês para isto ("aging") sobrevive só no código
> (`AgingReportController`, `TitlesAgingReport`), que é a parte do
> projeto que fica em inglês por convenção (CLAUDE.md); usuário não vê
> essa palavra em lugar nenhum.

| | |
|---|---|
| **Pergunta** | Quem nos deve? A quem devemos? Há quanto tempo? |
| **Fonte** | Financeiro (`finance_receivables`, `finance_payables`) |
| **Filtros** | Tipo (a receber/a pagar), janela de vencimento (data de/até) |
| **Colunas** | Cliente/Fornecedor, categoria, vencimento, faixa de atraso, saldo aberto |
| **Permissão** | `reports.view` |
| **Dono** | Financeiro |

**Mesma definição de faixa da tela operacional** (`TitleController`) —
1–15/16–30/30+ dias, calculada com `abs()` sobre a diferença de dias
(sem isso todo vencido cairia na primeira faixa, armadilha já corrigida
uma vez na tela e que o relatório repetiria se recalculasse do zero).
Divergir a definição entre os dois lugares seria bug (§5).

**Soma o saldo aberto, não o valor original** — um título com baixa
parcial entra pelo que ainda falta, não pelo total.

**Sem paginação, de propósito**: o relatório existe para ser somado (a
tela de totais por faixa) e exportado inteiro, não navegado página a
página — mesmo raciocínio do relatório de nomes repetidos (§3.2).

**Route:** `GET /financeiro/relatorios/vencimentos` (tela) e
`/financeiro/relatorios/vencimentos/csv` (exportação). Controller:
`Finance\Http\Controllers\AgingReportController`. Consulta:
`Finance\Repositories\TitlesAgingReport`.

### 3.4 Ficha — Fluxo de caixa (exportável)

> **Implementado em 2026-08-12.** A tela `/financeiro/fluxo-de-caixa`
> (Gate 04, `GetCashFlowQuery`) já era relatório de fato — só leitura,
> sem impacto em nenhuma tela transacional (§2, ponto 2). Virou ficha
> formal com a exportação a mais, sem duplicar tela nem consulta.

| | |
|---|---|
| **Pergunta** | Vamos fechar o mês? Quanto entra/sai nos próximos 30/60/90 dias, e quanto já entrou/saiu nos últimos 30/60/90? |
| **Fonte** | Financeiro (`GetCashFlowQuery` — projetado por `due_date`, realizado por `settled_at`) |
| **Filtros** | Nenhum — janelas fixas 30/60/90 dias |
| **Permissão** | Ver a tela é `finance.view`; exportar é `reports.view` (família própria, §5) |
| **Dono** | Financeiro |

**Route:** `GET /financeiro/fluxo-de-caixa/csv`. Controller:
`Finance\Http\Controllers\CashFlowController::export()`.

### 3.5 Ficha — Vendas por período/canal

> **Implementado em 2026-08-12, sem o recorte por categoria** que o
> catálogo original previa — cruzar pedido → item → produto → categoria
> é o mesmo trabalho da Curva ABC (fase 6, ainda não construída) e ficou
> fora desta rodada por escopo, não por esquecimento.

| | |
|---|---|
| **Pergunta** | Quanto vendemos, onde? |
| **Fonte** | Vendas (`orders`) |
| **Filtros** | Período (data de/até, padrão: mês corrente) |
| **Colunas** | Canal, nº de pedidos, total vendido, ticket médio |
| **Permissão** | `reports.view` |
| **Dono** | Vendas |

**"Venda" tem definição canônica** (`SalesByPeriodReport::STATUS_VENDA`):
pedidos confirmados em diante (`confirmed`, `separando`, `embalado`,
`expedido`, `entregue`) — rascunho e cancelado ficam de fora. É a mesma
lista que o painel geral usa (`GetDashboardMetricsQuery`, pasta 21 §2);
divergir entre os dois seria exatamente o risco que o §5 deste documento
já registra.

**Ticket médio** = total ÷ pedidos, calculado com `bcdiv` — nunca
`AVG()`/`SUM()` do SQL sobre coluna monetária (ADR-0013).

**Route:** `GET /relatorios/vendas-por-periodo` (tela) e
`/relatorios/vendas-por-periodo/csv`. Controller: `Sales\Http\
Controllers\SalesByPeriodReportController`. Consulta: `Sales\
Repositories\SalesByPeriodReport`.

### 3.6 Ficha — Funil de pedidos por status

> **Implementado em 2026-08-12.**

| | |
|---|---|
| **Pergunta** | O que está travado, agora? |
| **Fonte** | Vendas (`orders`) |
| **Filtros** | Nenhum — contagem instantânea, todos os status |
| **Colunas** | Status, quantidade de pedidos |
| **Permissão** | `reports.view` |
| **Dono** | Vendas |

**Contagem, não dinheiro** — `COUNT(*)` agrupado por status não tem o
problema de precisão do `sum()` de coluna monetária; a ressalva do
ADR-0013 é sobre valor, não sobre quantidade de linhas.

**Route:** `GET /relatorios/funil-de-pedidos` (tela) e
`/relatorios/funil-de-pedidos/csv`. Controller: `Sales\Http\
Controllers\OrderStatusFunnelReportController`. Consulta: `Sales\
Repositories\OrderStatusFunnelReport`.

### 3.7 Nota sobre `reports.view` e o papel `sales`

A matriz de permissões (pasta 19 §3) **não concede `reports.view` ao
papel `sales`** — decisão já revisada e confirmada pelo dono antes deste
relatório existir (pasta 19 §3.1, "sem célula pendente"). Na prática,
hoje **só `admin`, `finance` e `accountant` enxergam os dois relatórios
de Vendas** que acabaram de ser construídos (§3.5/§3.6) — quem lança o
pedido (papel `sales`) não vê o consolidado por período/canal nem o
funil, mesmo sendo o dado que ele mesmo gera.

Isto é consistente com o desenho já existente (relatório é família de
permissão própria, não herda de `sales.view`/`finance.view`), mas é
plausível que seja um ponto cego agora que Vendas ganhou seu primeiro
relatório — vale confirmar com o dono se o papel `sales` deveria ganhar
`reports.view`, ou se a visão consolidada é deliberadamente reservada a
quem administra o negócio.

## 4. Dependências

Consome todos os módulos; depende de 19 (permissão `reports.view` + recortes por papel) e 22 (testes de totais).

## 5. Riscos

| Risco | Mitigação |
|---|---|
| Relatório pesado degradar a operação | Query objects com índices planejados (pasta 04); limites; snapshot noturno para os pesados |
| Números divergirem entre relatórios ("qual é o certo?") | Definições únicas documentadas na ficha (ex.: "venda = pedido expedido", não "pago") — glossário de métricas na pasta 21 |

## 6. Evoluções futuras

- Agendamento com envio por e-mail (fase 6) · exportação contábil para o contador (fase 5–6) · BI externo somente-leitura via réplica (se VPS, fase 7).
