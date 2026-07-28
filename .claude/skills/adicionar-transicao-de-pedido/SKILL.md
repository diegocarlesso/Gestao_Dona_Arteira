---
name: adicionar-transicao-de-pedido
description: Adiciona uma transição à máquina de estados do pedido (ou de outro agregado com máquina de estados) ponta a ponta — enum, service, evento, alçada, controller, rota, teste e botão na tela. Use ao introduzir um novo passo do ciclo do pedido (ex.: pagar, separar, expedir, entregar, devolver) ou de qualquer agregado com `status` + trilha de transições.
---

# Skill: Adicionar Transição de Estado ao Pedido

## Objetivo

Introduzir um novo estado/ação na máquina do pedido (BR-303) de forma
consistente e completa: a transição só avança da etapa certa, grava a
trilha (com autor), respeita a alçada, dispara evento quando alguém
reage, é testada e aparece na tela. Generaliza para qualquer agregado com
`status` + `*_status_history` (produção, títulos financeiros).

## Princípios (não negociáveis)

1. **A máquina cresce por corte.** Só adicione o estado/transição quando o
   comportamento dele existir — enum com caso que ninguém exercita convida
   código morto. Veja o comentário-guia no `OrderStatus`.
2. **A transição é uma ação explícita**, dona da validação da etapa
   anterior e da gravação da trilha. Nunca `->update(['status' => ...])`
   solto.
3. **Efeito colateral de estoque/dinheiro passa pela via canônica.** Ex.:
   expedir **consome a reserva** por `ReserveStockService.consumirPorReferencia`
   (`sale_shipment` baixa o `on_hand`) — Vendas não toca `StockReservation`
   (ADR-0020). Nunca mexa em saldo direto.
4. **Trilha é append-only** (`order_status_history`): `from_status`,
   `to_status`, `reason?`, `created_by`, `created_at`.

## Passos

### 1. Enum de estado — `app/Modules/Sales/Enums/OrderStatus.php`
- Adicione o `case`, o `label()` (pt-BR) e, se a semântica pedir, um
  helper de agrupamento (ex.: `reservaAtiva()`, `cancelavel()`). Ajuste
  `cancelavel()`/afins se o novo estado muda a regra.

### 2. (Se precisar) Migration de campos do estado — skill `criar-migration`
- Marcos que as telas/relatórios leem sem varrer a trilha (ex.:
  `shipped_at`, `tracking_code`). Nuláveis, **expand-only**. Adicione ao
  `$fillable` e aos `casts()` do `Order`.

### 3. Service da transição — `app/Modules/Sales/Services/` (skill `criar-service`)
- `DB::transaction`; valide o `status` de origem e lance
  `PedidoInvalido::transicaoInvalida($de, $para)` se não for a etapa certa.
- Grave a trilha e faça o efeito colateral pela via canônica.
- **Dispare um Event** (`app/Modules/Sales/Events/`) se outro módulo
  reage (a Integração ouve `OrderShipped` para devolver status ao Woo —
  ver `sync-pedidos`). O Event carrega o `Order`; quem ouve de fora lê só
  os primitivos, nunca importa `Order` (ADR-0020, teste de arquitetura).

### 4. Alçada — `OrderPolicy` (pasta 19)
- Um método por família de ação. Fulfillment usa `fulfillment.execute`,
  distinta de `sales.create`/`sales.cancel`. Se a permissão não existir no
  enum `Permission`, crie-a **e atualize `docs/19` no mesmo PR** (ADR-0011),
  e mapeie a papel no `Role` enum.

### 5. Controller + rota — skills `criar-controller` / pasta 07
- Ação fina: `authorize(...)`, valide o input (rastreio etc.), delegue ao
  Service, `catch (PedidoInvalido|ReservaInvalida)` → `back()->withErrors`.
- Rota `POST pedidos/{order}/<acao>` nomeada, no grupo autenticado.

### 6. Teste Pest — `tests/Feature/Sales/` (skill obrigatória, regra 8)
- Avança da etapa certa; **recusa** de etapa errada (não muda status, não
  toca estoque); efeito colateral (ex.: `sale_shipment` + saldo); Event
  disparado (`Event::fake`); alçada (papel sem permissão → 403).

### 7. Tela — `resources/js/pages/sales/orders/edit.tsx`
- Botão no cabeçalho, visível só quando `pedido.pode_<acao>` (flag de
  status vinda do `edit()`) **e** a flag de permissão. Confirmação via
  `confirm()`; ações com dados (rastreio) ganham seção própria. Atualize o
  `variant` do Badge e exiba `errors.<chave>`.
- Passe do controller `edit()`: as flags `pode_*` (derivadas do status) e
  a flag de permissão.

## Armadilhas

- **Fronteira no listener de fora:** um listener na Integração ouve o
  Event de Vendas e lê `$event->pedido->id/->tracking_code` — **sem**
  `use App\Modules\Sales\Models\Order`. O teste `tests/Architecture/ModulesTest`
  reprova referência a `...\Models`; acesso a propriedade via Event passa.
  Enums e Events de outro módulo **são permitidos**.
- **Idempotência da saída:** empurrar status ao canal e o canal reenviar o
  webhook não pode duplicar — o `ImportWooOrder` já devolve `duplicado`
  para pedido mapeado (anti-eco de graça).
- **Cancelar:** reveja `cancelavel()` — cancelar antes da expedição libera
  a reserva; depois exige devolução (`return_in`), fluxo próprio.

## Checklist final
- [ ] Enum: case + label + helpers de agrupamento ajustados?
- [ ] Service valida a etapa de origem e grava a trilha com autor?
- [ ] Efeito de estoque/dinheiro pela via canônica (nunca saldo direto)?
- [ ] Event disparado se alguém reage? Listener de fora sem `use ...Models`?
- [ ] Alçada na Policy (+ `docs/19` se permissão nova)?
- [ ] Teste: avança, recusa etapa errada, efeito colateral, alçada?
- [ ] Tela: botão por flag de status + permissão; erros exibidos?
- [ ] Suíte + PHPStan + Pint + Prettier/ESLint/tsc verdes?
