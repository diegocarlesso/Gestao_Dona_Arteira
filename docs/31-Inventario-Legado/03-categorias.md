# 03 — Categorias

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** woocommerce-specialist
> **Regras relacionadas:** BR-007 (árvore de categorias espelhada do Woo)

## 1. Objetivo

Inventariar a taxonomia de categorias de produto (`product_cat`) — estrutura, hierarquia e problemas — insumo para a migração da árvore de categorias ([BR-007](../01-Regras-de-Negocio/01-registro-de-regras.md)) e para o catálogo do ERP.

## 2. Números

- **48 categorias** de produto (`product_cat`), hierárquicas (com `parent`).
- **9 tags** (`product_tag`).
- 1.208 associações produto↔categoria em 716 produtos → **média ~1,7 categorias por produto** (produtos aparecem em várias categorias, inclusive de merchandising).

## 3. Categorias de topo (parent = 0)

| Categoria | Produtos | Natureza |
|---|---:|---|
| ARTE SACRA | 129 | Linha (sacra/religiosa) |
| BUDAS | 119 | Linha |
| INCENSÁRIOS | 78 | Linha |
| **DIA DAS MÃES** | 62 | ⚠️ **Sazonal/merchandising** (mesmos produtos cruzados) |
| GANESHAS | 42 | Linha |
| AFRICANAS | 37 | Linha |
| ELEFANTES | 36 | Linha |
| KITS | 33 | Composto (bundle) |
| TRIOS | 14 | Composto (bundle) |
| ORIXÁS | 7 | Linha |
| **MDF** | 5 | ⚠️ **Material diferente** (não é gesso) |
| **HOME SITE** | 4 | ⚠️ **Merchandising de vitrine** (blocos da home) |
| DEUSES | 3 | Linha |
| ESCULTURAS | 2 | Linha |
| ANIMAIS | 1 | Linha |
| INCENSOS | 0 | Guarda-chuva de revenda (filhos têm produtos) |
| SEM CATEGORIA | 0 | Uncategorized (vazia — bom sinal) |

## 4. Subcategorias relevantes (mostram a estrutura real)

- **BUDAS** → Buda Sidarta (60), Buda da Alegria (29)
- **TRIOS** → Trio da Sabedoria (91), Trio de Sidartas (20), Trio de Ganeshas (16), Trio da Alegria (15), Trio da Felicidade (14), Trio Trabalhador (13), Trio de Filós (10), Trio de Budas (2), Trio de Corujas da Sabedoria (1)
- **GANESHAS** → Ganesha Infantil (24), Busto de Ganesha (5), Ganesha com Livro (4), Ganesha da Prosperidade (3), Ganesha da Fortuna (2), Ganesha sem Coroa (1)
- **INCENSÁRIOS** → Incensários Cascata (42), Incensários Vareta (39), Queimador Palo Santo (2)
- **INCENSOS** (revenda) → Incensos Vareta (22), Incensos Cone (13)
- **ARTE SACRA** → Imagens Realistas (77), Imagens Infantis (43) → Anjinhos (17)
- **MDF** → Escapulário de Porta (20), Oração Santo Anjo (7)
- **HOME SITE** (vitrine) → Home Arte Sacra (16), Home Kits (11), Home Trios Da Sabedoria (9), Home Lançamentos (8)

> A subcategoria mais povoada de todas é **Trio da Sabedoria (91)** — o campeão de sortimento é um **produto composto**.

## 5. Problemas identificados

1. **Merchandising misturado à taxonomia.** Categorias como `HOME SITE` (+ 4 subcategorias "Home ...") e `DIA DAS MÃES` **não são categorias de produto** — são blocos de vitrine da home e campanha sazonal. Elas inflam contagens (produtos cruzados) e poluem a árvore. No ERP, isso deve ser modelado como **coleções/vitrines** ou **campanhas**, separado da categoria canônica do produto.
2. **Material embutido na categoria.** `MDF` denota material, não tema. Como há também revenda (`INCENSOS`), a "categoria" hoje mistura **tema, material e origem**. O ERP precisa de dimensões separadas: categoria temática + `kind`/material + origem (própria/revenda).
3. **Categoria guarda-chuva vazia com filhos povoados** (`INCENSOS` count 0 mas filhos com 35 produtos) — normal no WooCommerce, mas exige atenção no espelhamento.
4. **`SEM CATEGORIA` existe mas está vazia** — bom: nenhum produto ficou sem classificação.

## 6. Sazonalidade evidenciada

`DIA DAS MÃES` (62 produtos) é a marca de **sazonalidade de campanha**. Cruzando com o calendário de pedidos ([07](07-pedidos.md)), há picos em **maio** (mês do Dia das Mães no Brasil). Isso confirma a hipótese de sazonalidade da pasta [30-Domínio](../30-Dominio-da-Dona-Arteira/README.md) e reforça a regra de **não fazer cutover em época de pico**.

## 7. Impacto na migração

- Espelhar a árvore `product_cat` com `parent` preservado ([BR-007](../01-Regras-de-Negocio/01-registro-de-regras.md)), **exceto** as categorias de merchandising/sazonais, que devem ser reclassificadas (sugestão em [15](15-recomendacoes.md)).
- Decidir com o negócio a categoria canônica de produtos que hoje estão em várias categorias temáticas + de vitrine. Ver [98](98-perguntas-para-o-negocio.md).
