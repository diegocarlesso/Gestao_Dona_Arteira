# Testing Patterns

**Analysis Date:** 2026-07-07

> **Two source layers, clearly separated in this document — read this before anything else:**
> - **[EXISTS]** — what is actually present and runnable in the repository today.
> - **[PLANNED]** — the testing strategy mandated by `docs/22-Testes/README.md` and `CLAUDE.md` rule 8 for the future ERP (Gate 01 not started as of this analysis; see `docs/28-Roadmap/`). None of this is implemented yet — no `composer.json`, no `package.json`, no test runner config exists anywhere in the repo.
>
> **Bottom line: [EXISTS] = zero.** There is no test framework installed, no test file, no CI config, and no coverage report anywhere in this repository. Every section below documents either that absence (with evidence) or the target policy from `docs/`.

## Test Framework

### [EXISTS]

**Runner:** None.

Evidence:
- `Dona_Arteira_Gestao_desktop/dagestao/requirements.txt` lists exactly five runtime dependencies — `PyQt6==6.7.1`, `SQLAlchemy==2.0.35`, `PyMySQL==1.1.1`, `python-dotenv==1.0.1`, `Pillow==10.4.0`. No `pytest`, no `unittest2`, no `pytest-qt`, no test-related package at all.
- No `requirements-dev.txt`, `pyproject.toml`, `setup.cfg`, `tox.ini`, or `pytest.ini` exists in `Dona_Arteira_Gestao_desktop/`.
- A full file listing of `Dona_Arteira_Gestao_desktop/` (`dagestao/*.py`, `dagestao/da_widgets/*.py`, `dagestao/widgets/*.py`, plus `run.py`, `README.md`, `.gitignore`, `estrutura.txt`) contains **zero** files named `test_*.py`, `*_test.py`, or matching any `*test*` pattern.
- There is no ERP application code in the repository at all (confirmed in `STACK.md`): no `composer.json`, `package.json`, `artisan`, `vite.config.ts` — therefore no `phpunit.xml`, `Pest.php`, `vitest.config.ts`, or `playwright.config.ts` either.

**Assertion Library:** Not applicable — no test code exists to assert anything.

**Run Commands:** None exist. There is no `composer test`, `npm test`, `pytest`, or equivalent script anywhere in the repo. Do not invent one when documenting this repo's current state.

### [PLANNED] — mandated by `docs/22-Testes/README.md` and `CLAUDE.md` rule 8

**Runner (backend):**
- **Pest** — PHP unit + feature test framework, run against **real MariaDB in CI, never SQLite** ("SQLite mente sobre locks/collation", `docs/22-Testes/README.md` §3 rule 7).
- Config: not yet created — will be `Pest.php` / `phpunit.xml` at the Laravel app root once Gate 01 scaffolding begins.

**Runner (frontend):**
- **Vitest** + **Testing Library** for component tests (`docs/06-Frontend/README.md` line 23; `docs/22-Testes/README.md` §2).
- Config: not yet created — will be `vitest.config.ts`.

**Runner (E2E):**
- **Playwright**, planned from "fase 2" onward, not Gate 01 (`docs/22-Testes/README.md` §2). Runs nightly in CI, not on every PR.

**Static analysis (same pipeline as tests, CI-blocking, `docs/22-Testes/README.md` §4):**
- **Pint** (formatting), **PHPStan/Larastan level 8**, **ESLint + `tsc --noEmit`**, `composer audit` / `npm audit` (fails CI on high-severity vulnerabilities).

**Planned run commands (from CI pipeline description, `docs/23-Deploy/README.md` §3 — no actual scripts exist yet):**
```
Lint: Pint + ESLint + tsc  →  PHPStan nível 8  →  Pest em MariaDB real + contrato OpenAPI  →  Build Vite
```
CI target: full suite (lint + static analysis + tests + contract check) under **5 minutes** (`docs/22-Testes/README.md` §5 risk table).

## Test File Organization

### [EXISTS]
Not applicable — no test files exist to organize.

### [PLANNED]
- Backend: tests live inside each domain module's own `Tests/` directory, mirroring the modular-monolith layout — `app/Modules/<Module>/Database/{migrations,factories,seeders}` sits alongside `app/Modules/<Module>/Tests/` (`docs/05-Backend/README.md` §2, referenced by `CONVENTIONS.md` naming section). Split Unit vs Feature is implied by the pyramid (`docs/22-Testes/README.md` §2): unit tests target domain invariants/state machines/calculations; feature tests target full API endpoints via `RefreshDatabase`.
- Frontend: no explicit co-location rule documented beyond feature-based structure (`src/features/<feature>/{components,hooks,api,schemas,pages}`, `docs/06-Frontend/README.md` §3, quoted in `CONVENTIONS.md`) — Vitest component tests are expected to sit near the components they cover, in line with that feature-first layout, but no doc states a `*.test.tsx` vs `__tests__/` convention explicitly.
- Naming rule that **is** explicit and non-negotiable: **the test name embeds the business rule ID** whenever a BR is exercised — `it('BR-201: rejeita movimento que negativaria o saldo')` (`docs/22-Testes/README.md` §3 rule 1, `CLAUDE.md` rule 3, echoed in `CONVENTIONS.md` Comments section). This is how rule↔test traceability is audited at each gate close (`.claude/agents/qa-specialist.md` "Missão").

## Test Structure

### [EXISTS]
Not applicable — no test suite exists to show a structure from.

### [PLANNED]
No concrete `it(...)`/`test(...)` code exists in the repo to quote verbatim. The only structural pattern documented is the **test pyramid** itself (`docs/22-Testes/README.md` §2):

| Level | Tool | Covers | Target |
|---|---|---|---|
| Unit (domain) | Pest | invariants, state machines, calculations (average cost, totals, taxes), business rules | ≥ 80% in core modules; **every implemented BR has a test named with its ID** |
| Feature (API) | Pest + `RefreshDatabase` | full endpoint: auth, validation, rule, response, event fired | every endpoint: happy path + 403 by role + business errors |
| Contract | OpenAPI validation | real response ≡ spec (`docs/07-API/`) | all public endpoints |
| External integration | Pest + recorded payloads (fixtures) | Woo/SEFAZ/Melhor Envio adapters with HTTP fake | mapping edge cases; **real sandbox** run manually before each gate |
| Frontend | Vitest + Testing Library | critical components: masked/validated forms, tables, permission guards | main form flows |
| E2E | Playwright (phase 2+) | 5 smoke flows: login, counter sale, simulated Woo order, production-order apportionment, NF-e homolog issuance | runs in nightly CI |

**Setup/teardown pattern (planned):** Feature tests use Laravel's `RefreshDatabase` trait against a real MariaDB test database (not SQLite) — stated explicitly as a hard rule, not a suggestion, because SQLite's locking/collation semantics differ from production (`docs/22-Testes/README.md` §3 rule 7, §2 Feature row).

**Migration tests (planned, `docs/04-Banco-de-Dados/02-convencoes-de-banco.md` and `docs/22-Testes/README.md` §3 rule 4):**
- Every migration's `down()` must be functional and is tested: CI runs `migrate → rollback → migrate`.
- Importers are asserted idempotent by running twice over a fixture dump in CI and asserting no duplication (references `BR-706`, tied to the legacy-data migration effort described in `docs/17-Migracao/` and `docs/31-Inventario-Legado/`).

**Idempotency tests (planned, `docs/22-Testes/README.md` §3 rule 5):** every Job/Listener gets a test asserting "run twice = one effect" — called out repeatedly across integration skills, e.g. `.claude/skills/importador-woocommerce/SKILL.md` ("importador roda 2× sobre fixture → mesmos números"), `.claude/skills/integracao-melhor-envio/SKILL.md` ("compra de etiqueta idempotente (teste 2×)").

## Mocking

### [EXISTS]
Not applicable — no test/mock code exists. Note for context only: the legacy app has no dependency-injection seams (direct `SessionLocal()` context managers used inline in UI handlers, per `CONVENTIONS.md` Function/Class Design section) which would make it hard to unit test even if tests were retrofitted onto it — this is a structural reason not to invest in testing the legacy app, consistent with `CLAUDE.md` rule 9 (read-only reference, never evolve).

### [PLANNED]
- **HTTP fake for external integrations:** Woo/SEFAZ/Melhor Envio adapters are tested against **recorded payload fixtures** with Laravel's HTTP fake, not against the real services in CI (`docs/22-Testes/README.md` §2 "Integração externa" row). Real sandbox calls happen manually before each gate close, not automatically in CI.
- **What to fake:** the external HTTP boundary only — "o contrato do adapter é testado, não o Woo inteiro" (only the adapter's contract, not the whole of WooCommerce) — fixtures are kept minimal, containing only the fields actually consumed, specifically to avoid tests breaking on irrelevant upstream payload noise (`docs/22-Testes/README.md` §5 risk table).
- **What NOT to fake:** the database. Feature tests run against real MariaDB (`RefreshDatabase`), never SQLite or an in-memory mock, "mesma engine de produção" (`docs/22-Testes/README.md` §3 rule 7).
- **Fiscal (NF-e) fixtures:** versioned sample XMLs covering authorized returns and common SEFAZ rejections, so the NF-e flow can be exercised without hitting SEFAZ (`docs/22-Testes/README.md` §3 rule 3; detailed further per `.claude/skills/integracao-sefaz/SKILL.md`, which calls for fixtures covering rejection codes 204/539/778, timeout, and numbering concurrency, plus a manual batch of 20 notes in SEFAZ homologation before go-live).

## Fixtures and Factories

### [EXISTS]
None. No factory or fixture files exist anywhere in the repository.

### [PLANNED]
- **Model factories with named states** are mandatory for every new Eloquent model — `Order::factory()->paid()`, `Order::factory()->wholesale()` — never depend on production seed data for tests (`docs/22-Testes/README.md` §3 rule 6; `.claude/skills/criar-model/SKILL.md` checklist item "Factory com estados nomeados úteis?").
- Factories are a required deliverable of the `criar-model` skill itself: "Model + enums + factory + testes de invariantes" (`.claude/skills/criar-model/SKILL.md`).
- Report/dashboard tests use a dedicated factory dataset to assert **exact totals**, shared between the report and its equivalent dashboard so both are validated against the same numbers (`.claude/skills/criar-relatorio/SKILL.md`, `.claude/skills/criar-dashboard/SKILL.md`).
- **Integration fixtures** (WooCommerce/Melhor Envio/SEFAZ payloads) are versioned in-repo, anonymized where they originate from real production payloads (`.claude/skills/integracao-woocommerce/SKILL.md`: "Testes com fixtures de payloads reais anonimizados").
- Location: not yet specified in docs beyond the Laravel default (`database/factories/` per module, i.e. `app/Modules/<Module>/Database/factories/`, inferred from the module layout in `docs/05-Backend/README.md` §2 — no doc states the factory path explicitly, this is an inference from the modular-monolith convention, not a quoted rule).

## Coverage

### [EXISTS]
None. No coverage tool is configured; no coverage report has ever been generated (no ERP code exists to instrument).

### [PLANNED]
**Target:** ≥ 80% in "core modules" for unit/domain tests — but coverage percentage is explicitly demoted to a guard-rail, not a review criterion: "cobertura é guard-rail; revisão foca em BRs testadas, não %" (coverage is a guard-rail; review focuses on tested business rules, not the percentage) (`docs/22-Testes/README.md` §2, §5 risk table). The QA specialist agent reinforces this: "não aprova cobertura como métrica de vaidade" (`.claude/agents/qa-specialist.md` "Limites").

**What actually gates a PR (`docs/22-Testes/README.md` §3 rule 2, `.claude/agents/qa-specialist.md` checklist):**
- Every new domain code path has a test.
- Every implemented BR has a test named with its ID.
- Every new endpoint has happy-path + 403-by-role + validation + documented 409 business-error tests.
- Every new Job/Listener/webhook has an idempotency test.
- Money is compared by exact value, never by float delta.

**View Coverage:** No command exists yet — not specified in any doc (Pest supports `--coverage` but no invocation is documented).

## Test Types

### [EXISTS]
None of the three types below exist in the repository today.

### [PLANNED]

**Unit Tests:**
- Scope: domain invariants, state-machine transitions, calculations (weighted-average cost, order totals, taxes), and business rules — run via Pest, no database dependency implied beyond what a Model invariant needs (`docs/22-Testes/README.md` §2).
- Money assertions must compare exact decimal values (via `brick/money`, per `STACK.md`/`CONVENTIONS.md`), never a float delta/tolerance (`.claude/agents/qa-specialist.md` checklist).

**Integration Tests:**
- Scope: external adapters (WooCommerce, SEFAZ, Melhor Envio) exercised against recorded fixtures with HTTP faked, focused on the de-para (field-mapping) edge cases and idempotency, not the full third-party API surface (`docs/22-Testes/README.md` §2, §5).
- Scope: feature/API tests exercising a full endpoint (auth → validation → business rule → response → event) against real MariaDB via `RefreshDatabase` (`docs/22-Testes/README.md` §2 "Feature (API)" row).
- Scope: contract tests validating real API responses against the OpenAPI spec (`docs/07-API/README.md` line 75; `docs/07-API/01-fluxo-openapi.md` step "Teste de contrato: resposta real ≡ spec"; required deliverable of `.claude/skills/criar-api/SKILL.md` and `.claude/skills/criar-controller/SKILL.md`, both of which end their workflow with "rodar teste de contrato").

**E2E Tests:**
- **Playwright**, deferred to "fase 2" (post-Gate-01), not part of the initial CI-blocking suite. Five smoke flows are pre-specified: login, counter sale ("venda balcão"), simulated WooCommerce order, production-order apportionment ("apontar OP"), and NF-e issuance in SEFAZ homologation (`docs/22-Testes/README.md` §2). Runs nightly in CI, distinct from the PR-blocking pipeline (`docs/23-Deploy/README.md` §3 pipeline diagram shows Pest + contract + Vite build on PR/main; Playwright smoke runs specifically against the staging deploy, per the same diagram's "Smoke E2E no staging" step after automatic staging deploy).

## Common Patterns

### [EXISTS]
None — no async or error-path test code exists anywhere in the repo to quote.

### [PLANNED]
No concrete code samples exist in any doc for async or error testing patterns; only the governing rules are documented (no fabricated code blocks are included here to avoid presenting invented syntax as real):
- **Error/business-rule testing:** every 409 (business-rule violation) response documented in the OpenAPI spec must be exercised by a test, per endpoint (`.claude/agents/qa-specialist.md` checklist: "Endpoint novo: feliz + 403 + validação + erros 409 documentados testados?"). Error responses follow the RFC 9457 problem+json shape documented in `docs/07-API/README.md` §3 (also summarized in `CONVENTIONS.md` Error Handling section) — a business-rule-violation test asserts on the stable `code` field, not the human-readable message, since `code` is the contract (`docs/07-API/README.md` §3, quoted in `CONVENTIONS.md`).
- **Idempotency testing pattern:** "executar 2× = 1 efeito" (execute twice = one effect) is the standard assertion shape for every Job/Listener/webhook/importer test (`docs/22-Testes/README.md` §3 rule 5). Concretely: run the job/import twice against the same fixture/payload, then assert the resulting DB state (row counts, stock balance, invoice numbering) is identical to a single run.
- **Flaky test policy:** a flaky test is quarantined and must be fixed within one week or removed with justification — it is never left to "live" in the suite (`.claude/agents/qa-specialist.md` "Limites").

---

## Summary for planners/executors

When a future GSD phase touches test code in this repository:
1. **There is nothing to extend.** Any test file, config, or CI job is new work, not a modification of existing patterns.
2. **Follow `docs/22-Testes/README.md` as the spec**, not any code in `Dona_Arteira_Gestao_desktop/` — the legacy app has zero tests and is explicitly frozen (`CLAUDE.md` rule 9).
3. **Every test for a business rule must cite the `BR-xxx` ID in its name** (`it('BR-xxx: ...')`) — cross-reference `docs/01-Regras-de-Negocio/01-registro-de-regras.md` for the ID before writing the test.
4. **Money assertions are exact-value, never float-tolerant**; DB tests run on real MariaDB, never SQLite.
5. **New Jobs/Listeners/webhooks/importers require an explicit idempotency test** ("run twice = one effect") before they can be considered done.

---

*Testing analysis: 2026-07-07*
