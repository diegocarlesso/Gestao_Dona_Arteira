# 05 — Backend (Laravel 12 · PHP 8.4)

> **Status:** Em revisão — atualizado para os ADRs 0019 e 0020 · **Última atualização:** 2026-07-22 · **Responsável:** laravel-specialist
> **ADRs:** [0001](../27-ADR/ADR-0001-monolito-modular.md) (monolito modular) · [0005](../27-ADR/ADR-0005-autenticacao-sanctum.md) (Sanctum) · [0014](../27-ADR/ADR-0014-fila-database.md) (filas) · [0015](../27-ADR/ADR-0015-camadas-e-repositorios.md) (camadas) · [0019](../27-ADR/ADR-0019-inertia-substitui-spa.md) (Inertia) · [0020](../27-ADR/ADR-0020-fronteiras-entre-modulos.md) (fronteiras)

## 1. Objetivo

Definir os padrões de engenharia do backend: estrutura de pastas modular, papel de cada camada, pacotes homologados e convenções de código — para que qualquer código escrito em 2026 ou 2029 pareça escrito pela mesma pessoa.

## 2. Estrutura modular

```text
app/
├── Modules/
│   ├── Catalog/        # produtos, categorias, preços, embalagens
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

Cada módulo contém internamente: `Models/`, `Services/`, `Repositories/` (só onde agrega), `Http/{Controllers,Requests}`, `Events/`, `Listeners/`, `Jobs/`, `Policies/`, `Routes/` e o seu `<Módulo>ServiceProvider`. Sem pacote externo de "modules": estrutura por PSR-4 + Service Providers próprios — menos magia, menos dependência. O namespace sai de graça: `app/Modules/Catalog/Models/Product.php` já resolve para `App\Modules\Catalog\Models\Product`.

**Fora do módulo, por decisão do [ADR-0020](../27-ADR/ADR-0020-fronteiras-entre-modulos.md):**

| Artefato | Onde vive | Por quê |
|---|---|---|
| **Migrations** | `database/migrations/` (centralizadas) | A ordem de execução é global, por timestamp; espalhá-las esconde o que importa quando uma FK falha |
| **Factories / Seeders** | `database/{factories,seeders}/<Módulo>/` | Convenção do Laravel |
| **Testes** | `tests/{Feature,Unit}/<Módulo>/` | `php artisan test`, Pest, cobertura e IDE assumem `tests/` |
| **Páginas Inertia** | `resources/js/Pages/<Módulo>/` | [06-Frontend §4](../06-Frontend/README.md) |
| **`Http/Resources`** | só em módulos com API de integração | Com Inertia, tela interna recebe props do controller ([ADR-0019](../27-ADR/ADR-0019-inertia-substitui-spa.md)); Resource é para a API externa ([pasta 07](../07-API/README.md)) |

\* Clientes vivem em Sales por pragmatismo (uso dominante); se Compras/CRM crescerem, extrair para módulo Partners (registrar ADR).

## 2.1 Fronteiras entre módulos ([ADR-0020](../27-ADR/ADR-0020-fronteiras-entre-modulos.md))

**A superfície pública de um módulo são seus `Services/` e seus `Events/`.** Mais nada.

| Um módulo pode, de outro módulo… | |
|---|---|
| Chamar um `Service` | ✅ |
| Ouvir/disparar um `Event` de domínio | ✅ **forma preferida** |
| Depender de uma `Interface` publicada (ex.: `NfeGatewayInterface`) | ✅ |
| Referenciar `Models`, `Repositories`, `Http`, `Jobs` ou `Policies` | ❌ |
| Consultar as tabelas diretamente | ❌ |

- Dependência circular entre módulos é **proibida** — vira evento, ou o conceito sobe para `Support/`.
- `Support/` não depende de módulo algum.
- `Integrations` depende dos Services dos módulos; **nenhum módulo depende de `Integrations`** — fala-se com a interface, o adapter é injetado.

**Isto é verificado por teste, não por disciplina:** os `arch()` do Pest em `tests/Architecture/` rodam no CI e falham no momento em que a violação é escrita.

```php
arch('vendas não acessa models do catálogo')
    ->expect('App\Modules\Sales')
    ->not->toUse('App\Modules\Catalog\Models');
```

## 3. Papel de cada camada (ADR-0015)

| Camada | Faz | Nunca faz |
|---|---|---|
| **Controller** | autoriza (Policy), valida (FormRequest), delega, responde — `Inertia::render(...)` nas telas internas, `Resource` na API de integração ([ADR-0019](../27-ADR/ADR-0019-inertia-substitui-spa.md)) | regra de negócio, query, transação |
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
| `laravel/sanctum` | tokens de integração (usuário humano usa sessão nativa) | [ADR-0005](../27-ADR/ADR-0005-autenticacao-sanctum.md) revisto pelo [0019](../27-ADR/ADR-0019-inertia-substitui-spa.md) |
| `inertiajs/inertia-laravel` | ponte entre controllers e telas React | [ADR-0019](../27-ADR/ADR-0019-inertia-substitui-spa.md) |
| `tightenco/ziggy` | rotas nomeadas do Laravel disponíveis no JS | evita URL literal espalhada |
| `spatie/laravel-permission` | RBAC | ADR-0011, maduro e simples |
| `owen-it/laravel-auditing` | trilha de auditoria | ADR-0012 |
| `brick/money` | dinheiro | ADR-0013 |
| `nfephp-org/sped-nfe` | NF-e | ADR-0009 |
| `pestphp/pest` | testes | pasta 22 |
| `larastan/larastan` + `laravel/pint` | qualidade | CI bloqueante |
| `spatie/laravel-query-builder` | filtros/sort padronizados na API | evita filtro artesanal divergente |
| `laravel/fortify` | **só as 4 Actions de 2FA** — nunca suas rotas/controllers/views | [ADR-0021](../27-ADR/ADR-0021-2fa-totp.md). Provider barrado em `extra.laravel.dont-discover` (ele se auto-registra); traz `pragmarx/google2fa`, `bacon/bacon-qr-code` e `laravel/passkeys` junto, este último também barrado |

Evitar deliberadamente: pacotes de "module system", CQRS frameworks, admin panels prontos (Filament/Nova) — as telas são as páginas Inertia ([ADR-0019](../27-ADR/ADR-0019-inertia-substitui-spa.md)); um admin paralelo criaria duas fontes de verdade de regras.

## 6. Fluxo de desenvolvimento de uma feature

1. Doc do módulo + BRs atualizados → 2. Skill correspondente (`criar-migration`, `criar-service`…) → 3. Testes Pest (unit domínio + feature API) → 4. Pint + PHPStan verdes → 5. Doc revisado no mesmo PR.

## 7. Riscos

| Risco | Mitigação |
|---|---|
| Módulos importarem uns aos outros livremente | **Testes `arch()` do Pest no CI** desde o primeiro módulo ([ADR-0020](../27-ADR/ADR-0020-fronteiras-entre-modulos.md)) — falham no momento da violação, não meses depois. `deptrac` fica como evolução, se a checagem precisar de mais granularidade |
| God-services acumulando casos de uso | 1 Service = 1 caso de uso nomeado (`ConfirmOrderService` ou Action equivalente) |
| Jobs não idempotentes duplicarem efeitos em retry | Checklist da skill `criar-service`/jobs: idempotência obrigatória documentada |

## 8. Evoluções futuras

- Octane/queue workers dedicados quando houver VPS (ADR-0016).
- Extração de contexto para serviço próprio apenas com gatilho objetivo (ADR-0001).
