# 12 — Qualidade dos Dados

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** migration-specialist / senior-dba
> **Regras relacionadas:** BR-706 (migração idempotente) · **ADRs:** ADR-0010 (ETL)

## 1. Objetivo

Relatório de qualidade do dump: duplicidades, campos vazios, inconsistências, dados órfãos, referências quebradas, anomalias, bloat e campos candidatos à remoção — base para o plano de saneamento do ETL.

## 2. Placar geral

| Dimensão | Situação |
|---|---|
| Integridade referencial (postmeta/term_relationships/variações) | 🟢 **Boa** (0 órfãos) |
| Completude de catálogo (preço, categoria, imagem, descrição) | 🟢 **Boa** |
| **SKU** | 🔴 **Ausente (100%)** |
| Controle de estoque no site | 🔴 **Inexistente** (708/716 sem quantidade) |
| Duplicatas de produto (por título) | 🟡 37 produtos / 14 grupos |
| Ruído/bloat (logs, page-builder, plugins mortos) | 🟡 **Alto** (~90% do dump) |
| Anomalias de pedido | 🟡 Pontuais (1 pedido fantasma, 3 linhas órfãs) |

## 3. Integridade referencial (boa)

| Verificação | Resultado |
|---|---:|
| `postmeta` sem post | 0 🟢 |
| `term_relationships` sem taxonomia | 0 🟢 |
| `product_variation` sem produto-pai | 0 🟢 |
| Imagem destaque órfã | 0 🟢 |
| Arquivos de mídia duplicados | 0 🟢 |
| **Linhas de item de pedido → produto excluído** | **3** 🟡 |

As 3 linhas apontam para produtos removidos do catálogo mas presentes no histórico — **esperado** (não se apaga produto que tem venda). Na migração, casar por `product_id` e marcar o item como "produto histórico".

## 4. Campos vazios / nunca usados

| Campo | Vazio | Comentário |
|---|---:|---|
| `_sku` (produtos) | 716/716 | 🔴 Crítico — sem chave de casamento |
| `_sku` (variações) | 77/77 | 🔴 |
| `post_excerpt` (desc. curta) | 707/716 | Praticamente não usado |
| `_billing_cnpj` | 85/85 | Loja 100% PF |
| Peso | 46/716 | 🟡 |
| Dimensões (cada) | 44/716 | 🟡 |

## 5. Duplicidades

- **Produtos:** 37 produtos em 14 grupos de **título idêntico** (ex.: "Incensário cascata buda na lua 12 cm - várias cores" ×5). Sem SKU, indetectáveis por chave; parte é o **mesmo produto recriado** em vez de variação. Risco de duplicação — [14](14-riscos.md).
- **Clientes:** **0 duplicatas** por CPF/e-mail entre compradores (base limpa). Risco real está **entre site e desktop** (não avaliável — desktop não fornecido).
- **Mídia:** 0 arquivos duplicados por caminho.

## 6. Anomalias de pedido

| Anomalia | Detalhe |
|---|---|
| **Pedido 2907** | `wc-completed`, total **−R$ 16,19**, **0 itens**, 2023-05-08. É um **reembolso registrado como pedido** — infla o "completed" no analytics. |
| Divergência 69 × 70 concluídos | `posts` conta 69; `wc_order_stats` conta 70 (inclui o 2907). Quirk conhecido do analytics do WooCommerce, não corrupção. |
| Reembolsados 2 × 4 | `posts` (shop_order `wc-refunded`) = 2; `wc_order_stats` = 4 (conta registros de reembolso). |
| **Cancelados de alto valor** | 12 cancelados com ticket médio R$ 817 e um de **R$ 3.880** — anômalo vs. concluídos (R$ 131). Investigar ([07](07-pedidos.md)). |

## 7. Anomalias estruturais do dump

1. **Prefixo `SERVMASK_PREFIX_`** — não é o prefixo real; é marcador do All-in-One WP Migration. A migração deve tratar isso ao ler o dump.
2. **Instalação `wp_` vestigial** — 6 posts, 1 usuário, 173 options: WordPress padrão residual no mesmo banco. **Não é dado de negócio**; ignorar.
3. **8 tabelas Wordfence não importáveis** na 10.4 (sintaxe MariaDB 11) — irrelevantes (segurança/log).
4. **Duas ferramentas para a mesma função** convivendo (Yoast+AIOSEO; Newsletter+Hostinger Reach; PIX antigo+Mercado Pago) — dívida de plugins.

## 8. Bloat (dados descartáveis)

| Tabela | Linhas | Ação |
|---|---:|---|
| `wpmailsmtp_debug_events` | 205.746 | 🗑️ Descartar (log de e-mail) |
| `actionscheduler_actions` | 20.186 | 🗑️ Descartar (fila) |
| Revisões (`post_type=revision`) | 821 | 🗑️ Descartar |
| `woocommerce_sessions` | 232 | 🗑️ Transitório |
| `cartflows_ca_cart_abandonment` | 80 | ➖ Analítico (opcional) |
| Tabelas de plugins inativos ([10](10-plugins.md)) | — | 🗑️ Não migrar |

**~90% do peso do dump é descartável.** O acervo de negócio real é pequeno e cabe em poucas tabelas.

## 9. Recomendações de saneamento (para o ETL — pasta 17)

1. **Gerar SKUs** e reconciliar com `pieces.code` do desktop (chave de casamento). 🔴 Prioridade 1.
2. **Deduplicar produtos** por título/heurística antes de carregar.
3. **Higienizar** descrições (HTML — sem shortcodes) e títulos de pagamento (HTML).
4. **Não confiar no estoque do site** — inventário físico no cutover ([BR-204](../01-Regras-de-Negocio/01-registro-de-regras.md), pasta 17).
5. **Ignorar** page-builder, logs e plugins mortos.
6. **Tratar duas fontes** (Woo + desktop) com dedupe cruzado por e-mail/CPF ([BR-706](../01-Regras-de-Negocio/01-registro-de-regras.md)).
7. Reconciliar o pedido 2907 e os cancelados de alto valor com o negócio.
