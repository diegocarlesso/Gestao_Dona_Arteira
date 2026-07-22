# 02 — Produtos (Catálogo)

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** woocommerce-specialist / migration-specialist
> **Regras relacionadas:** BR-002 (SKU), BR-003 (varejo/atacado), BR-004/005 (embalagem/dimensões), BR-007 (categorias), BR-204 (estoque publicado)

## 1. Objetivo

Inventariar o catálogo do WooCommerce: quantos produtos, de que tipos, com que completude de dados, situação de estoque e histórico de vendas — dimensionando o esforço de saneamento para a migração.

## 2. Contagens gerais

| Métrica | Valor |
|---|---:|
| Produtos publicados (`post_type=product`) | **716** |
| — simples (`product_type=simple`) | 677 |
| — variáveis (`product_type=variable`) | 39 |
| Variações (`product_type=variation`) | 77 |
| Produtos **inativos** (rascunho/lixeira/privado) | **0** — todos `publish` |
| Produtos virtuais / baixáveis | **0** — todos físicos |
| Produtos ocultos do catálogo e da busca | 35 |
| Produtos em destaque (`featured`) | 4 |

**Observações-chave:**
- **Não há produtos inativos** no sentido de status: todos os 716 estão publicados. "Inativo" na prática é sinalizado por `outofstock` + exclusão do catálogo (35 produtos).
- Média de ~2 variações por produto variável (77 ÷ 39). O eixo de variação é **Cor** e **Altura** (ver [04](04-atributos.md)).

## 3. Completude de dados (qualidade do cadastro)

| Campo | Faltando | % dos 716 | Nota |
|---|---:|---:|---|
| **SKU** (`_sku`) | **716** | **100%** | 🔴 Nenhum produto tem SKU. Variações também: 0/77. |
| Preço (`min_price`) | 0 | 0% | 🟢 Todos precificados |
| Categoria (`product_cat`) | 0 | 0% | 🟢 Todos categorizados |
| Descrição longa (`post_content`) | 0 | 0% | 🟢 Todos com descrição |
| Imagem destaque (`_thumbnail_id`) | 0 | 0% | 🟢 Todos com imagem |
| Peso (`_weight`) | 46 | 6,4% | 🟡 |
| Comprimento / Largura / Altura | 44 cada | 6,1% | 🟡 |
| Descrição curta (`post_excerpt`) | **707** | **98,7%** | 🟡 Praticamente não usada |

- **Produtos com dados completos de frete** (peso **e** as 3 dimensões): **670 de 716** (93,6%).
- Os 46 sem peso concentram-se em `INCENSÁRIOS` (12), `KITS` (11), `GANESHAS` (6), `ELEFANTES` (2), `BUDAS` (1) — kits (compostos) e lacunas pontuais de cadastro.

### 3.1 O problema do SKU (crítico)

**Nenhum produto ou variação do WooCommerce tem SKU** (`_sku` inexistente em 716/716 produtos e 0/77 variações). Isso contrasta com o **sistema desktop**, onde `pieces.code` é SKU único e obrigatório ([BR-002](../01-Regras-de-Negocio/01-registro-de-regras.md)). Consequências:
- A **chave de casamento produto↔produto** entre Woo, desktop e ERP **não existe** hoje ([16-WooCommerce/01](../16-WooCommerce/01-mapeamento-de-campos.md) já previa "Woo permite SKU vazio → saneamento obrigatório"; o inventário confirma o pior caso: 100% vazio).
- Casamento de itens de pedido por SKU é **impossível** no estado atual — hoje se apoiaria em `product_id` do Woo.
- A migração precisará **gerar SKUs** e reconciliá-los com os `code` do desktop. Ver [14-riscos.md](14-riscos.md) e [15-recomendacoes.md](15-recomendacoes.md).

## 4. Preços

| Métrica (preço de venda, `min_price`) | Valor |
|---|---:|
| Mínimo | R$ 17,99 |
| Máximo | R$ 379,90 |
| Médio | ~R$ 83,13 |

- O WooCommerce guarda **apenas o preço de varejo** — não há preço de atacado no site (o atacado é conceito exclusivo do desktop, [BR-003](../01-Regras-de-Negocio/01-registro-de-regras.md)). Coerente com o de-para da pasta 16 ("preço atacado NÃO vai ao Woo").
- Nenhum produto com preço zero.

## 5. Estoque

| Situação | Valor |
|---|---:|
| `manage_stock = no` (não controla quantidade) | **708** |
| `manage_stock = yes` (controla quantidade) | **8** |
| `stock_status = instock` | 680 |
| `stock_status = outofstock` | 36 |
| `stock_status = onbackorder` | 0 |
| Sem `stock_quantity` numérico | 708 |

**Leitura:** o site **quase não controla estoque por quantidade**. 708 produtos ficam "disponíveis a menos que marcados manualmente como esgotados"; só **8** produtos têm quantidade real (1 a 12 unidades). Ou seja, **o WooCommerce não é fonte confiável de saldo** — o que reforça a recomendação da pasta 17 de fazer **inventário físico no cutover** e a fórmula de publicação [BR-204](../01-Regras-de-Negocio/01-registro-de-regras.md). Ver [09-Estoque](../09-Estoque/README.md).

Os 8 produtos com quantidade: incensários de vareta (Tutancâmon 10, buda sidarta 10) e algumas peças infantis — quantidades de 1 a 12.

## 6. Vendas por produto

| Métrica | Valor |
|---|---:|
| Produtos que **já venderam** ≥1× (`total_sales>0`) | **86** |
| Produtos **nunca vendidos** | **630** (88%) |
| Itens/pedido (média, concluídos) | 1,61 |

- **88% do catálogo nunca vendeu pelo site** — de novo, sintoma de canal de baixo giro (ou catálogo muito maior que a demanda online).
- **Top produtos vendidos** (quantidade, todos status): "Kit buda infantil da alegria 20 cm + trio de budas infantis 7 cm" (10), "Incensário vareta Tutancâmon 10 cm" (5), "Trio de monges infantis da sabedoria 7 cm" (4), "Incensário cascata shukachanchu 21 cm" (3), "Incenso Cone Goloka" (3). Os **kits e trios** lideram — ver [07](07-pedidos.md).

## 7. Produtos duplicados

- **37 produtos** compartilham título com outro, formando **14 grupos de títulos idênticos**. Exemplos: "Incensário cascata buda na lua 12 cm - várias cores" (×5), "Incensário cascata com buda sidarta 11 cm - várias cores" (×4), "Trio de budas infantis da sabedoria 14 cm" (×3, com e sem sufixo de cor).
- Sem SKU, essas duplicatas **não são detectáveis por chave** — só por título/heurística. Parte parece ser **o mesmo produto recriado** em vez de virar variação de cor. Risco de duplicação na migração ([14](14-riscos.md)).

## 8. Composição do catálogo (kits e materiais)

- **213 produtos** estão em `KITS`/`TRIOS` (produtos compostos ≈ **30% do catálogo**). Um "trio"/"kit" é vendido como **uma peça**, mas é montado a partir de peças componentes — relação direta com **ficha técnica/BOM** e o módulo [08-Produção](../08-Producao/README.md). O atributo `pa_peca-kit-1` ("QUAIS PEÇAS?") descreve a composição (ver [04](04-atributos.md)).
- O catálogo mistura **materiais**: gesso (maioria), **MDF** (categoria `MDF`: escapulário de porta, oração Santo Anjo) e **revenda** (incensos vareta/cone — Goloka etc.). Nem tudo é gesso de fabricação própria — relevante para `kind` do produto no ERP e para o Fiscal (NCM diferente). Ver [17](17-glossario-extraido.md).

## 9. Descrições e page-builder

- **652/716 descrições contêm HTML**, mas **nenhuma contém shortcodes** de page-builder (0 ocorrências de `[av_...]` ou de qualquer `[` em `post_content`). Ou seja, a migração de descrições exige **higienização de HTML**, não remoção de shortcodes. O acoplamento ao Avia está no **layout da página**, não no campo de descrição: o `postmeta` é dominado por metadados do Avia Layout Builder (`_avia_*`, `_aviaLayoutBuilder*` — 1.400+ ocorrências cada), que descrevem a **página**, não o dado do produto. Ver [11](11-metadados.md).

## 10. Riscos e perguntas

- Riscos detalhados em [14-riscos.md](14-riscos.md) (SKU ausente, duplicatas, estoque não confiável).
- Perguntas ao negócio em [98-perguntas-para-o-negocio.md](98-perguntas-para-o-negocio.md) (tema Produtos/Produção): critério de kit, quais itens são revenda vs próprios, o catálogo online reflete o catálogo real?
