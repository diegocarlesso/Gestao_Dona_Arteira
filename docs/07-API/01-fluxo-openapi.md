# Fluxo OpenAPI (Documentação Primeiro)

> **Status:** Em revisão — escopo ajustado pelo [ADR-0019](../27-ADR/ADR-0019-inertia-substitui-spa.md) · **Última atualização:** 2026-07-22 · **Responsável:** api-specialist

## 1. Objetivo

Garantir que o contrato da **API de integração** seja **escrito, revisado e versionado antes** da implementação, eliminando drift entre o que é prometido e o que é entregue.

> ⚠️ **Alcance (2026-07-22):** este fluxo vale para os endpoints consumidos por sistemas externos ([07 §2](README.md#2-o-que-está-e-o-que-não-está-nesta-superfície)). As telas internas do ERP usam Inertia e **não passam por aqui** — sua garantia equivalente é o teste `assertInertia` ([06-Frontend §7](../06-Frontend/README.md)). A geração de client TypeScript deixou de existir.

## 2. Artefatos

```text
docs/07-API/openapi/
├── openapi.yaml          # raiz (info, servers, security, tags)
├── components/           # schemas reutilizáveis (Money, Problem, Pagination…)
└── paths/                # um arquivo por recurso (products.yaml, orders.yaml…)
```

O spec é a **fonte da verdade** do contrato (OpenAPI 3.1). Ferramentas: `redocly lint` (CI) e Redoc para navegação humana — é também a documentação que se entrega a um integrador parceiro.

## 3. Fluxo para criar/alterar endpoint

```mermaid
flowchart LR
    A[Doc do módulo + BR ok] --> A2{Consumidor é<br/>externo?}
    A2 -- não --> A3[Tela interna:<br/>controller + Inertia<br/>não use este fluxo]
    A2 -- sim --> B[Editar spec OpenAPI<br/>paths + schemas + exemplos]
    B --> C[redocly lint + revisão<br/>api-specialist]
    C --> D[Implementar controller/service<br/>skill criar-api]
    D --> E[Teste de contrato:<br/>resposta real ≡ spec]
```

Checklist mínimo por endpoint no spec: descrição em pt-BR, exemplos de request/response, **todos** os erros possíveis (incluindo códigos 409 de negócio), permissões requeridas (extensão `x-permission`), paginação/filtros documentados.

## 4. Regras

1. PR que altera rota/Resource sem alterar o spec é rejeitado (checklist da skill `criar-api`).
2. Spec de endpoint não implementado é marcado `x-status: planned` — o roadmap da API vive no próprio spec.
3. Exemplos do spec usam dados do domínio real (peça "Coruja Decorativa GB-0042"), nunca foo/bar — os exemplos viram documentação viva para integradores.
4. Versionamento: o spec é versionado junto do código; tag de release congela o contrato daquele release.

## 5. Riscos

| Risco | Mitigação |
|---|---|
| Spec virar burocracia e ser preenchido depois do código | Teste de contrato falha se divergir; cultura docs-first (pasta 00/03) |
| Spec monolítico gigante | Particionado por recurso em `paths/` com `$ref` — e a superfície agora é pequena por decisão ([ADR-0019](../27-ADR/ADR-0019-inertia-substitui-spa.md)) |
| Endpoint de tela interna entrar aqui por hábito | A primeira decisão do fluxo (§3) é justamente essa pergunta; revisão de PR barra |
