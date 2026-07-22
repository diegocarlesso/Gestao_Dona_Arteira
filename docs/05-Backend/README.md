# 05 — Backend (Laravel 12 · PHP 8.4)

> **Status:** Aprovado · **Última atualização:** 2026-07-03 · **Responsável:** laravel-specialist
> **ADRs:** 0001 (monolito modular) · 0005 (Sanctum) · 0014 (filas) · 0015 (camadas)

## 1. Objetivo

Definir os padrões de engenharia do backend: estrutura de pastas modular, papel de cada camada, pacotes homologados e convenções de código — para que qualquer código escrito em 2026 ou 2029 pareça escrito pela mesma pessoa.

## 2. Estrutura modular

```text
app/
├── Modules/
│   ├── Catalog/        # produtos, categorias, preços, embalagens, moldes
│   ├── Production/     # OPs, etapas, perdas, consumo
│   ├── Inventory/      # movimentos, saldos, reservas, contagens
│   ├── Sales/          # pedidos, expedição, clientes*
│   ├── Purchasing/     # fornecedores, compras, recebimento
│   ├── Finance/        # títulos, baixas, categorias, contas
│   ├── Fiscal/         # NF-e, séries, perfis fiscais
│   ├── Identity/       # usuários, papéis, permissões, 2FA
│   └── Integrations/   # ACL: WooCommerce, Sefaz, MelhorEnvio, Mail
│       ├── WooCommerce/{Client, Adapters, Jobs, Webhooks, DTOs}
│       └── ...
├── Support/            # helpers transversais (Money, Correlation, etc.)
└── Http/ …             # bootstrap padrão Laravel (rotas montam módulos)
```

Cada módulo contém internamente: `Models/`, `Services/`, `Http/{Controllers,Requests,Resources}`, `Events/`, `Listeners/`, `Jobs/`, `Policies/`, `Database/{migrations,factories,seeders}`, `Tests/`. Sem pacote externo de "modules": estrutura por PSR-4 + Service Providers próprios — menos magia, menos dependência.

\* Clientes vivem em Sales por pragmatismo (uso dominante); se Compras/CRM crescerem, extrair para módulo Partners (registrar ADR).

## 3. Papel de cada camada (ADR-0015)

| Camada | Faz | Nunca faz |
|---|---|---|
| **Controller** | autoriza (Policy), valida (FormRequest), delega, responde (Resource) | regra de negócio, query, transação |
| **FormRequest** | validação sintática + de formato | regra que depende de estado do domínio |
| **Service/Action** | orquestra caso de uso; **dono da transação**; dispara eventos | conhecer HTTP (request/response) |
| **Model** | invariantes locais, relações, casts, scopes nomeados | chamadas externas, side effects ocultos em boot() além de auditoria |
| **Repository** | consultas complexas/reutilizadas, nomeadas pelo negócio (`OverdueReceivables`) | virar passthrough burocrático de CRUD trivial |
| **Job/Listener** | efeito colateral re-executável (sync, e-mail) | decidir regra de negócio |
| **Adapter (Integrations)** | traduzir DTO interno ↔ API externa | vazar payload externo para o domínio |

**Sobre repositories (dose):** CRUD simples usa Eloquent direto no Service — repository só quando há consulta complexa reutilizada ou fronteira que mereça mock. Repositório dogmático sobre Eloquent duplica API sem ganho; esta dosagem é decisão registrada (ADR-0015).

## 4. Convenções de código

- `declare(strict_types=1)` em todo arquivo; tipos explícitos sempre (parâmetros, retorno, propriedades).
- Enums nativos PHP para status/tipos (`OrderStatus: string`), com métodos de transição quando forem máquina de estados.
- Dinheiro manipulado com `brick/money`; nunca aritmética float (ADR-0013).
- Exceções de negócio estendem `DomainException` do módulo e carregam código estável (`inventory.insufficient_stock`) mapeado no handler para o formato de erro da API (pasta 07).
- Nomes: classes/métodos em inglês; strings de UI/validação em pt-BR via lang files.
- Datas sempre `CarbonImmutable`; timezone da aplicação `America/Sao_Paulo`, armazenamento UTC.
- Nada de lógica em helpers globais; Support tem classes coesas e testadas.

## 5. Pacotes homologados (adicionar outro = ADR)

| Pacote | Uso | Justificativa |
|---|---|---|
| `laravel/sanctum` | auth SPA + tokens de API | ADR-0005 |
| `spatie/laravel-permission` | RBAC | ADR-0011, maduro e simples |
| `owen-it/laravel-auditing` | trilha de auditoria | ADR-0012 |
| `brick/money` | dinheiro | ADR-0013 |
| `nfephp-org/sped-nfe` | NF-e | ADR-0009 |
| `pestphp/pest` | testes | pasta 22 |
| `larastan/larastan` + `laravel/pint` | qualidade | CI bloqueante |
| `spatie/laravel-query-builder` | filtros/sort padronizados na API | evita filtro artesanal divergente |

Evitar deliberadamente: pacotes de "module system", CQRS frameworks, admin panels prontos (Filament/Nova) — a UI é a SPA React; um admin paralelo criaria duas fontes de verdade de regras.

## 6. Fluxo de desenvolvimento de uma feature

1. Doc do módulo + BRs atualizados → 2. Skill correspondente (`criar-migration`, `criar-service`…) → 3. Testes Pest (unit domínio + feature API) → 4. Pint + PHPStan verdes → 5. Doc revisado no mesmo PR.

## 7. Riscos

| Risco | Mitigação |
|---|---|
| Módulos importarem uns aos outros livremente | Revisão por gate; adotar `deptrac` na Fase 2 para verificar o mapa de dependências |
| God-services acumulando casos de uso | 1 Service = 1 caso de uso nomeado (`ConfirmOrderService` ou Action equivalente) |
| Jobs não idempotentes duplicarem efeitos em retry | Checklist da skill `criar-service`/jobs: idempotência obrigatória documentada |

## 8. Evoluções futuras

- Octane/queue workers dedicados quando houver VPS (ADR-0016).
- Extração de contexto para serviço próprio apenas com gatilho objetivo (ADR-0001).
