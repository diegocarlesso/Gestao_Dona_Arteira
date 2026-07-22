---
name: criar-api
description: Especifica um endpoint/recurso novo da API no OpenAPI ANTES da implementação (API First, ADR-0003) e conduz até o contrato verificado. Use sempre que a API ganhar ou mudar um endpoint — é a porta de entrada obrigatória antes de criar-controller.
---

# Skill: Criar API (contrato primeiro)

## Objetivo
Contrato antes do código: o endpoint nasce no spec OpenAPI, é revisado e só então implementado — com client TS regenerado e teste de contrato verde.

## Pré-requisitos
1. Doc do módulo cobre a funcionalidade; BRs registradas.
2. Convenções lidas: `docs/07-API/README.md` (recursos, erros RFC 9457, paginação, ações POST).
3. Permissão do endpoint decidida (matriz docs/19).

## Entradas
Operação de negócio a expor; consumidores (SPA/integrações); dados de entrada/saída; erros de negócio possíveis.

## Fluxo
1. Editar `docs/07-API/openapi/paths/<recurso>.yaml`: método, path (plural kebab; ação de negócio = `POST /recurso/{id}/acao`), parâmetros (paginação/filtros allowlist), request/response com `$ref` a schemas em `components/`.
2. Exemplos com dados reais do domínio (peça "Coruja Decorativa", SKU GB-0042) — nunca foo/bar.
3. Documentar TODOS os erros: 401/403/404/422 + cada 409 com seu `code` estável (`inventory.insufficient_stock`).
4. Anotar `x-permission` e, se exposto a integrações, suporte a `Idempotency-Key`.
5. `redocly lint` limpo; revisão do api-specialist (breaking change em v1 é bloqueado).
6. Implementar via skills `criar-service` + `criar-controller`.
7. Regenerar client TS (`openapi-typescript`); rodar teste de contrato.

## Saídas
Spec atualizado → implementação → client regenerado → contrato verde.

## Critérios mínimos
Nenhuma divergência spec × implementação; endpoint segue 100% das convenções; erros todos documentados e testados.

## Checklist final
- [ ] Spec escrito e revisado ANTES do controller?
- [ ] Exemplos completos e realistas? Erros 409 com códigos estáveis?
- [ ] Sem breaking change em v1 (campo novo é opcional)?
- [ ] Dinheiro string decimal; datas ISO; ids = ULID público?
- [ ] Client TS regenerado e commitado?
- [ ] Teste de contrato no CI cobrindo o endpoint?
