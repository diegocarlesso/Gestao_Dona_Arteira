# ADR-0010: Migração via staging + ETL idempotente (Woo API + banco legado)

> **Status:** Aceito · **Data:** 2026-07-03 · **Decisores:** migration-specialist, chief-architect
> **Módulos afetados:** 17, 04, 16

## Contexto

O patrimônio de dados está no WooCommerce (produtos, clientes, pedidos, imagens, estoque) e no MySQL do desktop legado (clientes de balcão/atacado, embalagens, preços de atacado). O ERP não pode nascer vazio (princípio do projeto); a migração precisa ser segura, conferível e repetível, com saneamento (duplicatas, SKUs ausentes, floats de preço).

## Decisão

ETL em fases (pasta 17): extração **Woo via REST API** e **legado via leitura direta do MySQL** (exceção pontual ao BR-701 — é um banco nosso, morto, somente leitura) → **staging `stg_*`** → saneamento com aprovação humana → carga idempotente (upsert por chave natural + `integration_mappings`) → validação por contagens/amostras/somas → cutover com **inventário físico** para estoque inicial.

## Alternativas consideradas

### Export/import por CSV manuais
Sem idempotência, sem trilha de rejeições, erro humano em cada rodada. Descartada.

### Migrar via plugins de export do WordPress
Formatos inconsistentes entre plugins, sem metadados de plugins brasileiros (CPF), sem controle. A REST API é o contrato estável. Descartada.

### Recadastrar manualmente ("começar limpo")
Proibido pelo projeto (perde histórico e patrimônio; semanas de digitação; erros novos). Descartada.

### Ler o banco do WordPress diretamente
Esquema interno do Woo (postmeta EAV) é frágil e não documentado como contrato. API REST é a fronteira suportada. Descartada.

## Consequências

**Positivas:** re-execução barata (dry-run, deltas), qualidade medida antes do go-live, rollback simples (staging descartável), pipeline reutilizável para importações futuras.

**Negativas / dívidas:** ETL é um mini-projeto com código descartável a manter durante o Gate 01–02; leitura do legado exige acesso/dump do MySQL antigo (pendência da pasta 01/02-levantamento).

**Gatilhos de revisão:** inventário (fase 1) revelar volumes/qualidade muito piores que o estimado → replanejar prazo do Gate 01 com o dono.
