# 16 — Integração WooCommerce

> **Status:** Em revisão · **Última atualização:** 2026-07-29 · **Responsável:** woocommerce-specialist
> **Regras:** BR-204, BR-304, BR-701…BR-705 · **Fase:** Gate 02 · **Documentos:** [Mapeamento de campos](01-mapeamento-de-campos.md)
> Segue o framework da [pasta 15](../15-Integracoes/README.md); migração inicial é assunto da [pasta 17](../17-Migracao/README.md).

## 1. Objetivo

Manter o e-commerce operando normalmente enquanto o ERP passa a ser o mestre: catálogo, preços e estoque fluem ERP→Woo; pedidos e clientes novos fluem Woo→ERP; status de fulfillment e rastreio voltam ERP→Woo. **Nunca** acesso ao banco do WordPress.

## 2. Contrato técnico

- **API:** WooCommerce REST API v3 (`/wp-json/wc/v3/`), autenticação por consumer key/secret sobre HTTPS. Chaves com escopo read/write guardadas cifradas.
- **Webhooks Woo → ERP:** `order.created`, `order.updated`, `customer.created`, `customer.updated` — assinados (secret HMAC-SHA256 verificado; BR-701).
- **Rate limit:** Woo em shared hosting degrada com rajadas — jobs de push trabalham em lotes de ~20 com espaçamento; reconciliação em horário de baixa (madrugada).
- **Ambiente de teste:** clone staging do WordPress (obrigatório antes do Gate 02 — ver riscos).

## 3. Direções de sincronização e resolução de conflito

| Entidade | Direção | Gatilho | Conflito: quem vence |
|---|---|---|---|
| Produto (dados, preço varejo, imagens*, categorias) | ERP → Woo | eventos `ProductUpdated`/`PriceChanged` | **ERP** (BR-702: edição no wp-admin é proibida por política; reconciliação sobrescreve e alerta) |
| Estoque publicado | ERP → Woo | `StockMovementRecorded`/`StockReserved` | **ERP** (fórmula BR-204: disponível − buffer) |
| Pedido | Woo → ERP | webhook `order.created/updated` | **Woo é a origem do fato** (BR-703); fulfillment depois é do ERP |
| Status de pedido + rastreio | ERP → Woo | `OrderShipped` etc. | ERP |
| Cliente | Woo → ERP (novos) · ERP → Woo (correções) | webhooks / manual | dedupe por e-mail+doc; correções cadastrais: ERP |

\* Imagens conforme ADR-0017 (fase 1: mídia permanece no Woo; ERP guarda referência).

## 4. Fluxo do pedido (o caminho crítico)

```mermaid
sequenceDiagram
    participant W as WooCommerce
    participant WH as ERP webhook endpoint
    participant Q as Fila
    participant S as Sales/Inventory
    W->>WH: order.created (HMAC)
    WH->>WH: verifica assinatura, grava bruto
    WH-->>W: 200 (imediato)
    Q->>Q: job processa (dedupe por order id no mapeamento)
    Q->>S: cria pedido canal=woocommerce<br/>cliente resolvido/dedupe<br/>itens casados por id do Woo
    S->>S: reserva estoque (BR-203)
    S-->>W: (job) estoque atualizado nos produtos afetados
    Note over S: pedido pronto para separação no ERP
```

Casos de borda documentados no [mapeamento](01-mapeamento-de-campos.md): item com id desconhecido (pedido entra com item "não mapeado" + alerta — nunca se perde venda), pedido editado no Woo após importado, reembolso/cancelamento no gateway, cliente convidado (sem conta).

### O que já entrou (2026-07-28): entrada (corte 4) + saída

**Entrada (Woo→ERP, corte 4):** o fluxo acima. **Saída (ERP→Woo):** o
listener `EnviarExpedicaoAoWoo` ouve `OrderShipped` (que a expedição do
corte 3 dispara) e enfileira `PushShipmentToWoo`, que devolve ao site o
status `completed` e o rastreio como **nota ao cliente** (o plugin de
rastreio segue pendência de inventário — a nota é o caminho nativo). Só
pedido do site (guarda de canal); assíncrono (BR-705); **anti-eco** de
graça — o `order.updated` que o Woo dispara de volta cai no `duplicado` do
`ImportWooOrder` (idempotência por id, BR-703). O **cancelamento** no ERP
tem o gêmeo `CancelarNoWoo`/`PushCancellationToWoo` (status `cancelled` +
motivo como nota interna). Os listeners respeitam a fronteira (ADR-0020):
leem o `Event` de Vendas, nunca o model `Order`.

**Entrada `order.updated` — cancelamento do site (sync-pedidos §5):** um
pedido **já importado** que o site cancela/reembolsa é refletido no ERP
(`ImportWooOrder.refletirCancelamento` → `CancelChannelOrderService`), que
libera a reserva. Itens/valores de pedido importado **não** mudam
(BR-304); só o cancelamento é refletido. Casos da regra de "quem vence"
(§3): já cancelado é no-op; **já expedido é conflito** (a peça saiu — vira
alerta, não desfaz; desfazer é devolução). O **anti-eco** fecha aqui: o
cancelamento originado no site entra com `originadoNoCanal`, e o
`CancelarNoWoo` não o empurra de volta. A puxada reconcilia cancelamentos
perdidos pelo webhook (não pula pedido mapeado quando o status é de
cancelamento).

- **Dois gatilhos, um miolo (ADR-0007):** o `WooWebhookController` (grava
  bruto → enfileira `ProcessWooOrder`) e o comando `erp:woo:pull-orders`
  (rede de segurança, filtra por `modified_after`) entregam o mesmo array
  ao `ImportWooOrder`. O webhook é assinado por HMAC-SHA256 (BR-701); a
  puxada usa a chave REST.
- **Casamento por id do Woo, não por SKU** (`integration_mappings`): 716 de
  716 produtos vieram sem SKU. Item sem casar → pedido em **rascunho** +
  pendência anotada; a operação mapeia o produto e a próxima passada
  confirma. Venda nunca se perde.
- **Idempotência por id do pedido** (BR-703/704): o mesmo pedido, venha por
  webhook ou puxada, entra uma vez só.
- **Confirmado, não Pago:** o pedido reserva estoque (BR-203). "Pago" e o
  pagamento esperam o Gate 04 — o status/pagamento do Woo ficam no bruto.
  Antes da contagem física (cutover) não há saldo, então os pedidos entram
  como rascunho e confirmam depois — o mesmo mecanismo do corte 2.
- **Endereço de entrega** fica no bruto (`woo_webhook_events`) para o corte
  3 consumir na expedição, sem nova chamada à API.

## 5. Reconciliação (rede de segurança)

Job diário na madrugada — a última peça do framework de integração, ao lado
do webhook e da puxada (ADR-0007). Compara o que o site tem com o que o ERP
importou e **reporta divergência no painel** de integrações. Não é KPI de
negócio (não passa pela pasta 21): é a rede que pega o que os gatilhos em
tempo real deixaram passar, e o detector de quem editou no wp-admin (BR-702).

**Escopo atual: só pedidos.** A versão original desta seção previa
reconciliar também produtos e estoque por checksum. As duas ficaram de fora
por ora: dependem de publicação ERP→Woo que ainda não existe
(`sync-produtos`/`sync-estoque` não implementados) e, no caso do estoque, de
um saldo confiável que só virá com a contagem pós-ativação — a produção é
majoritariamente sob demanda e hoje não há controle de estoque real (ver
[17](../17-Migracao/README.md)). Reconciliar contra um lado que o ERP não
publica seria comparar com o vazio. Ambas voltam ao escopo quando as
sincronizações de saída existirem e o ERP tiver saldo real.

Duas classes de divergência de pedido, na janela dos últimos 7 dias:

- **Pedido ausente no ERP** — o site tem, o ERP não (webhook perdido: site
  fora do ar, deploy). Woo é a origem do fato do pedido (BR-703), então a
  reconciliação **importa** pelo mesmo `ImportWooOrder` da puxada — a
  correção e o registro no painel saem de graça (vira um evento `order.pulled`,
  idempotente por id).
- **Divergência de valor** — pedido **já importado** cujo **total** mudou no
  Woo depois da importação (alguém editando no wp-admin — BR-702; e BR-304
  congela itens/valores no lado do ERP). Não se auto-corrige: é **alerta de
  investigação**. Divergência recorrente no mesmo pedido = investigar (bug de
  sync ou edição manual no site). O checksum é sobre o **total**, não o
  status: status é coownado (o ERP empurra expedição/cancelamento de volta ao
  site), então um delta de status é comportamento legítimo, não edição
  indevida — e o cancelamento do site já é refletido pela puxada.

A forma de persistir os achados e apresentá-los no painel — e o uso da
coluna `integration_mappings.checksum` como âncora do total congelado —
está no [ADR-0025](../27-ADR/ADR-0025-reconciliacao-pedidos-woo.md).

## 6. Dependências

Chaves REST do Woo e acesso admin (criar webhooks) · Estoque/Vendas/Catálogo operacionais no ERP (Gate 01) · Migração inicial concluída (pasta 17) — a sync começa **depois** do cutover.

## 7. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Plugin do Woo alterar payload/comportamento em update do WP | Alta | Alto | Staging Woo testa updates ANTES da produção; DTOs tipados falham cedo |
| Equipe continuar editando produto no wp-admin | Alta | Médio | BR-702: política + reconciliação sobrescreve + alerta nominal |
| Webhook perdido (site fora, deploy) | Certa (eventual) | Médio | Reconciliação diária + puxada incremental por `modified_after` |
| Estoque divergir no pico (Natal) | Média | Alto | Buffer por produto (BR-204) + sync < 2 min + monitor de fila |
| Woo lento derrubar jobs em rajada | Média | Baixo | Lotes espaçados, retry com backoff |

## 8. Evoluções futuras

- Sync de avaliações/reviews para o ERP (fase 7, CRM).
- Preço promocional agendado ERP→Woo (fase 6).
- Se um dia o e-commerce migrar de plataforma: só o adapter muda — o domínio não sabe que o Woo existe.
