# API Contracts

> All routes are in `routes/web.php` under the `api` prefix (there is **no** `routes/api.php`).
> Auth is **session-based** (same-origin SPA). Update this file whenever routes, validation, or
> response shapes change. Model: [decisions/ADR-002](decisions/ADR-002-vendor-portal-workflow.md).

## Conventions

- Base `/api/...`; everything except `POST /api/login` is behind `auth`. Login is `throttle:10,1`.
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
| GET | `/api/dashboard` | scoped KPIs: `cards{total_invoices, awaiting_posting, in_approval, paid_this_month}, my_queue, status_distribution[], monthly[], top_vendors[], by_business_unit[], recent[]` |

## Invoices (Invoice Log)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/invoices` | scoped `visibleTo` | filters `mine, status[], payment_status[], department, priority[], date_from/to, q`; `sort∈{submitted_at,invoice_date,due_date,total_amount,status,priority}`; paginated 15 |
| POST | `/api/invoices` | any auth | multipart: `vendor_id`, invoice_no, invoice_date, due_date?, currency, business_unit, department, location, payment_method, priority, description?, `items[]` (job_no?, customer_id?, description?, currency, amount, tax_amount?), documents? → status `submitted`; the server snapshots the selected vendor name; **201** with vendor, items + documents |
| GET | `/api/invoices/{invoice}` | canViewAll / owner / assigned approver (via PRF) | loads submitter, poster, items.customer, documents, `paymentRequest.approvals.approver`, auditLogs |
| POST | `/api/invoices/{invoice}` | owner, admin **or finance** | update (multipart, same fields as create); a queried invoice returns to `submitted`. Two modes: **normal** when `isEditable()`; **in-place correction** when finance/admin edit an invoice held by a PRF (`isCorrectableInPlace()`: payment `in_approval`/`approved_for_payment`, not cancelled) — no further approval needed, but the currency may **not** change and the total may **not** rise (`422` on `items` otherwise), the invoice status is left alone, and the PRF's `total_amount` is re-synced. Requesters still get `422` on an invoice inside a PRF |
| POST | `/api/invoices/{invoice}/release` | `role:finance,admin`; invoice must be in a PRF that is `in_approval`/`approved` (never `paid`) | `reason` **required** ≤2000 → this invoice alone returns to `not_initiated`/unlinked; the PRF keeps its approvals and its total is re-synced, so the other invoices in it stay payable. Releasing the **last** invoice withdraws the PRF (`withdrawn`, total 0). Returns `{invoice, payment_request}` |
| DELETE | `/api/invoices/{invoice}` | owner or admin; payment `not_initiated` | `{ message }` |
| POST | `/api/invoices/{invoice}/cancel` | owner or admin; payment `not_initiated` | → `cancelled` |
| POST | `/api/invoices/{invoice}/post` | `role:finance,admin`; status submitted/query | `erp_doc_no` req, `posting_date` nullable → `posted`. `422` when the invoice is still `query_raised` **and** already carries an `erp_doc_no` — posting would overwrite that document number; the requester's correction returns it to `submitted` first |
| POST | `/api/invoices/{invoice}/query` | `role:finance,admin`; status submitted/posted **and** `payment_status = not_initiated` | `finance_remarks` req → `query_raised`; **emails the invoice submitter** (`InvoiceQueryRaised`). `422` once the invoice is in a PRF: a query asks for a correction, and an invoice in a payment cycle cannot be edited — reject (or withdraw) the PRF first |

**Create/update validation:** `vendor_id` must reference an active vendor; the server derives
`vendor_name` from that record so same-named vendors remain distinct. invoice_no req ≤100;
invoice_date req; due_date `after_or_equal:invoice_date`; currency (header and every line) must be
an active `currencies` master-data name;
amount 0.01–1e12; tax_amount ≥0; business_unit/department/location must be active master-data names, payment_method/priority in config (Rule::in);
description ≤5000; documents ≤10 files, config mimes, ≤10 MB.

## Payment Requests (PRF)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/payment-requests/eligible` | `role:finance,admin` | invoices payable (not_initiated & posted/submitted); filters `department, currency, vendor, invoice_no, job_no` (via items), `customer` (via items.customer name); paginated 100 |
| GET | `/api/payment-requests/pending` | any `canApprove()` user for their own stages (approver, **or finance with a level**) / admin (all); `403` otherwise | PRFs `in_approval` awaiting the current user's stage; invoice payload includes submitter and `items.customer` for the decision dialog |
| GET | `/api/payment-requests` | scoped `visibleTo` | filters `status[], department, vendor, q` (searches reference_no, invoice_no); paginated 15 |
| POST | `/api/payment-requests` | `role:finance,admin` | create from `invoice_ids` + chain (see below); **201** |
| GET | `/api/payment-requests/{paymentRequest}` | scoped `visibleTo` | loads creator, payer, invoices with submitter and `items.customer`, approvals.approver, auditLogs |
| POST | `/api/payment-requests/{paymentRequest}/approve` | admin or assigned current-stage approver — **never the PRF's own creator** (`403`, even for an admin) | `comments` nullable ≤2000 |
| POST | `/api/payment-requests/{paymentRequest}/reject` | admin or assigned current-stage approver — **never the PRF's own creator** (`403`, even for an admin) | `comments` **required** ≤2000 → PRF rejected, invoices freed |
| POST | `/api/payment-requests/{paymentRequest}/mark-paid` | `role:finance,admin`; PRF must be `approved` | `payment_reference` req ≤100 → `paid` |
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
