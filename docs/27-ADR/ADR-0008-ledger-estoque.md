# ADR-0008: Estoque como ledger imutável + saldos materializados

> **Status:** Aceito · **Data:** 2026-07-03 · **Decisores:** inventory-specialist, senior-dba
> **Módulos afetados:** 09, 04, 08, 10, 11

## Contexto

O legado guarda estoque como um inteiro (`in_stock`) mutável — impossível responder "por que o saldo está assim?", auditar ajustes ou custear. O ERP precisa de: rastreabilidade total (auditoria é princípio), custo médio, reservas multicanal e reconciliação com o Woo.

## Decisão

Modelo contábil: **`inventory_movements` append-only** (cada entrada/saída com tipo, origem, custo, autor — BR-202) + **`inventory_balances` materializado** (on_hand/reserved/avg_cost) atualizado na mesma transação com lock, sempre reconciliável por Σ movimentos (job noturno verifica). Reservas em tabela própria. Nenhum código altera saldo diretamente.

## Alternativas consideradas

### Saldo mutável simples (como o legado)
Sem trilha, sem custo médio confiável, ajustes invisíveis. Inaceitável para ERP. Descartada.

### Apenas ledger (saldo calculado on-the-fly)
Puro e sempre correto, mas cada consulta de disponibilidade viraria um SUM com milhões de linhas ao longo dos anos. Descartada — materialização é otimização clássica com verificação automática.

### Event sourcing completo do domínio
Generalizar o padrão para tudo (pedidos etc.) adicionaria complexidade enorme; o ledger só onde ele é o modelo natural do negócio (estoque, financeiro via títulos/baixas, fiscal via eventos).

## Consequências

**Positivas:** extrato explicável de qualquer saldo; ajustes auditados; custo médio sólido; base natural para reconciliação com canais.

**Negativas / dívidas:** toda operação de estoque passa pelo serviço central (disciplina); tabela de movimentos cresce indefinidamente (índices + arquivamento futuro se necessário).

**Gatilhos de revisão:** necessidade de rastreio por lote/validade → adicionar dimensão `batch` ao movimento (extensão, não redesign).
