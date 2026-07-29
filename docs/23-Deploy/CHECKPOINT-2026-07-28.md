# Checkpoint — 2026-07-28

> Ponto de retomada do trabalho. Não é documento canônico de arquitetura — é um "onde paramos e o que fazer a seguir". Substitui o checkpoint de 2026-07-25. Apagar quando o Gate 02 tiver seu próprio acompanhamento.

## Onde chegamos

Sessão muito longa e de entrega pesada. **Toda a superfície de integração
de pedidos do Gate 02 foi construída, testada e publicada em produção** —
e está **dormente até o cutover** (o Woo ainda opera sozinho; nada dispara
contra ele sem o segredo do webhook).

`main` = produção = `ae33424`. Suíte **383 testes Pest**, PHPStan nível 6,
Pint, Prettier, ESLint, tsc, build — tudo verde, e o CI passou o portão
`qualidade` inteiro pela primeira vez (havia dívida pré-existente de Pint,
Prettier e um PHPStan de `PasswordBroker` que foram corrigidos no caminho).

O ciclo completo, em produção e inerte:

```
entrada:  order.created/processing → importa (cliente + itens + reserva)
          order.updated/cancelled  → reflete no ERP (libera reserva)   [anti-eco]
fulfillment: confirmado → separando → embalado → expedido → entregue
saída:    OrderShipped   → status completed + rastreio → Woo
          OrderCancelled → cancelled + motivo → Woo                    [anti-eco]
gatilhos: webhook assinado (HMAC) + puxada (reconciliação)
painel:   /integracoes — eventos, pendências, reprocessar
```

---

## 1. Corte 4 — entrada de pedidos do Woo→ERP

`ImportWooOrder` é o miolo que webhook e puxada compartilham. Decisões que
a realidade obrigou (o de-para da pasta 16 tinha premissa falsa):

- **Casamento por id do Woo, não por SKU.** 716 de 716 produtos vieram sem
  SKU; o que liga item de pedido a produto do ERP é o `integration_mappings`
  (id/variação do Woo → produto local) que a carga do catálogo gravou.
- **Item sem casar ou sem saldo → pedido em rascunho + pendência**, nunca
  venda perdida. Reserva é tudo-ou-nada.
- **"Pago" não entra** (Gate 04); pedido do site entra Confirmado (reserva).
- Fronteira ADR-0020: a Integração fala com Vendas só por Services (ids e
  DTOs), nunca toca `Order`/`Customer`. Serviços novos: `ResolveCustomerService`,
  `RegisterChannelOrderService`, `CancelChannelOrderService` (Vendas).

Gatilhos: `WooWebhookController` (assinatura HMAC BR-701, grava bruto,
enfileira `ProcessWooOrder`) e `erp:woo:pull-orders`.

## 2. Corte 3 — fulfillment

Máquina `Confirmado → Separando → Embalado → Expedido → Entregue`
(`FulfillmentService`). **Expedir é a única etapa que toca estoque:**
consome a reserva (`ReserveStockService.consumirPorReferencia` →
`sale_shipment` baixa o `on_hand`) e dispara `OrderShipped`. Alçada
`fulfillment.execute`. Sem NF-e (Gate 05) e sem Melhor Envio (fase 6):
rastreio/transportadora à mão. Telas de etapa no pedido + seção de
expedição.

## 3. Saída ERP→Woo e entrada `order.updated`

- `OrderShipped → EnviarExpedicaoAoWoo → PushShipmentToWoo`: status
  `completed` + rastreio (nota ao cliente — o plugin de tracking segue
  pendência de inventário).
- `OrderCancelled → CancelarNoWoo → PushCancellationToWoo`: status
  `cancelled` + motivo (nota interna).
- **Entrada `order.updated`**: cancelamento/reembolso do site reflete no
  ERP (`refletirCancelamento`), libera a reserva; já expedido = **conflito**
  (alerta, não desfaz — desfazer é devolução).
- **Anti-eco nos dois sentidos:** o `order.updated` que o Woo ecoa cai no
  `duplicado` do `ImportWooOrder`; e o cancelamento vindo do site entra com
  `OrderCancelled.originadoNoCanal`, que o `CancelarNoWoo` não devolve.

## 4. Painel de integrações — `/integracoes`

Monitor operacional (alçada `integrations.manage`, item no menu): resumo de
`woo_webhook_events` por situação (falha/pendência/rejeitado/na fila), lista
filtrável e **reprocessar** (idempotente — limpa o carimbo e re-enfileira;
pedido já importado volta `duplicado`). É onde a operação vê o que a sync
sinaliza antes/depois do cutover.

## 5. Achado de produção — o backup estava quebrado em silêncio

O primeiro deploy da sessão **abortou certo**: `mysqldump | gzip` gravava um
gzip de **20 bytes** (a armadilha da §3.2 do runbook). Causa: a senha do
banco tem um **`#`**, que no `~/.my.cnf` não-quotado inicia comentário e
**trunca a senha** — o PDO da app (que não lê o arquivo de opções) conectava
normal, então o site funcionava e o backup falhava sem ninguém ver. Quebrou
desde a rotação de senha. **Corrigido de forma durável:** senha entre aspas
no `.my.cnf` (`password="..."`), regenerada a partir do `config()` da app
sem expor a senha. Registrado em [[armadilhas-hostinger-business]].

## 6. Skill nova + nota de teste

- **Skill `adicionar-transicao-de-pedido`** (`.claude/skills/`): o padrão
  ponta a ponta de transição de estado (enum → service → evento → alçada →
  controller → rota → teste → tela), com as pegadinhas de fronteira
  (listener lê o `Event`, não o `Model`) e anti-eco. Criada a pedido do dono
  (usar/gerar skills).
- **`phpunit.xml` fixa `WOO_ENABLED=false`.** O `.env` local força `true`, e
  um teste que expede/cancela pedido do site vazava um push real
  (StrayRequest). Teste que precisa da integração liga com `ligarWoo()`.

**Gates:** 383 Pest, PHPStan nível 6, Pint, Prettier, ESLint, tsc, build,
CI verde.

---

## 7. Por onde continuar

### Falta só operação para o Gate 02 "pedido do site ao rastreio" fechar

O código está todo em produção e dormente. O que falta é **ativar o Woo** —
ação no painel do site + no `.env`, não código:

1. Criar o webhook no WooCommerce (Configurações → Avançado → Webhooks)
   apontando para `https://gestao.donaarteira.com.br/webhooks/woocommerce/orders`,
   tópicos `order.created`/`order.updated`, com uma **chave secreta**.
2. Pôr a **mesma** chave em `WOO_WEBHOOK_SECRET` no `.env` de produção e
   rodar `php artisan config:cache`. Sem isso o webhook **rejeita tudo com
   401** (é o que o mantém inerte hoje). `WOO_ENABLED` já está `true` (do
   catálogo).
3. Só depois do **cutover** (pasta 17) — a sync começa quando o ERP passa a
   ser o mestre. Antes disso, o site opera sozinho.

Fazer isso num **staging Woo** antes da produção (risco da pasta 16 §7).

### Próximo código: reconciliação diária (doc 16 §5)

A última peça do framework de integração: job agendado (madrugada) que
compara ERP×Woo (pedidos dos últimos 7 dias, estoque) por checksum e
sinaliza divergência no painel. Webhook + puxada + painel já existem; a
reconciliação é a rede de segurança que a doc desenha.

### Débito de código conhecido

- **`order.updated` só reflete cancelamento** (BR-304 congela itens/valores);
  mudança de item no site vira alerta manual — ainda não há tela para isso.
- Rastreio ao Woo vai como **nota ao cliente**; se a loja tiver plugin de
  tracking, dá para mapear o campo (pendência de inventário, pasta 16/01).

### Depois: Gate 03 (Produção + Compras)

Depende das **entrevistas da pasta 30** (BRs 1xx) e da premissa já corrigida
(a Dona Arteira **não fabrica** — compra cru e pinta; ADR-0023/0024).
