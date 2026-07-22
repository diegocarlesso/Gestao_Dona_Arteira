<!-- refreshed: 2026-07-06 -->
# Architecture

**Analysis Date:** 2026-07-06

## Important: Two Architectures in This Repository

This repository contains **no ERP application code**. It contains:

1. **A legacy reference app** — `Dona_Arteira_Gestao_desktop/` — a working PyQt6 desktop CRUD tool. Fully implemented, read-only reference. **Never evolve it** (per `CLAUDE.md` rule 9). Documented below under "Legacy Desktop App (Implemented)".
2. **An intended ERP architecture** — described entirely in `docs/03-Arquitetura/`, `docs/27-ADR/`, `docs/05-Backend/`, `docs/06-Frontend/`, `docs/02-Dominio/` — approved on paper but **not yet built**. No `app/`, `src/`, `composer.json`, or `package.json` for the ERP exist in the repo. Documented below under "Intended ERP Architecture (Not Yet Built)".

Any future ERP implementation work should follow section 2, using section 1 only as a functional/business-rule reference (per legacy inventory in `docs/31-Inventario-Legado/`).

---

## Part 1 — Legacy Desktop App (Implemented)

### System Overview

```text
┌─────────────────────────────────────────────────────────────┐
│                     PyQt6 UI Layer                           │
│  MainWindow + Dialogs/Windows (da_widgets/*)                 │
│  `Dona_Arteira_Gestao_desktop/dagestao/main_window.py`        │
│  `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/*.py`       │
└──────────────────────────┬────────────────────────────────────┘
                           │ direct instantiation of SQLAlchemy
                           │ Session inside widget methods
                           ▼
┌─────────────────────────────────────────────────────────────┐
│              SQLAlchemy ORM (models + session)                │
│  `dagestao/models.py` (declarative models)                    │
│  `dagestao/db.py` (engine, SessionLocal, ad-hoc migrations)    │
└──────────────────────────┬────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                MariaDB/MySQL (`dona_arteira`)                 │
│  Tables: clients, packages, pieces, piece_images,              │
│  orders, order_items                                           │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│         FTP image storage (side channel, not DB)               │
│  `dagestao/storage.py` (FTPStorage: upload/download bytes)     │
│  used directly from `da_widgets/piece_form.py`,                 │
│  `da_widgets/order_details.py`                                  │
└─────────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| Entry point | Bootstraps `QApplication`, shows `MainWindow` | `Dona_Arteira_Gestao_desktop/dagestao/run.py` |
| Main window | Client/order tree view, top-level menu/actions, theme switch | `Dona_Arteira_Gestao_desktop/dagestao/main_window.py` |
| Config | Loads `.env` via `python-dotenv` into a `Settings` dataclass | `Dona_Arteira_Gestao_desktop/dagestao/config.py` |
| DB bootstrap | SQLAlchemy engine/session factory, ad-hoc `ALTER TABLE` schema patcher, seeds default packages | `Dona_Arteira_Gestao_desktop/dagestao/db.py` |
| Domain models | `Client`, `Package`, `Piece`, `PieceImage`, `Order`, `OrderItem`, `PaymentMethod`/`DeliveryMethod` enums | `Dona_Arteira_Gestao_desktop/dagestao/models.py` |
| Validation | CPF/CNPJ checksum validators (pure functions, no framework) | `Dona_Arteira_Gestao_desktop/dagestao/validators.py` |
| Image storage | FTP upload/download of piece images (bytes in/out) | `Dona_Arteira_Gestao_desktop/dagestao/storage.py` |
| Theming | Static Qt stylesheet + color palette dict | `Dona_Arteira_Gestao_desktop/dagestao/styles.py` |
| Client CRUD dialog | Create/edit client, inline CPF/CNPJ validation | `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/client_form.py` |
| Order CRUD dialog | Create/edit order + line items, autofill price/description from piece code | `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/order_form.py` |
| Order list window | Table of orders (optionally filtered by client), edit/view/remove | `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/order_manager.py` |
| Order detail dialog | Read-only order + items + item thumbnails (fetched from FTP) | `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/order_details.py` |
| Piece CRUD dialog | Create/edit piece, package selection, image upload to FTP | `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/piece_form.py` |
| Piece list window | Table of pieces, launches piece/package dialogs | `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/piece_manager.py` |
| Pieces tab (alt view) | Embedded tab version of the piece list (used inside `MainWindow`) | `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/pieces_tab.py` |
| Package CRUD dialog | Manage shipping package presets (dimensions/weight) | `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/package_manager.py` |
| Package/piece pickers | Modal "select from list" dialogs used by the forms above | `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/package_select.py`, `.../piece_select.py` |

### Pattern Overview

**Overall:** Monolithic desktop CRUD application, single process, no layering beyond "Qt widget" vs "ORM model." There is no service layer, no repository layer, no DTOs, and no dependency injection — widgets talk to SQLAlchemy `Session` objects directly inside UI event handlers.

**Key Characteristics:**
- One `SessionLocal()` context manager opened per UI action (button click, dialog open/save) — sessions are short-lived and not shared across the app.
- Business rules (CPF/CNPJ validity, autofill of price/description from piece code, default package seeding) are implemented ad hoc inside widget classes or small validator functions, not in a distinct domain layer.
- No API boundary: this is a single-machine desktop client talking straight to a shared MySQL/MariaDB instance over the network.
- No automated tests, no CI configuration, no linter configuration found anywhere under `Dona_Arteira_Gestao_desktop/`.
- Two parallel widget directories exist (see Anti-Patterns below); only one is wired into the running app.

### Layers

**UI Layer (PyQt6):**
- Purpose: render forms/tables/dialogs, capture user input, wire button clicks to persistence calls.
- Location: `Dona_Arteira_Gestao_desktop/dagestao/main_window.py`, `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/`
- Contains: `QMainWindow`, `QDialog`, `QWidget` subclasses.
- Depends on: `db.py` (`SessionLocal`), `models.py`, `validators.py`, `storage.py`, `styles.py`.
- Used by: nothing (top of the stack) — invoked from `run.py`.

**Persistence Layer (SQLAlchemy):**
- Purpose: object-relational mapping, engine/session lifecycle, best-effort schema migration on startup.
- Location: `Dona_Arteira_Gestao_desktop/dagestao/db.py`, `Dona_Arteira_Gestao_desktop/dagestao/models.py`
- Contains: `Base = declarative_base()`, `ENGINE`, `SessionLocal`, `init_db()`, `_migrate_schema()` (raw `ALTER TABLE`/`CREATE INDEX` executed conditionally via SQLAlchemy `inspect()`).
- Depends on: `config.py` for connection settings.
- Used by: every widget module (UI layer calls `SessionLocal()` directly — no repository indirection).

**Configuration Layer:**
- Purpose: centralize environment-driven settings (MySQL host/credentials, FTP host/credentials).
- Location: `Dona_Arteira_Gestao_desktop/dagestao/config.py`
- Contains: a single `Settings` dataclass populated from `os.getenv` (via `python-dotenv`), instantiated once as module-level `SETTINGS`.
- Depends on: `.env` file (present but never read by the mapper — existence only).
- Used by: `db.py`, `storage.py`.

**External Storage (FTP):**
- Purpose: store/retrieve piece images outside the database (paths only are stored in `piece_images.ftp_path`).
- Location: `Dona_Arteira_Gestao_desktop/dagestao/storage.py`
- Contains: `FTPStorage` class wrapping Python's stdlib `ftplib.FTP`.
- Depends on: `config.py` for FTP credentials.
- Used by: `da_widgets/piece_form.py` (upload), `da_widgets/order_details.py` and `da_widgets/piece_form.py` (download for thumbnails).

### Data Flow

#### Primary Path — Opening the App and Listing Clients/Orders

1. `run.py` creates `QApplication`, instantiates `MainWindow`, calls `show()` (`Dona_Arteira_Gestao_desktop/dagestao/run.py:6-7`).
2. `MainWindow.__init__` builds the UI (menu, tree, tabs) then calls `self.load_clients()` (`main_window.py:56`).
3. `load_clients()` opens a `SessionLocal()`, runs a `select(Client)` with `joinedload(Client.orders).joinedload(Order.items)`, and populates a `QTreeWidget` with clients as top-level rows and orders as child rows, computing each order's total by summing `quantity * price` over items in Python (`main_window.py:166-219`).
4. Double-clicking a client or order row calls `edit_client`/`edit_order`, which re-opens a session, fetches the record with `joinedload`, detaches it (`s.expunge`), and opens the corresponding `QDialog` (`main_window.py:265-316`).

#### Secondary Path — Saving a Piece with an Image

1. User opens `PieceManagerWindow` → clicks "Adicionar Peça" → `PieceFormDialog` opens (`da_widgets/piece_manager.py:92-93`, `da_widgets/piece_form.py`).
2. `_add_image()` reads a local file into bytes and calls `self.ftp.upload_bytes(rel, data)` where `rel = f"pecas/{code}/{filename}"` (`da_widgets/piece_form.py:84-91`) — this happens **before** the piece itself is saved to the DB (upload is decoupled from persistence).
3. `_save()` opens a `SessionLocal()`, creates or updates the `Piece` row, clears `p.images` and re-adds one `PieceImage(piece=p, ftp_path=...)` per entry currently shown in the UI list, then commits (`da_widgets/piece_form.py:93-107`).
4. Thumbnails are re-fetched on demand by downloading each `ftp_path` from FTP and decoding into a `QPixmap` (`_thumb_from_ftp`, `da_widgets/piece_form.py:61-69`) — there is no local image cache.

**State Management:**
- No shared application state/store. Each dialog holds its own local widget state; data is round-tripped through short-lived SQLAlchemy sessions on every open/save. Cross-window refresh is manual (callers call `.load()`/`load_clients()` again after a dialog returns `Accepted`).

### Key Abstractions

**Session-per-action (SQLAlchemy):**
- Purpose: isolate each UI operation in its own transaction using `with SessionLocal() as s: ...`.
- Examples: every widget's `load()`/`_save()`/`edit_*()` method, e.g. `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/order_manager.py:48-60`.
- Pattern: open → query/mutate → `s.commit()` (or `s.expunge`/`s.expunge_all()` to detach objects for reuse outside the session) → implicit close via context manager exit.

**Detached-object hand-off:**
- Purpose: pass ORM objects between windows after their originating session has closed.
- Examples: `main_window.py:265-274` (`s.expunge(c)` before opening `ClientFormDialog`), `da_widgets/order_manager.py:57-60`.
- Pattern: fetch inside a session, call `s.expunge(obj)` (or `expunge_all()`), then use the now-detached object outside the `with` block — reattached later via `s.merge(obj)` when saved (`da_widgets/order_form.py:67`).

**Enum-backed status/method fields:**
- Purpose: constrain `Order.delivery_method`/`payment_method` to fixed value sets.
- Examples: `PaymentMethod`, `DeliveryMethod` in `Dona_Arteira_Gestao_desktop/dagestao/models.py:65-69`.
- Pattern: plain Python `enum.Enum` mapped via SQLAlchemy `Enum()` column type; UI populates `QComboBox` from `for m in DeliveryMethod`.

### Entry Points

**Desktop app launch:**
- Location: `Dona_Arteira_Gestao_desktop/dagestao/run.py`
- Triggers: `python run.py` (per `Dona_Arteira_Gestao_desktop/dagestao/README.md`).
- Responsibilities: adjust `sys.path`, construct `QApplication`/`MainWindow`, enter Qt event loop.

**DB schema bootstrap:**
- Location: `Dona_Arteira_Gestao_desktop/dagestao/db.py::init_db()`
- Triggers: not called from `run.py` in the current tree (dead code path — tables appear to be created lazily via `Base.metadata.create_all` only if `init_db()` is invoked elsewhere/manually).
- Responsibilities: create tables if missing, patch `pieces` columns (rename/add), create `orders(date)` index if applicable, seed 5 default `Package` rows.

### Architectural Constraints

- **Threading:** Single-threaded Qt event loop; all DB/FTP calls are synchronous and block the UI thread (no `QThread`/worker offloading anywhere in `da_widgets/`).
- **Global state:** Module-level singletons — `SETTINGS` (`config.py:17`), `ENGINE`/`SessionLocal` (`db.py:11-12`), `Base` (`db.py:6`). All widget modules import these directly rather than receiving them via injection.
- **Circular imports:** None observed structurally, but `models.py` imports `Base` from `db.py` while `db.py::init_db()` imports models back (`from models import Client, Package, ...`) — a deferred/local import used specifically to break the cycle (`Dona_Arteira_Gestao_desktop/dagestao/db.py:81`).
- **Two widget packages:** `dagestao/widgets/` and `dagestao/da_widgets/` both exist with overlapping filenames; `main_window.py` imports exclusively from `da_widgets` (`main_window.py:21-25`). `widgets/` is unused dead code left in the tree.

### Anti-Patterns

#### Business logic and persistence embedded directly in UI classes

**What happens:** Every `QDialog`/`QWidget` subclass under `da_widgets/` opens its own `SessionLocal()`, runs queries, and applies business rules (e.g., price autofill in `order_form.py:86-107`, default package seeding logic effectively in `db.py:80-96`) inline inside Qt event handler methods.
**Why it's wrong:** No unit-testable domain layer exists; every change to a business rule requires touching UI code, and there is no way to reuse the logic (e.g., in a script, CLI, or future API) without duplicating it.
**Do this instead:** In the ERP rebuild, keep this logic entirely out of UI/controller code — per `docs/05-Backend/README.md` and ADR-0015, business rules live in `Service`/`Action` classes and `Model` invariants, never in `Http` or presentation code.

#### Duplicate/dead widget package

**What happens:** `Dona_Arteira_Gestao_desktop/dagestao/widgets/` contains older, functionally similar copies of files also present in `da_widgets/` (`client_form.py`, `order_form.py`, `order_manager.py`, `piece_form.py`, `piece_manager.py`), but `main_window.py` only imports from `da_widgets`.
**Why it's wrong:** A reader of this repo could mistakenly edit or reference `widgets/` believing it is live code; it is not reachable from `run.py`.
**Do this instead:** Since this codebase is read-only reference material (per `CLAUDE.md` rule 9), do not attempt to "fix" it — simply be aware `widgets/` is inert when using this app as a business-rule reference, and always trace behavior from `run.py` → `main_window.py` → `da_widgets/*`.

#### Direct-attribute copy/paste bug from scope confusion

**What happens:** `ClientFormDialog._fill(self, c)` is called with a local variable `c`, but its body reads `client.cep` / `client.notes` instead of `c.cep` / `c.notes` (`Dona_Arteira_Gestao_desktop/dagestao/da_widgets/client_form.py:44-49`) — `client` is not defined in that method's scope.
**Why it's wrong:** With no automated tests, this raises an uncaught `NameError` the moment an existing client with those fields is opened for editing; the bug is invisible until manually exercised.
**Do this instead:** Symptomatic of the "no tests, no layering" pattern above — the ERP rebuild's mandatory Pest/Vitest coverage (`CLAUDE.md` rule 8) is the structural fix, not a patch to this legacy file.

### Error Handling

**Strategy:** Broad `try/except Exception` blocks around most UI action handlers in `main_window.py`, logging via `logging.exception(...)` and surfacing a `QMessageBox.critical` dialog with the exception text; a global Qt `sys.excepthook` (`_qt_excepthook`, `main_window.py:27-34`) catches anything unhandled and shows a generic error dialog with a "Detalhes" traceback.

**Patterns:**
- Per-action `try/except` swallowing all exceptions and showing a `QMessageBox` (e.g., `main_window.py:247-253`, `:265-274`).
- Silent `except Exception: pass` around best-effort operations (stylesheet loading, FTP `ftp.quit()`, theme restore) — failures are invisible (`main_window.py:44-49`, `storage.py:20-22`).
- No custom exception types, no error codes, no retry logic anywhere in the desktop app.

### Cross-Cutting Concerns

**Logging:** Python stdlib `logging` module used ad hoc (`logging.exception(...)`) only inside `main_window.py`; no logging configuration (handlers/formatters/level) is set up anywhere, so output destination depends on Python defaults.
**Validation:** CPF/CNPJ checksum validation only (`validators.py`); no schema/type validation library; numeric fields parsed with bare `float()`/`int()` calls wrapped in local `try/except`.
**Authentication:** None — the desktop app has no login/user concept; whoever runs the executable has full access to all data via the shared MySQL credentials in `.env`.

---

## Part 2 — Intended ERP Architecture (Not Yet Built)

Everything in this section describes **decisions recorded in documentation only** — no corresponding code exists in the repository yet. Status: Gate 01, pending the hosting decision in `docs/27-ADR/ADR-0016-hospedagem.md`. Treat this section as a target blueprint for `/gsd:plan-phase`, not as current state.

### System Overview (C4, from `docs/03-Arquitetura/01-visao-c4.md`)

```text
┌─────────────────────────────────────────────────────────────┐
│                 External actors / systems                     │
│  Dono/Produção/Vendas/Expedição/Contador (users)               │
│  WordPress+WooCommerce (sales channel) · SEFAZ (NF-e)          │
│  Melhor Envio/carriers · SMTP e-mail                            │
└──────────────────────────┬────────────────────────────────────┘
                           │ REST + webhooks / SOAP-XML / notifications
                           ▼
┌─────────────────────────────────────────────────────────────┐
│         Hosting: gestao.donaarteira.com.br                    │
│  ┌───────────────┐   HTTPS JSON    ┌──────────────────────┐   │
│  │ SPA React+Vite│ ─────Sanctum──▶ │ Laravel 12 API /api/v1│   │
│  │ (static build)│                 │ + webhooks             │   │
│  └───────────────┘                 └──────────┬─────────────┘   │
│                                                │                 │
│                    ┌───────────────────────────┼───────────┐    │
│                    ▼                           ▼           ▼    │
│            ┌───────────────┐         ┌────────────────┐ ┌────┐ │
│            │ Queue workers │         │  MariaDB        │ │Files│ │
│            │ (queue:work)  │◀───────▶│  utf8mb4        │ │(XML/│ │
│            └───────┬───────┘         └────────────────┘ │DANFE│ │
│                    │  Scheduler (cron, every minute)      │A1) │ │
│                    ▼                                     └────┘ │
│            External APIs: Woo · SEFAZ · Melhor Envio             │
└─────────────────────────────────────────────────────────────┘
```

### Component Responsibilities (Laravel container, C4 level 3)

| Component | Responsibility | Doc reference |
|-----------|----------------|------|
| Controllers | Authorize (Policy), validate (FormRequest), delegate to Service, respond via API Resource — never business logic | `docs/03-Arquitetura/01-visao-c4.md`, `docs/05-Backend/README.md` |
| FormRequests | Syntactic/format validation only | `docs/05-Backend/README.md` |
| Services/Actions | Application layer: one named use case per class, owns the DB transaction, dispatches domain events | ADR-0015 |
| Models (Eloquent) | Domain model: local invariants, relations, casts, named scopes | ADR-0015 |
| Events | Domain events consumed by listeners/jobs for async side effects | `docs/02-Dominio/01-eventos-de-dominio.md` |
| Repositories | Only for complex/reused, business-named queries (e.g., `OverdueReceivables`) — not a blanket CRUD wrapper | ADR-0015 |
| Jobs (queue) | Re-executable, idempotent side effects (sync, e-mail, NF-e transmission) | ADR-0007, ADR-0014 |
| Integrations/* (adapters) | Anti-corruption layer translating internal DTOs ↔ external APIs (Woo, SEFAZ, Melhor Envio) | ADR-0007, `docs/02-Dominio/README.md` |
| IntegrationMappings | Checksum-based state linking ERP entities ↔ external IDs for reconciliation | ADR-0007 |
| Policies | Contextual authorization nuances on top of RBAC roles | ADR-0011 |
| Auditing | `owen-it/laravel-auditing` generic mutation trail + domain-specific fact tables (ledgers, status history) | ADR-0012 |

### Pattern Overview (Intended)

**Overall:** Modular monolith — a single Laravel 12 application with bounded-context modules under `app/Modules/*`, one MariaDB database, one deploy unit. Explicitly **not** microservices (ADR-0001) and **not** a framework-agnostic Clean Architecture with entities/use-case interfaces (ADR-0015 rejects that as over-engineering for a one-person team).

**Key Characteristics:**
- API-first: OpenAPI 3.1 contract written before implementation; SPA and all integrations consume the same `/api/v1` (ADR-0003).
- Frontend/backend are separately deployed codebases sharing one domain to avoid CORS (ADR-0004).
- ERP is the single source of truth after cutover; WooCommerce becomes a channel, never edited directly (ADR-0006).
- All cross-system sync is asynchronous: domain event → queued job (retry+backoff) → adapter → external API, with idempotent webhook ingestion and periodic reconciliation (ADR-0007).
- Queue driver is `database` (no Redis) unless/until a VPS is provisioned (ADR-0014, gated by ADR-0016).
- Money is `brick/money`/`DECIMAL(15,2)`, quantities `DECIMAL(15,3)` — never float (ADR-0013, `CLAUDE.md` rule 6).
- Inventory is an append-only ledger (`inventory_movements`) with materialized balances, never a mutable integer field like the legacy `pieces.in_stock` (ADR-0008, `CLAUDE.md` rule 7).

### Layers (Intended)

**Presentation (SPA):**
- Purpose: operational UI for produção/vendas/expedição/financeiro/fiscal roles.
- Location (planned): `web/src/` — `app/` (router, providers, auth guards), `features/<module>/` (mirrors backend modules: catalog, production, inventory, sales, purchasing, finance, fiscal, integrations, identity), `components/` (shared UI), `lib/` (generated API client, formatting, permissions helper).
- Depends on: `/api/v1` contract (OpenAPI-generated TypeScript client), Sanctum cookie session.
- Used by: end users only.
- Doc reference: `docs/06-Frontend/README.md`.

**HTTP/API (Laravel, per module):**
- Purpose: thin authorize→validate→delegate→respond boundary.
- Location (planned): `app/Modules/<Module>/Http/{Controllers,Requests,Resources}`.
- Depends on: Services/Actions of the same module.
- Doc reference: `docs/07-API/README.md`, `docs/05-Backend/README.md`.

**Application (Services/Actions):**
- Purpose: orchestrate one named use case per class; owns transactions; raises domain events.
- Location (planned): `app/Modules/<Module>/Services/`.
- Depends on: Models, Repositories (only where justified), other modules' Services (synchronous, same-transaction calls) or Events (async).
- Doc reference: ADR-0015.

**Domain (Models):**
- Purpose: Eloquent models carrying local invariants, relationships, casts, scopes — the domain model itself (no separate entity layer).
- Location (planned): `app/Modules/<Module>/Models/`.
- Doc reference: ADR-0015, `docs/02-Dominio/README.md` (aggregate table).

**Infrastructure (Integrations/ACL):**
- Purpose: translate between internal DTOs and external systems; nothing outside this layer knows external payload shapes.
- Location (planned): `app/Modules/Integrations/{WooCommerce,Sefaz,MelhorEnvio,Mail}/{Client,Adapters,Jobs,Webhooks,DTOs}`.
- Doc reference: `docs/15-Integracoes/README.md` (indexed but not yet detailed beyond README), ADR-0007.

**Support:**
- Purpose: cross-cutting, framework-agnostic helpers (e.g., `Money`, correlation IDs).
- Location (planned): `app/Support/`.
- Doc reference: `docs/05-Backend/README.md`.

### Data Flow (Intended)

#### Primary Request Path (planned)

1. SPA calls `/api/v1/...` with a Sanctum session cookie (CSRF bootstrap via `/sanctum/csrf-cookie`).
2. Controller authorizes via Policy, validates via FormRequest, delegates to a Service/Action.
3. Service/Action executes the use case inside a DB transaction, mutates Models, and dispatches domain Events.
4. Controller returns a Resource-shaped JSON response; RFC 9457 error format on failure.
- Doc reference: ADR-0003, ADR-0015, `docs/07-API/README.md`.

#### Asynchronous Integration Path (planned)

1. Domain event fires (e.g., "estoque alterado", "pedido confirmado").
2. Event triggers a queued Job (database queue driver) with retry/backoff.
3. Job calls the module-specific Adapter, which maps internal DTOs to the external API shape (Woo/SEFAZ/Melhor Envio) and records/updates `integration_mappings` with a checksum.
4. Incoming webhooks are persisted raw first, then processed asynchronously with deduplication; a periodic reconciliation job is the final convergence guarantee.
- Doc reference: ADR-0007, ADR-0014, `docs/16-WooCommerce/README.md` (indexed, not yet detailed).

**State Management (Intended):** Server state lives only in the API (TanStack Query cache on the frontend, never duplicated into global UI state); Zustand reserved strictly for session/UI-only state (`docs/06-Frontend/README.md`).

### Key Abstractions (Intended)

**Bounded-context modules:** `Catalog`, `Production`, `Inventory`, `Sales`, `Purchasing`, `Finance`, `Fiscal`, `Identity`, `Integrations` — each self-contained with its own `Models/`, `Services/`, `Http/`, `Events/`, `Listeners/`, `Jobs/`, `Policies/`, `Database/`, `Tests/` (`docs/05-Backend/README.md`).

**Aggregates with explicit invariants:** `Product`, `ProductionOrder`, `Mold`, `InventoryItem`, `Order`, `PurchaseOrder`, `Receivable`/`Payable`, `FiscalDocument`, `Customer`/`Supplier` — each with invariants tied to a `BR-xxx` business rule ID (`docs/02-Dominio/README.md` §4).

**Ledger + materialized balance (Inventory):** `inventory_movements` (append-only) + `inventory_balances` (materialized, reconciled nightly by summing movements) — the structural fix for the legacy app's mutable `pieces.in_stock` integer (ADR-0008).

**Anti-corruption layer (Integrations):** every external system's payload is translated to/from internal DTOs inside `Integrations/*`; no business module ever parses a WooCommerce/SEFAZ/Melhor Envio payload directly (`docs/02-Dominio/README.md` §3, ADR-0007).

### Entry Points (Intended)

**API application:** Laravel 12 `public/index.php` bootstrap (standard Laravel entry, not yet created) serving `/api/v1/*` and webhook endpoints.
**SPA application:** Vite-built static bundle served from the same origin as the API (no separate CORS-facing domain) — `docs/06-Frontend/README.md`.
**Queue workers:** `queue:work` invoked via cron (shared hosting) or supervisor (VPS) — decision pending ADR-0016.
**Scheduler:** `schedule:run` via cron every minute, driving the queue worker cadence and reconciliation jobs.

### Architectural Constraints (Intended)

- **Hosting undecided:** ADR-0016 is `Proposto` — whether workers run under `supervisor` (VPS) or `cron --stop-when-empty` (shared hosting) is an open decision blocking Gate 01 completion; it directly affects achievable sync latency (NFR) and Redis availability (ADR-0014).
- **No Redis by default:** queue and cache use the `database` driver until/unless a VPS is provisioned; this caps queue throughput and worker concurrency (ADR-0014).
- **Single database, single deploy:** all bounded-context modules share one MariaDB instance and one Laravel deploy — horizontal extraction of any module requires a new ADR (ADR-0001).
- **Module dependency discipline is manual for now:** `deptrac` enforcement is deferred to "Fase 2"; until then, cross-module imports are only checked by human review (`docs/05-Backend/README.md` §7).
- **Media dependency inversion (temporary):** in phase 1, the ERP (system of record for catalog) references product images that are physically hosted on WordPress, not on ERP storage — an intentional, time-boxed exception revisited at Gate 06 (ADR-0017).

### Anti-Patterns to Avoid When Implementing (per ADRs)

#### Fat controllers / business logic outside Services

**What happens (rejected pattern):** Putting query logic, transactions, or business rules directly in Controllers, Jobs, or Listeners.
**Why it's wrong:** Violates ADR-0015's explicit layer contract; untestable, unreusable, and is exactly the failure mode observed in the legacy desktop app (Part 1 above).
**Do this instead:** Controllers only authorize/validate/delegate/respond; all business logic lives in Services/Actions or Model invariants.

#### Repository-for-everything with interfaces on trivial CRUD

**What happens (rejected pattern):** Wrapping every Eloquent call behind a `Repository` interface "for testability."
**Why it's wrong:** ADR-0015 explicitly rejects this as bureaucratic passthrough that "tests the mock, not the code" — tests use a real database (`docs/22-Testes/`), removing the usual justification for mocking repositories.
**Do this instead:** Use Eloquent directly in Services for simple CRUD; reserve Repository classes for complex, business-named, reused queries only.

#### Direct mutation of stock balances

**What happens (rejected pattern):** Any code path that does `UPDATE pieces SET in_stock = in_stock - 1` or equivalent direct balance mutation.
**Why it's wrong:** Destroys auditability/explainability of "why is the balance what it is" — the exact problem ADR-0008 was written to eliminate, and explicitly forbidden by `CLAUDE.md` rule 7.
**Do this instead:** All stock changes go through the Inventory module's service, writing an immutable `inventory_movements` row and updating `inventory_balances` in the same transaction/lock.

### Error Handling (Intended)

**Strategy:** RFC 9457 (Problem Details) formatted JSON error responses from the API; business exceptions extend a per-module `DomainException` carrying a stable machine code (e.g., `inventory.insufficient_stock`) mapped by a central handler to the API error format (`docs/05-Backend/README.md` §4, ADR-0003).

**Patterns:**
- FormRequest validation failures → 422 with field-level errors, consumed by React Hook Form on the frontend (`docs/06-Frontend/README.md`).
- 401 → SPA redirects to login; 403 → SPA shows a no-permission screen; 5xx → toast with a visible correlation ID for support (`docs/06-Frontend/README.md` §4).

### Cross-Cutting Concerns (Intended)

**Logging:** Not yet detailed beyond monitoring domain (`docs/24-Monitoramento/README.md` indexed, content not yet written).
**Validation:** FormRequest (syntactic) + Service/Model (business) split, mirrored on the frontend by Zod schemas generated to match API validation (`docs/06-Frontend/README.md`).
**Authentication/Authorization:** Laravel Sanctum (cookie-based SPA auth, ADR-0005) + `spatie/laravel-permission` RBAC with deny-by-default (ADR-0011); frontend gating via a `can()` helper is UX-only — the backend is always the authority.
**Auditing:** Dual-layer — domain fact tables (ledgers, status histories) plus generic `owen-it/laravel-auditing` mutation trail with mandatory reason text on sensitive actions (ADR-0012).

---

*Architecture analysis: 2026-07-06*
