# 21 — Dashboards

> **Status:** Em revisão · **Última atualização:** 2026-07-03 · **Responsável:** business-analyst
> **Fase:** Gate 06 (visão mínima no Gate 02) · Relatórios: [pasta 20](../20-Relatorios/README.md)

## 1. Objetivo

Painéis por papel que respondem "como estamos AGORA?" em uma tela — sem substituir relatórios (análise) nem virar árvore de Natal de gráficos.

## 2. Glossário de métricas (fonte única das definições)

| Métrica | Definição canônica |
|---|---|
| Venda do dia/mês | Σ pedidos **confirmados** no período (cancelados excluídos), por canal |
| Ticket médio | venda ÷ nº pedidos do período |
| Disponível | on_hand − reservado (pasta 09) |
| WIP | peças em OPs de pintura (entre a saída da peça crua e a entrada da acabada) |
| % de perda | qty_lost ÷ (qty_produced + qty_lost) por período |
| Inadimplência | receivables vencidos > 5 dias ÷ receivables do período |
| Saúde da sync | idade do item mais antigo na fila + falhas 24 h |

Toda visualização usa estas definições — divergência entre telas é bug.

## 3. Painéis por papel

| Painel | Público | Conteúdo (cards/gráficos) |
|---|---|---|
| **Geral (home)** | admin | venda dia/mês por canal, pedidos por status (funil), alertas: estoque mínimo, encomendas atrasando, títulos vencendo hoje, saúde das integrações, certificado A1 (dias p/ vencer) |
| **Produção** | production | OPs por etapa (kanban WIP), fila de encomendas por data prometida, perdas da semana, lotes prontos para liberar da secagem |
| **Vendas/Expedição** | sales/fulfillment | pedidos a separar/embalar/expedir, atrasos, rastreios sem movimentação (fase 6) |
| **Financeiro** | finance | fluxo de caixa 30/60/90, a receber/pagar da semana, aging |
| **Integrações** | admin | status por integração, última sync, pendências, erros com ação de reprocesso |

## 4. Padrões

- Skill `criar-dashboard` + guia visual `dataviz` do projeto: paleta única, mesmos formatos pt-BR, todo número clicável leva ao detalhe (relatório/lista filtrada).
- Performance: endpoints agregados dedicados com cache curto (60 s); dashboard nunca dispara N queries de listagem.
- Auto-refresh discreto (60–120 s) nos painéis operacionais.

## 5. Riscos

| Risco | Mitigação |
|---|---|
| Métricas sem definição gerarem desconfiança | Glossário acima é contrato; teste de totais compartilhado com relatórios |
| Dashboard lento no shared hosting | agregações noturnas + cache; medir no Gate 06 |

## 6. Evoluções futuras

- Metas mensais configuráveis com farol (fase 6) · TV do ateliê com painel de produção (fase 6) · comparativos ano a ano (após 12 meses de dados).

## 7. Mínimo do Gate 02 (Dashboard Home)

Este é o recorte inicial para entregar valor rápido focado em Vendas e Operação (Fulfillment):

- **Vendas:** Venda do dia e do mês por canal (balcão/atacado/site), nº de pedidos e ticket médio.
  - *Regra (Glossário):* Σ pedidos confirmados no período, cancelados excluídos.
- **Funil de pedidos por status:** Rascunho → Confirmado → Em separação → Embalado → Expedido → Entregue (cancelados monitorados à parte).
- **Fila de fulfillment:** Quantos a separar / a embalar / a expedir. Cada número deve levar à lista filtrada.
- **Saúde da sync:** Pendências + rejeitados + resultado da última reconciliação, com link para `/integracoes`.
- **Visão por papel:** `admin` vê o painel completo; `sales`/`fulfillment` veem a fila de fulfillment em grande destaque.
- **Arquitetura (Padrão):** Endpoint agregado dedicado (Query Object), sem disparo de N queries (evitando N+1), com cache em Redis/banco de 60 segundos, retornando JSON otimizado para dataviz.
