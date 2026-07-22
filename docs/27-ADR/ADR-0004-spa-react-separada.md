# ADR-0004: Frontend SPA React separada consumindo a API

> **Status:** ❌ **Substituído por [ADR-0019](ADR-0019-inertia-substitui-spa.md)** (2026-07-22) · **Data:** 2026-07-03 · **Decisores:** chief-architect, react-specialist
> **Módulos afetados:** 06, 07, 23
> ⚠️ **Este ADR não vale mais.** A premissa central — "a API REST vai ter que existir de qualquer forma para o WooCommerce" — não resistiu à reconfirmação prevista no item F4 da [análise crítica](../00-Visao-Geral/04-analise-critica-gate00.md). Mantido no repositório como registro histórico, conforme a regra 3 da [pasta 27](README.md).

## Contexto

Stack definida pelo projeto: React + Vite + TypeScript. O ERP é uma aplicação operacional interna (não site público, sem SEO). A API First (ADR-0003) já obriga a existência de contrato completo via REST.

## Decisão

SPA React (Vite build estático) **separada do Laravel** (repositório mono com duas pastas ou dois repos — definir no Gate 01; recomendação: monorepo simples `api/` + `web/`), servida no mesmo domínio `gestao.donaarteira.com.br` (evita CORS/cookies cross-site), autenticada por cookie Sanctum.

## Alternativas consideradas

### Blade + Livewire (full-stack Laravel)
Menos peças móveis, porém contraria a stack definida e acoplaria a UI ao backend; a API completa teria que existir de qualquer forma para Woo/mobile → trabalho dobrado de manutenção de duas superfícies. Descartada.

### Inertia.js (React "acoplado")
Meio-termo atraente, mas a API REST independente é requisito de qualquer forma (integrações/mobile); Inertia criaria um segundo caminho de dados paralelo à API. Descartada.

### Next.js (SSR)
SSR/SEO irrelevantes para ERP interno; adicionaria runtime Node em produção — problemático em shared hosting. Descartada.

## Consequências

**Positivas:** frontend e backend evoluem/deployam separados; API exercitada pelo consumidor nº 1 desde o dia 1 (dogfooding do contrato); build estático é trivial de servir.

**Negativas / dívidas:** duas bases de código para 1 dev (mitigado por tipos gerados + agentes); estado de autenticação/permissão precisa de guards no cliente (padronizados na pasta 06).

**Gatilhos de revisão:** nenhum previsto — decisão estrutural do projeto.
