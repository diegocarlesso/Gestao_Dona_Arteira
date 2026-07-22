---
name: criar-controller
description: Cria um Controller fino da API do ERP (FormRequest + Policy + Service + Resource) seguindo as convenções da pasta 07 e o ADR-0015. Use ao expor qualquer operação via HTTP — sempre DEPOIS do spec OpenAPI (skill criar-api).
---

# Skill: Criar Controller

## Objetivo
Camada HTTP mínima: autorizar, validar, delegar ao Service, responder com Resource — zero regra de negócio, zero query complexa.

## Pré-requisitos (bloqueantes)
1. Endpoint especificado no OpenAPI ANTES (skill `criar-api` / docs/07-API/01-fluxo-openapi.md).
2. Service do caso de uso existente (skill `criar-service`) para mutações.
3. Permissão definida na matriz (docs/19) e existente no seeder.

## Entradas
Spec do endpoint (path, verbos, request/response/erros, `x-permission`).

## Fluxo
1. Rota em `routes/api/v1/<modulo>.php` com middleware de permissão; nomes de rota padronizados.
2. FormRequest: validação sintática completa (tipos, formatos, limites) com mensagens pt-BR; `authorize()` via Policy quando contextual.
3. Controller invocável ou resource controller fino: chama o Service com dados validados tipados; captura NADA (exceções de domínio são mapeadas pelo handler global para RFC 9457).
4. Resource com campos EXPLÍCITOS (espelho do schema OpenAPI; dinheiro como string decimal; datas ISO-8601; `public_id` como id).
5. Ações de negócio = endpoints POST dedicados (`/orders/{id}/confirm`), nunca PATCH de status.
6. Testes feature: 200/201 feliz, 401, 403 (papel sem permissão), 422 (validação), 409 (cada regra violada com `code` correto), paginação/filtros se listagem.
7. Rodar teste de contrato: resposta real ≡ spec.

## Saídas
Rota + FormRequest + Controller + Resource + testes feature + contrato verde.

## Critérios mínimos
Controller sem `if` de negócio; Resource sem serialização implícita de model; todos os erros do spec exercitados em teste.

## Checklist final
- [ ] Spec OpenAPI existia antes e está idêntico à implementação?
- [ ] Permissão aplicada + teste 403?
- [ ] FormRequest cobre tudo que o spec declara?
- [ ] Resource explícito (nenhum campo por reflexo)?
- [ ] Exceções de domínio viram 409 com código estável (sem try/catch local)?
- [ ] Client TS regenerado (frontend)?
