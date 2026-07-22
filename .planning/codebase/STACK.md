# Technology Stack

**Analysis Date:** 2026-07-06

## Important: Two distinct stacks in this repository

This repository currently contains **no ERP application code**. It is in **Gate 01** of a documentation-first project (see `CLAUDE.md`, `README.md`). Two separate technology stacks appear in the repo and must not be conflated:

1. **Legacy reference app (implemented, read-only)** — `Dona_Arteira_Gestao_desktop/` — a Python/PyQt6 desktop application. It is explicitly **not to be evolved** ("somente leitura — referência de regras de negócio, jamais evoluir ou converter automaticamente", `CLAUDE.md` rule 9). It exists only so business rules can be reverse-engineered into the new ERP's documentation.
2. **Target ERP stack (decided, not yet implemented)** — Laravel 12 / PHP 8.4 + React/Vite/TypeScript + MariaDB, defined entirely in ADRs under `docs/27-ADR/` and module docs under `docs/05-Backend/` and `docs/06-Frontend/`. No `composer.json`, `package.json`, `artisan`, or `vite.config.ts` exists yet anywhere in the repo.

Any future implementation work should follow stack #2. Stack #1 is for behavior reference only.

---

## Part A — Legacy reference app (implemented)

**Location:** `Dona_Arteira_Gestao_desktop/dagestao/`

### Languages

**Primary:**
- Python 3 — entire application (`Dona_Arteira_Gestao_desktop/dagestao/*.py`)

### Runtime

**Environment:**
- CPython (version unpinned — no `.python-version` or `pyproject.toml` present)

**Package Manager:**
- pip
- Lockfile: missing (only `Dona_Arteira_Gestao_desktop/dagestao/requirements.txt`, unpinned to a resolved lock)

### Frameworks

**Core:**
- PyQt6 `6.7.1` (`Dona_Arteira_Gestao_desktop/dagestao/requirements.txt`) — desktop GUI framework. Entry point: `Dona_Arteira_Gestao_desktop/dagestao/run.py`, main window: `Dona_Arteira_Gestao_desktop/dagestao/main_window.py`
- SQLAlchemy `2.0.35` — ORM, declarative models in `Dona_Arteira_Gestao_desktop/dagestao/models.py`, engine/session setup in `Dona_Arteira_Gestao_desktop/dagestao/db.py`

**Testing:**
- None detected — no test files, no `pytest`/`unittest` config or dependency in `requirements.txt`

**Build/Dev:**
- None — runs directly via `python run.py`, no packaging/build tooling (no PyInstaller spec, no setup.py)

### Key Dependencies

**Critical:**
- `PyMySQL 1.1.1` — pure-Python MySQL/MariaDB DB-API driver, used by SQLAlchemy's `mysql+pymysql://` connection string (`Dona_Arteira_Gestao_desktop/dagestao/db.py:8-10`)
- `python-dotenv 1.0.1` — loads `.env` for DB/FTP credentials (`Dona_Arteira_Gestao_desktop/dagestao/config.py:3-4`)
- `Pillow 10.4.0` — image handling (piece photos)

**Infrastructure:**
- None (no queue, cache, or external SDK dependencies)

### Configuration

**Environment:**
- `.env` file (not committed — see `.gitignore`), loaded via `python-dotenv` in `Dona_Arteira_Gestao_desktop/dagestao/config.py`
- Required variables (names only, values never read): `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DB`, `MYSQL_USER`, `MYSQL_PASSWORD`, `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD`, `FTP_BASE_PATH`
- Defaults hardcoded in `Dona_Arteira_Gestao_desktop/dagestao/config.py` (e.g. `mysql_host` defaults to `"localhost"`, `mysql_db` to `"dona_arteira"`, `mysql_user` to `"root"`)

**Build:**
- None — no build config files present

### Platform Requirements

**Development:**
- Python 3.x with pip-installable PyQt6 (requires system Qt6 libraries)
- Network access to a MySQL/MariaDB instance and an FTP server (for `Dona_Arteira_Gestao_desktop/dagestao/storage.py`)

**Production:**
- Runs as a local desktop application (Windows, given repo context) — not deployed as a service. Legacy/reference only; not a deployment target going forward.

---

## Part B — Target ERP stack (decided, not implemented)

Source of truth: `docs/27-ADR/` (ADR-0001 through ADR-0017), `docs/05-Backend/README.md`, `docs/06-Frontend/README.md`, `docs/22-Testes/README.md`, `docs/23-Deploy/README.md`. No code exists for any of this yet — treat the following as a specification to implement, not an inventory of running code.

### Languages

**Primary:**
- PHP 8.4 — backend (`docs/05-Backend/README.md`; ADR-0001)
- TypeScript (strict mode) — frontend (`docs/06-Frontend/README.md`; ADR-0004)

**Secondary:**
- SQL (MariaDB dialect) — schema/migrations (`docs/04-Banco-de-Dados/02-convencoes-de-banco.md`)

### Runtime

**Environment:**
- PHP 8.4 (target extensions to validate pre-Gate 05: `openssl`, `soap`, `dom`, `intl`, `gd`, `curl` — `docs/23-Deploy/README.md` §5, `docs/14-NFe/README.md` §6)
- Node.js (version unspecified in docs) for the Vite/React build

**Package Manager:**
- Composer (PHP) — no `composer.json`/`composer.lock` yet
- npm/pnpm/yarn (unspecified which) for the frontend — no `package.json`/lockfile yet

### Frameworks

**Core:**
- Laravel 12 — backend monolith, modular by bounded context (`app/Modules/*`) (ADR-0001; `docs/05-Backend/README.md`)
- React + Vite + TypeScript — SPA frontend, served static, same root domain as the API (ADR-0004; `docs/06-Frontend/README.md`)

**Testing:**
- Pest — PHP unit + feature tests, run against real MariaDB in CI (not SQLite) (`docs/22-Testes/README.md`)
- Vitest + Testing Library — frontend component tests (`docs/06-Frontend/README.md` §2; `docs/22-Testes/README.md`)
- Playwright — planned for E2E smoke tests from "fase 2" onward (5 flows: login, venda balcão, pedido Woo simulado, apontar OP, emitir NF-e homolog) (`docs/22-Testes/README.md` §2)

**Build/Dev:**
- Vite — frontend bundler (ADR-0004)
- Docker Compose (PHP 8.4 + MariaDB) or Laravel Herd — local dev environment (`docs/23-Deploy/README.md` §2)
- GitHub Actions — CI pipeline: Lint (Pint + ESLint + tsc) → PHPStan level 8 → Pest on real MariaDB + OpenAPI contract check → Vite build → staging auto-deploy → E2E smoke → manual tagged production deploy (`docs/23-Deploy/README.md` §3)

### Key Dependencies

**Critical (backend, "pacotes homologados" — adding another requires an ADR, `docs/05-Backend/README.md` §5):**
- `laravel/sanctum` — SPA cookie auth + API tokens (ADR-0005)
- `spatie/laravel-permission` — RBAC (ADR-0011)
- `owen-it/laravel-auditing` — audit trail (ADR-0012)
- `brick/money` — all monetary arithmetic, BRL, never float (ADR-0013)
- `nfephp-org/sped-nfe` — NF-e (Brazilian e-invoice) issuance against SEFAZ with local A1 certificate (ADR-0009)
- `pestphp/pest` — test framework
- `larastan/larastan` + `laravel/pint` — static analysis (PHPStan level 8) + code style, CI-blocking
- `spatie/laravel-query-builder` — standardized API filtering/sorting

**Critical (frontend, `docs/06-Frontend/README.md` §2):**
- React Router v7 — routing
- TanStack Query — server-state cache/invalidation (server data must never be duplicated into global state)
- Zustand — minimal UI-only global state (session/preferences)
- React Hook Form + Zod — typed forms mirroring API validation rules
- Tailwind CSS + shadcn/ui — UI kit
- TanStack Table — server-side paginated/sortable tables
- `date-fns` + `Intl.NumberFormat('pt-BR')` — date/currency formatting, centralized in `lib/format.ts`
- `ky` (or typed fetch) generated from OpenAPI via `openapi-typescript` — HTTP client, no manual type drift

**Explicitly avoided (documented anti-choices):**
- Redis (queue/cache) — not available on target shared hosting plan; would require VPS (ADR-0014, ADR-0016)
- GraphQL, Inertia.js, Next.js SSR — rejected for the API/frontend boundary (ADR-0003, ADR-0004)
- Filament/Nova or other admin-panel packages — rejected to avoid a second UI/source-of-truth (`docs/05-Backend/README.md` §5)
- Laravel Passport (OAuth2 server) — deferred, no third-party app authorization use case yet (ADR-0005)

### Configuration

**Environment:**
- `.env` (gitignored; `.env.example` is the only committed variant per `.gitignore`) — will hold DB credentials, Woo API keys (encrypted at rest via Laravel's `encrypted` cast for credentials stored in DB), certificate password, SMTP credentials, etc. (`docs/25-Seguranca/README.md` §2)
- Money: `DECIMAL(15,2)` in DB, never float/double; quantities `DECIMAL(15,3)`; percentages `DECIMAL(5,2)` (ADR-0013; `docs/04-Banco-de-Dados/02-convencoes-de-banco.md`)
- Timezone: application timezone `America/Sao_Paulo`, stored in UTC, dates as `CarbonImmutable` (`docs/05-Backend/README.md` §4)

**Build:**
- No build config committed yet. Planned: standard Laravel `composer.json`, Vite `vite.config.ts`/`tsconfig.json` (strict), ESLint config, Pint config (`pint.json` implied by "Pint" usage), PHPStan config (`phpstan.neon` implied by "larastan level 8").

### Platform Requirements

**Development:**
- Docker Compose (PHP 8.4 + MariaDB) or Laravel Herd (`docs/23-Deploy/README.md` §2)

**Production:**
- Hosting decision is an **open ADR** (`docs/27-ADR/ADR-0016-hospedagem.md`, status "⚠️ Proposto — decisão do dono", deadline "antes do fim do Gate 01"):
  - **Recommended:** dedicated VPS (e.g., Hostinger KVM), Ubuntu LTS, Nginx + PHP-FPM 8.4 + MariaDB + Redis + supervisor
  - **Plan B (if staying on current shared plan):** Hostinger Business (shared hosting), queue via cron (`queue:work --stop-when-empty --max-time=50` every minute), no Redis, RPO ~24h, documented latency/risk trade-offs
- Target domain: `gestao.donaarteira.com.br` (staging: `staging-gestao.donaarteira.com.br`)
- MariaDB version constraint: verify ≥ 10.11 during Gate 01 pre-flight (ADR-0002)
- Deploy: atomic releases + symlink via Deployer over SSH; GitHub as the only path to production credentials (`docs/23-Deploy/README.md` §4, §7)

---

*Stack analysis: 2026-07-06*
