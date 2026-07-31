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
- **PRF status:** `draft`, `in_approval`, `approved`, `rejected`, `paid`.
- **PRF approval status:** `pending`, `approved`, `rejected`.
- **Roles:** `admin`, `requester`, `approver`, `finance`. **Currencies:** AED/USD/EUR/GBP/SAR.

## Auth & meta

| Method | Path | Notes |
|--------|------|-------|
| POST | `/api/login` | `email, password, remember?`; checks `is_active`; `{ user }` |
| POST | `/api/logout` · GET `/api/me` | session |
| GET | `/api/meta` | `business_units[], departments[], locations[]` (active names from DB), `vendors[{id,name,vendor_code,credit_limit,credit_days}]`, `customers[{id,name,customer_code,credit_limit,credit_days}]` (active from DB), `currencies, payment_methods, priorities, statuses, payment_statuses, pr_statuses, roles, approval_levels (with defaultApprover), upload{…}` |
| GET | `/api/approvers` | active users with role approver/admin — the chain-builder pool |
| GET | `/api/vendors` | active vendor names (for filter dropdowns) |
| GET | `/api/dashboard` | scoped KPIs: `cards{total_invoices, awaiting_posting, in_approval, paid_this_month}, my_queue, status_distribution[], monthly[], top_vendors[], by_business_unit[], recent[]` |

## Invoices (Invoice Log)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/invoices` | scoped `visibleTo` | filters `mine, status[], payment_status[], department, priority[], date_from/to, q`; `sort∈{submitted_at,invoice_date,due_date,total_amount,status,priority}`; paginated 15 |
| POST | `/api/invoices` | any auth | multipart: vendor_name, invoice_no, invoice_date, due_date?, currency, business_unit, department, location, payment_method, priority, description?, `items[]` (job_no?, customer_id?, description?, currency, amount, tax_amount?), documents? → status `submitted`; **201** with items + documents |
| GET | `/api/invoices/{invoice}` | canViewAll / owner / assigned approver (via PRF) | loads submitter, poster, items.customer, documents, `paymentRequest.approvals.approver`, auditLogs |
| POST | `/api/invoices/{invoice}` | owner or admin; must be editable | update (multipart, same fields as create); a queried invoice returns to `submitted` |
| DELETE | `/api/invoices/{invoice}` | owner or admin; payment `not_initiated` | `{ message }` |
| POST | `/api/invoices/{invoice}/cancel` | owner or admin; payment `not_initiated` | → `cancelled` |
| POST | `/api/invoices/{invoice}/post` | `role:finance,admin`; status submitted/query | `erp_doc_no` req, `posting_date` nullable → `posted` |
| POST | `/api/invoices/{invoice}/query` | `role:finance,admin`; status submitted/posted | `finance_remarks` req → `query_raised`; **emails the invoice submitter** (`InvoiceQueryRaised`) |

**Create/update validation:** vendor_name req; vendor_email nullable email; vendor_trn ≤50;
invoice_no req ≤100; invoice_date req; due_date `after_or_equal:invoice_date`; currency in list;
amount 0.01–1e12; tax_amount ≥0; business_unit/department/location/payment_method/priority in config (Rule::in);
description ≤5000; documents ≤10 files, config mimes, ≤10 MB.

## Payment Requests (PRF)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/payment-requests/eligible` | `role:finance,admin` | invoices payable (not_initiated & posted/submitted); filters `department, currency, vendor, invoice_no, job_no` (via items), `customer` (via items.customer name); paginated 100 |
| GET | `/api/payment-requests/pending` | approver (own stage) / admin (all) | PRFs `in_approval` awaiting the current user's stage |
| GET | `/api/payment-requests` | scoped `visibleTo` | filters `status[], department, vendor, q` (searches reference_no, invoice_no); paginated 15 |
| POST | `/api/payment-requests` | `role:finance,admin` | create from `invoice_ids` + chain (see below); **201** |
| GET | `/api/payment-requests/{paymentRequest}` | scoped `visibleTo` | loads creator, payer, invoices, approvals.approver, auditLogs |
| POST | `/api/payment-requests/{paymentRequest}/approve` | admin or assigned current-stage approver | `comments` nullable ≤2000 |
| POST | `/api/payment-requests/{paymentRequest}/reject` | admin or assigned current-stage approver | `comments` **required** ≤2000 → PRF rejected, invoices freed |
| POST | `/api/payment-requests/{paymentRequest}/mark-paid` | `role:finance,admin`; PRF must be `approved` | `payment_reference` req ≤100 → `paid` |
| GET | `/api/payment-requests/{paymentRequest}/pdf` | scoped `visibleTo` | Downloads a landscape company PAF without PRF status; includes voucher/request/accounts fields, supplier lines, totals, payment-approval limits, separate requisition/dynamic-approval/accounts sign-off areas, and source files embedded/listed as attachments |

**Create payload (JSON):**
- `invoice_ids`: required array of eligible invoice ids.
- `approvers`: object mapping approval **level → user id** (overrides that level's default).
- `adhoc_approvers`: array of `{ approver_id, label? }` appended after the level stages.
- Every referenced user must be **active with role approver/admin** (validated). The server
  builds the ordered chain from `ApprovalLevel::requiredFor(total)` (assignment or default per
  level) + ad-hoc stages, sets `current_stage = 1`, `status = in_approval`, and reserves the
  invoices (`payment_status = in_approval`).

**Chain routing:** approve marks the current stage and advances to the next pending sequence, or
finalizes the PRF to `approved` (invoices → `approved_for_payment`). Reject marks the stage,
sets PRF `rejected`, and returns invoices to `not_initiated` (unlinked).

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
| GET | `/api/reports` · `/api/reports/export` | filters `status[], department, business_unit, vendor, date_from/to`; export = 24-col XLSX, audit-logged |
| GET/POST/PUT/DELETE | `/api/audit-logs`, `/api/users`, `/api/approval-levels` | `role:admin`. Approval-level create/update accepts `default_approver_id` (nullable; must be an active approver/admin) |
| GET/POST | `/api/master-data/{entity}` | `role:admin`. Entity ∈ `vendors, customers, business-units, departments, locations`. GET returns all (ordered by name); POST creates (vendors: `name`, `vendor_code?`, `credit_limit?`, `credit_days?`; customers: `name`, `customer_code?`, `credit_limit?`, `credit_days?`; others: `name` only). |
| PUT/DELETE | `/api/master-data/{entity}/{id}` | `role:admin`. PUT updates name/email/trn/is_active; DELETE removes. |

## Notable quirks

- No `{data,meta}` wrapper; clients handle raw-model and paginator JSON.
- Invoice update is POST (multipart); users/approval-levels use PUT.
- Authorization is mixed (middleware + inline + service). Reports/dashboard are data-scoped only.
- The approval chain lives on the **PRF**, not the invoice.

## Public routes (no auth)

| Method | Path | Notes |
|--------|------|-------|
| GET | `/prf/view/{id}/{token}` | Token-gated PRF view. `{token}` can be the PRF `view_token` (view-only) or an approval stage `view_token` (can act if current stage). |
| POST | `/prf/view/{id}/{token}/approve` | Approve the current stage. Token must match the current stage's `view_token`. Emails next approver with their token. |
| POST | `/prf/view/{id}/{token}/reject` | Reject with required `comments`. Token must match the current stage's `view_token`. |
| GET | `/prf/view/{id}/{token}/document/{document}` | Download an invoice attachment. Token-gated; document must belong to the PRF. |
