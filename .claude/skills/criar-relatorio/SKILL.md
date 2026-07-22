---
name: criar-relatorio
description: Cria um relatório do ERP (ficha no catálogo, query object testada, tela com filtros, exportação CSV/PDF) conforme os padrões da pasta 20. Use para qualquer listagem analítica ou documento de trabalho (separação, aging, perdas…).
---

# Skill: Criar Relatório

## Objetivo
Relatório = consulta documentada, testada e exportável, com definições que batem com o resto do sistema — nunca uma query solta.

## Pré-requisitos
1. Ficha no catálogo (docs/20 §3): pergunta de negócio, filtros, colunas, fonte, permissão, dono. Ficha primeiro, código depois.
2. Definições de métricas alinhadas ao glossário (docs/21 §2) — ex.: "venda" = pedido confirmado, não pago.
3. Índices para o padrão de consulta avaliados (senior-dba).

## Entradas
Ficha do relatório; volume esperado; visão competência × caixa (se financeiro).

## Fluxo
1. Query object dedicado (skill `criar-repository`) com parâmetros = filtros da ficha; paginação para telas, streaming/chunk para exportação.
2. Teste com dataset de factory validando **totais exatos** e ordenação (relatório errado é pior que ausente).
3. Endpoint via `criar-api` (`GET /reports/<nome>` + `GET /reports/<nome>/export`), permissão `reports.view` (+ recorte por papel se BR-803).
4. Tela: filtros com defaults sensatos persistidos na URL, tabela com totalizadores, estado vazio explicativo.
5. Exportação CSV sempre (streaming, separador `;`, encoding UTF-8 BOM p/ Excel BR); PDF se documento de trabalho.
6. Se consulta pesada: avaliar snapshot noturno (documentar a defasagem na tela — "dados de ontem 23h").

## Saídas
Ficha no catálogo + query object testado + endpoint + tela + exportação.

## Critérios mínimos
Totais batem com o dataset de teste e com o dashboard equivalente; execução dentro dos NFRs; financeiros declaram competência × caixa.

## Checklist final
- [ ] Ficha no catálogo antes do código?
- [ ] Teste de totais exatos passa? Fonte única com dashboards?
- [ ] Exportação CSV abre corretamente no Excel BR (acentos, ; , vírgula decimal)?
- [ ] Permissões (incl. recorte do contador) testadas?
- [ ] Performance verificada com volume realista (factory em massa)?
