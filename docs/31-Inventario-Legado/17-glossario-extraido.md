# 17 — Glossário Extraído dos Dados

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** business-analyst / technical-writer
> Complementa o [29-Glossário](../29-Glossario/README.md) com **termos reais do negócio** encontrados no catálogo/pedidos. Sugestão de incorporação em [15-recomendacoes.md](15-recomendacoes.md) (S-05).

## 1. Objetivo

Registrar o vocabulário do domínio como ele aparece nos dados — nomes de linhas de produto, tipos de peça, termos de acabamento e de logística — para que a UI e a documentação usem **os termos do ateliê**.

## 2. Linhas de produto (categorias reais)

| Termo | Significado no negócio |
|---|---|
| **Arte Sacra** | Imagens religiosas/devocionais (santos, sagrada família, divino) — maior linha (129) |
| **Budas** | Figuras de Buda; subdivide em **Buda Sidarta**, **Buda da Alegria**, infantis |
| **Ganeshas** | Figuras de Ganesha (divindade hindu); tem linha **infantil** |
| **Orixás** | Figuras de matriz afro-brasileira |
| **Africanas** | Peças de temática africana |
| **Elefantes** | Elefantes decorativos (ex.: "elefante manto com ramos") |
| **Incensário** | Suporte/queimador de incenso. **Cascata** (incenso "backflow") e **Vareta** (incenso em vareta) |
| **Incensos** | **Revenda** de incenso (vareta/cone) — ex.: marca Goloka. Não é fabricação própria |
| **Queimador Palo Santo** | Suporte para queima de Palo Santo |
| **Escapulário de Porta** | Peça de porta em **MDF** (não gesso) |
| **Anjinhos** | Anjos infantis (sub de Imagens Infantis) |
| **Imagens Realistas / Infantis** | Estilos de acabamento das figuras sacras |

## 3. Produtos compostos

| Termo | Significado |
|---|---|
| **Trio** | Conjunto de 3 peças vendido como um produto (ex.: **Trio da Sabedoria** — 3 monges "não vejo/não ouço/não falo"; Trio de Sidartas, Trio de Ganeshas) |
| **Kit** | Conjunto de peças combinadas (ex.: "Kit buda infantil da alegria 20 cm + trio de budas 7 cm") |
| **"Quais peças?"** (`pa_peca-kit-1`) | Atributo que lista a composição do kit/trio |

> Trios e kits são **~30% do catálogo** e **líderes de venda** — conceito central, não acessório.

## 4. Acabamento (cores) — vocabulário de pintura

Termos de cor usados como **acabamento manual** (não cor pura): "**bronze velho**", "**marsala dourada**", "**verde alecrim**", "**rosê gold**", "**champanhe**", "**tabaco**", "**ouro negro**", "**capuccino**", "**verde água**", "**azul marinho**". Aparecem no título como sufixo ("… - preto e dourado"). São **combinações de acabamento**, insumo da etapa de pintura.

## 5. Tamanho

- Altura sempre no título em **cm** ("… 20 cm") e em **faixas** (`pa_altura`: "11 a 20 cm" etc.).
- "**infantil**" = versão pequena da peça (7–14 cm), muito presente (budas/monges/ganeshas infantis).

## 6. Logística / operação

| Termo | Significado (nos dados) |
|---|---|
| **Retirada / Retirar no local** | Cliente busca no ateliê (Jacutinga) — ~38% dos pedidos |
| **Melhor Envio** | Agregador de frete (Jadlog, Correios, Loggi) |
| **Região Norte Gaúcho** | Zona de entrega local (entorno de Jacutinga/RS) |
| **PIX / Débito e crédito / Boleto e lotérica** | Formas de pagamento via Mercado Pago |
| **Pagaleve** | PIX parcelado |
| **primeiracompra** | Cupom de 10% na primeira compra |

## 7. Materiais / origem

| Termo | Significado |
|---|---|
| **Gesso** | Material principal (fabricação própria) |
| **MDF** | Material de peças de porta (escapulário, oração) — linha distinta |
| **Revenda** | Incensos (vareta/cone) — comprados para revender, não fabricados |

## 8. Termos sugeridos ao glossário canônico

Incluir em [29-Glossário](../29-Glossario/README.md) (ver S-05 em [15](15-recomendacoes.md)): **Incensário (cascata/vareta)**, **Trio**, **Kit**, **Orixá**, **Escapulário**, **Retirada no local**, **Revenda**, **Acabamento (combinação de cores)**, **Linha de produto**, **Peça infantil**.

> Nota de linguagem: os títulos de produto seguem um **padrão implícito** — `<peça> <tamanho> cm - <acabamento>` (ex.: "Trio de budas infantis da sabedoria 7 cm - verde alecrim e bronze velho"). Esse padrão é candidato a **regra de nomenclatura** do catálogo no ERP.
