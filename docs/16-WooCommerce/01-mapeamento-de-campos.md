# Mapeamento de Campos ERP ↔ WooCommerce

> **Status:** Rascunho (completar no Gate 02 com inventário real do Woo) · **Última atualização:** 2026-07-03 · **Responsável:** woocommerce-specialist

De-para canônico usado pelos Adapters e pelo importador da migração (pasta 17). Campos do Woo conforme REST API v3.

## Produto

| ERP (`products` + relacionadas) | WooCommerce | Notas |
|---|---|---|
| sku | `sku` | **chave de casamento**; Woo permite SKU vazio → saneamento obrigatório na migração |
| name | `name` | |
| description | `description` (HTML) | ERP guarda HTML sanitizado |
| price varejo (`product_prices.retail`) | `regular_price` | preço atacado NÃO vai ao Woo |
| promo (fase 6) | `sale_price`, datas | |
| estoque publicado | `stock_quantity` + `manage_stock=true` | fórmula BR-204 (disponível − buffer) |
| status active/archived | `status` publish/draft | arquivar no ERP despublica no Woo |
| peso/dimensões (da embalagem padrão — BR-004) | `weight`, `dimensions` | Woo usa isso p/ frete do checkout; enviar dados da **embalagem**, não da peça |
| categorias | `categories[]` | árvore espelhada; mapping por id |
| imagens | `images[]` | ADR-0017: fase 1 mídia fica no Woo |
| `kind` ≠ finished_good/resale | — | MP/embalagem/insumo **nunca** sincronizam |
| variações (se houver no Woo) | `type=variable` + `variations[]` | inventariar antes do Gate 01 (pergunta aberta pasta 04) |

## Cliente

| ERP (`customers`) | WooCommerce | Notas |
|---|---|---|
| name | `first_name + last_name` | concatenação na entrada, split heurístico marcado p/ revisão |
| email | `email` | **chave de dedupe** nº 1 |
| doc (CPF/CNPJ) | meta (plugin brasileiro, ex.: `_billing_cpf`/`_billing_cnpj`) | inventariar qual plugin de checkout há no site; chave de dedupe nº 2 |
| phone | `billing.phone` | |
| endereços | `billing` / `shipping` | viram `customer_addresses` |
| is_wholesale | — | conceito só do ERP |
| convidado (guest checkout) | pedido sem customer id | ERP cria/casa cliente pelo e-mail do pedido |

## Pedido

| ERP (`orders`) | WooCommerce | Notas |
|---|---|---|
| channel | — | fixo `woocommerce` |
| number | `number` (exibição) + `id` (mapping) | ERP mantém numeração própria; nº Woo visível na tela |
| status | `status` | tabela abaixo |
| itens | `line_items[]` | **casados por id do Woo** (`variation_id`, senão `product_id`) via `integration_mappings` — **não por SKU**: 716 de 716 produtos vieram sem SKU (corte 4). Sem casar → pedido entra em rascunho + item na pendência + alerta |
| totais/frete/desconto | `total`, `shipping_total`, `discount_total` | conferência de centavos na importação |
| pagamento | `payment_method_title`, `transaction_id` | vira `payments` + baixa conforme status |
| rastreio (saída) | meta/plugin de rastreio ou nota ao cliente | definir plugin no Gate 02 |

## Status de pedido (de-para)

| WooCommerce | ERP (BR-303) | Observação |
|---|---|---|
| pending | não importa (aguarda pagamento) | importar apenas ≥ processing; `on-hold` configurável |
| on-hold | Confirmado (sem pagamento) | boleto/PIX aguardando |
| processing | **Confirmado** (reserva estoque) | caso principal. *Pago* é o alvo final, mas o ERP não modela pagamento até o Gate 04 (Financeiro) — no corte 4 entra Confirmado; o status/pagamento do Woo ficam no bruto (`woo_webhook_events`) |
| completed | Expedido/Entregue | na migração de histórico: Entregue |
| cancelled / refunded / failed | Cancelado (com estorno se pago) | reembolso parcial: caso de borda documentar no Gate 02 |

## Sentido inverso (ERP → Woo)

| Evento ERP | Ação no Woo |
|---|---|
| OrderShipped | status `completed` + rastreio |
| OrderCancelled (iniciado no ERP) | status `cancelled` + nota |
| Estoque/preço/produto | update nos campos acima |

## Pendências de inventário (Gate 02)

- [ ] Plugin de checkout brasileiro em uso (campos de CPF/CNPJ) — nome exato dos metadados.
- [ ] Uso de produtos variáveis? cupons? assinaturas? — listar plugins ativos.
- [ ] Plugin de frete (Correios/Melhor Envio?) e de rastreio.
- [ ] Gateways ativos e comportamento de refund.
