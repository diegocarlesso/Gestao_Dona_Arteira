---
name: criar-dashboard
description: Cria ou altera um painel/dashboard do ERP (cards, gráficos, alertas) seguindo o glossário de métricas da pasta 21 e endpoints agregados dedicados. Use para qualquer visualização de KPIs ou painel por papel.
---

# Skill: Criar Dashboard

## Objetivo
Painel que responde "como estamos agora?" com métricas de definição única (docs/21 §2), rápido (endpoint agregado + cache) e acionável (todo número leva ao detalhe).

## Pré-requisitos
1. Métricas do painel definidas no glossário de métricas (docs/21 §2) — se a métrica é nova, defina-a LÁ primeiro (com fórmula exata).
2. Permissão/papel do painel definido (docs/19 e §3 da pasta 21).
3. Guia de visualização carregado (skill `dataviz` do harness) antes de escrever qualquer gráfico.

## Entradas
Painel (novo/existente), público-alvo, perguntas que responde, métricas (com definição), frequência de atualização.

## Fluxo
1. Backend: endpoint agregado dedicado (`GET /dashboards/<painel>`) — uma chamada devolve tudo do painel; queries de leitura otimizadas; cache 60 s; spec via `criar-api`.
2. Teste do endpoint com dataset de factory validando **valores exatos** das métricas (mesma fonte de verdade dos relatórios).
3. Frontend: cards de estatística e gráficos conforme guia dataviz (paleta única, formatos pt-BR: R$, dd/mm); estados loading/erro/vazio; auto-refresh 60–120 s.
4. Todo card/gráfico clicável → lista/relatório filtrado correspondente (drill-down).
5. Alertas visuais (estoque mínimo, títulos vencendo, sync com falha) com contagem + link direto para a ação.

## Saídas
Endpoint agregado testado + painel React + entrada atualizada na pasta 21.

## Critérios mínimos
Nenhuma métrica sem definição no glossário; painel carrega < 2 s (NFR); número do dashboard = número do relatório equivalente (teste compartilhado).

## Checklist final
- [ ] Métricas definidas no glossário (fórmula exata) antes do código?
- [ ] Endpoint único agregado com cache — sem N chamadas do front?
- [ ] Valores testados contra dataset conhecido?
- [ ] Drill-down em todo número? Estados de tela completos?
- [ ] Formatos brasileiros via helper central? Paleta/acessibilidade do guia dataviz?
