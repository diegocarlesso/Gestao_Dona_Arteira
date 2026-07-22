# 07 — API

> **Status:** Aprovado · **Última atualização:** 2026-07-03 · **Responsável:** api-specialist
> **Documentos:** [Fluxo OpenAPI](01-fluxo-openapi.md)
> **ADRs:** 0003 (API First) · 0005 (autenticação)

## 1. Objetivo

Definir o contrato público do ERP: convenções REST, formato de erro, paginação, versionamento e segurança. A API é a **única porta** do sistema — SPA, integrações, futuro mobile e marketplaces passam todos por aqui (BR-701).

## 2. Convenções REST

- Base: `https://gestao.donaarteira.com.br/api/v1` — versionamento por URL; quebra de contrato exige `v2` + período de convivência.
- Recursos em inglês, plural, kebab-case: `/products`, `/production-orders`, `/fiscal-documents`.
- Identificador público: **ULID** (`public_id`) — nunca expor auto-increment.
- Verbos: GET (leitura), POST (criação/ações), PUT/PATCH (atualização), DELETE (inativação/remoção conforme BR-008).
- **Ações de negócio são sub-recursos POST explícitos** (não PATCH de status): `POST /orders/{id}/confirm`, `/cancel`, `/ship`; `POST /fiscal-documents/{id}/authorize`. A máquina de estados valida a transição (BR-303).
- Respostas: `data` (payload), `meta` (paginação etc.). Datas ISO-8601 UTC; dinheiro como string decimal `"149.90"` + `currency` (evita float em JSON).

## 3. Erros — RFC 9457 (problem+json)

```json
{
  "type": "https://gestao.donaarteira.com.br/errors/inventory.insufficient_stock",
  "title": "Estoque insuficiente",
  "status": 409,
  "detail": "Produto GB-0042 possui apenas 3 unidades disponíveis.",
  "code": "inventory.insufficient_stock",
  "correlation_id": "01J...",
  "errors": { "items.0.qty": ["Quantidade acima do disponível."] }
}
```

| HTTP | Uso |
|---|---|
| 400 | requisição malformada |
| 401 / 403 | não autenticado / sem permissão |
| 404 | recurso inexistente (ou fora do escopo do usuário) |
| 409 | violação de regra de negócio/estado (código estável `modulo.regra`) |
| 422 | validação de campos |
| 429 | rate limit |
| 5xx | falha interna — nunca vazar stack trace |

`code` é estável e documentado; frontend e integrações tratam por ele, não pela mensagem.

## 4. Consulta: paginação, filtro, ordenação

- Paginação: `?page=2&per_page=25` (máx. 100) → `meta: {current_page, per_page, total}`.
- Filtros: `?filter[status]=confirmed&filter[channel]=woocommerce` (spatie/query-builder; filtros permitidos são allowlist por endpoint).
- Ordenação: `?sort=-created_at,name`. Includes: `?include=items,customer` (allowlist).
- Busca textual: `?filter[q]=coruja` nos recursos com busca.

## 5. Segurança

- **SPA**: Sanctum cookie de sessão (SameSite=Lax, Secure) + CSRF. **Integrações/scripts**: personal access tokens com *abilities* mínimas e expiração; rotação documentada (pasta 25).
- Rate limit: 60 req/min autenticado (per user), 10 req/min em endpoints de login; webhooks têm limites próprios.
- **Idempotência**: mutações críticas expostas a integrações (criação de pedido) aceitam header `Idempotency-Key`; repetição retorna a resposta original (armazenada 24 h).
- Webhooks de entrada: assinatura HMAC verificada + resposta rápida (processamento em fila) — pasta 15.
- CORS: allowlist somente `gestao.donaarteira.com.br` (e staging).

## 6. Dependências

Contrato definido aqui alimenta: 06-Frontend (client gerado), 15/16 (integrações), 22 (testes de contrato).

## 7. Boas práticas

- Endpoint novo nasce no OpenAPI **antes** do controller ([fluxo](01-fluxo-openapi.md)).
- Nunca quebrar contrato em v1: campo novo é sempre opcional; remoção exige depreciação documentada (header `Deprecation` + changelog da API).
- Resources nunca serializam model inteiro por reflexo — campos explícitos (evita vazar coluna nova sensível).

## 8. Riscos

| Risco | Mitigação |
|---|---|
| Drift entre OpenAPI e implementação | Teste de contrato no CI valida respostas contra o spec (pasta 22) |
| Endpoints "temporários" fora do padrão | Revisão da skill `criar-api`; sem exceções sem ADR |

## 9. Evoluções futuras

- Webhooks de saída do ERP assinados (fase 6+) para parceiros.
- Escopo OAuth para apps de terceiros (somente se marketplace próprio de integrações surgir).
