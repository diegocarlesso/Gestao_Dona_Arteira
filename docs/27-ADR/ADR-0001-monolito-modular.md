# ADR-0001: Monolito modular Laravel (não microserviços)

> **Status:** Aceito · **Data:** 2026-07-03 · **Decisores:** chief-architect (dono informado)
> **Módulos afetados:** 02, 03, 05

## Contexto

ERP completo (produção, estoque, vendas, financeiro, fiscal, integrações) para empresa artesanal de pequeno porte; equipe de desenvolvimento de 1 pessoa assistida por agentes; hospedagem única (Hostinger); volume estimado ≤ 1.000 pedidos/mês; forte consistência transacional entre estoque/vendas/fiscal.

## Decisão

Um único aplicativo Laravel 12 com **módulos por bounded context** (`app/Modules/*`), regras de dependência explícitas entre módulos (pasta 03) e comunicação interna por serviços e eventos de domínio. Uma única base MariaDB.

## Alternativas consideradas

### Microserviços por domínio
Isolamento e escala independentes — porém: N deploys, N bancos, transações distribuídas (saga para reservar estoque!), observabilidade complexa, custo de infra multiplicado. Suicídio operacional para 1 dev. Descartada.

### Monolito sem modularização (Laravel "padrão MVC solto")
Mais rápido no início; degenera em acoplamento total em 1–2 anos de ERP (dezenas de entidades). Descartada — a modularização custa pouco agora e paga sempre.

### Dois aplicativos (ERP + serviço de integrações separado)
Isolaria falhas de sync, mas duplica deploy/infra cedo demais. A arquitetura escolhida permite extrair Integrations depois sem redesign (módulo já é isolado atrás de eventos/fila).

## Consequências

**Positivas:** um deploy, uma transação de banco para invariantes críticas, refatoração barata, onboarding simples, custo mínimo de infra.

**Negativas / dívidas:** escala vertical apenas (suficiente por anos no volume previsto); disciplina de fronteiras exige revisão constante (deptrac na fase 2); um bug grave pode derrubar tudo (mitigado por testes+monitoramento).

**Gatilhos de revisão:**
- Fila de integração atrasando > 5 min de forma recorrente mesmo com workers dedicados → extrair workers.
- Equipe ≥ 4 devs com conflitos frequentes de deploy → reavaliar particionamento.
