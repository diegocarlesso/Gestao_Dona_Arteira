# Codebase Concerns

**Analysis Date:** 2026-07-06

**Scope note:** No ERP application code exists yet (Gate 00 delivered documentation only; Gate 01 — Núcleo + Migração — has not started). The only source code in the repository is the legacy reference app at `Dona_Arteira_Gestao_desktop/` (Python + PyQt6 + SQLAlchemy), which is **read-only** per `CLAUDE.md` rule 9 — it must never be evolved or auto-converted, only consulted for business-rule archaeology. This document therefore covers three things: (a) technical debt and fragility found in that legacy reference app — useful so nobody mistakes its shortcuts for intended behavior, (b) project-level risks visible in the repository itself, and (c) pointers to legacy **data** risks that are already fully catalogued in `docs/31-Inventario-Legado/14-riscos.md` and are not repeated here.

## Tech Debt

**Manual, disconnected stock counter (legacy app):**
- Issue: `Piece.in_stock` (`Dona_Arteira_Gestao_desktop/dagestao/models.py:50`) is a plain `Integer` edited by hand via a `QSpinBox` in `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/piece_form.py:34-38,102`. No order-related code path adjusts it: `OrderFormDialog._save()` (`Dona_Arteira_Gestao_desktop/dagestao/da_widgets/order_form.py:61-83`) creates/updates `OrderItem` rows but never touches `in_stock` on save, cancel, or delete.
- Files: `Dona_Arteira_Gestao_desktop/dagestao/models.py`, `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/piece_form.py`, `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/order_form.py`, `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/piece_manager.py:73,84`
- Impact: the legacy app's stock number cannot be trusted as ground truth (it also gets displayed as a "Sim/Não" boolean in `piece_manager.py:73,84` despite being a quantity field — mixed semantics). This is the exact anti-pattern `CLAUDE.md` rule 7 exists to prevent in the ERP ("estoque nunca é atualizado diretamente… todo ajuste passa por movimento imutável em `inventory_movements`").
- Fix approach: none needed in the legacy app (read-only reference). When building the ERP's `09-Estoque` module, do not port this field or its semantics — implement the immutable movement ledger described in `docs/09-Estoque/`.

**Money represented as floating point (legacy app):**
- Issue: all monetary columns are SQLAlchemy `Float` — `Piece.retail_price`/`wholesale_price` (`models.py:41-42`), `Order.payment_value` (`models.py:79`), `OrderItem.price` (`models.py:90`) — and totals are accumulated in Python `float` (`Dona_Arteira_Gestao_desktop/dagestao/main_window.py:194-200`: `total = 0.0; total += q * p`).
- Files: `Dona_Arteira_Gestao_desktop/dagestao/models.py`, `Dona_Arteira_Gestao_desktop/dagestao/main_window.py`
- Impact: precedent for rounding drift on totals; exactly the anti-pattern `CLAUDE.md` rule 6 forbids for the ERP (`DECIMAL(15,2)` + `brick/money`).
- Fix approach: none needed in legacy app. Confirm the ERP's Eloquent models/migrations use `decimal` columns and `brick/money` value objects from the first migration, per `docs/04-Banco-de-Dados/02-convencoes-de-banco.md`.

**Duplicated/conflicting column and import declarations (legacy app):**
- Issue: `Dona_Arteira_Gestao_desktop/dagestao/models.py` imports `Column, Integer, String, Float, Text, ForeignKey` twice (lines 2 and 6), and declares `package_id = Column(Integer, ForeignKey("packages.id"))` twice in a row on `Piece` (lines 53-54).
- Files: `Dona_Arteira_Gestao_desktop/dagestao/models.py`
- Impact: harmless at runtime (Python re-executes the assignment) but signals the file was edited by copy-paste patches over time without cleanup — matches the "known simplifications" the legacy app carries.
- Fix approach: none needed (read-only reference); do not copy this file's structure verbatim into ERP migrations.

**Imperative, unversioned schema "migration" run on every startup (legacy app):**
- Issue: `Dona_Arteira_Gestao_desktop/dagestao/db.py:_migrate_schema()` (lines 15-76) issues raw `ALTER TABLE` statements (rename/add columns, create index) directly against the live DB every time `init_db()` runs, guarded only by ad-hoc `try/except Exception: return set()` introspection helpers (`columns()`, `has_column()`, `index_exists()`, lines 18-31).
- Files: `Dona_Arteira_Gestao_desktop/dagestao/db.py`
- Impact: no migration history/versioning; if introspection fails for any reason (permissions, connectivity) the helper silently returns an empty set and the function proceeds as if columns are missing, potentially re-issuing `ADD COLUMN`/`CHANGE COLUMN` against an already-correct schema.
- Fix approach: none needed in legacy app. The ERP uses Laravel migrations (`docs/04-Banco-de-Dados/`), which already solves this properly.

**Duplicate, partially stale widget module tree (legacy app):**
- Issue: two parallel PyQt widget packages exist — `Dona_Arteira_Gestao_desktop/dagestao/widgets/` (8 files) and `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/` (11 files, includes `pieces_tab.py`, `package_manager.py`, `piece_select.py` that `widgets/` lacks). `main_window.py` imports exclusively from `da_widgets` (`main_window.py:21-25,153`).
- Files: `Dona_Arteira_Gestao_desktop/dagestao/widgets/*.py` (dead), `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/*.py` (live)
- Impact: anyone reading the legacy app "for reference" can easily read the wrong (dead, slightly different — see `diff` of `order_form.py`, which differs only in a hardcoded dialog size) copy and draw incorrect conclusions about actual behavior.
- Fix approach: none needed (read-only reference); when consulting this codebase for business rules, always confirm the entry point (`main_window.py`) actually imports the file being read, and prefer `da_widgets/`.

**Dead autocomplete scaffold (legacy app):**
- Issue: `_apply_completer()` (`Dona_Arteira_Gestao_desktop/dagestao/da_widgets/order_form.py:34-39`, duplicated in `widgets/order_form.py:31-36`) constructs a `QCompleter` but never attaches it to any editor and is never called from anywhere in the codebase (confirmed via full-repo grep).
- Files: `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/order_form.py`
- Impact: none (unreachable code); noted only so it isn't mistaken for working piece-code autocomplete when reading the reference app.
- Fix approach: none needed (read-only reference).

**Broad exception swallowing without logging (legacy app):**
- Issue: pervasive `except Exception: pass` blocks discard errors silently — FTP connect/upload/download and `quit()` (`Dona_Arteira_Gestao_desktop/dagestao/storage.py:16-22,29-32`), thumbnail loading (`da_widgets/piece_form.py:67-68`, `da_widgets/order_details.py:35-36`), stylesheet loading (`main_window.py:45-49`), directory creation on FTP (`da_widgets/piece_form.py` via `storage.py` mkd loop).
- Files: `Dona_Arteira_Gestao_desktop/dagestao/storage.py`, `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/piece_form.py`, `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/order_details.py`, `Dona_Arteira_Gestao_desktop/dagestao/main_window.py`
- Impact: failures (bad FTP credentials, missing image, broken stylesheet) present as "nothing happened" rather than a diagnosable error. The one good counter-example in the same codebase is `main_window.py`'s top-level handlers, which do use `logging.exception(...)` plus a user-facing `QMessageBox` (e.g. `main_window.py:215-217,251-253`) — that pattern, not the silent one, is the one worth learning from.
- Fix approach: none needed in legacy app. For the ERP, follow `docs/05-Backend/` error-handling conventions instead.

## Known Bugs

**`ClientFormDialog._fill()` references an undefined variable (legacy app):**
- Symptoms: opening an existing client for editing raises `NameError: name 'client' is not defined`.
- Files: `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/client_form.py:44-49` (method signature is `_fill(self, c: Client)`, but body reads `client.cep` and `client.notes` instead of `c.cep`/`c.notes`)
- Trigger: double-click any client row in `main_window.py` (`on_double_click` → `edit_client`) that has the newer `cep`/`notes` fields populated.
- Workaround: none in the app; this is a latent crash in the reference tool, not something to fix (read-only) but something to be aware of if attempting to run the legacy app for verification.

**`PackageManagerDialog.save()` breaks existing piece↔package links (legacy app):**
- Symptoms: after editing packages and saving, pieces that referenced a package by ID silently lose the association (or point at the wrong package).
- Files: `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/package_manager.py:43-55` — `save()` does `s.query(Package).delete()` then re-inserts every row from the table, so every package gets a **new auto-increment ID** on every save.
- Trigger: open "Editar Embalagens" and click "Salvar alterações" at least once after any `Piece.package_id` has been set.
- Workaround: none; avoid treating this dialog's behavior as a model for the ERP's packaging/BOM feature — use an upsert-by-identity approach instead.

**Duplicate Qt signal connection causes multi-fire autofill (legacy app):**
- Symptoms: after adding several order item rows, editing the "Código" cell can trigger the auto-fill handler multiple times for a single keystroke.
- Files: `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/order_form.py:41-46` — `add_item_row()` calls `self.table.cellChanged.connect(self._maybe_autofill)` on every invocation instead of connecting once in `_build_ui()`.
- Trigger: add 2+ item rows to an order, then edit a code cell.
- Workaround: none; effect is mostly idempotent (auto-fill only writes when the target cell is empty/zero) so it does not visibly corrupt data, but it is a resource leak (handler count grows unbounded per dialog instance).

**`PieceManagerWindow` passes non-string values to `QTableWidgetItem` (legacy app):**
- Symptoms: potential `TypeError` when populating the price/dimension columns, since `QTableWidgetItem(text: str)` receives raw `float`/`None` values (`retail_price`, `height_cm`, etc.) instead of formatted strings.
- Files: `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/piece_manager.py:77-82` (`self.table.setItem(r, 2, QTableWidgetItem(varej))` etc., where `varej = getattr(p, "retail_price", None)`)
- Trigger: open "Gerenciar Peças" with any piece row.
- Workaround: none in the app; the helper methods `_fmt2`/`_norm_code` defined just above (`piece_manager.py:17-32`) look like they were meant to format these values but are missing `self` in their signature and are never called — dead, unused formatting helpers left next to the bug they were presumably meant to fix.

## Security Considerations

**Legacy app image storage over plaintext FTP:**
- Risk: `Dona_Arteira_Gestao_desktop/dagestao/storage.py` uploads/downloads piece images over unencrypted FTP (`ftplib.FTP`, not FTPS/SFTP) — credentials and image bytes travel in clear text on the network.
- Files: `Dona_Arteira_Gestao_desktop/dagestao/storage.py`, `Dona_Arteira_Gestao_desktop/dagestao/config.py` (FTP settings)
- Current mitigation: none; acceptable only because this is a frozen, read-only reference tool with limited use.
- Recommendation: none needed for the legacy app itself. Ensure the ERP's media/storage approach (whatever is chosen in the relevant module doc) uses an encrypted transport; do not reuse this FTP client.

**No authentication/authorization in the legacy app:**
- Risk: the desktop app has no login, roles, or audit trail — any local user with DB/FTP credentials in `.env` has full read/write access to all clients, orders, and pricing.
- Files: `Dona_Arteira_Gestao_desktop/dagestao/config.py`, `Dona_Arteira_Gestao_desktop/dagestao/db.py`
- Current mitigation: none; single-operator desktop tool, out of scope to fix.
- Recommendation: already addressed for the ERP by design in `docs/18-Usuarios/`, `docs/19-Permissoes/`, and `docs/26-Auditoria/` — no action needed here beyond noting that none of the legacy app's (lack of) access-control logic should be treated as a reference.

**`.env`-based distribution habit (legacy app):**
- Risk: `Dona_Arteira_Gestao_desktop/dagestao/README.md:10` instructs "Configure `.env` (já incluso)" ("already included"), implying a real `.env` with DB/FTP credentials was historically handed off alongside the app outside version control. No `.env` file is present in the current checkout (confirmed — only `.env` is listed in `.gitignore`, no actual file found), so nothing is currently leaked, but the distribution habit itself is worth flagging.
- Files: `Dona_Arteira_Gestao_desktop/dagestao/README.md`, `Dona_Arteira_Gestao_desktop/.gitignore`
- Current mitigation: `.gitignore` correctly excludes `.env*` from version control.
- Recommendation: when writing the ERP's developer setup guide (proposed in `docs/00-Visao-Geral/04-analise-critica-gate00.md` §E as "Guia de setup do desenvolvedor"), specify `.env.example` + secret manager/vault handoff instead of ad-hoc file sharing.

## Performance Bottlenecks

**Unbounded eager loading with no pagination (legacy app):**
- Problem: `MainWindow.load_clients()` loads **every** client with **every** order and **every** order item via `joinedload` in a single query, with no pagination or lazy expansion (`Dona_Arteira_Gestao_desktop/dagestao/main_window.py:166-188`).
- Files: `Dona_Arteira_Gestao_desktop/dagestao/main_window.py`
- Cause: `select(Client).options(joinedload(Client.orders).joinedload(Order.items))` with no `LIMIT`/paging.
- Improvement path: not applicable to the legacy app (frozen, low volume per `docs/31-Inventario-Legado/99-relatorio-executivo.md` — 85 online orders in 4.5 years). Worth remembering as a pattern **not** to replicate once the ERP's order volume grows past the current legacy/online scale — plan pagination in `docs/05-Backend/`/`docs/07-API/` list endpoints from the start.

## Fragile Areas

**`db.py` schema auto-migration function:**
- Files: `Dona_Arteira_Gestao_desktop/dagestao/db.py:_migrate_schema()`
- Why fragile: hand-maintained, order-dependent `ALTER TABLE` statements with silent failure fallbacks (see Tech Debt above); every new column requires manually extending this function, and a failed introspection call is indistinguishable from "column already exists."
- Safe modification: none needed — do not extend this function; it is frozen with the rest of the reference app.
- Test coverage: none (no tests exist for the legacy app at all).

**Duplicate widget trees (`widgets/` vs `da_widgets/`):**
- Files: `Dona_Arteira_Gestao_desktop/dagestao/widgets/*.py`, `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/*.py`
- Why fragile: identical-looking files with subtly different behavior (confirmed via diff of `order_form.py`, which differs only in a hardcoded window size between the two copies) — easy to consult the wrong one when mining business rules.
- Safe modification: none applicable (read-only); always check `main_window.py`'s imports (`da_widgets`) before trusting a file under `widgets/`.

**Documentation-vs-eventual-code drift (project-level):**
- Files: all of `docs/` (Gate 00 output)
- Why fragile: `docs/00-Visao-Geral/04-analise-critica-gate00.md` (risk R2, §5.1-5.3) already self-flags that much of the foundation is marked "Em revisão/Rascunho," that the data model in `docs/04-Banco-de-Dados/` is conceptual rather than physical, and that NFR numbers are estimates (📏) pending the Gate 01 inventory. Until Gate 01 code exists, every module doc is a hypothesis, not a verified spec.
- Safe modification: treat "Em revisão"/"Rascunho" status markers in module docs as blocking signals before planning implementation phases against them; re-validate against `docs/31-Inventario-Legado/` findings first.
- Test coverage: not applicable (no code yet); the equivalent safeguard is the gate-exit review process described in `docs/28-Roadmap/README.md`.

**Bus factor of one (project-level):**
- Files: whole repository
- Why fragile: `docs/00-Visao-Geral/04-analise-critica-gate00.md` §2 and risk R6 explicitly name solo-developer fatigue/abandonment as "the biggest real risk of any project like this," given the gap between the requested enterprise-grade architecture (Clean Architecture, DDD, SOLID, Repository Pattern, SPA+API) and a one-person team maintaining it.
- Safe modification: the roadmap's mitigation is already documented — blocking gates (`docs/28-Roadmap/`), militant simplicity, and automation via `.claude/agents/` and `.claude/skills/`. No code-level action needed yet; keep this in mind when scoping any future phase (prefer the simplest solution that satisfies the gate).

## Scaling Limits

Not applicable — no ERP code exists yet, and the legacy reference app is a frozen single-user desktop tool with no scaling model (single FTP connection per file operation in `Dona_Arteira_Gestao_desktop/dagestao/storage.py:_connect()`, single local MySQL connection pool in `db.py`). The one relevant data point for future ERP capacity planning — real transaction volume — is captured in `docs/31-Inventario-Legado/99-relatorio-executivo.md`, not repeated here.

## Dependencies at Risk

**Legacy app's pinned Python dependencies:**
- Package: `PyQt6==6.7.1`, `SQLAlchemy==2.0.35`, `PyMySQL==1.1.1`, `python-dotenv==1.0.1`, `Pillow==10.4.0` (`Dona_Arteira_Gestao_desktop/dagestao/requirements.txt`)
- Risk: exact pins with no lockfile hash, no CI, and no plan to ever upgrade — acceptable specifically **because** `CLAUDE.md` rule 9 forbids evolving this app; flagged only so nobody runs `pip install -U` against it expecting a supported upgrade path.
- Impact: none if left untouched; a stale but working reference.
- Migration plan: none — this app is retired, not maintained.

## Missing Critical Features

**ADR-0016 hosting decision still pending (project-level, blocking):**
- Problem: `docs/27-ADR/ADR-0016-hospedagem.md` is status "Proposto," recommending a VPS over the originally-assumed Hostinger Business shared plan for queue workers, Redis, NF-e certificate isolation, and backup RPO. `docs/00-Visao-Geral/04-analise-critica-gate00.md` §F1 marks this the most urgent open decision, due "before the end of Gate 01."
- Blocks: deploy design (`docs/23-Deploy/`), monitoring (`docs/24-Monitoramento/`), NF-e environment assumptions (`docs/14-NFe/`), and queue/cache architecture (Redis, ADR-0014) all remain provisional until this is resolved.

**Git repository not initialized (project-level):**
- Problem: the working directory is not a git repository (confirmed: `git status` → "not a git repository"). The entire Gate 00 documentation foundation currently has no version history.
- Blocks: `docs/00-Visao-Geral/04-analise-critica-gate00.md` §F6 already flags this and recommends `git init` plus a first commit of the foundation before any code is written — this has not yet happened.

**Desktop MySQL dump not obtained (project-level, references legacy data risk):**
- Problem: the wholesale-channel data that lives only in the legacy desktop app's MySQL database (`dona_arteira`) has not been provided, so wholesale clients/orders remain invisible to the Gate 01 migration effort.
- Blocks: client/order deduplication across the two source systems (site + desktop); full detail and mitigation already tracked as risk **R-04** in `docs/31-Inventario-Legado/14-riscos.md` — see that document rather than duplicating the analysis here.

**Other legacy-data migration risks:** the full catalogue of data-quality and migration risks (missing SKUs, unreliable published stock counts, duplicated products, HTML-polluted descriptions, unaudited `sevensi-functions` custom plugin, unstructured kit composition, etc.) is already maintained in `docs/31-Inventario-Legado/14-riscos.md` (risks R-01 through R-15) and `docs/31-Inventario-Legado/12-qualidade-dados.md`. Consult those documents directly; they are not duplicated in this file.

## Test Coverage Gaps

**Legacy app has zero automated tests:**
- What's not tested: everything — there is no `tests/` directory, no `pytest`/`unittest` configuration, and no CI workflow anywhere under `Dona_Arteira_Gestao_desktop/`.
- Files: `Dona_Arteira_Gestao_desktop/` (entire tree)
- Risk: every behavior documented in this file (the `ClientFormDialog` `NameError`, the package-ID-reset bug, the disconnected stock counter) was found by reading the code, not by a failing test — none of it has ever been mechanically verified. Treat the legacy app's behavior as a hint about historical business rules, never as a verified specification, per `CLAUDE.md` rule 9.
- Priority: not applicable to fix (read-only reference); relevant only as a reminder that any business rule "confirmed" by reading this code still needs independent validation with the product owner before being encoded as a `BR-xxx` rule.

**ERP has no code yet, therefore no test coverage to assess:**
- What's not tested: N/A — `CLAUDE.md` rule 8 ("Sem código sem teste": Pest for backend, Vitest for frontend) and `docs/22-Testes/` define the intended strategy, but no implementation exists to measure coverage against.
- Files: N/A
- Risk: none yet; flagged so that the first Gate 01 implementation phases are planned with test scaffolding from the start rather than retrofitted later.
- Priority: High — establish Pest/Vitest scaffolding and CI as part of the earliest Gate 01 setup work, before feature code lands.

---

*Concerns audit: 2026-07-06*
