# API Contracts

> All routes are in `routes/web.php` under the `api` prefix (there is **no** `routes/api.php`).
> Auth is **session-based** (same-origin SPA). Update this file whenever routes, validation, or
> response shapes change. Model: [decisions/ADR-002](decisions/ADR-002-vendor-portal-workflow.md).

## Conventions

- Base `/api/...`; everything except `POST /api/login`, the password-reset pair and
  `GET /api/export-report` (key-authenticated, see below) is behind `auth`. Login is `throttle:10,1`.
- Roles via `EnsureRole` (`role:...`): `role:finance,admin` (invoice post/query, PRF create,
  mark-paid, eligible list), `role:admin` (audit-logs, users, approval-levels).
- **No response envelope** — controllers return raw models or Laravel paginator JSON
  (`{ data, current_page, last_page, per_page, total, ... }`).
- Errors: `422` from validation/`ValidationException`; `403` from `role:` middleware or inline
  checks; `404` from route-model binding.
- Uploads: invoices only, `multipart/form-data`, field `documents[]`. Invoice **update uses POST**
  (multipart). `total_amount` and `reference_no` are server-computed.

### Enums (wire values)

- **Invoice status:** `submitted`, `posted`, `query_raised`, `cancelled`.
- **Invoice payment_status:** `not_initiated`, `in_approval`, `approved_for_payment`, `paid`.
- **PRF status:** `draft`, `in_approval`, `approved`, `rejected`, `withdrawn`, `paid`.
- **PRF approval status:** `pending`, `approved`, `rejected`.
- **Roles:** `admin`, `requester`, `approver`, `finance`. **Currencies:** master data (the
  `currencies` table; seeded AED/USD/EUR/GBP/SAR), stored on the invoice as a plain string.

## Auth & meta

| Method | Path | Notes |
|--------|------|-------|
| POST | `/api/login` | `email, password, remember?`; checks `is_active`; `{ user }` |
| POST | `/api/logout` · GET `/api/me` | session |
| GET | `/api/meta` | `business_units[], departments[], locations[]` (active names from DB), `vendors[{id,name,vendor_code,credit_limit,credit_days}]`, `customers[{id,name,customer_code,credit_limit,credit_days}]` (active from DB), `currencies[]` (active names from DB), `payment_methods, priorities, statuses, payment_statuses, pr_statuses, roles, approval_levels (with defaultApprover), upload{…}` |
| GET | `/api/approvers` | the chain-builder pool = `User::scopeEligibleApprovers()`: active users with role approver/admin, **plus finance users who have an `approval_level`** (an admin nominates them individually). `User::canApprove()` is the same rule for a single user, and the client mirrors it in the `canApprove` auth getter |
| GET | `/api/vendors` | active vendor names (for filter dropdowns) |
| GET | `/api/dashboard` | scoped KPIs for one **period**: `period{from,to,all_time,label}, cards{total_invoices, awaiting_posting, in_approval, paid}, my_queue, status_distribution[], monthly[], top_vendors[], by_business_unit[], recent[]`. Optional `from`/`to` as `YYYY-MM`, or `all=1` for all time; default is year to date. Resolved by `App\Support\DashboardPeriod` — an inverted range is swapped, a span over 120 months is capped, and `monthly[]` is capped at 24 bars (most recent of the span). Each metric uses its own date: `submitted_at` for the counts, status donut and recent list, the payment request's `paid_at` for `paid`, `invoice_date` for the two spend charts. **`my_queue` is deliberately not period-filtered** — it is a live approval queue |

## Invoices (Invoice Log)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/invoices` | scoped `visibleTo` | filters `mine, status[], payment_status[], department, priority[], date_from/to, q`; `sort∈{submitted_at,invoice_date,due_date,total_amount,status,priority}`; paginated 15 |
| POST | `/api/invoices` | any auth | multipart: `vendor_id`, invoice_no, invoice_date, due_date?, currency, business_unit, department, location, payment_method, priority, description?, `items[]` (job_no?, customer_id?, description?, currency, amount, **tax_rate?** as a percentage), documents? → status `submitted`; the server snapshots the selected vendor name; **201** with vendor, items + documents |
| GET | `/api/invoices/{invoice}` | canViewAll / owner / assigned approver (via PRF) | loads submitter, poster, items.customer, documents, `paymentRequest.approvals.approver`, auditLogs |
| POST | `/api/invoices/{invoice}` | owner, admin **or finance** | update (multipart, same fields as create); a queried invoice returns to `submitted`. Two modes: **normal** when `isEditable()`; **in-place correction** when finance/admin edit an invoice held by a PRF (`isCorrectableInPlace()`: payment `in_approval`/`approved_for_payment`, not cancelled) — no further approval needed, but the currency may **not** change and the total may **not** rise (`422` on `items` otherwise), the invoice status is left alone, and the PRF's `total_amount` is re-synced. Requesters still get `422` on an invoice inside a PRF |
| POST | `/api/invoices/{invoice}/resend-query` | `role:finance,admin` + `throttle:10,1`; status must be `query_raised` | Queues the `InvoiceQueryRaised` mail to the submitter again, for when the first one could not be delivered. Changes nothing on the invoice; audit-logged as `query_notification_resent` only when accepted. Returns `{notification_sent, sent_to}`; `422` when there is no open query or the submitter has no email |
| POST | `/api/invoices/{invoice}/release` | `role:finance,admin`; invoice must be in a PRF that is `in_approval`/`approved` (never `paid`) | `reason` **required** ≤2000 → this invoice alone returns to `not_initiated`/unlinked; the PRF keeps its approvals and its total is re-synced, so the other invoices in it stay payable. Releasing the **last** invoice withdraws the PRF (`withdrawn`, total 0). Returns `{invoice, payment_request}` |
| DELETE | `/api/invoices/{invoice}` | owner or admin; payment `not_initiated` | `{ message }` |
| POST | `/api/invoices/{invoice}/cancel` | owner or admin; payment `not_initiated` | → `cancelled` |
| POST | `/api/invoices/{invoice}/post` | `role:finance,admin`; status submitted/query | `erp_doc_no` req, `posting_date` nullable → `posted`. `422` when the invoice is still `query_raised` **and** already carries an `erp_doc_no` — posting would overwrite that document number; the requester's correction returns it to `submitted` first |
| POST | `/api/invoices/{invoice}/posting` | `role:finance,admin`; **poster or admin**; status `posted` | Correct a mistyped ERP document number. `erp_doc_no` req, `posting_date` nullable (omitted keeps the recorded one). `403` unless `posted_by` is the actor or the actor is an admin — the message names the poster. `422` unless the invoice is `posted`, which also covers a queried invoice still carrying a document number (resolve the query instead). Allowed at **any** payment status, paid included: reconciliation is where a wrong number surfaces, and the field is a reference, not an amount. Does **not** touch `posted_by`/`posted_at`/`status` — correcting is not re-posting. Audited as `posting_corrected` with the replaced `erp_doc_no`/`posting_date`; the original `posted` entry survives |
| POST | `/api/invoices/{invoice}/query` | `role:finance,admin`; status submitted/posted **and** `payment_status = not_initiated` | `finance_remarks` req → `query_raised`; **queues mail to the invoice submitter** (`InvoiceQueryRaised`). The response carries `notification_sent`: the query is recorded either way, so a mail problem is reported rather than failing the request — recover with `/resend-query`. `422` once the invoice is in a PRF: a query asks for a correction, and an invoice in a payment cycle cannot be edited — reject (or withdraw) the PRF first |

**Create/update validation:** `vendor_id` must reference an active vendor; the server derives
`vendor_name` from that record so same-named vendors remain distinct. invoice_no req ≤100;
invoice_date req; due_date `after_or_equal:invoice_date`; currency (header and every line) must be
an active `currencies` master-data name;
amount −1e12–1e12 but **never 0** (a negative line is a vendor credit note, netted off by the
header sums; zero is only ever an unfilled line), and the **lines must not net to exactly 0** —
neither a charge nor a credit, so there is nothing to record (`422` on `items`). A net **below** 0
is allowed: a **credit-only invoice**, settled by being grouped into a PRF alongside the charge it
offsets, which is where the "more than 0" floor now lives (see `POST /api/payment-requests`); tax_rate 0–100 (a percentage; the server derives `tax_amount` and ignores any posted value — signed, so a credit line's tax is a reduction); business_unit/department/location must be active master-data names, payment_method/priority in config (Rule::in);
description ≤5000; documents ≤10 files, config mimes, ≤10 MB.

## Export API (key-authenticated, no session)

For spreadsheets and BI tools that cannot hold a session cookie. Serves the **same rows and columns
as the Reports page Excel download** — both read `App\Support\InvoiceReport`, so a connected workbook
cannot drift from the file people download by hand.

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/export-report` | `api-key` middleware + `throttle:60,1`; **outside** the session `auth` group | Credentials in any of four shapes: `X-Api-Key`/`X-Api-Secret` headers (preferred), `Authorization: Bearer <key>:<secret>`, `?token=<key>:<secret>` (one field, for clients like Power Query's "Web API" credential that append a single parameter), or `?key=&secret=`. Filters match the Reports page: `status` (array or comma-separated), `department`, `business_unit`, `vendor` (partial), `date_from`, `date_to`; plus `format=json\|xlsx` (default json), `limit` (≤50,000), `offset`. `401` on missing/wrong/revoked credentials or a deactivated owner; `422` on an invalid filter |

**Response (json):** `{generated_at, columns{key: heading}, filters, total, offset, count, has_more, rows[]}`.
Each row is keyed by the column keys in `columns`; amounts are JSON numbers and dates are strings
(`Y-m-d`, or `Y-m-d H:i` for timestamps). `format=xlsx` returns the same file as `/api/reports/export`.

**Scope:** a key belongs to a user and the query runs through `Invoice::scopeVisibleTo($key->user)`,
so a key never reads more than the person it was issued for. Keys live in `api_keys`; the secret is
stored only as a hash. Every call is audit-logged as `report_exported_via_api` naming the key.

**Connecting Excel / Power Query:** simplest is **Data → From Web** with the full `?key=&secret=` URL and **Anonymous** auth. To keep the secret out of the URL, use a blank query with
`Web.Contents(url, [ApiKeyName="token"])` and pick **Web API**, pasting `key:secret` as the single Key —
Power Query stores it in the credential store and appends it as `?token=`. `Json.Document` → `[rows]` →
`Table.FromRecords` gives a refreshable table.

**Issuing:** `php artisan api-key:issue "<name>" <user-email>` prints the key, the secret (once) and a
ready-made URL. `php artisan api-key:revoke` lists keys with last-used info; with a key argument it
deactivates that key immediately.

## Payment Requests (PRF)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/payment-requests/eligible` | `role:finance,admin` | invoices payable (not_initiated & posted/submitted); filters `department, currency, vendor, invoice_no, job_no` (via items), `customer` (via items.customer name); paginated 100 |
| GET | `/api/payment-requests/pending` | any `canApprove()` user for their own stages (approver, **or finance with a level**) / admin (all); `403` otherwise | PRFs `in_approval` awaiting the current user's stage; invoice payload includes submitter and `items.customer` for the decision dialog |
| GET | `/api/payment-requests` | scoped `visibleTo` | filters `status[], department, vendor, q` (searches reference_no, invoice_no); paginated 15 |
| POST | `/api/payment-requests` | `role:finance,admin` | create from `invoice_ids` + chain (see below); **201**. `credit_invoice_ids[]` (optional, must be a subset of `invoice_ids`) are invoices that are really credit notes recorded as a **positive** amount: their sign is corrected — header **and** line items — so the request deducts them instead of adding them, audited as `credit_note_marked` with the replaced figures. An id whose invoice is already negative is refused (`422` on `credit_invoice_ids`): it is deducted as it stands, and marking it would turn a credit back into a charge. The flip is applied in memory for the currency/payable checks and the chain, then persisted **inside** the creation transaction, so a refused request rewrites nothing. The selected invoices must share one currency **and net to more than 0** (`422` on `invoices`) — an individual invoice may be a credit-only one, but a request at or below 0 matches no `min_amount`, could be routed to nobody and pays nothing, so a credit is settled by selecting the charge it offsets. Both rules are asserted in `PaymentRequestService` **and** ahead of the chain builder in the controller, so a non-positive set is reported as a netting problem rather than as a missing approver |
| GET | `/api/payment-requests/{paymentRequest}` | scoped `visibleTo` | loads creator, payer, invoices with submitter and `items.customer`, approvals.approver, auditLogs |
| POST | `/api/payment-requests/{paymentRequest}/approve` | admin or assigned current-stage approver — **never the PRF's own creator** (`403`, even for an admin) | `comments` nullable ≤2000 |
| POST | `/api/payment-requests/{paymentRequest}/reject` | admin or assigned current-stage approver — **never the PRF's own creator** (`403`, even for an admin) | `comments` **required** ≤2000 → PRF rejected, invoices freed |
| POST | `/api/payment-requests/{paymentRequest}/mark-paid` | `role:finance,admin`; PRF must be `approved` | `payment_reference` req ≤100 → `paid` |
| POST | `/api/payment-requests/{paymentRequest}/payment-reference` | `role:finance,admin`; **payer or admin**; PRF must be `paid` | Correct a mistyped payment reference. `payment_reference` req ≤100. `403` unless `paid_by` is the actor or the actor is an admin — the message names the payer; the PRF **creator** has no special right here. `422` on any non-`paid` status, which also means this can never be a back door to marking something paid. No uniqueness rule: one transfer legitimately settles several PRFs. Does **not** touch `paid_by`/`paid_at`/`status`, nor the total, invoices or approvals — correcting is not re-paying. Audited as `payment_reference_corrected` against the PRF with the replaced value; the original `paid` entry survives |
| POST | `/api/payment-requests/{paymentRequest}/withdraw` | `role:finance,admin` (never approvers — it reverses a completed approval); PRF must be `approved` | `reason` **required** ≤2000 → PRF `withdrawn`, invoices returned to `not_initiated` and unlinked. `422` while `in_approval` (reject instead) or once `paid`. Approvals are kept as history and are **not** reused: the corrected invoices need a new PRF and a fresh chain |
| GET | `/api/payment-requests/{paymentRequest}/pdf` | scoped `visibleTo` | Downloads a landscape company PAF without PRF status; includes voucher/request/accounts fields, supplier lines, totals, payment-approval limits, separate requisition/dynamic-approval/accounts sign-off areas, and source files embedded/listed as attachments |

**Create payload (JSON):**
- `invoice_ids`: required array of eligible invoice ids.
- `approvers`: object mapping approval **level → user id** (overrides that level's default).
- `adhoc_approvers`: array of `{ approver_id, label? }` appended after the level stages. A blank
  label falls back to the approver's `job_title`, then to `"Additional approver"`.
- Each stage's stored `label` is the **approver's `job_title`**, not the approval level's name — the
  level names are generic positions and the signer often holds a different one. The level name is the
  fallback when a user has no job title, and `level` is still recorded on the stage either way.
- Every referenced user must satisfy `User::canApprove()` — **active, and role approver/admin or
  finance with an `approval_level`** (validated as an `exists` rule). The server
  builds the ordered chain from `ApprovalLevel::requiredFor(total)` (assignment or default per
  level) + ad-hoc stages, sets `current_stage = 1`, `status = in_approval`, and reserves the
  invoices (`payment_status = in_approval`).
- **The creator may not be on the chain** (`422` on `approvers`). Finance raises requests and can
  also be nominated to approve, so the same person can be both; every payment keeps a second pair of
  eyes. `assertActionable()` repeats the check at action time, which also covers chains built before
  the rule and the admin override.

**Chain routing:** approve marks the current stage and advances to the next pending sequence, or
finalizes the PRF to `approved` (invoices → `approved_for_payment`). Reject marks the stage,
sets PRF `rejected`, and returns invoices to `not_initiated` (unlinked). Rejection is only available
while the PRF is `in_approval`; **withdrawal** is the equivalent exit for an `approved` PRF that has
not been paid, and there is deliberately no exit once it is `paid`.

**Mail delivery:** every mailable implements `ShouldQueue`, so sends happen in a queued job (`QUEUE_CONNECTION`, a worker must be running) rather than inside the request. SMTP faults become retryable jobs — `queue:failed` to list, `queue:retry all` to re-run — instead of 500s on the action that triggered them. All sends go through `App\Services\Notifier`, which logs and returns false rather than throwing, because the state change it notifies about has already been committed. **Queued jobs build links from `APP_URL`**, not the incoming request host, so that value must be correct in every environment.

**Emails:** each advance emails the next approver (`PaymentRequestSubmitted`, with their public
`view_token` link). On **final approval** the requestors are notified (`PaymentRequestApproved` to
the PRF creator + every invoice submitter) — fired from both the API approve and the public-link
approve paths via `PaymentRequestService::notifyApproved()`. Daily reminders to the current
approver go out via the `SendPendingApprovalReminders` console command (`PaymentRequestReminder`).

## Documents · Reports · Admin

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/documents/{document}/download` | view rule as invoice show; streams file |
| DELETE | `/api/documents/{document}` | uploader or admin; invoice must be editable |
| GET | `/api/reports` · `/api/reports/export` | filters `status[], department, business_unit, vendor, date_from/to`; rows eager-load `items[{id,invoice_id,job_no,sort_order}]` for the Job No column; export = 25-col XLSX (Job No after Invoice No, distinct line job numbers joined with `, `), audit-logged |
| GET/POST/PUT/DELETE | `/api/audit-logs`, `/api/users`, `/api/approval-levels` | `role:admin`. User `department` must match an active Departments master-data row. `approval_level` (1–10) is **required for role approver and optional for role finance** — setting it on a finance user is what nominates them as an approver; it is nulled for requesters/admins. Approval-level create/update accepts `default_approver_id` (nullable; must satisfy `canApprove()` **and** be assigned to the level being saved — `422` naming the user otherwise. The level's *current* default is always accepted so a legacy mismatch doesn't block renaming or deactivating it) |
| GET/POST | `/api/master-data/{entity}` | `role:admin`. Entity ∈ `vendors, customers, business-units, departments, locations, currencies`.
A currency's `name` is its 3-letter upper-case code (`size:3`, `alpha:ascii`, `uppercase`). GET is paginated (`page`, `per_page` 1–100) and accepts `q` (name for all entities, plus code for vendors/customers); results are ordered by name then id. POST creates vendors/customers with repeatable names and unique entered codes; other entities retain unique names. |
| PUT/DELETE | `/api/master-data/{entity}/{id}` | `role:admin`. PUT updates the entity's create fields plus `is_active`; DELETE removes. |
| GET | `/api/master-data/{entity}/template` | `role:admin`. Downloads an XLSX import template with an **Import Data** sheet and entity-specific instructions. |
| POST | `/api/master-data/{entity}/import` | `role:admin`; multipart `file` (`xlsx`/`xls`, max 5 MB). Create-only, maximum 1,000 rows, and atomic. Vendors/customers allow repeated names but reject duplicate entered codes; other entities reject duplicate names. Any missing header or invalid/duplicate value returns `422` and creates nothing. Imported rows default to `is_active = 1`; status is not included in templates. Success returns `{message, imported_count}`; every created row is audit-logged. |

## Notable quirks

- No `{data,meta}` wrapper; clients handle raw-model and paginator JSON.
- Invoice update is POST (multipart); users/approval-levels use PUT.
- Authorization is mixed (middleware + inline + service). Reports/dashboard are data-scoped only.
- The approval chain lives on the **PRF**, not the invoice.

## Public routes (no auth)

| Method | Path | Notes |
|--------|------|-------|
| GET | `/prf/view/{id}/{token}` | Token-gated PRF view with invoice submitter and line details (job no., customer, description, currency). `{token}` can be the PRF `view_token` (view-only) or an approval stage `view_token` (can act if current stage). |
| POST | `/prf/view/{id}/{token}/approve` | Approve the current stage. Token must match the current stage's `view_token`. Emails next approver with their token. |
| POST | `/prf/view/{id}/{token}/reject` | Reject with required `comments`. Token must match the current stage's `view_token`. |
| GET | `/prf/view/{id}/{token}/document/{document}` | Download an invoice attachment. Token-gated; document must belong to the PRF. |
