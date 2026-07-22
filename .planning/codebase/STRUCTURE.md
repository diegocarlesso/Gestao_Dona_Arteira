# Codebase Structure

**Analysis Date:** 2026-07-07

> **Two realities, kept separate throughout this document:**
> - **EXISTS** — the physical repository as it sits on disk today: `docs/` (the canonical knowledge base), `Dona_Arteira_Gestao_desktop/` (read-only legacy reference app), `.claude/` (agents + skills), `.obsidian/` (vault config), `.planning/` (this GSD workspace), and root-level `CLAUDE.md`/`README.md`/`.gitignore`.
> - **PLANNED** — the ERP application layout (`app/`, `web/`, `tests/`, `database/`, etc.) described in `docs/05-Backend/README.md`, `docs/06-Frontend/README.md`, and the ADRs. **None of this exists on disk yet.** Gate 01 has not started (`CLAUDE.md`, root `README.md`). Do not create these directories speculatively — they appear here only so future implementation work lands in the right place on day one.

## Directory Layout (EXISTS — actual repository root)

```
Dona_Arteira_Gestão/                          # repo root (no .git detected in this checkout)
├── .claude/
│   ├── agents/                               # 18 role-specialist agent prompts (*.md)
│   └── skills/                               # 18 reusable flow skills, one folder per skill
│       └── skill-name/SKILL.md
├── .obsidian/                                # Obsidian vault config for browsing docs/ (app.json, appearance.json, core-plugins.json, graph.json, workspace.json)
├── .planning/
│   └── codebase/                             # GSD codebase map output (this file's home)
│       ├── ARCHITECTURE.md
│       ├── CONCERNS.md
│       ├── CONVENTIONS.md
│       ├── STACK.md
│       └── STRUCTURE.md                      # ← this document
├── docs/                                     # canonical knowledge base — see docs/README.md
│   ├── README.md                             # master index/map of all 32 module folders
│   ├── _templates/                           # document/ADR/rule/integration/runbook templates
│   ├── 00-Visao-Geral/  … 31-Inventario-Legado/   # 31 numbered module folders (detailed below)
│   ├── 27-ADR/                               # architecture decision records (immutable once accepted)
│   └── database_dump/
│       └── u917402451_donaarteira.sql        # WooCommerce/legacy DB dump used for migration analysis
├── Dona_Arteira_Gestao_desktop/              # legacy PyQt6 desktop app — READ-ONLY reference, never evolve (CLAUDE.md rule 9)
│   ├── .gitignore
│   ├── README.md
│   ├── estrutura.txt                         # stale `tree`-style dump of this folder, UTF-16 encoded, not authoritative — ignore
│   └── dagestao/                             # the actual Python package (detailed below)
├── CLAUDE.md                                 # project rules — read first, every session
├── README.md                                 # repo-level orientation, links into docs/
└── .gitignore                                # excludes .env*, /vendor/, /node_modules/, /public/build/, /dist/, .claude/settings.local.json, certs/keys
```

**Note on `.git`:** no `.git` directory was found at the repo root during this scan — treat any git-related tooling here as external to this checkout, not an assumption to hardcode into planning steps.

## Directory Purposes (EXISTS)

**`docs/`:**
- Purpose: the project's single source of truth for decisions — "se não está documentado, não está decidido; se não está decidido, não se implementa" (`docs/README.md`).
- Contains: 31 numbered module folders (`00-Visao-Geral` through `31-Inventario-Legado`), each with a `README.md` plus optional numbered complementary documents; `_templates/` for the five document types; `27-ADR/` for architecture decisions; `database_dump/` holding the raw legacy SQL dump.
- Key files: `docs/README.md` (map + docs-first flow diagram), `docs/28-Roadmap/README.md` (phases/gates), `docs/00-Visao-Geral/04-analise-critica-gate00.md` (open risks/decisions).

**`docs/27-ADR/`:**
- Purpose: architecture decision records — accepted decisions are immutable; revoke with a new ADR, never edit an old one (`docs/README.md`).
- Contains: `ADR-0000-template.md` plus `ADR-0001` … `ADR-0017`, each `ADR-NNNN-slug-em-kebab-case.md`, and a `README.md` index.
- Key files: `ADR-0001-monolito-modular.md`, `ADR-0015-camadas-e-repositorios.md` (layering contract), `ADR-0016-hospedagem.md` (open — blocks Gate 01 completion).

**`docs/01-Regras-de-Negocio/`:**
- Purpose: canonical registry of business rules, each with a stable `BR-xxx` ID referenced by future code/tests (`CLAUDE.md` rule 3).
- Contains: `01-registro-de-regras.md` (the registry itself), `02-levantamento-legado.md` (rules reverse-engineered from the legacy app), `README.md`.

**`docs/31-Inventario-Legado/`:**
- Purpose: structured inventory of the legacy WooCommerce/desktop data (products, categories, images, clients, orders, payment/delivery methods, plugins, metadata, data-quality issues, risks, recommendations, entity map, glossary extracted from legacy) — feeds the migration plan in `docs/17-Migracao/`.
- Contains: 17 numbered files `01-visao-geral.md` through `17-glossario-extraido.md`, plus `98-perguntas-para-o-negocio.md`, `99-relatorio-executivo.md`, `README.md`. The `98-`/`99-` prefixes are an intentional out-of-sequence convention marking "questions for the business" and "executive summary" respectively.

**`docs/_templates/`:**
- Purpose: the five document templates every new doc must follow.
- Contains: `TEMPLATE-DOCUMENTO.md` (general module doc: Objetivo/Responsabilidades/Fluxo/Dependências/Boas práticas/Riscos/Evoluções futuras/Perguntas em aberto), `TEMPLATE-ADR.md`, `TEMPLATE-INTEGRACAO.md`, `TEMPLATE-REGRA-DE-NEGOCIO.md`, `TEMPLATE-RUNBOOK.md`.

**`docs/database_dump/`:**
- Purpose: holds the raw legacy WooCommerce SQL dump used for the migration/inventory analysis in `docs/31-Inventario-Legado/`.
- Contains: `u917402451_donaarteira.sql` — a single large SQL file. Treat as read-only source data, not a schema to copy verbatim into the ERP (ERP schema is defined fresh per `docs/04-Banco-de-Dados/`).

**`Dona_Arteira_Gestao_desktop/dagestao/`:**
- Purpose: the only executable code in the repository — a working PyQt6 desktop CRUD app used strictly as a business-rule reference. Never evolve (`CLAUDE.md` rule 9).
- Contains: flat package, no internal module boundaries — `run.py` (entry point), `main_window.py`, `models.py`, `db.py`, `config.py`, `storage.py`, `validators.py`, `styles.py`, `requirements.txt`, `README.md`, `assets/` (logo, icon), `da_widgets/` (**active** widget package, imported by `main_window.py`), `widgets/` (**dead** duplicate package — not imported from the entry point, present only as inert legacy cruft; see `.planning/codebase/CONVENTIONS.md` and `.planning/codebase/ARCHITECTURE.md` Anti-Patterns).
- Key files: `Dona_Arteira_Gestao_desktop/dagestao/run.py` (launch), `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/` (11 active dialog/window modules), `Dona_Arteira_Gestao_desktop/dagestao/models.py` (SQLAlchemy models: `Client`, `Package`, `Piece`, `PieceImage`, `Order`, `OrderItem`).

**`.claude/agents/`:**
- Purpose: role-specialist prompts for assisted development (one file per specialist role).
- Contains: 18 files, `kebab-case.md`, e.g. `chief-architect.md`, `laravel-specialist.md`, `react-specialist.md`, `nfe-specialist.md`, `woocommerce-specialist.md`, `senior-dba.md`.

**`.claude/skills/`:**
- Purpose: reusable, standardized development flows (used by `/gsd:*` commands and by the specialist agents).
- Contains: 18 skill folders, each `kebab-case/SKILL.md`, e.g. `criar-migration/SKILL.md`, `criar-service/SKILL.md`, `criar-model/SKILL.md`, `sync-estoque/SKILL.md`, `integracao-sefaz/SKILL.md`.

**`.obsidian/`:**
- Purpose: local Obsidian vault configuration for browsing/navigating `docs/` as a linked knowledge graph. Not part of the application or its build.
- Contains: `app.json`, `appearance.json`, `core-plugins.json`, `graph.json`, `workspace.json`. Generated/editor-local — do not treat as project source.

**`.planning/codebase/`:**
- Purpose: GSD-generated codebase map (this document set) consumed by `/gsd:plan-phase` and `/gsd:execute-phase`.
- Contains: `ARCHITECTURE.md`, `STACK.md`, `CONVENTIONS.md`, `CONCERNS.md`, `STRUCTURE.md`.

## Key File Locations (EXISTS)

**Entry Points:**
- `Dona_Arteira_Gestao_desktop/dagestao/run.py`: launches the legacy PyQt6 app (`python run.py`).
- `docs/README.md`: entry point into the documentation map — read before any other doc.
- `CLAUDE.md`: entry point for project rules — read first, every session.

**Configuration:**
- `Dona_Arteira_Gestao_desktop/dagestao/config.py`: `Settings` dataclass loading legacy `.env` (MySQL/FTP credentials) — existence-only, never read contents (`.gitignore` excludes `.env*`).
- `.gitignore` (root): excludes `.env`/`.env.*`, `/vendor/`, `/node_modules/`, `/public/build/`, `/dist/`, `.claude/settings.local.json`, certs/keys (`*.pfx`,`*.p12`,`*.pem`,`*.key`).
- `Dona_Arteira_Gestao_desktop/.gitignore`: legacy-app-specific ignores (separate from root).

**Core Logic (legacy reference only):**
- `Dona_Arteira_Gestao_desktop/dagestao/models.py`: SQLAlchemy declarative models.
- `Dona_Arteira_Gestao_desktop/dagestao/db.py`: engine/session factory + ad hoc schema patcher.
- `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/*.py`: all active business/UI logic (11 files).

**Testing:**
- None exist anywhere in the repository. No test files, no test framework config, for either the legacy app or the (not-yet-created) ERP. See `.planning/codebase/TESTING.md` if present, and `docs/22-Testes/README.md` for the planned strategy.

## Naming Conventions (EXISTS)

**Docs files/folders (`docs/`):**
- No accents, kebab-case, numeric prefix for ordering — e.g. `docs/04-Banco-de-Dados/02-convencoes-de-banco.md` (`docs/README.md` §Convenções).
- Each numbered top-level folder = one "domínio documental" with a `README.md` plus numbered complementary files (`01-...md`, `02-...md`).
- ADR files: `ADR-NNNN-slug-kebab-case.md` inside `docs/27-ADR/` (4-digit zero-padded sequence).
- Out-of-sequence numeric prefixes are used intentionally for appendix-style content: `98-` = open questions for the business, `99-` = executive summary (seen in `docs/31-Inventario-Legado/`).
- Dates inside documents: ISO format `AAAA-MM-DD` (`CLAUDE.md`, `docs/_templates/TEMPLATE-DOCUMENTO.md`).

**Legacy Python files (`Dona_Arteira_Gestao_desktop/dagestao/`):**
- `snake_case.py` modules matching their primary responsibility — `models.py`, `db.py`, `validators.py`, `main_window.py`, `order_form.py`.
- No accents/uppercase in filenames; two parallel widget packages exist (`widgets/` dead, `da_widgets/` active) — see Concerns doc, not a pattern to replicate.

**Agents/skills (`.claude/`):**
- `kebab-case.md` per agent (`.claude/agents/laravel-specialist.md`); one folder per skill containing `SKILL.md` (`.claude/skills/criar-service/SKILL.md`).

## Where to Add New Code (mixed EXISTS/PLANNED)

**New documentation (EXISTS today — this is the only kind of "new code" currently valid per Gate 00/01 rules):**
- Identify the matching numbered folder in `docs/` (see `docs/README.md` map) and add a new numbered file following `docs/_templates/TEMPLATE-DOCUMENTO.md`.
- New architectural decision → new `docs/27-ADR/ADR-NNNN-slug.md` from `docs/_templates/TEMPLATE-ADR.md`, status `Proposto` until the product owner approves (`CLAUDE.md` rule 2).
- New business rule → append to `docs/01-Regras-de-Negocio/01-registro-de-regras.md` with a fresh `BR-xxx` ID (`CLAUDE.md` rule 3).

**New ERP backend feature (PLANNED — not to be created until Gate 01 is authorized):**
- Per module, under `app/Modules/ModuleName/{Models,Services,Http/{Controllers,Requests,Resources},Events,Listeners,Jobs,Policies,Database/{migrations,factories,seeders},Tests}` — modules are `Catalog`, `Production`, `Inventory`, `Sales`, `Purchasing`, `Finance`, `Fiscal`, `Identity`, `Integrations` (`docs/05-Backend/README.md` §2).
- Cross-cutting helpers (e.g. `Money`, correlation IDs) go in `app/Support/` — never generic global helpers (`docs/05-Backend/README.md` §4,6).
- External-system adapters go under `app/Modules/Integrations/SystemName/{Client,Adapters,Jobs,Webhooks,DTOs}` (e.g. `app/Modules/Integrations/WooCommerce/`) — external payloads must never reach domain code directly (`CLAUDE.md` rule 4).
- Backend tests live inside each module's own `Tests/` folder (Pest), not a separate top-level `tests/` — per the modular layout in `docs/05-Backend/README.md` §2.

**New ERP frontend feature (PLANNED):**
- Under `web/src/features/featureName/{components,hooks,api,schemas,pages}`, mirroring backend modules 1:1: `catalog`, `production`, `inventory`, `sales`, `purchasing`, `finance`, `fiscal`, `integrations`, `identity` (`docs/06-Frontend/README.md` §3).
- Shared UI → `web/src/components/`; shared logic/generated API client/formatting → `web/src/lib/`; app bootstrap (router, providers, auth guards) → `web/src/app/`; global styles → `web/src/styles/`.
- Rule: a feature module never imports from another feature module — shared code is promoted to `components/`/`lib/` instead (`docs/06-Frontend/README.md` §3).

**Utilities/shared helpers (PLANNED):**
- Backend: `app/Support/`. Frontend: `web/src/lib/`. No ad hoc global helper files outside these locations (`docs/05-Backend/README.md` §4).

## Special Directories

**`docs/database_dump/`:**
- Purpose: raw legacy WooCommerce SQL dump for migration/inventory analysis.
- Generated: yes (produced by exporting the legacy DB, not hand-written).
- Committed: yes (present in the working tree at time of scan).

**`Dona_Arteira_Gestao_desktop/dagestao/widgets/` (EXISTS, dead code):**
- Purpose: none — inert duplicate of `da_widgets/`, unreachable from `run.py`.
- Generated: no.
- Committed: yes — left in place as-is because the legacy app is frozen/read-only (`CLAUDE.md` rule 9); do not delete or "clean up" during ERP work, and never import from it.

**`.obsidian/`:**
- Purpose: local vault/editor configuration for navigating `docs/`.
- Generated: yes (created/maintained by the Obsidian app).
- Committed: yes (present in the working tree at time of scan) — not a build artifact of the project itself.

**`app/`, `web/`, `database/`, `tests/`, `public/`, `vendor/`, `node_modules/`, `dist/`, `public/build/` (PLANNED, none exist):**
- Purpose: standard Laravel (`app/`, `database/`, `public/`) and Vite/React (`web/`) application roots, plus dependency/build output directories already pre-excluded in root `.gitignore` in anticipation of Gate 01 (`/vendor/`, `/node_modules/`, `/public/build/`, `/dist/`).
- Generated: `vendor/`, `node_modules/`, `dist/`, `public/build/` will be generated (Composer/npm/Vite); `app/`, `web/`, `database/` will be hand-written.
- Committed: none exist yet; when created, `vendor/`/`node_modules/`/build output are git-ignored, hand-written source is committed.

---

*Structure analysis: 2026-07-07*
