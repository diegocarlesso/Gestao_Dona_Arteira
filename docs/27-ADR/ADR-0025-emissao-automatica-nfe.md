# ADR-0025: Emissão automática de NF-e no fluxo de pedidos — gatilho, módulo Fiscal e gate de produção

> **Status:** Proposto
> **Data:** 2026-08-10 · **Decisores:** chief-architect, nfe-specialist, dono (gate de produção)
> **Módulos afetados:** 10 (Vendas), 13 (Fiscal), 14 (NF-e), 15 (Integrações)

## Contexto

A diretoria priorizou (P0) a emissão de NF-e (já decidida em princípio pelo
[ADR-0009](ADR-0009-emissao-nfe.md)) com um requisito novo: **pedido
recebido via webhook do WooCommerce deve disparar a emissão automaticamente**
— sem passo manual. O [ADR-0007](ADR-0007-sync-assincrona.md) já define o
padrão de sincronização assíncrona, e a entrada de pedidos Woo→ERP já está
implementada e em produção (corte 4, commit `f632835`): todo pedido do site
que reserva estoque vira `Order` confirmado e dispara
`Sales\Events\OrderConfirmed`. O próprio `FulfillmentService::expedir()` já
comenta a lacuna: *"Sem NF-e neste corte. A NF-e antes de expedir (BR-309) é
o Gate 05"* — ou seja, o código já antecipa que esse gate chega junto do
módulo fiscal.

Três pontos, porém, não estão decididos em nenhum ADR aceito:

1. **Como o módulo novo se organiza**: a doc 13 (perfis/regras tributárias)
   e a doc 14 (mecânica de emissão) são responsabilidades diferentes, mas
   isso não implica necessariamente dois módulos Laravel separados.
2. **Em que ponto do ciclo de vida do pedido a emissão é disparada**, e como
   isso se liga ao BR-309 (expedição só ocorre com NF-e autorizada quando a
   operação exigir documento fiscal).
3. **Como conciliar "construir e testar agora" com o fato de
   `docs/13-Fiscal` estar "BLOQUEADO por validação com contador"** — regime,
   NCM por linha, CFOP, CSOSN e até quando a NF-e é exigida (BR-601) são
   hipóteses (💡) não confirmadas. `BR-605` já exige ambiente de
   homologação SEFAZ permanentemente disponível — isso é o que permite
   construir e testar sem esperar o contador, mas emitir de verdade em
   produção com dado tributário não confirmado é risco de autuação/nota
   incorreta que a diretoria não pediu para assumir.

## Decisão

### 1. Módulo único `app/Modules/Fiscal`

Um só módulo Laravel cobre pasta 13 (perfis fiscais) e pasta 14 (emissão):
`TaxProfile`/`ResolveTaxProfile` (perfis) convivem com `FiscalSeries`,
`Invoice` e o gateway de emissão (mecânica). Motivo: acoplamento forte — a
emissão sempre resolve um perfil antes de montar o XML — e volume pequeno
por módulo (o próprio [ADR-0020](ADR-0020-fronteiras-entre-modulos.md) lista
"módulo com menos de três classes por um ano" como gatilho de consolidação;
dois módulos-bebê seria o oposto). `Fiscal` publica
`Contracts\NfeGatewayInterface` (já antecipada pelo
[ADR-0015](ADR-0015-camadas-e-repositorios.md)) para a implementação
concreta (`sped-nfe` hoje; API gerenciada amanhã, se os gatilhos do
ADR-0009 dispararem) ficar atrás da interface.

### 2. Gatilho: evento de domínio de Vendas, não polling nem chamada síncrona

`Fiscal` ouve `Sales\Events\OrderConfirmed` — o mesmo evento que hoje já
libera a reserva de estoque — filtrando `channel === OrderChannel::WooCommerce`
neste corte. Segue o padrão já estabelecido em
`Integrations\WooCommerce\Listeners\EnviarExpedicaoAoWoo`: o listener
enxerga só o **Event** (nunca `Sales\Models\Order`) e delega o trabalho a um
job em fila (`Jobs\EmitNfeForOrder`, mesmo padrão de retry/backoff de
`ProcessWooOrder`) — a emissão nunca trava a resposta do webhook nem a
confirmação do pedido (BR-705). Balcão/atacado entram no mesmo listener
quando BR-601 for validado com o contador; não é uma reabertura desta
decisão, é o mesmo gatilho com o filtro de canal removido.

Como `Fiscal` não pode acessar `Sales\Models\Order` (fronteira do
ADR-0020), Vendas ganha um novo Service público,
`Sales\Services\BuildOrderInvoiceSnapshot`, que devolve um DTO
(`OrderInvoiceSnapshot`) com os primitivos necessários para faturar — mesmo
espírito de `Catalog\DTO\ProductSnapshot`.

### 3. Gate BR-309 em `FulfillmentService::expedir()`

`Fiscal` publica `Events\InvoiceAuthorized` e `Events\InvoiceRejected`.
`Sales` ganha um Listener que grava `nfe_status`/`invoice_authorized_at` no
próprio `Order` (colunas do próprio módulo — Vendas nunca referencia
`Fiscal\Models\Invoice`). `FulfillmentService::expedir()` passa a recusar a
transição Embalado→Expedido se o pedido exigir NF-e e ela não estiver
autorizada — a mesma trava de estado que já existe para as outras
transições (`exigirStatus`), só que olhando `nfe_status` em vez de
`OrderStatus`.

### 4. Ambiente controlado por flag — produção é decisão humana explícita

`config/fiscal.php` replica o padrão já usado em `config/integrations.php`
(`WOO_ENABLED` etc.): `nfe.enabled` e `nfe.environment`
(`homologacao`|`producao`), lidos de `.env`. O pipeline inteiro (gatilho,
job, XML, assinatura, transmissão) funciona igual nos dois ambientes — a
única diferença é o `tpAmb` enviado à SEFAZ. **`nfe.environment` nasce e
permanece `homologacao`** até uma decisão humana explícita e documentada
(runbook de virada) trocar para `producao` — decisão que só deve ser tomada
depois da pauta de validação com o contador
([docs/13-Fiscal/01](../13-Fiscal/01-pauta-validacao-contador.md)) resolvida.
Isso permite que o P0.2/P0.3 sejam construídos e testados ponta a ponta
agora (BR-605 já manda manter homologação sempre disponível), sem emitir
documento fiscal real com dado tributário não confirmado.

### 5. Observabilidade reaproveita o padrão da pasta 15 §5

Painel "Fiscal" análogo ao painel de integrações já existente
(`WooCommerce\Http\Controllers\PanelController`): notas pendentes,
rejeitadas, com motivo e reprocesso manual — mesma expectativa de
observabilidade que toda integração já segue.

## Alternativas consideradas

### Alternativa A — Dois módulos Laravel (`Fiscal` para perfis, `Nfe` para emissão), espelhando as pastas 13/14 uma a uma
**Prós:** mapeamento 1:1 com a documentação; fronteira mais explícita entre "regra tributária" e "mecânica de emissão".
**Contras:** os dois só existem porque um o outro existe — não há caso de uso onde `TaxProfile` é consumido fora da emissão. Módulo com uma responsabilidade e poucas classes é exatamente o cenário que o próprio ADR-0020 pede para consolidar. Mais um `ServiceProvider`, mais uma fronteira `arch()` para manter sem benefício real de isolamento.
**Descartada.**

### Alternativa B — Gatilho síncrono (emitir NF-e dentro da própria transação de `ConfirmOrderService`/`RegisterChannelOrderService`)
**Prós:** simplicidade aparente — um lugar só.
**Contras:** viola BR-705 e o princípio 3 da pasta 15 (síncrono só para consultas interativas curtas); SEFAZ fora do ar travaria a confirmação do pedido e a resposta do webhook; acopla Vendas a Fiscal por chamada direta de Service quando o caso de uso claramente não precisa da mesma transação (a emissão pode — e deve — falhar e ser reprocessada sem desfazer a venda).
**Descartada.**

### Alternativa C — Emitir automaticamente já em produção (`tpAmb=1`) desde o primeiro pedido, confiando nas hipóteses da doc 13
**Prós:** entrega "completa" mais rápida, sem flag extra para gerenciar.
**Contras:** doc 13 está explicitamente bloqueada por validação do contador; NCM por linha de produto, CFOP e CSOSN ainda são hipóteses. Emitir com dado tributário errado é autuação/retrabalho real, não risco técnico reversível como um bug de UI. O custo da flag (uma config a mais) é desprezível perto do risco.
**Descartada** — mantida como Alternativa A do próprio ADR-0009 (API gerenciada) se algum dia o custo de manter as duas transmissões pesar mais que o risco.

## Consequências

**Positivas:**
- Pipeline técnico completo pode ser construído e testado (homologação SEFAZ real, BR-605) sem esperar o contador — o trabalho não fica parado atrás de uma dependência externa de prazo incerto.
- Reaproveita integralmente os padrões já validados em produção (ADR-0007, ADR-0020, o próprio `EnviarExpedicaoAoWoo` como referência de listener) — nenhum mecanismo novo de sincronização.
- A trava de produção é um fato de configuração auditável (`nfe.environment`), não uma promessa de processo.

**Negativas / dívidas assumidas:**
- Enquanto `nfe.environment=homologacao`, o P0.2/P0.3 não entrega valor fiscal real (nenhuma NF-e vale para o cliente) — a diretoria precisa saber que "pronto" aqui é "pronto para virar a chave", não "emitindo notas válidas".
- O gate BR-309 em `FulfillmentService::expedir()` muda um comportamento hoje sem trava — pedidos em fluxo no momento do deploy precisam ser considerados (nenhum pedido "preso" sem poder expedir por falta de nota retroativa).
- Consolidar perfis + emissão no mesmo módulo é uma aposta de escala pequena; se o fiscal crescer (ex.: NFC-e, CT-e), a fronteira interna (`Contracts/`, pastas por responsabilidade dentro do módulo) precisa existir desde já para não virar um módulo "gaveta".

**Gatilhos de revisão:**
- `docs/13-Fiscal` sair de "bloqueado" (contador validar as hipóteses) → decisão humana de virar `nfe.environment=producao`, com runbook próprio (não reabre este ADR, só executa o que ele já previu).
- BR-601 validado por entrevista com critério diferente de "todo pedido Woo" → ajustar o filtro do listener (extensão, não substituição).
- Volume ou complexidade do módulo `Fiscal` justificar separar perfis de emissão em dois módulos → novo ADR que o substitui.
