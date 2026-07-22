# 07 — Pedidos

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** sales-specialist / financial-specialist
> **Regras relacionadas:** BR-303 (máquina de estados), BR-304 (import Woo), BR-306..BR-308 (entrega/encomenda/pagamento)

## 1. Objetivo

Inventariar o histórico de pedidos do WooCommerce — volume, período, status, valores, itens, sazonalidade e inconsistências — dimensionando a migração de histórico e informando o desenho de Vendas.

## 2. Onde os pedidos moram

Os pedidos estão no **armazenamento legado por posts** (`post_type=shop_order` + `postmeta` + `woocommerce_order_items`/`itemmeta`). **HPOS está desligado** — `wc_orders` tem **0 linhas**. As tabelas de analytics (`wc_order_stats`, `wc_order_product_lookup`) estão populadas e foram usadas para cruzamento.

## 3. Volume e status

| Status | Pedidos (`posts`) | `wc_order_stats` |
|---|---:|---:|
| Concluído (`wc-completed`) | 69 | 70 |
| Cancelado (`wc-cancelled`) | 12 | 12 |
| Malsucedido (`wc-failed`) | 2 | 2 |
| Reembolsado (`wc-refunded`) | 2 | 4* |
| **Total de pedidos** | **85** | — |
| Registros de reembolso (`shop_order_refund`) | 3 | — |

\* A diferença (69 vs 70 concluídos; 2 vs 4 reembolsados) vem de o analytics do WooCommerce **contar um registro de reembolso como "completed"** — ver anomalia do pedido 2907 em [12](12-qualidade-dados.md). É quirk conhecido do `wc_order_stats`, não corrupção.

## 4. Período e volume no tempo

- **Primeiro pedido:** 2021-11-26 · **Último:** 2026-05-25 → **~4,5 anos** de operação online.
- Volume **muito baixo**: tipicamente **1 a 3 pedidos/mês**.

| Ano | Pedidos concluídos | Receita concluída | Ticket |
|---|---:|---:|---:|
| 2021 | 8 | R$ 597,61 | R$ 74,70 |
| 2022 | 10 | R$ 1.562,40 | R$ 156,24 |
| 2023 | 23 | R$ 2.424,41 | R$ 105,41 |
| 2024 | 17 | R$ 2.852,22 | R$ 167,78 |
| 2025 | 9 | R$ 1.325,79 | R$ 147,31 |
| 2026 (parcial) | 3 | R$ 414,00 | R$ 138,00 |
| **Total** | **70** | **R$ 9.176,43** | **R$ 131,09** |

## 5. Valores (financeiro do canal online)

| Métrica | Valor |
|---|---:|
| Receita concluída (toda a vida) | **R$ 9.176,43** |
| **Ticket médio (concluído)** | **R$ 131,09** |
| Maior venda **concluída** | R$ 384,50 |
| Menor venda concluída | −R$ 16,19 (anomalia — pedido 2907, reembolso total) |
| Itens por pedido (média) | 1,61 (mín 0*, máx 7) |

\* Um pedido concluído com **0 itens** (2907) — inconsistente, ver [12](12-qualidade-dados.md).

### 5.1 O paradoxo dos cancelados

| Status | Pedidos | Ticket médio | Maior |
|---|---:|---:|---:|
| Concluído | 70 | R$ 131,09 | R$ 384,50 |
| **Cancelado** | 12 | **R$ 817,37** | **R$ 3.880,00** |

Os **pedidos cancelados têm ticket ~6× maior** que os concluídos, incluindo **a maior transação de toda a base (R$ 3.880)**, que foi **cancelada**. Interpretações prováveis: tentativas de **compra grande/atacado** que não se concretizaram no site, pedidos de teste, ou falha de pagamento em valores altos. **Vale investigar com o negócio** ([98](98-perguntas-para-o-negocio.md)) — pode indicar demanda de atacado reprimida no canal online.

## 6. Sazonalidade

Picos observados: **nov/2021** (9 — provável lançamento), **maio/2023** (10) e **out–nov/2024**. **Maio** casa com o **Dia das Mães** (categoria homônima, [03](03-categorias.md)). Confirma sazonalidade de datas comemorativas ([30-Domínio](../30-Dominio-da-Dona-Arteira/README.md)) e a diretriz de **evitar cutover em pico**.

## 7. Itens, produtos e categorias vendidas

- **Top produtos** (qtd): "Kit buda infantil da alegria 20 cm + trio de budas 7 cm" (10), "Incensário vareta Tutancâmon 10 cm" (5), "Trio de monges infantis 7 cm" (4).
- **Top categorias** (qtd, todos status): Trio da Sabedoria (22), ARTE SACRA (22), KITS (19), BUDAS (18), INCENSÁRIOS (15). *(Receita por categoria fica distorcida pelo pedido cancelado de R$ 3.880 em BUDAS/KITS — usar quantidade como referência.)*
- **Kits e trios dominam as vendas**, coerente com os 213 compostos do catálogo ([02](02-produtos.md)).

## 8. Vínculo com cliente e cupom

- **Todos os 85 pedidos** têm `_customer_user` preenchido → **não há checkout de convidado**; todo pedido está atrelado a uma conta (o checkout FunnelKit cria a conta). Isso simplifica o casamento de cliente na migração.
- Único cupom usado: **`primeiracompra`** (10% na primeira compra), em **19 pedidos** — ver [08](08-formas-pagamento.md).

## 9. Pedidos inconsistentes (resumo)

| Anomalia | Qtde | Detalhe |
|---|---:|---|
| Concluído com total ≤ 0 e 0 itens | 1 | Pedido 2907 (reembolso total registrado como pedido) |
| Concluído sem itens | 1 | idem |
| Linhas de item → produto excluído | 3 | Produtos removidos do catálogo, mantidos no histórico |
| Divergência posts × analytics | 1 | 69 vs 70 concluídos (quirk do `wc_order_stats`) |

Detalhamento completo em [12-qualidade-dados.md](12-qualidade-dados.md).

## 10. Impacto no ERP / migração

- Migrar **histórico** com status mapeado ([16-WooCommerce/01](../16-WooCommerce/01-mapeamento-de-campos.md), [BR-304](../01-Regras-de-Negocio/01-registro-de-regras.md)); `completed` histórico → **Entregue**.
- **Casamento de itens por `product_id`** (não há SKU) — atenção às 3 linhas com produto excluído.
- O baixíssimo volume torna o canal online **operacionalmente simples**; o desafio de Vendas está no **offline** (balcão/atacado), fora deste dump.
