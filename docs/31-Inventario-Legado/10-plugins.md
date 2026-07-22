# 10 — Plugins

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** woocommerce-specialist / integration-specialist

## 1. Objetivo

Inventariar os plugins do WordPress/WooCommerce — ativos e inativos — para entender a operação real do site, mapear pontos de integração e identificar dívida técnica (plugins abandonados com tabelas órfãs).

## 2. Como foi identificado

Lista **ativa** extraída de `options.active_plugins` (**36 plugins**). Plugins **inativos** inferidos por **tabelas que existem no banco mas cujo plugin não está na lista ativa** (rastro deixado por plugins removidos/desativados sem limpeza).

## 3. Plugins ATIVOS (36) — por função

### Núcleo e-commerce / Brasil
| Plugin | Papel |
|---|---|
| `woocommerce` (10.7.0) | Núcleo da loja |
| `woocommerce-extra-checkout-fields-for-brazil` | **Campos BR**: CPF, CNPJ, `persontype`, bairro, número, celular |
| `woocommerce-mercadopago` | **Gateway** PIX/cartão/boleto |
| `wc-pagaleve` | PIX parcelado / BNPL |
| `woo-parcelas-com-e-sem-juros` | Parcelamento |
| `checkout-fees-for-woocommerce` | Taxas por forma de pagamento |
| `woocommerce-correios` | Frete Correios |
| `melhor-envio-cotacao` | **Frete Melhor Envio** (Jadlog/Correios/Loggi) |
| `woofunnels-aero-checkout` | **Checkout customizado** (FunnelKit) |
| `woo-cart-abandonment-recovery` | Recuperação de carrinho |
| `advanced-product-fields-for-woocommerce` (WAPF) | Campos/opções extras de produto (kits/personalização) |
| `advanced-woo-search` | Busca de produtos |
| `wc-ajax-product-filter` | Filtros de catálogo |
| `woocommerce-side-cart-premium` | Mini-carrinho |
| `yith-color-and-label-variations-for-woocommerce` | Variações por cor/rótulo |
| `yith-woocommerce-wishlist` | Lista de desejos |
| `yith-woocommerce-waiting-list-premium` | **Avise-me quando chegar** (back-in-stock) |

### Marketing / SEO / canais
`all-in-one-seo-pack` (SEO) · `facebook-for-woocommerce` (catálogo Facebook/Instagram) · `google-listings-and-ads` (Google Shopping) · `hostinger-reach` (e-mail marketing) · `popup-builder` · `contact-form-7` · `wpforms-lite` · `jetpack`.

### Infra / tema / operação
`litespeed-cache` · `async-javascript` · `wp-mail-smtp` · `wordfence` (segurança) · `hostinger` / `hostinger-ai-assistant` · `admin-menu-editor-pro` · `loco-translate` (traduções) · `ubermenu` (menu) · `lightweight-social-icons` · **`sevensi-functions`** (plugin de **código customizado da agência/desenvolvedor** — atenção: pode conter regras de negócio próprias).

## 4. Plugins INATIVOS (rastro de tabelas órfãs)

Tabelas presentes de plugins **não** ativos — evidência de **rotatividade e experimentação**:

| Rastro (tabelas) | Plugin provável | O que indica |
|---|---|---|
| `yoast_indexable*`, `yoast_*` | **Yoast SEO** | Troca de SEO (hoje usam **AIOSEO**) — dois acervos de SEO |
| `newsletter*` | The Newsletter Plugin | E-mail marketing anterior (hoje Hostinger Reach) |
| `ced_shopee_*`, `ced_lazada_*` | **CedCommerce Shopee/Lazada** | **Tentativa de marketplaces** (Shopee, Lazada) |
| `EWD_OTP_*` | Order Tracking Page | Página de rastreio (desativada) |
| `wpgly_pix_receipts` | Gateway PIX antigo | **PIX anterior ao Mercado Pago** |
| `wdr_rules`, `wdr_order_*` | WooCommerce Discount Rules | Regras de desconto (desativadas) |
| `revslider_*`, `layerslider*` | Revolution/Layer Slider | Sliders de tema |
| `e_events`, `e_submissions*` | Elementor Pro (forms) | Construtor/forms alternativo |
| `bwf_*`, `cartflows_ca_*` | FunnelKit/Autonami/CartFlows | Funis (parte ativa via cart-abandonment) |
| `gla_*` | Google Listings & Ads | (ativo) tabelas de suporte |
| `aws_*` | Advanced Woo Search | (ativo) índice de busca |
| `wfacp_*`, `wf*` | FunnelKit checkout / Wordfence | (ativos) |

> **Leitura estratégica:** o site passou por **experimentação de canais e ferramentas** — marketplaces (Shopee/Lazada), múltiplos SEOs, múltiplos PIX, múltiplos e-mail marketing. Indica um negócio que **testou vários caminhos de venda online** sem consolidar. Oportunidade: o ERP pode **centralizar multicanal** de forma organizada ([13](13-oportunidades.md)).

## 5. Pontos de integração relevantes para o ERP

| Necessidade do ERP | Plugin/fonte no site |
|---|---|
| Sincronizar pedidos/produtos/estoque | WooCommerce REST v3 ([16-WooCommerce](../16-WooCommerce/README.md)) |
| CPF/CNPJ/persontype dos clientes | Extra Checkout Fields for Brazil (`_billing_cpf`/`_cnpj`/`_persontype`) |
| Frete/etiqueta/rastreio | Melhor Envio |
| Pagamentos/baixa | Mercado Pago (+ Pagaleve) |
| Marketing/catálogo externo | Facebook, Google Listings (evolução) |
| Código customizado a auditar | `sevensi-functions` |

## 6. Riscos e recomendações

- **Auditar `sevensi-functions`** antes da migração — pode conter regras de negócio ou ajustes de checkout não documentados. Ver [14](14-riscos.md).
- **Limpar tabelas órfãs** de plugins inativos (não migrar). Ver [12](12-qualidade-dados.md).
- Confirmar com o negócio se **Shopee/Lazada** ainda operam por fora ([98](98-perguntas-para-o-negocio.md), tema Integrações).
