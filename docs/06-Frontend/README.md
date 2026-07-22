# 06 — Frontend (React · Vite · TypeScript)

> **Status:** Aprovado · **Última atualização:** 2026-07-03 · **Responsável:** react-specialist
> **ADRs:** 0003 (API First) · 0004 (SPA separada) · 0005 (Sanctum)

## 1. Objetivo

Padronizar a SPA de gestão: estrutura, stack de bibliotecas, integração com a API e critérios de qualidade de interface para uso operacional diário (produção, vendas, expedição).

## 2. Stack decidida

| Camada | Escolha | Justificativa |
|---|---|---|
| Build | Vite + TypeScript `strict` | requisito do projeto |
| Roteamento | React Router (v7) | mainstream, documentação abundante |
| Dados de servidor | TanStack Query | cache, invalidação e estados de rede resolvidos; **proibido** duplicar dado de servidor em estado global |
| Estado global de UI | Zustand (mínimo) | só sessão/preferências/UI transversal |
| Formulários | React Hook Form + Zod | validação tipada espelhando regras da API |
| UI kit | Tailwind CSS + shadcn/ui | produtividade + controle visual; componentes copiados são customizáveis para a marca |
| Tabelas | TanStack Table | listagens com paginação/ordenação server-side |
| Datas/moeda | `date-fns` + `Intl.NumberFormat('pt-BR')` | formatação brasileira centralizada em `lib/format.ts` |
| Cliente HTTP | `ky`/fetch tipado gerado do OpenAPI (`openapi-typescript`) | contrato API→tipos sem drift (pasta 07) |
| Testes | Vitest + Testing Library (+ Playwright fase 2) | pasta 22 |

## 3. Estrutura de pastas (feature-based)

```text
src/
├── app/            # bootstrap: router, providers, guards de auth/permissão
├── features/       # espelha os módulos do backend
│   ├── catalog/    ├── production/   ├── inventory/
│   ├── sales/      ├── purchasing/   ├── finance/
│   ├── fiscal/     ├── integrations/ └── identity/
│   └── <feature>/{components, hooks, api, schemas, pages}
├── components/     # UI compartilhada (DataTable, MoneyInput, StatusBadge…)
├── lib/            # api client gerado, format, permissions helper
└── styles/
```

Regra: feature não importa de outra feature — compartilhado sobe para `components/`/`lib/`.

## 4. Integração com a API

- Autenticação por **cookie de sessão Sanctum** (mesmo domínio raiz): CSRF bootstrap em `/sanctum/csrf-cookie`, sem token em localStorage (XSS-safe) — ADR-0005.
- Tipos e client **gerados do OpenAPI** no CI; PR que muda a API sem regenerar client falha.
- Erros da API (formato pasta 07) tratados centralmente: 401 → login; 403 → tela de sem-permissão; 422 → mapeado aos campos do formulário; 5xx → toast + correlation id visível para suporte.
- Permissões: API entrega as permissões do usuário no login; helper `can('inventory.adjust')` esconde ações não permitidas (**a autoridade é sempre o backend** — UI só melhora UX).

## 5. Padrões de interface

- Desktop-first (uso em balcão/escritório), funcional em tablet (produção no ateliê).
- pt-BR em toda a UI; moeda R$, datas dd/mm/aaaa, decimais com vírgula (máscaras nos inputs de dinheiro/quantidade).
- Toda listagem: busca, filtros persistentes na URL, paginação server-side, estado vazio orientando ação.
- Toda mutação: feedback otimista somente em ações reversíveis; destrutivas pedem confirmação com consequência explícita ("Cancelar pedido libera a reserva de estoque").
- Acessibilidade mínima: navegação por teclado nos formulários de operação (velocidade no balcão), contraste AA, foco visível.
- Componentes com estados obrigatórios: loading (skeleton), erro (retry), vazio.

## 6. Dependências

Depende de: 07-API (contrato), 19-Permissoes (gating de UI). Alimenta: 21-Dashboards (componentes de gráfico).

## 7. Riscos

| Risco | Mitigação |
|---|---|
| Drift entre tipos TS e API real | Client gerado do OpenAPI no CI (bloqueante) |
| Regra de negócio vazar para o frontend | Frontend só formata e orquestra UX; validação de negócio é 422 da API |
| UI travar operação com internet instável no ateliê | TanStack Query com retry; avaliar modo leitura offline (fase 7, junto do mobile) |

## 8. Evoluções futuras

- Tema visual da marca Dona Arteira (design tokens) — fase 2.
- PWA leve para apontamento de produção em tablet — fase 6/7.
- App mobile (React Native) reutilizando o client gerado — fase 7.
