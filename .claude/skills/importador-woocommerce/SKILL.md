---
name: importador-woocommerce
description: Constrói/executa os importadores da migração inicial (WooCommerce + legado → staging → ERP) conforme a pasta 17 e o ADR-0010 — extração, saneamento, carga idempotente e validação com relatórios. Use em qualquer trabalho da migração de dados.
---

# Skill: Importador WooCommerce (migração)

## Objetivo
Importadores idempotentes e auditáveis que levam produtos, categorias, clientes, pedidos e imagens (refs) para o ERP com qualidade medida — re-executáveis sem duplicar nada (BR-706).

## Pré-requisitos
1. Docs lidos: `docs/17-Migracao/README.md` (fases) + `docs/16-WooCommerce/01-mapeamento-de-campos.md` (de-para) + convenções de staging (`docs/04-.../02` §Padrões).
2. Fase 1 (inventário) executada — volumes e problemas conhecidos ANTES de codificar transformações.
3. Acesso: chaves REST do Woo (read) + dump/acesso somente-leitura ao MySQL do legado.
4. Modelo ERP de destino pronto (migrations do Gate 01).

## Entradas
Entidade a importar (ordem: categorias → produtos → clientes → pedidos), fonte(s), lote/batch id.

## Fluxo
1. Comando Artisan por entidade: `erp:migrate:extract {fonte} {entidade}`, `erp:migrate:load {entidade} --dry-run|--execute --batch=`.
2. **Extração** → `stg_woo_*`/`stg_legacy_*`: colunas espelhando a origem + controle (`import_batch`, `status`, `error`); paginação/incremental `modified_after`; sem FKs no staging.
3. **Saneamento** (fase 3 da doc 17): dedupe de clientes (doc válido > e-mail > recência), SKUs ausentes (propostos → aprovação humana em lote), floats→DECIMAL com relatório de diferenças, merge Woo×legado (legado ganha em dados físicos; Woo em conteúdo).
4. **Carga**: upsert por chave natural (SKU/doc/e-mail/id externo) + `integration_mappings` SEMPRE populado; lotes pequenos retomáveis; rejeições com motivo em relatório — nada silencioso.
5. **Validação**: contagens origem×stg×destino; amostras; Σ totais de pedidos/ano × relatório Woo; pendências (NCM, doc inválido) como flags.
6. Teste no CI: importador roda 2× sobre fixture → mesmos números (idempotência provada).

## Saídas
Comandos + relatórios (inventário, rejeições, diferenças, validação) + mappings íntegros + staging descartável.

## Critérios mínimos
Dry-run fiel; re-execução idempotente testada; toda rejeição explicada; contagens assinadas pelo dono antes do cutover.

## Checklist final
- [ ] Ordem de dependência respeitada (categorias→produtos→clientes→pedidos)?
- [ ] Upsert por chave natural + mapping para TUDO que sincronizará?
- [ ] Relatório de rejeições/diferenças gerado e triado?
- [ ] Idempotência no CI (2× = mesmos números)?
- [ ] Pedidos históricos com produto extinto → produto archived placeholder?
- [ ] Estoque NÃO importado como verdade (inventário físico no cutover — doc 17)?
