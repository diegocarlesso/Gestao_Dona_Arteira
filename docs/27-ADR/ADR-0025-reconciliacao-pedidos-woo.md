# ADR-0025: Reconciliação diária de pedidos Woo — âncora de checksum no mapeamento + tabela de achados

> **Status:** Aceito
> **Data:** 2026-07-29 · **Decisores:** chief-architect + dono (aprovado pelo dono em 2026-07-29)
> **Módulos afetados:** docs/16 (WooCommerce), docs/15 (Integrações)

## Contexto

O ADR-0007 fechou o pipeline de integração com **reconciliação periódica**
como garantia final de convergência ("webhooks perdem eventos"). A doc 16
§5 detalha a fatia de pedidos: um job diário na madrugada repuxa os pedidos
dos últimos 7 dias e trata duas classes de divergência, com "relatório no
painel" e destaque para **recorrência** ("divergência recorrente =
investigação").

Escopo confirmado pelo dono em 2026-07-29: **só pedidos** (Woo→ERP).
Reconciliação de estoque e de produtos fica adiada — não há publicação
ERP→Woo desses ainda, e não há controle de estoque real até a contagem
pós-ativação (produção ~90% sob demanda).

As duas classes de divergência têm naturezas opostas:

1. **Pedido ausente no ERP** (webhook perdido: site fora do ar, deploy). A
   correção é importar, e o caminho já existe: `PullWooOrdersCommand`
   (`erp:woo:pull-orders`) grava um `woo_webhook_events` com
   `topic='order.pulled'` e delega ao `ImportWooOrder`. Woo é a origem do
   fato do pedido (BR-703), então importar **é** a correção. O achado se
   auto-corrige e deixa trilha natural no log de eventos.

2. **Divergência de valor em pedido já importado** (total mudou no Woo
   depois da importação — edição no wp-admin, que a política proíbe:
   BR-702). O pedido do ERP está **congelado** (BR-304: itens/valores de
   pedido importado não mudam). Isso **não é auto-corrigível**: é um achado
   que pede investigação humana.

Estado do código relevante hoje:

- `PullWooOrdersCommand::importarUm` — para um pedido **já mapeado** que não
  seja cancelamento, incrementa `duplicados` e retorna **antes de comparar
  qualquer coisa**. Nenhuma conferência de total/status acontece hoje.
- `woo_webhook_events` — bruto de cada evento de entrada (webhook ou
  puxada). `situacao()` deriva `rejeitado`/`falha`/`na_fila`/`pendencia`/
  `processado`. O painel (`PanelController`) lê essa tabela, agrupa por
  situação e oferece **reprocessar**, que re-enfileira `ProcessWooOrder`
  (re-importa do payload; idempotente — pedido já importado volta
  `duplicado`).
- `integration_mappings` — ponte `entity_type`/`local_id` ↔ `remote_id`.
  Tem a coluna **`checksum` VARCHAR(64) nullable hoje sem uso**; o comentário
  da migration a descreve como "impressão do payload na última
  sincronização … na reconciliação, denuncia alteração feita fora do ERP
  (BR-702)". Foi desenhada exatamente para ancorar isto.

Restrições que pressionam a escolha: hospedagem compartilhada (Hostinger
Business) com cron que **falha em silêncio** (histórico do projeto);
fronteiras entre módulos verificadas no CI (ADR-0020: a Integração não lê
`Sales\Models\Order`); painel é monitor **operacional**, fora do glossário
de KPIs da pasta 21; §5 exige recorrência visível.

## Decisão

Persistiremos os achados de reconciliação de pedidos em **três peças**, cada
uma com uma responsabilidade:

1. **Âncora de checksum na coluna existente `integration_mappings.checksum`**
   (para `entity_type='order'`). No momento da importação, `ImportWooOrder`
   grava ali o checksum do **valor congelado** do pedido. A reconciliação
   compara o total corrente do Woo contra essa âncora — **nunca lê o pedido
   do ERP** (mantém a fronteira ADR-0020: a comparação é toda dentro de
   Integrações). A âncora é **congelada na importação** e a reconciliação
   nunca a reescreve (BR-304).

2. **Tabela dedicada `woo_reconciliation_findings`** para a classe 2 (a que
   não tem outro lar): um achado por `(entity_type, remote_id, kind)`, com
   contador de ocorrências (o sinal de **recorrência** que a §5 pede),
   ciclo aberto/resolvido e seção própria no painel. A classe 1 (ausente)
   **não** vira achado: ela é importada e deixa um `woo_webhook_events`
   natural — o log de eventos já é o seu registro.

3. **Tabela de execuções `woo_reconciliation_runs`** (heartbeat): uma linha
   por rodada com o que foi conferido/importado/divergiu. Resolve "o job
   rodou ontem? achou o quê?" — e a **ausência** de uma rodada recente é,
   ela mesma, um alerta (a rede de segurança silenciosa parou), o que
   nenhuma outra tabela registra (uma rodada saudável sem achados não deixa
   traço em nenhum outro lugar).

O checksum do pedido é sobre o **total** (a origem do fato monetário,
congelada por BR-304/BR-703), **não** sobre o status — status é coownado
(Woo cria/cancela; o ERP empurra fulfillment/cancelamento de volta), então
um delta de status é comportamento legítimo, não evidência de edição
indevida.

## Alternativas consideradas

### Alternativa A — Reusar `woo_webhook_events` com um `topic` sintético (`order.reconcile`)

Gravar cada divergência como um evento com `error` preenchido → aparece como
`pendencia` no painel "de graça".

- **Prós:** zero schema novo; entra no painel existente sem código.
- **Contras (decisivos):**
  - *Semântica corrompida.* Um `woo_webhook_events` é "entrada bruta com
    payload a processar". Um achado de checksum **não tem payload a
    reprocessar**. O botão **reprocessar** re-enfileira `ProcessWooOrder`,
    que acha o mapeamento e volta `duplicado` — **no-op**. Uma alavanca que
    diz "reprocessar" e não faz nada é mentira de UX.
  - *Recorrência suja.* A §5 quer recorrência. Aqui "recorrente" viraria
    "quantos `order.reconcile` existem para o mesmo `remote_id`" — mas a
    mesma tabela guarda `order.created/updated/pulled` do mesmo id, então o
    group-by é frágil. Pior: cada rodada noturna cria **uma linha nova** para
    a **mesma** divergência não resolvida → a tabela de eventos incha (1
    linha/pedido/noite numa hospedagem compartilhada) e "é recorrente ou é a
    mesma aberta?" fica ambíguo.
  - *Ciclo de vida errado.* Evento vive por `processed_at`/`error`; achado
    vive por detectado→investigado→resolvido. Marcar um achado resolvido
    exigiria abusar de `processed_at`.
- **Descartada** como mecanismo dos achados. (A classe 1 continua gravando
  `order.pulled` legítimo — isso **não** é a Alternativa A: é um evento real,
  com payload, reprocessável.)

### Alternativa B isolada — só `woo_reconciliation_findings`, sem tabela de execuções

Achados limpos, mas sem heartbeat de rodada.

- **Prós:** uma tabela a menos.
- **Contra decisivo:** uma rodada que **não acha nada** não deixa traço
  algum. "Rodou e estava tudo certo" fica **indistinguível** de "não rodou"
  — exatamente o modo de falha perigoso do cron silencioso do Hostinger. O
  heartbeat não é derivável de achados nem de eventos; precisa existir.
- **Descartada** — vira a decisão só se combinada com a tabela de execuções.

### Alternativa D — Checksum sobre o payload inteiro (incluindo status e itens)

Ancorar o checksum no payload completo do pedido.

- **Prós:** pega qualquer alteração.
- **Contras (decisivos):**
  - *Falsos positivos do próprio ERP.* O ERP empurra `completed` (expedição)
    e `cancelled` (cancelamento) de volta ao Woo — mudanças de status que
    ele mesmo causou. Um checksum com status acusaria a saída legítima do
    ERP (o "eco" que a doc 15 §6 já combate).
  - *Ruído do Woo.* `meta_data`/reordenações mudam sob nós sem significado
    de negócio → alertas falsos.
  - *Redundância.* Cancelamento **já** é refletido pela puxada
    (`ImportWooOrder::refletirCancelamento`); incluir status re-sinalizaria o
    que já é tratado.
- **Descartada** em favor do checksum sobre o **total** — sentinela
  suficiente (quase toda edição de pedido no wp-admin mexe no total) e sem
  falso positivo.

### Escolhida — âncora em `integration_mappings.checksum` (total) + `woo_reconciliation_findings` + `woo_reconciliation_runs`

Combina B e a tabela de execuções, ancorando o checksum na coluna que já
existia para isso. Cada peça mapeia a um requisito explícito da §5 ou a uma
necessidade operacional; nenhuma é decorativa.

## Consequências

**Positivas:**

- **Reconciliação inteiramente dentro de Integrações.** Ancorar na coluna de
  `integration_mappings` (tabela da própria Integração) permite comparar
  Woo-corrente × âncora **sem tocar `Sales\Order`** — a fronteira ADR-0020
  fica intacta, sem novo acoplamento entre módulos.
- **Recorrência limpa** via `occurrences` na tabela de achados (um achado por
  pedido/tipo; a rodada faz *upsert*, não insere repetido).
- **Auto-cura.** Se a edição no wp-admin for revertida, o total volta a bater
  a âncora e a reconciliação **auto-resolve** o achado — sem ação humana.
- **Cron silencioso deixa de ser cego.** A tabela de execuções dá o
  heartbeat: latest run > ~26h → o painel avisa que a rede de segurança
  parou.
- **Coluna `checksum` finalmente ativada** para o propósito documentado,
  reservada para estoque/produto reusarem o mesmo padrão (mesmo `kind`,
  mesmo painel) quando a reconciliação deles chegar.
- **Pedido do ERP nunca é mutado** (BR-304): a divergência vira sinal, não
  sobrescrita.

**Negativas / dívidas assumidas:**

- **Duas tabelas novas** para manter (schema, models, policy, seção de
  painel) — mais superfície que a Alternativa A. Justificado por semântica e
  pela §5; ainda assim é custo.
- **Status fora do checksum** significa que uma edição de status no wp-admin
  que **não** mexe no total (e não seja cancelamento) passa silenciosa. É
  aceito: baixo dano, status é coownado. Se virar problema, é gatilho de
  revisão.
- **`resolver` manual re-baseliza a âncora** (copia `remote_checksum` do
  achado para `integration_mappings.checksum`) para o achado não reabrir toda
  noite. Isso ativa a coluna como "linha de base aceita", não como valor do
  pedido — precisa ficar claro na doc para não parecer que o ERP adotou o
  total do Woo (não adotou; BR-304).
- **Backfill de âncora nula.** Pedidos importados antes deste recurso têm
  `checksum = null`; na primeira passada a reconciliação adota o total
  corrente como linha de base (sem achado) — não há referência congelada
  para acusar ninguém. Aceito e documentado.
- **Retenção.** `woo_reconciliation_runs` cresce 1 linha/noite (~365/ano) e
  achados resolvidos acumulam; precisam de poda (follow-up, não bloqueia).
- **Texto da §5 a ajustar.** A §5 diz "total/status"; esta decisão honra
  *total* (achado) e *status-de-cancelamento* (caminho de reflexão já
  existente), não os dois via um checksum. Atualizar docs/16 §5 na próxima
  revisão para refletir isso.

**Gatilhos de revisão:**

- Aparecerem divergências de **status sem mudança de total** que importem
  para a operação → reavaliar incluir status (ou um segundo `kind`) no
  checksum.
- A janela de 7 dias deixar escapar edições tardias no wp-admin → revisar a
  janela.
- Reconciliação de **estoque/produto** entrar em escopo → estender `kind`/
  `entity_type` reusando estas tabelas (validar que o modelo aguenta sem
  reprojeto).
- Volume de achados abertos tornar a lista do painel ingerenciável (> ~200
  abertos) → revisar priorização/paginação/alerta ativo.
- Volume de eventos/execuções pressionar a fila database (gatilhos do
  ADR-0014) → revisar retenção e host dos workers.
