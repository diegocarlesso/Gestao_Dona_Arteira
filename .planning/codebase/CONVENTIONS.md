# Coding Conventions

**Analysis Date:** 2026-07-06

> **Two source layers, clearly separated in this document:**
> - **[OBSERVADO]** — conventions actually found in the only executable code in the repo: the legacy reference app `Dona_Arteira_Gestao_desktop/dagestao/` (Python 3 + PyQt6 + SQLAlchemy 2.0). This app is **read-only reference** per `CLAUDE.md` rule 9 — never evolve it, never copy its shortcuts into the ERP.
> - **[INTENÇÃO/DOC]** — conventions prescribed for the future ERP (Laravel 12 / PHP 8.4 backend, React/TypeScript frontend) as documented in `docs/05-Backend/README.md`, `docs/06-Frontend/README.md`, `docs/27-ADR/`, `docs/04-Banco-de-Dados/02-convencoes-de-banco.md`, and `.claude/skills/`. **No ERP application code exists yet** (Gate 01 not started) — these are standards to apply from the first line of code written.
>
> When planning or executing ERP phases, follow the **[INTENÇÃO/DOC]** conventions. The **[OBSERVADO]** conventions exist only to understand business rules embedded in the legacy app (see `docs/01-Regras-de-Negocio/01-registro-de-regras.md`, entries tagged `legado`) — do not perpetuate their code style.

## Naming Patterns

**Files: [OBSERVADO]**
- Python modules: `snake_case.py` — `models.py`, `db.py`, `validators.py`, `main_window.py`, `order_form.py`.
- Two parallel widget packages exist: `dagestao/widgets/` (dead — only imported from within itself, see `Dona_Arteira_Gestao_desktop/dagestao/widgets/order_manager.py:6`) and `dagestao/da_widgets/` (active — imported by `Dona_Arteira_Gestao_desktop/dagestao/main_window.py:19-25`). This duplication is a legacy artifact, not a pattern to replicate.

**Files: [INTENÇÃO/DOC]**
- Backend (PHP/Laravel): PSR-4 class-per-file, `StudlyCase.php` matching class name, organized under `app/Modules/<Module>/{Models,Services,Http/{Controllers,Requests,Resources},Events,Listeners,Jobs,Policies,Database/{migrations,factories,seeders},Tests}` — see `docs/05-Backend/README.md`.
- Frontend (React/TS): feature-based under `src/features/<feature>/{components,hooks,api,schemas,pages}`; shared UI in `src/components/`, shared logic in `src/lib/` — see `docs/06-Frontend/README.md`.

**Functions/Methods: [OBSERVADO]**
- `snake_case` throughout — `only_digits`, `validate_cpf`, `is_cpf_cnpj_valid` (`Dona_Arteira_Gestao_desktop/dagestao/validators.py`); private/internal helpers prefixed `_` (`_build_ui`, `_save`, `_fill`, `_on_doc_changed` in `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/client_form.py`).
- Qt slot handlers named `on_<event>` or action verbs (`new_client`, `edit_client`, `open_pieces` in `Dona_Arteira_Gestao_desktop/dagestao/main_window.py`).

**Functions/Methods: [INTENÇÃO/DOC]**
- Classes/methods in **English**; UI/validation strings in **pt-BR** via lang files (`docs/05-Backend/README.md` §4).
- One Service class = one named use case in verb-object form: `ConfirmOrderService` (`.claude/skills/criar-service/SKILL.md`), not generic `OrderService` doing everything.
- Model transition methods named by the business action, not by state: `markAsPaid()` (`.claude/skills/criar-model/SKILL.md`), which itself validates the transition and throws a typed domain exception on violation.
- Scopes named by the business concept: `scopeOverdue`, `scopeAvailable` (`.claude/skills/criar-model/SKILL.md`).

**Variables: [OBSERVADO]**
- Short/abbreviated names common: `s` for SQLAlchemy session, `c` for client/customer, `o` for order (`Dona_Arteira_Gestao_desktop/dagestao/main_window.py:170-217`). Acceptable in the legacy app's small scope; **do not carry into ERP code**, which requires explicit typed names (`docs/05-Backend/README.md` §4).

**Types/Enums: [OBSERVADO]**
- Python `enum.Enum` with pt-BR values inline on one line: `class PaymentMethod(enum.Enum): DINHEIRO = "Dinheiro"; PIX = "PIX"; ...` (`Dona_Arteira_Gestao_desktop/dagestao/models.py:65-69`).

**Types/Enums: [INTENÇÃO/DOC]**
- Native PHP enums (backed, typically `string`) for every status/type field — `OrderStatus: string` — with transition methods when the enum models a state machine (`docs/05-Backend/README.md` §4).
- Database convention: enum-like domain columns are `VARCHAR` + PHP enum, **never MySQL native `ENUM`** (`docs/04-Banco-de-Dados/02-convencoes-de-banco.md`).

**Database naming: [INTENÇÃO/DOC]** (`docs/04-Banco-de-Dados/02-convencoes-de-banco.md`)

| Item | Convention | Example |
|---|---|---|
| Tables | English, snake_case, **plural** | `production_orders` |
| Columns | English, snake_case | `unit_price` |
| PK | `id` BIGINT UNSIGNED auto-increment | |
| Public ID | `public_id` ULID CHAR(26) UNIQUE, for API-exposed entities | |
| FK | `<singular>_id` + named constraint | `customer_id`, `fk_orders_customer` |
| Index | `idx_<table>_<columns>` / `uq_` for unique | `uq_products_sku` |
| Boolean | prefix `is_`/`has_` | `is_wholesale` |
| Event dates | suffix `_at` (TIMESTAMP) / `_date` (DATE) | `authorized_at`, `due_date` |

## Code Style

**Formatting: [OBSERVADO]**
- No formatter/linter configured for the legacy app (no `.flake8`, `pyproject.toml`, `black`/`ruff` config found). Style is inconsistent by file: some files pack multiple statements per line with `;` (`Dona_Arteira_Gestao_desktop/dagestao/db.py`, `da_widgets/client_form.py`, `da_widgets/order_form.py`), others use one-statement-per-line (`Dona_Arteira_Gestao_desktop/dagestao/models.py`, `validators.py`).
- No `requirements-dev.txt` or test/lint tooling declared in `Dona_Arteira_Gestao_desktop/dagestao/requirements.txt` (only `PyQt6`, `SQLAlchemy`, `PyMySQL`, `python-dotenv`, `Pillow`).
- Comment markers used to flag incremental edits directly in code: `# >>> novos campos` / `# <<<` and `# >>> NOVO CAMPO` (`Dona_Arteira_Gestao_desktop/dagestao/models.py:19-22,49-51`, `da_widgets/client_form.py:69-72`) — an ad hoc changelog-in-code pattern, not a structured convention.

**Formatting: [INTENÇÃO/DOC]**
- Backend: **Pint** (PHP-CS-Fixer wrapper, Laravel default ruleset), **PHPStan/Larastan level 8**, both CI-blocking (`docs/22-Testes/README.md` §4, `docs/05-Backend/README.md` §5).
- Frontend: **ESLint** + `tsc --noEmit` (TypeScript `strict` mode), CI-blocking (`docs/22-Testes/README.md` §4, `docs/06-Frontend/README.md` §2).
- `declare(strict_types=1)` mandatory in every PHP file; explicit types always (parameters, return, properties) (`docs/05-Backend/README.md` §4).
- Dependency audit gates: `composer audit` / `npm audit` fail CI on high-severity vulnerabilities (`docs/22-Testes/README.md` §4).

## Import Organization

**[OBSERVADO]**
- No enforced order. Files re-import the same symbol redundantly, e.g. `Dona_Arteira_Gestao_desktop/dagestao/models.py:2` and `:6` both import `Column, Integer, String, Float, Text, ForeignKey` from `sqlalchemy`; `Dona_Arteira_Gestao_desktop/dagestao/db.py:1,4,5` re-imports overlapping `sqlalchemy` symbols across three lines.
- Local re-imports inside methods are common (`from styles import PALETTE` inside `_on_doc_changed` and `_save` in `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/client_form.py:40,52`; `from db import SessionLocal` inside `_save` at line 54 despite already being imported at module level line 8) — avoid this pattern in new code; it hides dependencies and risks import-order bugs.

**[INTENÇÃO/DOC]**
- Frontend path aliases and API client are generated from OpenAPI (`openapi-typescript`) — imports of API types/client always come from the generated `lib/` client, never hand-written duplicate types (`docs/06-Frontend/README.md` §2,4).
- Backend: no cross-module imports without justification — module boundaries are enforced by code review pending `deptrac` adoption in Phase 2 (`docs/05-Backend/README.md` §7 risks).
- Frontend rule: a feature module never imports from another feature module; shared code is promoted to `components/` or `lib/` (`docs/06-Frontend/README.md` §3).

## Error Handling

**[OBSERVADO]**
- Blanket `try/except Exception` around nearly every UI action handler in `Dona_Arteira_Gestao_desktop/dagestao/main_window.py` (e.g. `new_client`, `edit_client`, `new_order`, `edit_order`, `open_pieces`, `open_orders`, lines 247-376): logs via `logging.exception(...)`, then shows `QMessageBox.critical(self, "Erro", f"Ocorreu um erro: {e}")`. Exceptions are never typed or distinguished — every failure reaches the user as a generic "Ocorreu um erro" dialog with the raw exception message.
- Silent swallowing (`except Exception: pass`) used for best-effort/non-critical paths: theme restore (`Dona_Arteira_Gestao_desktop/dagestao/main_window.py:111`), optional stylesheet load (`main_window.py:48`), FTP cleanup (`Dona_Arteira_Gestao_desktop/dagestao/storage.py:17,21,31`), directory-exists probing (`storage.py:16`).
- Uncaught exceptions at the Qt application level are routed through a custom excepthook (`_qt_excepthook` in `Dona_Arteira_Gestao_desktop/dagestao/main_window.py:27-34`) that logs and shows a critical dialog with full traceback — this is the only structured top-level handler in the app.
- No custom exception types anywhere in the legacy app — everything is a bare `Exception`.

**[INTENÇÃO/DOC]**
- Business-rule violations are **typed domain exceptions** extending a module's `DomainException`, carrying a stable machine-readable code (e.g. `inventory.insufficient_stock`), mapped centrally by the API exception handler to **RFC 9457 problem+json** responses (`docs/05-Backend/README.md` §4, `docs/07-API/README.md` §3):
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
- HTTP status mapping is fixed: 400 malformed, 401/403 auth, 404 not found, **409 business-rule/state violation** (stable `module.rule` code), 422 field validation, 429 rate limit, 5xx internal (never leak stack trace) (`docs/07-API/README.md` §3).
- Controllers **never** `try/catch` domain exceptions locally — the global handler does the mapping (`.claude/skills/criar-controller/SKILL.md` checklist).
- Frontend error handling is centralized, not per-call: 401 → redirect to login, 403 → no-permission screen, 422 → mapped to form fields, 5xx → toast with visible correlation id for support (`docs/06-Frontend/README.md` §4).
- `code` (not the human message) is the contract integrations and frontend branch on (`docs/07-API/README.md` §3).

## Money & Numeric Handling

**[OBSERVADO] — anti-pattern, do not replicate**
- All monetary and quantity fields use SQLAlchemy `Float` — `retail_price`, `wholesale_price`, `payment_value`, `OrderItem.price` (`Dona_Arteira_Gestao_desktop/dagestao/models.py:41-42,79,90`).
- Manual BRL formatting via string replacement instead of a formatting library: `f"R$ {total:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")` (`Dona_Arteira_Gestao_desktop/dagestao/main_window.py:206`).
- Float parsed straight from UI text fields with no decimal-safety: `float(self.ed_value.text() or 0)` (`Dona_Arteira_Gestao_desktop/dagestao/da_widgets/order_form.py:69`).

**[INTENÇÃO/DOC] — mandatory for ERP code** (`docs/27-ADR/ADR-0013-dinheiro-decimal.md`, `CLAUDE.md` rule 6)
- Database: `DECIMAL(15,2)` for money, `DECIMAL(15,3)` for quantities/weights, `DECIMAL(5,2)` for percentages — never `FLOAT`/`DOUBLE`.
- PHP: all monetary arithmetic via `brick/money`; half-up rounding for fiscal calculations (documented per calculation), half-even only where a specific rule requires it.
- API: money serialized as a **decimal string** + currency code, never a JSON number (avoids float round-trip loss) — `docs/07-API/README.md` §2.
- Frontend never computes an authoritative total — it only displays what the API returns (`docs/27-ADR/ADR-0013-dinheiro-decimal.md`).

## Comments

**[OBSERVADO]**
- Sparse; mostly section-divider comments (`# --------------------------\n# Construção da interface\n# --------------------------` in `Dona_Arteira_Gestao_desktop/dagestao/main_window.py:58-60`) and inline Portuguese explanations of intent (`# Requer cliente selecionado`, `main_window.py:277`).
- No docstrings/type-hint documentation on classes or public methods anywhere in the codebase.
- Change markers embedded directly in code as pseudo-diff comments (see Code Style above) — a substitute for commit history, not a docstring convention.

**[INTENÇÃO/DOC]**
- No formal JSDoc/TSDoc or PHPDoc standard documented; documentation obligation is placed on `docs/` module files and ADRs, not inline comments — "documentation first" rule (`CLAUDE.md` rule 1): before writing code, the relevant `docs/` module file must already cover the behavior; update the doc first if it doesn't.
- Every migration must reference, in a comment, the section of the conceptual model it implements (`docs/04-Banco-de-Dados/02-convencoes-de-banco.md` §Migrations).
- Every business rule implemented in code references its `BR-xxx` ID in a comment/test name (`CLAUDE.md` rule 3; `docs/22-Testes/README.md` §3 rule 1) — e.g. `it('BR-201: rejeita movimento que negativaria o saldo')`.

## Function/Class Design

**[OBSERVADO]**
- UI dialog classes mix all responsibilities in one class: widget construction (`_build_ui`), data population (`_fill`), persistence (`_save`) and validation directly against SQLAlchemy session in the same method — e.g. `ClientFormDialog` (`Dona_Arteira_Gestao_desktop/dagestao/da_widgets/client_form.py`), `OrderFormDialog` (`da_widgets/order_form.py`). No separation between UI, validation, and persistence layers.
- Direct ORM session usage inline inside UI event handlers (`with SessionLocal() as s: ...`) rather than through a service/repository abstraction (`Dona_Arteira_Gestao_desktop/dagestao/da_widgets/order_form.py:31-32,63-82,93-95`).
- Schema migration logic (`_migrate_schema`) lives inline in `db.py` as ad hoc `ALTER TABLE`/rename probing at startup rather than versioned migration files (`Dona_Arteira_Gestao_desktop/dagestao/db.py:15-77`) — no rollback path, no migration history.

**[INTENÇÃO/DOC]**
- Strict layering, enforced by review (`docs/05-Backend/README.md` §3, `docs/27-ADR/ADR-0015-camadas-e-repositorios.md`):

| Layer | Does | Never does |
|---|---|---|
| Controller | authorize (Policy), validate (FormRequest), delegate, respond (Resource) | business rule, query, transaction |
| FormRequest | syntactic/format validation | rule depending on domain state |
| Service/Action | orchestrates the use case; **owns the transaction**; dispatches events | know about HTTP request/response |
| Model | local invariants, relations, casts, named scopes | external calls, hidden side effects in `boot()` beyond auditing |
| Repository | complex/reused queries, named by the business (`OverdueReceivables`) | become a bureaucratic passthrough for trivial CRUD |
| Job/Listener | re-executable side effect (sync, email) | decide business rules |
| Adapter (Integrations) | translate internal DTO ↔ external API | leak external payload into the domain |

- Repository dosage is a deliberate decision (not dogmatic): trivial CRUD uses Eloquent directly in the Service; a Repository is introduced only for complex/reused queries or a boundary that deserves a test double (`docs/05-Backend/README.md` §3, ADR-0015).
- One Service = one named use case; a Service with no business rule and no transaction "should not exist" — call the Model/Eloquent directly instead (`.claude/skills/criar-service/SKILL.md` checklist).
- Business actions are dedicated POST sub-resources (`POST /orders/{id}/confirm`), never a PATCH of a status field (`docs/07-API/README.md` §2, `.claude/skills/criar-controller/SKILL.md`).
- Migrations: one migration = one intent; `down()` must be functional and tested (CI runs migrate + rollback + migrate); destructive changes go through expand/contract across two releases (`docs/04-Banco-de-Dados/02-convencoes-de-banco.md` §Migrations).

## Module Design

**[OBSERVADO]**
- Flat package layout with no internal module boundaries: `dagestao/` mixes UI (`da_widgets/`), persistence (`db.py`, `models.py`), config (`config.py`), external I/O (`storage.py`), and validation (`validators.py`) at the top level with no domain grouping.
- Dead code left in the tree: `dagestao/widgets/` duplicates `dagestao/da_widgets/` and is never imported from the active entry point (`Dona_Arteira_Gestao_desktop/dagestao/run.py` → `main_window.py` → `da_widgets/*`); `widgets/` only imports itself.

**[INTENÇÃO/DOC]**
- Backend: modular monolith by domain (`docs/05-Backend/README.md` §2) — `app/Modules/{Catalog,Production,Inventory,Sales,Purchasing,Finance,Fiscal,Identity,Integrations}`, each with its own `Models/Services/Http/Events/Listeners/Jobs/Policies/Database/Tests`. No third-party "module system" package — plain PSR-4 + custom Service Providers (`docs/27-ADR/ADR-0001-monolito-modular.md`).
- Integrations are isolated behind an ACL layer: `app/Modules/Integrations/{WooCommerce,Sefaz,MelhorEnvio,Mail}`, each with `Client/Adapters/Jobs/Webhooks/DTOs` — external payloads never reach domain code directly (`docs/05-Backend/README.md` §2; `CLAUDE.md` rule 4).
- Frontend: feature-based, mirroring backend modules 1:1 (`catalog`, `production`, `inventory`, `sales`, `purchasing`, `finance`, `fiscal`, `integrations`, `identity`) under `src/features/` (`docs/06-Frontend/README.md` §3).
- Approved package list is closed — adding a new package requires an ADR (`docs/05-Backend/README.md` §5): `laravel/sanctum`, `spatie/laravel-permission`, `owen-it/laravel-auditing`, `brick/money`, `nfephp-org/sped-nfe`, `pestphp/pest`, `larastan/larastan` + `laravel/pint`, `spatie/laravel-query-builder`. Deliberately avoided: "module system" packages, CQRS frameworks, ready-made admin panels (Filament/Nova) — the SPA is the only UI, avoiding a second source of truth for rules.

---

*Convention analysis: 2026-07-06*
