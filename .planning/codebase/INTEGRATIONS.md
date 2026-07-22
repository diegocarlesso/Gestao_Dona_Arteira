# External Integrations

**Analysis Date:** 2026-07-07

## Important: Two distinct realities in this repository

This repository contains **no ERP application code**. As with `STACK.md` and `ARCHITECTURE.md`, integrations must be read in two separate layers:

1. **Legacy reference app (implemented, read-only)** — `Dona_Arteira_Gestao_desktop/` — has exactly two real integrations: a MySQL/MariaDB database and an FTP file store. Both are documented below under "Part A" as things that **exist and run**. Per `CLAUDE.md` rule 9, this app is reference-only and must never be evolved or wired into new integrations.
2. **Target ERP integrations (specified in docs, not implemented)** — WooCommerce, SEFAZ (NF-e), Melhor Envio, e-mail, WhatsApp, payment gateway, marketplaces — described only under `docs/15-Integracoes/`, `docs/16-WooCommerce/`, `docs/14-NFe/`, and ADRs in `docs/27-ADR/`. No `Integrations/` module, no HTTP client, no webhook controller exists in code anywhere in the repo. These are documented below under "Part B" as **planned/specified**, always with a doc citation.
3. **A one-time migration data source** — the legacy WooCommerce production database has been dumped and imported into a scratch MariaDB instance (`donaarteira_legado`) for analysis/migration planning. This is not a live integration; it is a static, disposable copy used only during Gate 01 planning and the future Gate 01 migration ETL. Documented under "Part C".

**Governing rule (BR-701, `docs/15-Integracoes/README.md` §2, `CLAUDE.md` rule 4):** the ERP must never access another system's database directly. All integration is via authenticated REST APIs + webhooks, through an anti-corruption `Integrations/<Sistema>` layer, with queues and idempotency. The one documented exception is the migration ETL reading the legacy MySQL database directly, because that source is explicitly "ours, not an external system" (`docs/17-Migracao/README.md` §3, F2) — a one-time, time-boxed carve-out, not a precedent for ongoing integration design.

---

## Part A — Legacy reference app integrations (implemented)

### Database (MySQL/MariaDB)

- **What:** direct SQLAlchemy connection to a MySQL/MariaDB instance holding the desktop app's own schema (`clients`, `packages`, `pieces`, `piece_images`, `orders`, `order_items`).
- **Client:** SQLAlchemy `2.0.35` engine + `PyMySQL 1.1.1` driver, connection string built as `mysql+pymysql://...` in `Dona_Arteira_Gestao_desktop/dagestao/db.py:8-10`.
- **Config:** `.env`-driven — `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DB`, `MYSQL_USER`, `MYSQL_PASSWORD`, loaded via `python-dotenv` in `Dona_Arteira_Gestao_desktop/dagestao/config.py`. Defaults hardcoded (`mysql_host="localhost"`, `mysql_db="dona_arteira"`, `mysql_user="root"`) in the same file.
- **Auth:** plain username/password, no TLS enforcement visible in the connection code.
- **Pattern:** session-per-action — every widget opens its own `SessionLocal()` (`db.py:11-12`), queries/mutates, commits, and closes; no connection pooling strategy beyond SQLAlchemy's engine default.
- **Note:** this is a *separate* database from the WooCommerce site database and from `donaarteira_legado` (Part C below) — it holds only the desktop app's own client/order/piece data, not e-commerce data.

### File storage (FTP)

- **What:** piece photos are uploaded/downloaded as raw bytes over FTP; only the FTP path (e.g., `pecas/{code}/{filename}`) is stored in the database column `piece_images.ftp_path` — the images themselves live entirely on the FTP server, not in the DB or on local disk.
- **Client:** Python stdlib `ftplib.FTP`, wrapped by `FTPStorage` class in `Dona_Arteira_Gestao_desktop/dagestao/storage.py`.
- **Config:** `.env`-driven — `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD`, `FTP_BASE_PATH` (`Dona_Arteira_Gestao_desktop/dagestao/config.py`).
- **Auth:** plain FTP username/password (no FTPS/SFTP observed in `storage.py`).
- **Call sites:** upload in `Dona_Arteira_Gestao_desktop/dagestao/da_widgets/piece_form.py:84-91` (upload happens *before* the DB row is saved — no transactional coupling between the two); downloads (thumbnails) in `piece_form.py:61-69` and `da_widgets/order_details.py`.
- **Error handling:** best-effort only — `ftp.quit()` and similar calls are wrapped in silent `except Exception: pass` (`storage.py:20-22`); no retry logic.

### What does NOT exist in the legacy app

- No WooCommerce/REST integration of any kind — the desktop app has no awareness of the e-commerce site.
- No SMTP/e-mail sending.
- No SEFAZ/NF-e emission — the desktop app has no fiscal module.
- No authentication provider — no login/user concept at all (`ARCHITECTURE.md` §"Cross-Cutting Concerns", Part 1).
- No message queue, cache, or webhook receiver.

---

## Part B — Target ERP integrations (specified, NOT implemented)

Source of truth: `docs/15-Integracoes/README.md` (framework), `docs/16-WooCommerce/` (WooCommerce detail), `docs/14-NFe/README.md` (NF-e), `docs/03-Arquitetura/01-visao-c4.md` (system context), and ADR-0007/ADR-0009/ADR-0016 in `docs/27-ADR/`. None of this has corresponding code — no `Integrations/` directory, no HTTP client class, no webhook route exists anywhere in the repo yet. Treat everything in this section as a specification to implement, not an inventory of running integrations.

### Universal integration framework (planned)

Every integration — present or future — must follow one pattern, documented once in `docs/15-Integracoes/README.md` and never reinvented per-integration:

- **Anti-corruption layer:** `App\Modules\Integrations\<Sistema>` translates external payloads ↔ internal DTOs; no other module ever parses an external payload directly.
- **Anatomy of an integration (planned directory shape):**
  ```text
  Integrations/<Sistema>/
  ├── Client.php            # HTTP client: auth, base URL, rate limit, transport retries
  ├── DTOs/                 # typed mirror of the external payload
  ├── Adapters/             # translation DTO ↔ internal entities/DTOs
  ├── Jobs/                 # PushX / PullX / ReconcileX — small, idempotent, batched
  ├── Webhooks/              # controller + signature verification + raw persistence
  └── Mappers/StatusMap.php # de-para tables (status, payment methods, ...)
  ```
- **Outbound pipeline:** domain event → Listener enqueues Job → Job reads mapping → Adapter builds payload → Client sends → updates mapping/checksum → logs to `sync_jobs_log`. Retry backoff: 1m/5m/30m/2h; exhausted → `IntegrationSyncFailed` event (alert + manual-reprocess item in the integrations panel).
- **Inbound pipeline:** Webhook → HMAC signature check → persist raw to `incoming_webhooks` → immediate `200` → Job processes asynchronously → dedupe → Adapter → module Service → mapping update. Raw payloads retained 30 days for reprocessing after a bug fix.
- **Asynchronous by default (ADR-0007, BR-705):** the local operation never waits on an external system; the one documented exception is interactive lookups (e.g., an on-screen freight quote) with a short timeout and fallback.
- **Idempotency both directions:** inbound dedupe by `delivery_id`/external ID; outbound safe-for-retry via upsert-by-mapping, `Idempotency-Key` header where the partner supports it.
- **Persistent mapping:** `integration_mappings` table links a local entity ↔ external ID with a checksum to detect drift (BR-704).
- **Periodic reconciliation:** webhooks can be lost — a scheduled job diff's ERP state vs. external state and corrects/alerts; eventual consistency is guaranteed by reconciliation, not by luck.
- **Feature flag per integration:** each integration can be toggled off without a deploy.
- **Credentials encrypted:** integration credentials live in the DB with an `encrypted` cast, or in `.env`; never in the repository (`docs/25-Seguranca/README.md` §2, `docs/15-Integracoes/README.md` §2.8).

### Integration catalog (`docs/15-Integracoes/README.md` §4)

| System | Direction | Phase (Gate) | Criticality | Doc |
|---|---|---|---|---|
| WooCommerce | bidirectional | Gate 2 | High | `docs/16-WooCommerce/README.md` |
| SEFAZ (NF-e) | ERP → SEFAZ | Gate 5 | High | `docs/14-NFe/README.md` |
| E-mail (SMTP) | outbound only | Gate 2 | Medium | transactional: order confirmation, tracking, NF-e delivery — no dedicated doc yet, referenced from `docs/14-NFe/README.md` §5 and `docs/15-Integracoes/README.md` §4 |
| Melhor Envio | bidirectional (label/tracking) | Gate 6 | Medium | template to be filled at Gate 06 (`docs/00-Visao-Geral/01-escopo-e-nao-escopo.md`, `docs/10-Vendas/README.md` §5) |
| Direct carriers | outbound only | Gate 6+ | Low | same as above |
| WhatsApp (Meta Cloud API) | outbound (notifications) | Gate 7 | Low | mentioned in `docs/11-Compras/README.md` (informal supplier orders) and `docs/12-Financeiro/README.md` (overdue reminders); no dedicated integration doc yet |
| Payment gateway | bidirectional | Gate 7 | Medium | referenced in `docs/12-Financeiro/README.md` (Woo checkout gateway fees today; dedicated gateway integration is future scope) |
| Marketplaces | bidirectional | Gate 7 | Medium | one adapter per marketplace, same framework pattern |

### WooCommerce (`docs/16-WooCommerce/README.md`, `docs/16-WooCommerce/01-mapeamento-de-campos.md`)

- **Purpose:** the WordPress+WooCommerce site remains the sales channel; the ERP becomes the master of catalog, price, and stock. Never direct database access (`docs/16-WooCommerce/README.md` §1, BR-701).
- **API:** WooCommerce REST API v3 (`/wp-json/wc/v3/`), authenticated via consumer key/secret over HTTPS; keys are read/write-scoped and stored encrypted.
- **Inbound webhooks (Woo → ERP):** `order.created`, `order.updated`, `customer.created`, `customer.updated` — HMAC-SHA256 signed and verified (BR-701).
- **Rate limiting:** Woo runs on shared hosting and degrades under bursts — push jobs batch ~20 items with spacing; reconciliation runs during low-traffic hours (overnight).
- **Test environment requirement:** a staging clone of the WordPress site is required before Gate 02 (documented risk).
- **Sync matrix and conflict resolution (`docs/16-WooCommerce/README.md` §3):**

  | Entity | Direction | Trigger | Conflict winner |
  |---|---|---|---|
  | Product (data, retail price, images*, categories) | ERP → Woo | `ProductUpdated`/`PriceChanged` events | ERP (BR-702 — editing in wp-admin is forbidden by policy; reconciliation overwrites + alerts) |
  | Published stock | ERP → Woo | `StockMovementRecorded`/`StockReserved` | ERP (BR-204 formula: available − buffer) |
  | Order | Woo → ERP | webhook `order.created`/`order.updated` | Woo is the source of the fact (BR-703); fulfillment afterward is ERP's |
  | Order status + tracking | ERP → Woo | `OrderShipped` etc. | ERP |
  | Customer | Woo → ERP (new) / ERP → Woo (corrections) | webhooks / manual | dedupe by e-mail + document; cadastral corrections: ERP |

  \* Images: per ADR-0017, phase 1 keeps media hosted on WooCommerce; the ERP stores only a reference (see "Media dependency inversion" in `ARCHITECTURE.md`).

- **Order webhook flow (critical path, `docs/16-WooCommerce/README.md` §4):** Woo sends `order.created` with HMAC signature → ERP webhook endpoint verifies signature, persists the raw payload, returns `200` immediately → a queued job processes it (deduped by delivery/order id) → Sales/Inventory module creates the order (channel=`woocommerce`, customer resolved/deduped, items matched by SKU) → stock reservation (BR-203) → job pushes updated stock back to Woo for affected products.
- **Documented edge cases** (`docs/16-WooCommerce/01-mapeamento-de-campos.md`): line item with unknown SKU (order still imported with an "unmapped item" + alert — a sale is never silently lost); order edited in Woo after import; gateway refund/cancellation; guest checkout (no Woo customer account — ERP creates/matches the customer by order e-mail).
- **Field mapping highlights (`docs/16-WooCommerce/01-mapeamento-de-campos.md`):**
  - Product: `sku` is the match key (Woo allows empty SKU → mandatory sanitization during migration); wholesale price never syncs to Woo; only products with `kind` = `finished_good`/`resale` sync (raw materials/packaging/supplies never do).
  - Customer: `email` is dedupe key #1; CPF/CNPJ comes from a Brazilian checkout plugin's post meta (exact plugin/meta key still an open Gate 02 inventory item) and is dedupe key #2.
  - Order status de-para: Woo `processing` → ERP "Paid" (ready to pick, the main case); `completed` → "Shipped/Delivered"; `pending` is not imported (awaits payment); `cancelled`/`refunded`/`failed` → "Cancelled" (with reversal if already paid).
- **Reconciliation:** a nightly job checksum-compares products/stock/last-7-days orders between ERP and Woo, corrects divergence per the conflict table above, and reports on the integrations panel; recurring divergence triggers investigation (bug or someone editing wp-admin directly, BR-702).
- **Dependencies:** Woo REST keys + admin access (to create webhooks); Inventory/Sales/Catalog operational in the ERP (Gate 01); initial migration completed (`docs/17-Migracao/`) — sync starts only *after* cutover.
- **Key risks (`docs/16-WooCommerce/README.md` §7):** a WordPress plugin update silently changing the Woo payload shape; staff continuing to edit products in wp-admin (mitigated by BR-702 policy + reconciliation overwrite); lost webhooks (mitigated by daily reconciliation + incremental pull via `modified_after`); stock divergence during sales peaks (mitigated by per-product buffer BR-204 + <2min sync target).
- **Open Gate 02 inventory items (`docs/16-WooCommerce/01-mapeamento-de-campos.md` §"Pendências"):** which Brazilian checkout plugin is in use (CPF/CNPJ meta key name); use of variable products/coupons/subscriptions; which freight/tracking plugin is active; which payment gateways are active and their refund behavior.

### SEFAZ / NF-e (`docs/14-NFe/README.md`, ADR-0009)

- **Purpose:** issue, manage, and store NF-e model 55 (Brazilian e-invoice) with an A1 digital certificate — from a billed order to authorized XML + DANFE delivered to customer and accountant, including contingency and events (cancellation, CC-e correction letter, number invalidation) and 5-year legal retention.
- **Library (planned):** `nfephp-org/sped-nfe` (ADR-0009) — chosen over managed fiscal APIs (Focus NFe, NFe.io, Tecnospeed) for zero licensing cost, at the cost of owning ongoing maintenance against SEFAZ's Notas Técnicas (regulatory updates), especially during the 2026+ tax reform (IBS/CBS). ADR-0009 explicitly reserves a `NfeGatewayInterface` seam so a managed API can be swapped in later without touching the domain.
- **Protocol:** SOAP/XML transmission to SEFAZ, signed with an A1 certificate.
- **Certificate handling:** `.pfx` file stored **outside the webroot**, encrypted at rest, password kept only in the config vault/`.env` (never in the repository); validity monitored with alerts at 30/15/7 days before expiry (`docs/14-NFe/README.md` §4, `docs/25-Seguranca/README.md`).
- **Numbering (BR-602):** `fiscal_series.next_number` reserved via `SELECT ... FOR UPDATE`; a SEFAZ rejection does not burn the number (same number is reissued); an abandoned number is invalidated at SEFAZ.
- **Environments:** homologação (test) environment is permanent (BR-605) — every fiscal change is tested there first, with the mandatory "NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO" watermark.
- **Contingency:** manual operator-triggered SVC contingency (`tpEmis=6/7` depending on state) when SEFAZ is unreachable and the situation is urgent; otherwise the queue retries with the number still reserved.
- **Async:** emission is job-based with UI feedback via polling/refresh — the UI never blocks waiting on SEFAZ.
- **Distribution/retention (BR-603):** authorized XML + events stored with daily backup + monthly zip export to external storage; retention ≥ 5 years; DANFE PDF is regenerable from the XML so does not need the same guarantee; fiscal panel supports search by key/number/customer/period with batch download (accountant profile, BR-803).
- **Dependencies:** `docs/13-Fiscal` tax profiles validated by the accountant; a valid A1 certificate; `nfephp-org/sped-nfe`; PHP extensions `openssl`, `soap`, `curl`, `dom` (must be validated in the hosting environment before Gate 05 — see ADR-0016 below); Sales module (order data), with shipping blocked on invoice authorization (BR-309).
- **Key risks (`docs/14-NFe/README.md` §8):** shared hosting lacking the CPU/extensions to sign+transmit reliably (critical — mitigated by validating the environment before Gate 05, and by ADR-0016's VPS recommendation); tax-reform Notas Técnicas forcing rapid library updates (mitigated by tracking `sped-nfe` releases, with a documented fallback to a managed fiscal API if remediation exceeds 2 person-weeks in a semester, per ADR-0009's review triggers).

### Melhor Envio / carriers (planned, Gate 6)

- **Scope:** shipping label generation and tracking, bidirectional; tracking is pushed back to WooCommerce so the customer sees it on the site (`docs/00-Visao-Geral/01-escopo-e-nao-escopo.md` §2, `docs/10-Vendas/README.md` §5).
- **Current state:** referenced in the C4 diagram (`docs/03-Arquitetura/01-visao-c4.md`, actor "Melhor Envio / Transportadoras") and in the integration catalog, but has **no dedicated integration document yet** — "template a preencher no Gate 06" (`docs/15-Integracoes/README.md` §4). Freight cost today is either entered manually at the counter or comes through from the Woo order; Melhor Envio calculation is deferred to fase 6 (`docs/10-Vendas/README.md` §4).

### E-mail (SMTP) (planned, Gate 2)

- **Scope:** transactional outbound only — order confirmation, shipment tracking, NF-e XML/DANFE delivery to customer and a monthly copy to the accountant (`docs/14-NFe/README.md` §5, `docs/03-Arquitetura/01-visao-c4.md` actor "MAIL").
- **Current state:** no dedicated integration document, no provider/library named yet in the docs (e.g., no ADR pins a mailer package). Treat as unspecified beyond "SMTP, outbound, transactional."

### WhatsApp (Meta Cloud API) (planned, Gate 7)

- **Scope:** outbound notifications only — e.g., overdue-receivable reminders (`docs/12-Financeiro/README.md` §"Boas práticas"), and informally referenced as a channel staff already use to send purchase orders to suppliers today (`docs/11-Compras/README.md` §3, listed as a risk: "compra informal via WhatsApp pode contornar o sistema").
- **Current state:** catalog entry only (`docs/15-Integracoes/README.md` §4); no dedicated integration document.

### Payment gateway (planned, Gate 7)

- **Scope:** bidirectional gateway integration for wholesale/atacado billing (boleto/PIX), per the integration catalog. Today, gateway activity is indirect — Woo checkout payments land in a "Gateway Woo" bank account in the financial module with the gateway's fee recorded as an expense (`docs/12-Financeiro/README.md` §3, §4).
- **Current state:** catalog entry only; no dedicated integration document; "Boleto/PIX cobrança integrada a gateway" is explicitly future scope (`docs/12-Financeiro/README.md` §8, fase 7).

### Marketplaces (planned, Gate 7)

- **Scope:** one adapter per marketplace, reusing the same integration framework (`docs/15-Integracoes/README.md` §4). No specific marketplace named yet anywhere in the docs.

### Explicitly out of scope

- **Replacing WordPress/WooCommerce** — Woo remains the sales channel permanently (`docs/00-Visao-Geral/01-escopo-e-nao-escopo.md` §3).
- **Formal accounting integration** (SPED, ledger entries) — handled by an external accountant via monthly exports, not a live integration.
- **Payroll/HR systems** — out of domain.
- **NFC-e / offline POS** — only NF-e modelo 55 is in scope; NFC-e is reconsidered only if a high-volume physical store opens.
- **CRM/marketing automation** — covered by Woo/external tools if ever needed.

---

## Part C — Legacy WooCommerce data (one-time migration source, not a live integration)

- **What it is:** a full SQL dump of the production WooCommerce site, `docs/database_dump/u917402451_donaarteira.sql` (115 MB, ~167 tables, phpMyAdmin export dated 2026-07-03), imported into a disposable local MariaDB database named **`donaarteira_legado`** for analysis (`docs/31-Inventario-Legado/01-visao-geral.md` §5; user memory `legado-analysis-db.md`).
- **Origin site:** WordPress + WooCommerce 10.7.0, hosted on Hostinger (account `u917402451`), theme Enfold, source database MariaDB 11.8.8; the analysis copy runs on a local XAMPP MariaDB 10.4.32 (`C:\xampp\mysql`, `root` with no password, port 3306), started via `mysqld.exe --standalone` and queried with `mysql.exe -u root --default-character-set=utf8mb4 donaarteira_legado`.
- **Table prefix quirk:** tables use `SERVMASK_PREFIX_` (an All-in-One WP Migration export marker, not the site's real prefix); a near-empty vestigial `wp_`-prefixed install coexists in the same dump but holds no store data (`docs/31-Inventario-Legado/01-visao-geral.md` §4).
- **HPOS is off** on the source site — orders live in `SERVMASK_PREFIX_posts` (`post_type = shop_order`), not in a dedicated `wc_orders` table (which is empty) — this affects how the future migration ETL must query orders.
- **Known import gaps:** 8 Wordfence security/log tables (`wf*`) failed to import due to MariaDB 11-only syntax unsupported on 10.4 — judged irrelevant to the domain analysis (`docs/31-Inventario-Legado/01-visao-geral.md` §5). Image files themselves are not in the dump (SQL only) — file existence in `/wp-content/uploads` is unverified.
- **Anchor numbers extracted:** 716 products (677 simple + 39 variable), 77 variations, 48 categories, 200 users (62 real buyers), 85 orders spanning 2021-11→2026-05, ~R$9,176 total completed online revenue (`docs/31-Inventario-Legado/01-visao-geral.md` §7).
- **Relationship to the desktop app's database:** this is a **different** database from the one described in Part A (`dona_arteira` MySQL DB used by `Dona_Arteira_Gestao_desktop/`). The desktop app's database was not provided for this inventory — customers/orders that exist only in the desktop app (wholesale/counter sales) are not captured here (`docs/31-Inventario-Legado/01-visao-geral.md` §5, limitation 3).
- **Future use — Gate 01 migration ETL (`docs/17-Migracao/README.md`, ADR-0010):** the planned migration reads Woo via REST API incrementally (`modified_after`), and reads the legacy desktop MySQL database *directly* (`stg_legacy_*` staging tables) — the one documented exception to "never database-to-database" (BR-701), justified because that source is the team's own system, not an external one. Planned Artisan commands (not yet implemented): `erp:migrate:inventory-report`, `erp:migrate:extract {fonte}`, `erp:migrate:load {entidade} [--dry-run]`, `erp:migrate:validate`.
- **Not a live sync:** unlike Part B's WooCommerce integration, `donaarteira_legado` is a static, disposable snapshot used for pre-Gate-01 planning and will be superseded by the real ETL extraction (REST API + a fresh dump) at actual cutover time — it is not wired into any running process today.

---

*Integration audit: 2026-07-07*
