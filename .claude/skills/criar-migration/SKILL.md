---
name: criar-migration
description: Cria uma migration do banco do ERP seguindo as convenções da pasta 04 (nomes, tipos, constraints, expand/contract). Use sempre que for necessário criar ou alterar tabelas — nunca crie migration manualmente sem esta skill.
---

# Skill: Criar Migration

## Objetivo
Evoluir o esquema MariaDB com segurança: toda migration nasce do modelo conceitual documentado, segue as convenções e é reversível.

## Pré-requisitos (bloqueantes)
1. Mudança refletida em `docs/04-Banco-de-Dados/01-modelo-conceitual.md` (se não está, atualize ANTES — docs-first).
2. Convenções lidas: `docs/04-Banco-de-Dados/02-convencoes-de-banco.md`.
3. Se a mudança nasce de decisão nova (tipo de dado, estratégia) → ADR existe/atualizado.

## Entradas
Descrição da mudança; trecho do modelo conceitual correspondente; BRs de integridade envolvidas (unicidades, imutabilidades).

## Fluxo
1. Confirmar no modelo conceitual: tabela/colunas/relações/regras.
2. Classificar: **aditiva** (criar tabela/coluna nullable) ou **destrutiva** (rename/drop/NOT NULL em coluna existente) → destrutiva obriga expand/contract em dois releases com backfill idempotente.
3. Escrever a migration: nomes snake_case, tipos obrigatórios (DECIMAL 15,2 dinheiro / 15,3 quantidade — ADR-0013), `created_at/updated_at`, `created_by/updated_by` em tabelas de negócio, FKs nomeadas com RESTRICT, UNIQUEs de negócio, índices previstos.
4. Escrever `down()` funcional (reverte de verdade).
5. Comentário no topo linkando o trecho do modelo conceitual que a justifica.
6. Rodar: `migrate` → `migrate:rollback` → `migrate` (ciclo completo local).
7. Atualizar factories/seeders afetados (seeds idempotentes).

## Saídas
Migration + factories/seeders atualizados + modelo conceitual em dia.

## Critérios mínimos
Ciclo migrate/rollback/migrate limpo; nenhuma violação de convenção; sem operação que exija privilégio SUPER (shared hosting).

## Checklist final
- [ ] Modelo conceitual atualizado antes do código?
- [ ] Uma migration = uma intenção (sem assuntos misturados)?
- [ ] Tipos monetários/quantidade corretos (nunca float)?
- [ ] FKs com constraint nomeada e política RESTRICT deliberada?
- [ ] UNIQUE para unicidades de negócio (SKU, doc, chave NF-e…)?
- [ ] `down()` testado? Destrutiva em expand/contract?
- [ ] Impacto de lock em tabela grande avaliado (senior-dba se dúvida)?
