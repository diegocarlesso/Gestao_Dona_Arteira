# Convenções de Banco de Dados

> **Status:** Aprovado · **Última atualização:** 2026-07-03 · **Responsável:** senior-dba
> Aplicadas pela skill `criar-migration`; desvios exigem justificativa em ADR.

## Nomenclatura

| Item | Convenção | Exemplo |
|---|---|---|
| Tabelas | inglês, snake_case, **plural** | `production_orders` |
| Colunas | inglês, snake_case | `unit_price` |
| PK | `id` BIGINT UNSIGNED auto increment | |
| ID público | `public_id` ULID CHAR(26) UNIQUE (entidades expostas na API) | |
| FK | `<singular>_id` + constraint nomeada | `customer_id`, `fk_orders_customer` |
| Pivot | singulares em ordem alfabética | `order_product` (se necessário) |
| Índice | `idx_<tabela>_<colunas>` / `uq_` para únicos | `uq_products_sku` |
| Enum de domínio | coluna VARCHAR + Enum PHP (não ENUM MySQL) | `status` |
| Booleano | prefixo `is_`/`has_` | `is_wholesale` |
| Datas de evento | sufixo `_at` (TIMESTAMP) / `_date` (DATE) | `authorized_at`, `due_date` |

## Tipos obrigatórios

| Dado | Tipo | Regra |
|---|---|---|
| Dinheiro | `DECIMAL(15,2)` | ADR-0013 — nunca FLOAT/DOUBLE |
| Quantidade/peso | `DECIMAL(15,3)` | unidade documentada no nome ou comentário |
| Percentual | `DECIMAL(5,2)` (0–100) | |
| Documento (CPF/CNPJ) | VARCHAR(14) só dígitos | máscara é responsabilidade da UI |
| CEP/telefone | VARCHAR só dígitos | idem |
| JSON | `JSON` nativo | somente para payloads/metadata sem consulta relacional |
| Texto livre | `TEXT` | evitar VARCHAR(255) por reflexo — dimensionar |

## Padrões de tabela

- Engine InnoDB, charset `utf8mb4`, collation `utf8mb4_unicode_ci`.
- `created_at`/`updated_at` em todas as tabelas; `deleted_at` **apenas** em cadastros com inativação (BR-008).
- `created_by`/`updated_by` (FK users, nullable para processos de sistema) em tabelas de negócio.
- FKs **sempre** com constraint; `ON DELETE RESTRICT` por padrão (proteção contra perda em cascata); `CASCADE` só em composição estrita (itens de um pedido rascunho).
- Tabelas de staging da migração: prefixo `stg_`, sem FKs, colunas espelhando a origem + colunas de controle (`import_batch`, `status`, `error`).

## Migrations (regras para o Gate 01+)

1. Uma migration = uma intenção (não misturar assuntos).
2. `down()` funcional e testado (CI roda migrate + rollback + migrate).
3. Mudança destrutiva (drop/rename) só via expand/contract em dois releases, com backfill idempotente.
4. Nunca editar migration já aplicada em produção.
5. Seeds de referência (papéis, categorias financeiras padrão, perfis fiscais) são versionados e idempotentes.
6. Toda migration referencia o trecho do modelo conceitual que a justifica (comentário com link).

## Transações e locks

- Serviço de numeração fiscal (`fiscal_series`) e baixa de saldo usam `SELECT ... FOR UPDATE` — nunca read-modify-write sem lock.
- Movimento + saldo de estoque na **mesma transação**; falha reverte ambos (BR-201/202).
- Jobs de sincronização não seguram transação aberta durante chamada HTTP externa (persistir intenção → chamar → persistir resultado).
