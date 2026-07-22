# 11 — Metadados

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** migration-specialist / senior-dba

## 1. Objetivo

Inventariar os metadados (chave-valor) do WooCommerce/WordPress — `postmeta`, `usermeta` e meta de pedido — separando o que é **dado de negócio** (migrar) do que é **ruído de plugin/page-builder** (descartar).

## 2. Estrutura EAV do WordPress

O WordPress guarda quase tudo em tabelas **entidade-atributo-valor**: `postmeta` (produtos, pedidos, anexos), `usermeta` (clientes), `termmeta`, `woocommerce_order_itemmeta` (itens de pedido). O `postmeta` tem **76.324 linhas** — a maior parte **não** é dado de produto.

## 3. `postmeta` — chaves mais frequentes

| Chave | Ocorrências | Natureza | Migrar? |
|---|---:|---|---|
| `_avia_sc_parser_state` | 1.487 | Page-builder Avia/Enfold | ❌ Descartar |
| `_av_el_mgr_version` | 1.478 | Avia | ❌ |
| `_avia_builder_shortcode_tree` | 1.451 | Avia (layout da página) | ❌ |
| `_aviaLayoutBuilder_active` / `CleanData` | 1.451 | Avia | ❌ |
| `_wp_attached_file` | 1.002 | Caminho do arquivo de mídia | ✅ (mídia) |
| `_wp_attachment_metadata` | 1.000 | Metadados de imagem | ✅ (mídia) |
| `_price` | 830 | **Preço efetivo** | ✅ |
| `_edit_lock` / `_product_version` | 807 / 793 | Controle interno do WP/Woo | ❌ |
| `_virtual`, `_manage_stock`, `_stock`, `_backorders`, `_sold_individually` | 793 cada | **Config de produto** | ✅ (as de negócio) |
| `total_sales` | 793 | Contador de vendas | ➖ (recomputável) |
| `fb_visibility` | 793 | Facebook plugin | ❌ |

**Leitura:** as **5 chaves mais frequentes são todas do page-builder Avia** — confirmam que o conteúdo das páginas de produto está **acoplado ao Enfold**. Para o ERP interessam poucas chaves de negócio: `_price`/`_regular_price`, `_weight`, `_length/_width/_height`, `_stock_status`, `_manage_stock`, `_thumbnail_id`, `_product_image_gallery`, `_product_attributes`.

## 4. Meta de PEDIDO (`postmeta` em `shop_order`) — dado de negócio

Chaves presentes em **todos os 85 pedidos** (plugin *Extra Checkout Fields for Brazil* + WooCommerce):

| Chave | Uso | Migrar? |
|---|---|---|
| `_billing_first_name` / `_last_name` / `_email` | Identificação | ✅ |
| `_billing_cpf` (85) / `_billing_cnpj` (0) | **Documento** | ✅ |
| `_billing_persontype` (85, todos "1"=PF) | PF/PJ | ✅ |
| `_billing_phone` (62) / `_billing_cellphone` (47) | Contato | ✅ |
| `_billing_postcode` / `_address_1` / `_address_2` (47) / `_number` / `_neighborhood` / `_city` / `_state` / `_country` | **Endereço BR** | ✅ |
| `_shipping_number` / `_shipping_neighborhood` | Endereço de entrega | ✅ |
| `_customer_user` | Vínculo com conta | ✅ |
| `_payment_method` / `_payment_method_title` | Pagamento (higienizar HTML) | ✅ |
| `_order_total` / `_order_tax` / `_cart_discount` | Valores | ✅ |

> O **bairro** (`_neighborhood`) e o **número** separados são particularidade brasileira do plugin — o ERP precisa de endereço estruturado à brasileira (logradouro, número, complemento, bairro, cidade, UF, CEP).

## 5. `usermeta` — clientes

- `SERVMASK_PREFIX_capabilities` → papéis (198 customer, 2 admin).
- `billing_cpf` (~63), `billing_phone` (47), `billing_cellphone` (27) → perfil de cobrança dos compradores reais; os ~136 registrados sem compra **não** têm esses campos.
- Restante do `usermeta` é ruído do WP (sessões, preferências de admin, tokens de plugin).

## 6. Campos nunca usados / candidatos à remoção

| Campo/estrutura | Situação |
|---|---|
| `_sku` (produto/variação) | **Nunca preenchido** (0/716 e 0/77) — não é "remover", é **preencher** na migração |
| `post_excerpt` (descrição curta) | Vazio em 707/716 — praticamente inútil |
| `_billing_cnpj` | Sempre vazio (loja 100% PF) |
| `pa_cor` "Nude", "Rosa ciclame" | Termos de atributo sem uso |
| Meta de page-builder (`_avia_*`, `fb_visibility`, `_wc_average_rating`, `_wc_review_count`) | Ruído — descartar na migração |
| Tabelas de plugins inativos ([10](10-plugins.md)) | Descartar |

## 7. Impacto na migração

- **Extrair um subconjunto pequeno de chaves de negócio**; ignorar o EAV de page-builder/plugins.
- Normalizar meta de pedido em **entidades relacionais** (cliente, endereço, item, pagamento) — o ETL da pasta 17 deve mapear as chaves da §4.
- **Higienizar** valores com HTML (títulos de pagamento; descrições de produto contêm HTML mas **não** shortcodes).
