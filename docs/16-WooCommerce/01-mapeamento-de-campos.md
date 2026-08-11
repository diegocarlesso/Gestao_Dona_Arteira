# Mapeamento de Campos ERP ↔ WooCommerce

> **Status:** Rascunho (completar no Gate 02 com inventário real do Woo) · **Última atualização:** 2026-08-11 · **Responsável:** woocommerce-specialist

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
| name | `first_name + last_name` | recuo: nomes de `billing`; depois `Cliente do site` |
| email | `email` | **chave de dedupe** nº 1; normalizado em minúsculas |
| doc (CPF/CNPJ) | `billing.cpf`/`billing.cnpj` **ou** meta `_billing_cpf`, `billing_cpf`, `_billing_cnpj`, `billing_cnpj` | ✅ nomes confirmados rodando (corte 4 + migração de clientes); chave de dedupe nº 2 |
| phone | `billing.phone` | |
| endereços | `billing` / `shipping` | viram `customer_addresses`; iguais → **um** registro com os dois padrões |
| endereço: número e bairro | meta `_billing_number` / `_billing_neighborhood` (idem `shipping`) | do plugin brasileiro; **nunca** extraídos de `address_1` por heurística |
| is_wholesale | — | conceito só do ERP; sempre `false` no canal (100% PF/varejo) |
| convidado (guest checkout) | pedido sem customer id | ERP cria/casa cliente pelo e-mail do pedido; **não** aparece em `/customers` |
| — (só quem comprou migra) | `orders_count` | critério de rejeição da migração (LGPD) — [pasta 17 § Clientes](../17-Migracao/README.md#clientes-f2f5) |

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
| endereço de entrega/cobrança (`order_addresses`) | `shipping` / `billing` (do **pedido**, não do cliente) | ✅ **implementado em 2026-08-11**: gravado por pedido, não pelo cadastro do cliente — a entrega às vezes não é no endereço do próprio comprador (presente). Mesma tradução de campos que já roda para o cliente (`_billing_number`/`_billing_neighborhood` etc.), aplicada ao bloco do pedido. `BuildOrderInvoiceSnapshot` (Fiscal) usa este endereço para a NF-e antes de cair no endereço padrão do cliente |
| comentário do cliente (`orders.customer_note`) | `customer_note` | ✅ **implementado em 2026-08-11** |
| forma de entrega (`orders.shipping_method`) | `shipping_lines[0].method_title` | ✅ **implementado em 2026-08-11** — texto livre do Woo (ex.: "Loggi Express (Melhor Envio)"), não normalizado; o valor continua em `orders.shipping` |
| `orders.created_at` / `orders.delivered_at` (histórico) | `date_created_gmt` / `date_completed_gmt` (recuo para a variante sem `_gmt`) | ✅ **corrigido em 2026-08-11** — sem isso o Eloquent carimbava `created_at` com o instante da importação: uma puxada histórica fazia todo pedido antigo parecer vendido no dia da carga, e o dashboard (que soma vendas por `created_at`) contava tudo como vendas do mês corrente. Vale para webhook e puxada, não só para `--historico` |
| forma de pagamento (`orders.payment_method`) | `payment_method_title` | ✅ **implementado em 2026-08-11** — texto livre, mesmo tratamento de `shipping_method` |
| `orders.paid_at` | `date_paid_gmt` (recuo para `date_created_gmt`), só quando `status` é `processing` ou `completed` | ✅ **implementado em 2026-08-11** (BR-501) — `on-hold` nunca grava (Woo trata como "aguardando compensação"); é este campo que o Financeiro usa para decidir se o título a receber nasce aberto ou já baixado |

## Status de pedido (de-para)

| WooCommerce | ERP (BR-303) | Observação |
|---|---|---|
| pending | não importa (aguarda pagamento) | importar apenas ≥ processing; `on-hold` configurável |
| on-hold | Confirmado (sem pagamento) | boleto/PIX aguardando |
| processing | **Confirmado** (reserva estoque) | caso principal. *Pago* é o alvo final, mas o ERP não modela pagamento até o Gate 04 (Financeiro) — no corte 4 entra Confirmado; o status/pagamento do Woo ficam no bruto (`woo_webhook_events`) |
| completed | Expedido/Entregue | na migração de histórico: Entregue |
| cancelled / refunded / failed | Cancelado (com estorno se pago) | reembolso parcial: caso de borda documentar no Gate 02 |

> ✅ **`completed` no histórico é rótulo documentário, implementado em
> 2026-08-11.** A entrada corrente (webhook em tempo real,
> `ProcessWooOrder`) **continua** tratando `completed` como hoje —
> `Draft` sem reserva, com pendência anotada (decisão deliberada:
> completed chegando ao vivo normalmente significa que a peça já saiu
> sem passar pelo fulfillment do ERP). Só a **puxada histórica**
> (`erp:woo:pull-orders --historico`) grava `completed` diretamente como
> `Entregue` — e só como rótulo: **não** lança reserva nem movimento de
> baixa no ledger. O Estoque ainda não tem contagem física calibrada
> (P2 não priorizado); lançar baixas retroativas de pedidos que já
> saíram há tempo arriscaria poluir um saldo que ninguém validou ainda.
> `processing`/`on-hold` no histórico continuam pelo caminho atual
> (tenta reservar, cai para `Draft` sem saldo).

## Sentido inverso (ERP → Woo)

| Evento ERP | Ação no Woo |
|---|---|
| OrderShipped | status `completed` + rastreio |
| OrderCancelled (iniciado no ERP) | status `cancelled` + nota |
| Estoque/preço/produto | update nos campos acima |

## Pendências de inventário (Gate 02)

- [x] ~~Plugin de checkout brasileiro em uso (campos de CPF/CNPJ) — nome exato dos metadados.~~ Resolvido **empiricamente**, não por leitura de documentação: o código varre as chaves conhecidas (`_billing_cpf`, `billing_cpf`, `_billing_cnpj`, `billing_cnpj`, `_billing_number`, `_billing_neighborhood`) em vez de fixar uma — o plugin pode registrar o campo no REST ou só no `meta_data`, e ler um lugar só perderia metade dos casos.
- [ ] Uso de produtos variáveis? cupons? assinaturas? — listar plugins ativos.
- [ ] Plugin de frete (Correios/Melhor Envio?) e de rastreio.
- [ ] Gateways ativos e comportamento de refund.
