---
name: sync-pedidos
description: Implementa/ajusta o fluxo de pedidos Woo→ERP (webhook, criação com reserva de estoque, mapa de status) e o retorno ERP→Woo (status de fulfillment + rastreio). Use para tudo do ciclo de pedidos entre site e ERP — o caminho crítico da integração.
---

# Skill: Sincronização de Pedidos

## Objetivo
Pedido pago no site vira pedido operável no ERP (com cliente resolvido, itens casados por SKU e estoque reservado) em < 2 min; avanço do fulfillment no ERP devolve status e rastreio ao site.

## Pré-requisitos
1. Integração base + sync-clientes operantes (cliente é resolvido durante a importação do pedido).
2. De-para de status COMPLETO para os status ativos da loja (docs/16/01 §Status — inventariar antes).
3. Módulo Sales com máquina de estados (BR-303) e reservas (BR-203) funcionando.

## Entradas
Webhooks `order.created/updated`; eventos ERP `OrderShipped/OrderCancelled`.

## Fluxo — Woo→ERP
1. Webhook persistido bruto → 200 → job `ImportOrderFromWoo` (dedupe por order id + delivery id — BR-703).
2. Importar apenas status ≥ `processing` (configurável); `pending` ignorado até pagar.
3. Montagem: cliente via resolução da skill sync-clientes; itens casados por SKU — SKU desconhecido vira item não-mapeado + alerta (venda NUNCA se perde); totais conferidos ao centavo com o payload (divergência = rejeição triável).
4. Pedido criado `channel=woocommerce`, status mapeado, **reserva imediata** (BR-203); payment registrado conforme gateway (taxa → despesa, docs/12).
5. `order.updated`: só transições relevantes (cancelamento/reembolso no site → cancela no ERP liberando reserva, com regra de "quem vence" da doc 16 §3); itens/valores de pedido já importado NÃO mudam (BR-304) — divergência vira alerta manual.
6. Pedido importado é imutável no ERP exceto fulfillment (BR-304).

## Fluxo — ERP→Woo
7. `OrderShipped` → job atualiza Woo: status `completed` + rastreio (campo/plugin definido na doc 16/01); `OrderCancelled` iniciado no ERP → `cancelled` + nota.
8. Anti-eco: update de status vindo do próprio ERP não reprocessa.

## Saídas
Jobs de importação/retorno + de-para de status versionado + alertas de borda + testes.

## Critérios mínimos
Idempotência total (webhook duplicado = 1 pedido); reserva na importação; totais ao centavo; nenhum pedido perdido (não-mapeado com alerta em vez de descarte).

## Checklist final
- [ ] Dedupe testado (2 webhooks = 1 pedido)?
- [ ] Reserva criada na importação e liberada no cancelamento (ambos os sentidos)?
- [ ] SKU desconhecido → item não-mapeado + alerta (teste)?
- [ ] Totais conferidos com Money ao centavo?
- [ ] De-para cobre todos os status ativos + reembolso parcial documentado?
- [ ] Rastreio chega ao cliente no formato do site (plugin correto)?
