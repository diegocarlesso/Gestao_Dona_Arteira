# 07 — API de Integração

> **Status:** Em revisão — escopo reduzido pelo [ADR-0019](../27-ADR/ADR-0019-inertia-substitui-spa.md) · **Última atualização:** 2026-07-22 · **Responsável:** api-specialist
> **Documentos:** [Fluxo OpenAPI](01-fluxo-openapi.md)
> **ADRs:** [0003](../27-ADR/ADR-0003-api-first-rest.md) (API First — princípio mantido, escopo reduzido) · [0005](../27-ADR/ADR-0005-autenticacao-sanctum.md) (tokens) · [0019](../27-ADR/ADR-0019-inertia-substitui-spa.md)

## 1. Objetivo

Definir o contrato da API do ERP para **consumidores externos**: convenções REST, formato de erro, paginação, versionamento e segurança.

**Mudança de escopo (2026-07-22).** Até o [ADR-0019](../27-ADR/ADR-0019-inertia-substitui-spa.md), esta API era a via de acesso de *tudo*, inclusive das telas do próprio ERP. Com a adoção do Inertia, as telas internas passaram a receber dados diretamente dos controllers, e esta API atende apenas quem está **fora** do sistema.

[BR-701](../01-Regras-de-Negocio/01-registro-de-regras.md) continua íntegro: **nenhum sistema externo toca o banco do ERP** — toda troca passa por aqui, autenticada.

## 2. O que está (e o que não está) nesta superfície

**Está:**

| Consumidor | Uso | Fase |
|---|---|---|
| **WooCommerce** | receptores de webhook (pedido criado/atualizado, cliente, reembolso) | Gate 02 |
| **Provedor de cobrança** | webhook de liquidação de boleto/PIX ([ADR-0018](../27-ADR/ADR-0018-cobranca-boleto.md)) | Gate 04 |
| **Melhor Envio** | webhook de rastreio | Gate 06 |
| **Scripts de migração e manutenção** | tokens de máquina | Gate 01 |
| Parceiros e marketplaces | endpoints de leitura/escrita sob contrato | Fase 7 |
| Eventual app mobile | ampliação futura da superfície | Fase 7 |

**Não está:** as telas do ERP. Criar endpoint de API para alimentar tela interna é proibido ([06-Frontend §2](../06-Frontend/README.md)).

> As chamadas **de saída** do ERP para APIs de terceiros (Woo, SEFAZ, banco, Melhor Envio) não pertencem a este documento — vivem na camada `Integrations` ([pasta 15](../15-Integracoes/README.md)).

## 3. Convenções REST

- Base: `https://gestao.donaarteira.com.br/api/v1` — versionamento por URL; quebra de contrato exige `v2` com período de convivência.
- Recursos em inglês, plural, kebab-case: `/products`, `/production-orders`, `/fiscal-documents`.
- Identificador público: **ULID** (`public_id`) — nunca expor auto-increment.
- Verbos: GET (leitura), POST (criação/ações), PUT/PATCH (atualização), DELETE (inativação conforme [BR-008](../01-Regras-de-Negocio/01-registro-de-regras.md)).
- **Ações de negócio são sub-recursos POST explícitos**, nunca PATCH de status: `POST /orders/{id}/confirm`, `/cancel`, `/ship`. A máquina de estados valida a transição ([BR-303](../01-Regras-de-Negocio/01-registro-de-regras.md)).
- Respostas: `data` (payload) e `meta` (paginação). Datas em ISO-8601 UTC; dinheiro como **string decimal** (`"149.90"`) + `currency` — nunca float em JSON ([ADR-0013](../27-ADR/ADR-0013-dinheiro-decimal.md)).

## 4. Erros — RFC 9457 (`application/problem+json`)

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
| 404 | recurso inexistente (ou fora do escopo do token) |
| 409 | violação de regra de negócio/estado (código estável `modulo.regra`) |
| 422 | validação de campos |
| 429 | rate limit |
| 5xx | falha interna — **nunca** vazar stack trace |

`code` é estável e documentado: integrações tratam por ele, jamais pela mensagem.

> O mesmo vocabulário de erro de domínio serve às telas internas — lá ele chega como mensagem flash ou erro de campo, em vez de JSON ([06-Frontend §6](../06-Frontend/README.md)). A regra é única; muda só a apresentação.

## 5. Consulta: paginação, filtro, ordenação

- Paginação: `?page=2&per_page=25` (máx. 100) → `meta: {current_page, per_page, total}`.
- Filtros: `?filter[status]=confirmed&filter[channel]=woocommerce` — allowlist por endpoint.
- Ordenação: `?sort=-created_at,name`. Includes: `?include=items,customer` (allowlist).
- Busca textual: `?filter[q]=coruja` nos recursos que a suportam.

## 6. Segurança

- **Autenticação:** *personal access tokens* do Sanctum, com *abilities* mínimas e expiração; rotação documentada ([pasta 25](../25-Seguranca/README.md)). Não há mais autenticação por cookie de sessão nesta superfície — usuários humanos usam as telas Inertia ([ADR-0005](../27-ADR/ADR-0005-autenticacao-sanctum.md), revisto pelo 0019).
- **CORS:** desnecessário para as telas (mesma origem). Habilitar **apenas** se um parceiro externo em navegador vier a consumir a API — com allowlist explícita, nunca `*`.
- **Rate limit:** 60 req/min por token; webhooks têm limites próprios, dimensionados por origem.
- **Idempotência:** mutações críticas expostas a integrações (criação de pedido) aceitam o header `Idempotency-Key`; repetição retorna a resposta original, armazenada por 24 h.
- **Webhooks de entrada:** assinatura HMAC verificada **antes** de qualquer processamento, resposta imediata e trabalho em fila ([pasta 15](../15-Integracoes/README.md)); `delivery_id` único garante idempotência ([BR-703](../01-Regras-de-Negocio/01-registro-de-regras.md)).

## 7. Boas práticas

- Endpoint novo nasce no **OpenAPI antes** do controller ([fluxo](01-fluxo-openapi.md), skill `criar-api`). Isso continua obrigatório — só que agora se aplica a uma superfície bem menor.
- Nunca quebrar contrato em `v1`: campo novo é sempre opcional; remoção exige depreciação documentada (header `Deprecation` + changelog).
- Resources nunca serializam o model inteiro por reflexo — campos explícitos, para não vazar coluna nova sensível.
- Endpoint sem consumidor identificado **não é escrito**. A superfície pequena é uma vantagem a ser defendida, não um estado transitório.

## 8. Dependências

| Depende de | Motivo |
|---|---|
| [05-Backend](../05-Backend/README.md) | controllers e services que a API expõe |
| [19-Permissoes](../19-Permissoes/README.md) | *abilities* dos tokens |

**Alimenta:** [15-Integracoes](../15-Integracoes/README.md) e [16-WooCommerce](../16-WooCommerce/README.md) (contrato dos webhooks), [22-Testes](../22-Testes/README.md) (testes de contrato).

## 9. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Drift entre o OpenAPI e a implementação | Média | Médio | Teste de contrato no CI valida a resposta real contra o spec ([pasta 22](../22-Testes/README.md)) |
| A API voltar a inchar para servir telas | **Alta** | Médio | Proibição explícita (§2); revisão de PR barra |
| Perder o *dogfooding* que a SPA daria ao contrato | Certa | Médio | Dívida assumida no [ADR-0019](../27-ADR/ADR-0019-inertia-substitui-spa.md); compensada por testes de contrato nos endpoints de integração |
| Webhook processado de forma síncrona travar o parceiro | Média | Alto | Assinatura → 2xx imediato → fila ([pasta 15](../15-Integracoes/README.md)) |

## 10. Evoluções futuras

- Webhooks **de saída** assinados, do ERP para parceiros (fase 6+).
- Ampliação da superfície para app mobile (fase 7) — é o gatilho de revisão registrado no [ADR-0019](../27-ADR/ADR-0019-inertia-substitui-spa.md).
- Escopos OAuth para apps de terceiros, apenas se um ecossistema de integrações próprio surgir.
