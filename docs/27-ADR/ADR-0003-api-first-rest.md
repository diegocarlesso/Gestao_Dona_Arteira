# ADR-0003: API First — REST versionada + OpenAPI como contrato

> **Status:** ✅ Aceito — **escopo reduzido pelo [ADR-0019](ADR-0019-inertia-substitui-spa.md)** (2026-07-22) · **Data:** 2026-07-03 · **Decisores:** chief-architect, api-specialist
> **Módulos afetados:** 07, 06, 15, 16
> ⚠️ O princípio (REST versionada, OpenAPI como contrato, spec antes do controller) **permanece válido para a API de integração**. Deixou de valer para as telas internas do ERP, que passaram a usar Inertia.

## Contexto

O ERP terá múltiplos consumidores ao longo dos anos: SPA, WooCommerce, Melhor Envio, futuro app mobile e marketplaces. Princípios do projeto exigem API First e documentação primeiro; nenhum sistema externo acessa o banco (BR-701).

## Decisão

API REST JSON versionada por URL (`/api/v1`), com **OpenAPI 3.1 escrito antes da implementação** como contrato canônico (fluxo na pasta 07/01), erros RFC 9457, ações de negócio como sub-recursos (`POST /orders/{id}/confirm`) e client TypeScript gerado do spec.

## Alternativas consideradas

### GraphQL
Flexível para consumidores variados, mas: cache/autorização por campo mais complexos, tooling PHP menos maduro, curva para 1 dev. Os consumidores são conhecidos e controlados — flexibilidade de query não paga o custo. Descartada.

### REST sem spec formal (docs manuais)
Sempre desatualiza; sem geração de tipos, o frontend derrapa. Viola "documentação primeiro". Descartada.

### RPC-style interno (Inertia.js)
Acoplaria frontend ao backend e mataria o reuso da API por integrações/mobile — contra o requisito central. Descartada.

## Consequências

**Positivas:** um contrato para todos os consumidores; tipos gerados eliminam drift; integrações futuras (mobile, marketplace) já têm porta pronta; testes de contrato.

**Negativas / dívidas:** manter spec exige disciplina (mitigada por CI que compara implementação × spec); versionamento de API adiciona cerimônia quando houver breaking change.

**Gatilhos de revisão:** consumidor externo com necessidades de agregação muito diferentes (BI pesado) → endpoint de export dedicado, não mudar o estilo.
