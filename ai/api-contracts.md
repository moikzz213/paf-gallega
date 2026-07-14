# API Contracts

> All routes are defined in `routes/web.php` under the `api` prefix (there is **no**
> `routes/api.php`). Auth is **session-based** (same-origin SPA), not tokens/Sanctum.
> Update this file whenever a controller's routes, validation, or response shape changes.

## Conventions

- **Base:** all endpoints are `/api/...`. Every endpoint except `POST /api/login` is behind
  `auth`. `POST /api/login` also has `throttle:10,1`.
- **Roles:** `EnsureRole` middleware (`role:...`) gates route groups — `role:finance,admin`
  (payments), `role:admin` (audit-logs, users, approval-levels).
- **No response envelope.** Controllers return a raw Eloquent model/collection or
  `response()->json(...)`. List endpoints return Laravel's standard **paginator** shape:
  `{ data:[...], current_page, last_page, per_page, total, from, to, links, ... }`.
- **Pagination:** `?per_page=N`. Default 15 (invoices, approvals, payments, users) or 25
  (audit-logs, reports).
- **Errors:** no try/catch. `422` from `validate()`/`ValidationException` as
  `{ message, errors:{ field:[msg] } }`; `403` from inline checks / `role:` middleware; `404`
  from route-model binding.
- **Common filter params:** `q` (search), `status`, `department`, `date_from`, `date_to`,
  `sort`, `dir`, `per_page`, `mine`.
- **Uploads:** only on invoices, `multipart/form-data`, field `documents[]`. Stored on the
  `local` disk under `invoices/{id}`. Limits from `config/paf.php` (10 files, 10 MB,
  `pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt`). Invoice **update uses POST** (not PUT) to
  carry multipart.
- **Server-computed:** `total_amount = round(amount + tax_amount, 2)` and `reference_no` are set
  server-side, never client-supplied.

### Enums (wire values)

- **Invoice status:** `draft`, `pending_approval`, `approved`, `rejected`, `scheduled`, `paid`,
  `cancelled`. ⚠️ Pending serializes as `pending_approval`, whereas an **approval row's** pending
  is `pending` — two different strings.
- **Roles:** `admin`, `requester`, `approver`, `finance`.
- **Currencies:** `AED, USD, EUR, GBP, SAR`. **Priorities:** `low, normal, high, urgent`.
  **Payment methods (keys):** `bank_transfer, cheque, cash, card`.

## Auth

| Method | Path | Notes |
|--------|------|-------|
| POST | `/api/login` | body `email, password, remember?`; throttled 10/min; checks `is_active`; returns `{ user }` |
| POST | `/api/logout` | invalidates session; returns `{ message }` |
| GET | `/api/me` | returns `{ user }` |
| GET | `/api/meta` | reference data: `categories, departments, currencies, payment_methods, priorities, statuses, roles, approval_levels, upload{max_documents,max_document_kb,mimes}` |

## Invoices (payment requests)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/invoices` | scoped `visibleTo` | filters: `mine, status[], department, date_from, date_to, q`; `sort∈{created_at,invoice_date,due_date,total_amount,status}`, `dir`; paginated 15 |
| POST | `/api/invoices` | any auth | create (see rules below); `action=submit` also submits; **201** with `documents,approvals` |
| GET | `/api/invoices/{invoice}` | canViewAll / owner / approver-at-level, else 403 | loads submitter, payer, documents.uploader, approvals.approver, auditLogs.user |
| POST | `/api/invoices/{invoice}` | owner or admin; must be `draft`/`rejected` | update (same rules); `action=submit` submits |
| DELETE | `/api/invoices/{invoice}` | owner or admin; must be `draft` | `{ message }` |
| POST | `/api/invoices/{invoice}/submit` | owner or admin | → `ApprovalService::submit()` |
| POST | `/api/invoices/{invoice}/cancel` | owner or admin; `draft`/`pending_approval` | → `cancelled` |

**Create/update validation:** `vendor_name` req ≤255; `vendor_email` nullable email; `vendor_trn`
nullable ≤50; `invoice_no` req ≤100; `invoice_date` req date; `due_date` nullable
`after_or_equal:invoice_date`; `currency` req in currencies; `amount` req numeric 0.01–1e12;
`tax_amount` nullable numeric ≥0; `category` req in categories; `department` req in departments;
`cost_center` nullable ≤100; `payment_method` req in method keys; `priority` req in priorities;
`description` nullable ≤5000; `documents` nullable array ≤10; `documents.*` file, config mimes,
≤10240 KB.

## Approvals

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/approvals/pending` | admin (all) or approver (own level), else 403 | ordered priority then `submitted_at` asc; paginated 15 |
| POST | `/api/invoices/{invoice}/approve` | admin or approver-at-current-level | `comments` nullable ≤2000 |
| POST | `/api/invoices/{invoice}/reject` | admin or approver-at-current-level | `comments` **required** ≤2000 |

Workflow: `submit` deletes prior approvals (fresh cycle), creates one row per required level
(`ApprovalLevel::requiredFor(total)`), sets `pending_approval` at level 1. `approve` marks the
current level and advances `current_level`, or finalizes to `approved`. `reject` marks the level
rejected and sets invoice `rejected` with `rejection_reason`.

## Payments (`role:finance,admin`)

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/payments/queue?status=` | `status∈{approved,scheduled,paid}` (default `approved`), else 422; paginated 15 |
| POST | `/api/invoices/{invoice}/schedule` | invoice must be `approved`; `scheduled_date` req date `after_or_equal:today` → `scheduled` |
| POST | `/api/invoices/{invoice}/mark-paid` | status `approved`/`scheduled`; `payment_reference` req ≤100, `paid_at` nullable → `paid`, `paid_by=me` |

## Documents

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/documents/{document}/download` | same view rule as invoice show; 404 if file missing; streams file |
| DELETE | `/api/documents/{document}` | uploader or admin; parent invoice must be editable; `{ message }` |

## Reports (any auth, data-scoped)

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/reports` | filters `status[], department, category, vendor, date_from, date_to`; returns `summary[], totals{count,amount}, rows(paginated 25)` |
| GET | `/api/reports/export` | same filters; streams `paf-invoices-{Ymd-His}.xlsx` (`InvoicesExport`, 23 columns); logs `report_exported` |

## Administration (`role:admin`)

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/audit-logs` | filters `action, user_id, q, date_from, date_to`; paginated 25, `created_at desc` |
| GET | `/api/audit-logs/actions` | distinct action strings for filters |
| GET | `/api/users` | filters `q, role`; ordered by name; paginated 15 |
| POST | `/api/users` | `name, email(unique), password(min8), role, approval_level(required_if role=approver, 1–10), department?, job_title?, is_active` → **201** |
| PUT | `/api/users/{user}` | same rules; `password` nullable (unset if empty); email unique ignores self. No delete — deactivate via `is_active` |
| GET | `/api/approval-levels` | all levels ordered by `level` (not paginated) |
| POST | `/api/approval-levels` | `level(1–10, unique), name(≤100), min_amount(≥0), is_active` → **201** |
| PUT | `/api/approval-levels/{approvalLevel}` | same; level uniqueness ignores self |
| DELETE | `/api/approval-levels/{approvalLevel}` | `{ message }` |

## Dashboard

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/dashboard` | data-scoped `visibleTo`. Keys: `cards{total_requests, pending{count,amount}, awaiting_payment{count,amount}, paid_this_month{count,amount}}, my_queue, status_distribution[], monthly[]{month,submitted,paid}, top_vendors[]{vendor_name,count,amount}, by_category[]{category,amount}, recent[]` |

## Notable contract quirks

- **No `{data,meta}` wrapper** — clients handle both raw-model JSON and paginator JSON.
- **Two "pending" strings** — invoice `pending_approval` vs approval-row `pending`.
- **Invoice update is POST** (multipart); users/approval-levels use PUT.
- **Authorization placement is mixed** — some in middleware, some inline, some service-level;
  reports/dashboard have no role gate (data-scoped only).
