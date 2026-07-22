# 04 — Atributos

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** woocommerce-specialist
> **Regras relacionadas:** BR-002, BR-004/005 (dimensões), kits → BR-101..BR-108 (produção/BOM)

## 1. Objetivo

Inventariar os atributos globais do WooCommerce e como eles estruturam as variações e os kits — insumo para o modelo de produto do ERP (variações, cor/tamanho) e para a ficha técnica (BOM) dos compostos.

## 2. Atributos globais (`woocommerce_attribute_taxonomies`)

| Rótulo | Taxonomia | Tipo | Termos | Associações | Papel |
|---|---|---|---:|---:|---|
| **Cor** | `pa_cor` | colorpicker | 39 | 830 | Eixo principal de variação/acabamento |
| **Altura** | `pa_altura` | label | 7 | 585 | Faixa de tamanho |
| **QUAIS PEÇAS?** | `pa_peca-kit-1` | label | 13 | 59 | **Composição de kit/trio** |
| COR DETALHES | `pa_cor-detalhes-1312-1` | colorpicker | 3 | 3 | Detalhe de cor (residual) |

> Há também taxonomias não-Woo no dump (ex.: `yith_product_brand` com 1 termo, `category`/`monsterinsights_note_category` do WP/plugins) sem uso relevante para o catálogo.

## 3. Cor (`pa_cor`) — o eixo do acabamento

39 cores; a cor descreve o **acabamento de pintura manual** da peça — ligação direta com a etapa `painting` da produção. Mais usadas:

| Cor | Usos | | Cor | Usos |
|---|---:|---|---|---:|
| Azul | 108 | | Preto | 49 |
| Branco | 98 | | Amarelo | 48 |
| Dourado | 96 | | Verde | 42 |
| Rosa | 66 | | Cobre | 24 |
| Vermelho | 66 | | Lilás | 22 |

Cauda longa até cores com 1 uso (Ameixa) e **2 cores sem nenhum uso** (`Nude`, `Rosa ciclame`) — candidatas a limpeza.

**Leitura de produção:** a paleta é vocabulário do ateliê (ex.: "bronze velho", "marsala dourada", "verde alecrim"). Deve alimentar o [17-glossário-extraído](17-glossario-extraido.md) e, no futuro, a ficha técnica de pintura (tintas por cor).

## 4. Altura (`pa_altura`) — faixas de tamanho

| Faixa | Produtos |
|---|---:|
| 11 a 20 cm | 285 |
| 21 a 30 cm | 172 |
| 1 a 10 cm | 77 |
| 31 a 40 cm | 39 |
| 41 a 50 cm | 6 |
| 51 a 60 cm | 5 |
| 61 a 70 cm | 1 |

A altura é registrada como **faixa** (atributo de navegação), enquanto a **dimensão real** (A×L×P) vive no `postmeta` (`_height` etc.). São dois usos diferentes do mesmo conceito — no ERP, a dimensão real é dado da peça/embalagem ([BR-004/005](../01-Regras-de-Negocio/01-registro-de-regras.md)); a "faixa" é filtro de vitrine.

## 5. Composição de kit (`pa_peca-kit-1` — "QUAIS PEÇAS?")

Este atributo (13 termos) descreve **quais peças compõem um kit/trio**. É a evidência mais forte, no site, de que **produtos compostos existem e têm uma "receita"** — precursora informal da **ficha técnica/BOM** ([08-Produção](../08-Producao/README.md), glossário `BOM`). Combinado com os **213 produtos em KITS/TRIOS** ([02](02-produtos.md), [03](03-categorias.md)), confirma que composição é parte central do domínio.

> ⚠️ O atributo lista peças como **texto livre de vitrine**, não como vínculo estruturado produto-componente. A composição real (quantas unidades de cada peça) **não está estruturada** no site — precisará ser levantada com a produção. Ver [98](98-perguntas-para-o-negocio.md), tema Produção.

## 6. Variações

- 39 produtos variáveis geram 77 variações (máx. 4 por produto). Eixos: **Cor** e, secundariamente, **Altura**.
- Nenhuma variação tem SKU (0/77) — mesma lacuna dos produtos-pai ([02](02-produtos.md)).
- Muitos produtos que **deveriam** ser variações de cor foram cadastrados como **produtos separados** (ver duplicatas em [02](02-produtos.md) §7) — inconsistência de modelagem do catálogo.

## 7. Impacto no ERP

- Modelar **Cor** e **Tamanho/Altura** como atributos de variação canônicos; normalizar a paleta (39 cores, remover as sem uso).
- Tratar **kit/trio** como produto com **BOM** (componentes + quantidades), migrando `pa_peca-kit-1` como ponto de partida a validar.
- Separar "faixa de altura" (filtro) de "dimensão real" (frete/produção).
