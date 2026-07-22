# 19 — Permissões (RBAC)

> **Status:** Em revisão · **Última atualização:** 2026-07-03 · **Responsável:** security-specialist
> **Regras:** BR-801…BR-804 · **Fase:** Gate 01 · **ADR:** [0011 (spatie/laravel-permission)](../27-ADR/ADR-0011-rbac.md)

## 1. Objetivo

Controle de acesso **negado por padrão** (BR-801): papéis agrupam permissões granulares; toda rota da API exige permissão explícita; a UI esconde o que o usuário não pode (autoridade sempre no backend).

## 2. Modelo

- Permissões nomeadas `modulo.acao`: `catalog.view`, `catalog.manage`, `inventory.view`, `inventory.move`, `inventory.adjust`, `production.view`, `production.execute`, `production.manage`, `sales.view`, `sales.create`, `sales.cancel`, `sales.discount.approve`, `fulfillment.execute`, `purchasing.manage`, `finance.view`, `finance.settle`, `finance.manage`, `fiscal.view`, `fiscal.emit`, `fiscal.cancel`, `reports.view`, `integrations.manage`, `users.manage`, `audit.view`.
- Papéis são conjuntos versionados por seeder (mudança de papel = migration de seed + registro em auditoria). Usuário pode acumular papéis.
- Policies do Laravel implementam nuances contextuais (ex.: `sales.cancel` só do próprio pedido para `sales`, qualquer um para `admin`).

## 3. Matriz papel × permissão (inicial)

| Permissão | admin | production | sales | fulfillment | finance | accountant |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| catalog.view | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| catalog.manage | ✅ | — | — | — | — | — |
| inventory.view | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| inventory.move / adjust | ✅ / ✅ | ✅ / — | — | ✅ / — | — | — |
| production.execute / manage | ✅ | ✅ / — | — | — | — | — |
| sales.create / cancel | ✅ | — | ✅ / ✅* | — | — | — |
| sales.discount.approve | ✅ | — | — | — | — | — |
| fulfillment.execute | ✅ | — | ✅ | ✅ | — | — |
| purchasing.manage | ✅ | — | — | — | — | — |
| finance.view / settle / manage | ✅ | — | — | — | ✅ | — |
| fiscal.view / emit / cancel | ✅ | — | ✅ (emit) | ✅ (emit) | ✅ (view) | ✅ (view) |
| reports.view | ✅ | — | — | — | ✅ | ✅ (fiscais) |
| integrations.manage / users.manage / audit.view | ✅ | — | — | — | — | — |

\* com restrição de Policy (próprios pedidos, antes de expedido).

## 4. Alçadas (regras quantitativas)

| Ação | Limite sem aprovação | Acima do limite |
|---|---|---|
| Desconto em pedido (BR-305) | até X% (definir com o dono — pergunta aberta) | exige `sales.discount.approve` |
| Ajuste de inventário (BR-205) | nunca sozinho | aprovador ≠ contador da divergência |
| Cancelamento de NF-e | — | somente `fiscal.cancel` (admin), com motivo |

## 5. Aplicação técnica

Rota → middleware de permissão → Policy (contexto) → Service. Testes de autorização são **obrigatórios por endpoint** (pasta 22): papel sem permissão recebe 403 (testado explicitamente). Auditoria registra `PermissionDenied` (pasta 26).

## 6. Riscos

| Risco | Mitigação |
|---|---|
| Permissões inflarem caso a caso até todo mundo ser admin | Revisão trimestral de acessos (checklist pasta 25); mudanças de papel auditadas |
| Papel `accountant` receber dados de clientes além do fiscal | Resources específicos por papel testados |

## 7. Evoluções futuras

- Permissões por local/loja (se multi-local operacional).
- Aprovações em duas etapas com fila de pendências no dashboard (fase 6).
