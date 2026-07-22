# ADR-0002: MariaDB como SGBD único + convenções de identidade

> **Status:** Aceito · **Data:** 2026-07-03 · **Decisores:** senior-dba, chief-architect
> **Módulos afetados:** 04, todos os módulos de dados

## Contexto

Hostinger fornece MariaDB gerenciado; o legado e o WordPress já usam MySQL/MariaDB; a equipe conhece o ecossistema; requisitos do ERP são relacionais clássicos (integridade, transações, FKs) sem necessidades exóticas.

## Decisão

MariaDB (InnoDB, `utf8mb4`) como único armazenamento de dados. Identidade: PK interna `BIGINT` auto increment + **ULID público** (`public_id`) nas entidades expostas via API. Soft delete apenas em cadastros (BR-008). Convenções completas em [04/02](../04-Banco-de-Dados/02-convencoes-de-banco.md).

## Alternativas consideradas

### PostgreSQL
Tecnicamente excelente (CHECKs ricos, JSONB, particionamento) — mas não oferecido nativamente no plano; adicionaria host externo, latência e custo. Sem requisito que o exija. Descartada.

### UUIDv4 como PK única
Índices maiores e inserção dispersa no InnoDB; ULID público + BIGINT interno dá o benefício (não vazar sequência, ordenável) sem o custo.

### SQLite (dev) / bancos por módulo
SQLite mente sobre locks/collation → testes em MariaDB real (pasta 22). Banco por módulo quebraria transações de invariantes. Descartadas.

## Consequências

**Positivas:** zero infra extra, backup único, join entre módulos barato, familiaridade.

**Negativas / dívidas:** recursos avançados de constraint são mais fracos que no Postgres → invariantes complexas ganham dupla proteção (aplicação + testes); versão do MariaDB é a da hospedagem (conferir ≥ 10.11 no pré-flight do Gate 01).

**Gatilhos de revisão:** necessidade real de busca full-text avançada/analytics pesado → avaliar serviço dedicado (não trocar o SGBD primário).
