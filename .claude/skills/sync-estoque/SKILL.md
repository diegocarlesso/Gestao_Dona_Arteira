---
name: sync-estoque
description: Implementa/ajusta a sincronização de estoque ERP→WooCommerce (disponível − buffer, BR-204) disparada por eventos de movimento/reserva, com reconciliação. Use para tudo que envolva publicar estoque no site ou investigar divergências de estoque entre ERP e Woo.
---

# Skill: Sincronização de Estoque

## Objetivo
O site nunca vende o que não existe: estoque publicado = `disponível (on_hand − reserved) − buffer do produto`, atualizado em < 2 min após qualquer movimento (NFR), com reconciliação diária.

## Pré-requisitos
1. Integração base Woo ativa (skill `integracao-woocommerce`).
2. Ledger de estoque operante (docs/09, ADR-0008) emitindo `StockMovementRecorded`/`StockReserved`/`StockReleased`.
3. Buffer por produto configurável com default global definido (BR-204 — se indefinido, levantar com o dono).
4. Mapeamento produto ERP↔Woo íntegro (`integration_mappings`).

## Entradas
Eventos de estoque; lista de produtos `sell_on_woo`.

## Fluxo
1. Listener dos eventos → job `PushStockToWoo` **por produto** (não por movimento): coalescer atualizações (debounce curto) para não martelar a API em rajada.
2. Job idempotente: calcula disponível−buffer no MOMENTO da execução (não usa valor do evento — evita corrida), publica `stock_quantity`, atualiza checksum no mapping.
3. Produto sem mapping/`sell_on_woo=false` → ignorar silenciosamente (log debug).
4. Falha → retry backoff; esgotou → parking + alerta (item visível no painel).
5. Reconciliação diária: compara disponível calculado × `stock_quantity` do Woo em lote; divergência → corrige (ERP vence) + linha no relatório.
6. Testes: fórmula com reserva/buffer (valores exatos), coalescência, idempotência, reconciliação corrigindo divergência plantada.

## Saídas
Listener+job+reconciliação de estoque + configuração de buffer + testes.

## Critérios mínimos
Latência evento→Woo dentro do NFR; zero updates redundantes em rajada (coalescido); divergência da reconciliação = 0 ou explicada.

## Checklist final
- [ ] Fórmula BR-204 testada com casos: reservado, buffer, encomenda?
- [ ] Job recalcula no momento da execução (não confia no payload do evento)?
- [ ] Debounce/coalescência sob rajada de movimentos testada?
- [ ] Anti-eco (update de estoque não retorna via webhook como mudança)?
- [ ] Alerta + reprocesso manual para itens em parking?
