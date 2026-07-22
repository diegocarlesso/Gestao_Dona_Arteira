# Catálogo de Eventos de Domínio

> **Status:** Em revisão · **Última atualização:** 2026-07-03 · **Responsável:** chief-architect

## 1. Objetivo

Nomear os fatos de negócio que o sistema anuncia internamente. Eventos desacoplam módulos (Vendas não conhece WooCommerce; anuncia `OrderConfirmed` e a Integração reage), alimentam auditoria e preparam integrações futuras sem retrabalho.

## 2. Convenções

- Nome em inglês, passado, com payload mínimo (IDs + dados imutáveis do fato). Classe em `App\Modules\<Contexto>\Events`.
- Eventos são **fatos**: nunca falham, nunca são "cancelados" — o que muda o estado é um novo evento.
- Listeners assíncronos (fila) por padrão; síncronos apenas quando a consistência exigir (documentar no módulo).
- Todo evento é registrado na trilha de auditoria (pasta 26).

## 3. Catálogo inicial

| Evento | Contexto | Disparado quando | Consumidores previstos |
|---|---|---|---|
| `ProductCreated` / `ProductUpdated` / `ProductArchived` | Catálogo | mutação de produto | Sync Woo (push), Auditoria |
| `PriceChanged` | Catálogo | alteração de lista de preço | Sync Woo, Auditoria |
| `ProductionOrderCreated` | Produção | OP aberta | Dashboard, Auditoria |
| `ProductionStageCompleted` | Produção | etapa concluída (fundição, secagem…) | Dashboard WIP |
| `ProductionLossRegistered` | Produção | perda/quebra apontada | Relatórios de perdas |
| `ProductionOrderCompleted` | Produção | CQ aprovado, OP encerrada | **Estoque** (entrada PA), Custos |
| `StockMovementRecorded` | Estoque | qualquer movimento no ledger | Saldos, Sync Woo (estoque), Auditoria |
| `StockReserved` / `StockReleased` | Estoque | reserva criada/liberada | Sync Woo (disponível) |
| `StockBelowMinimum` | Estoque | saldo < mínimo configurado | Notificações, Sugestão de OP/compra |
| `OrderPlaced` | Vendas | pedido criado (qualquer canal) | Estoque (reserva), Financeiro |
| `OrderConfirmed` / `OrderPaid` | Vendas | transições de status | Financeiro (título), Produção (encomenda) |
| `OrderShipped` | Vendas | expedição concluída | Sync Woo (status+rastreio), Notificação cliente |
| `OrderCancelled` | Vendas | cancelamento | Estoque (libera reserva), Financeiro (estorno), Sync Woo |
| `PurchaseReceived` | Compras | recebimento conferido | Estoque (entrada MP), Financeiro (payable), Custo médio |
| `ReceivableSettled` / `PayableSettled` | Financeiro | baixa de título | Fluxo de caixa, Dashboard |
| `InvoiceAuthorized` | Fiscal | NF-e autorizada pela SEFAZ | Vendas (libera expedição, BR-309), E-mail XML/DANFE, Guarda |
| `InvoiceRejected` / `InvoiceCancelled` | Fiscal | rejeição/cancelamento | Alertas, Vendas |
| `IntegrationSyncFailed` | Integrações | job esgotou retries | Alertas, Painel de integrações |
| `UserLoggedIn` / `PermissionDenied` | IAM | segurança | Auditoria de segurança |

## 4. Boas práticas

- Payload carrega **IDs e valores do fato** (ex.: `OrderShipped { order_id, tracking_code, shipped_at }`), nunca o modelo inteiro — listeners buscam o que precisarem.
- Versionar evento se o payload mudar de forma incompatível (`OrderShippedV2`) — barato no monolito, essencial se um dia virar mensageria externa.
- Testes de módulo verificam que o evento é emitido (fato), separadamente dos testes dos listeners (reação).

## 5. Riscos

| Risco | Mitigação |
|---|---|
| Lógica crítica escondida em listeners assíncronos (difícil de raciocinar) | Consistência forte fica no Service; listener só faz efeito colateral re-executável |
| Fila indisponível atrasar sincronizações | Monitoramento de fila (pasta 24) + BR-705 (operação local nunca bloqueia) |
