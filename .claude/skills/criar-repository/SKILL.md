---
name: criar-repository
description: Cria um Repository para consultas complexas/reutilizadas do ERP — na dose definida pelo ADR-0015 (repository só onde agrega; CRUD trivial usa Eloquent direto). Use quando uma consulta de negócio precisa de nome, reuso e teste próprios.
---

# Skill: Criar Repository

## Objetivo
Encapsular consultas complexas ou reutilizadas com nome de negócio (`OverdueReceivablesQuery`, `AvailableStockRepository`) — sem criar passthrough burocrático de CRUD.

## Pré-requisitos
1. **Justificativa válida** (ADR-0015): consulta complexa reutilizada em ≥ 2 lugares, OU consulta com regras de negócio de leitura (filtros compostos), OU fronteira que merece implementação alternativa (ex.: gateway externo). CRUD simples → Eloquent direto no Service, sem repository.
2. Índices para a consulta avaliados (senior-dba / docs/04/01 §Índices).

## Entradas
Pergunta de negócio da consulta; parâmetros/filtros; volume esperado; onde será usada.

## Fluxo
1. Decidir formato: **query object** (uma consulta parametrizada — preferido) ou repository coeso (família de consultas do mesmo agregado).
2. Classe em `app/Modules/<X>/Repositories` retornando tipos explícitos (Collection tipada/paginator/DTO de leitura) — nunca Builder vazando para o chamador.
3. Interface SOMENTE se existir segunda implementação plausível hoje (senão, classe concreta — YAGNI).
4. Consulta com eager loading deliberado (sem N+1), seleção de colunas quando relevante, paginação obrigatória para listas abertas.
5. Teste com dataset de factory validando resultados e ordenação/limites.
6. Se a consulta alimenta relatório → ficha no catálogo (docs/20) via skill `criar-relatorio`.

## Saídas
Query object/repository + testes + (se necessário) migração de índice.

## Critérios mínimos
Nome explica a pergunta de negócio; sem N+1 (verificado); chamadores não recebem Builder cru.

## Checklist final
- [ ] Justificativa do ADR-0015 atendida (não é passthrough de CRUD)?
- [ ] Retornos tipados; Builder não vaza?
- [ ] N+1 verificado com contagem de queries no teste?
- [ ] Índice existente/criado para o padrão de acesso?
- [ ] Paginação/limite em toda lista aberta?
