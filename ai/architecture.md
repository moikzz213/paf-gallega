# Architecture

> Companion docs: [database-schema.md](database-schema.md), [api-contracts.md](api-contracts.md),
> [coding-standards.md](coding-standards.md).

## High-level design

A **single-page application** (Vue 3) talking to a **session-authenticated JSON API** (Laravel 13)
on the **same origin**. Laravel serves one Blade shell (`resources/views/app.blade.php`); Vue
Router owns all client-side navigation; a catch-all route returns the shell for any non-`/api`
path.

```
Browser
  └─ Vue 3 SPA (Vuetify 4, Pinia, Vue Router, Axios, Chart.js)
        │  fetch /api/*  (cookies: session + XSRF-TOKEN)
        ▼
Laravel 13 (routes/web.php, prefix "api")
  ├─ auth middleware (session)  ──  role:… middleware (EnsureRole)
  ├─ Controllers (thin: validate → delegate → return model/paginator)
  ├─ Services (PaymentRequestService = PRF workflow engine; AuditLogger)
  ├─ Eloquent Models (Invoice, PaymentRequest, PaymentRequestApproval, InvoiceDocument,
  │                    ApprovalLevel, AuditLog, User)
  └─ SQL DB (SQLite default; MySQL on this dev box) + local disk (invoice documents)
```

## Backend layers

- **Routing** — `routes/web.php`. Everything lives under an `api` prefix (there is **no**
  `routes/api.php`). `POST /api/login` is public + throttled; all else is behind `auth`.
  Role-restricted groups use the `role:` alias.
- **Middleware** — `App\Http\Middleware\EnsureRole` (aliased `role` in `bootstrap/app.php`).
  Usage `->middleware('role:finance,admin')`. Exceptions render as JSON for `api/*`
  (configured in `bootstrap/app.php`).
- **Controllers** — thin. They validate (`$request->validate`/`validated()`), enforce inline
  ownership checks, delegate workflow to services, and return Eloquent models or paginators
  directly (Laravel auto-serializes to JSON). See [api-contracts.md](api-contracts.md).
- **Services** — the domain logic layer:
  - `PaymentRequestService` — the workflow engine for PRFs. `create()` builds the ordered chain
    (from level defaults + ad-hoc stages) and reserves the invoices; `approve()` advances
    `current_stage` or finalizes; `reject()` frees the invoices; `markPaid()`. All in DB
    transactions. Invoice posting/query lives in `InvoiceController`.
  - `AuditLogger` — static `log(action, description, ?invoice, ?old, ?new, ?paymentRequest)`
    writing `audit_logs` with the current user + request IP.
- **Models** — Eloquent. Domain constants live on the models (`Invoice::STATUS_*` /
  `PAY_*`, `PaymentRequest::STATUS_*`, `User::ROLE_*`). Notable model behavior:
  - `Invoice::scopeVisibleTo(User)` / `PaymentRequest::scopeVisibleTo(User)` — the central
    authorization scopes (see below).
  - `Invoice::nextReferenceNo()` → `PAF-{year}-00001`; `PaymentRequest::nextReferenceNo()` →
    `PRF-{year}-00001`.
  - `ApprovalLevel::requiredFor(total)` — the levels (with defaults) that pre-fill a PRF chain.
- **Config** — `config/paf.php` holds domain enums (categories, departments, currencies,
  payment methods, priorities) and upload constraints. Surfaced to the SPA via `GET /api/meta`.

## Authentication & sessions

- **Session cookie auth**, same-origin. `AuthController@login` uses `Auth::attempt`, checks
  `is_active`, regenerates the session, and audit-logs the sign-in. No Sanctum/Passport, no
  tokens, no email verification, no password reset.
- **CSRF** — Laravel's `XSRF-TOKEN` cookie (echoed by Axios as `X-XSRF-TOKEN`), plus a
  `<meta name="csrf-token">` fallback read into `X-CSRF-TOKEN` on first load.
- On HTTP **401**, the Axios interceptor hard-redirects to `/login`.

## Authorization model (two tiers, must stay in sync)

1. **Server (authoritative):**
   - `role:` middleware gates payments (`finance,admin`), audit-logs / users / approval-levels
     (`admin`).
   - Inline ownership checks in controllers + `PaymentRequestService::assertActionable`
     (only the assigned approver of the current stage, or an admin, may act).
   - **Data scoping** via `Invoice::scopeVisibleTo` / `PaymentRequest::scopeVisibleTo`: admin &
     finance see all; approvers see items routed to them (PRFs where they're an approver, plus
     own submissions); requesters see their own. Reports & dashboard have no role gate — they
     rely entirely on these scopes.
2. **Client (UX only):** Vue Router `beforeEach` checks `to.meta.roles`; `AppLayout` shows nav
   items by auth getters (`canApprove`, `canProcessPayments`, `isAdmin`). These mirror the
   server rules but are **independent code paths** — keep them aligned when adding routes.

## Frontend architecture

- **Entry:** `resources/js/main.js` → `App.vue` (`<v-app><router-view/></v-app>`). Mounted at
  `#app`.
- **State (Pinia):** `auth` (current user + role getters), `meta` (reference data cached from
  `/api/meta`), `notify` (global snackbar).
- **API layer:** `services/api.js` — one Axios instance (`baseURL /api`, `withCredentials`),
  CSRF wiring, 401 interceptor, and an `errorMessage(error)` helper that flattens Laravel
  validation errors.
- **Routing:** history mode; every route lazy-loaded; global auth+role guard. Route table in
  [api-contracts.md](api-contracts.md) / feature docs.
- **Shared UI:** `StatusChip` and `ChartCanvas` components; `AppLayout` shell (nav drawer +
  app bar + global snackbar); `utils/format.js` holds `money/date` formatters and the canonical
  `STATUS_META` / `PRIORITY_META` color+label maps (single source of truth for status colors).
- **Charts:** Chart.js via a thin `ChartCanvas` wrapper (dashboard only).

## Build & serving

- **Vite** (`vite.config.js`) with `laravel-vite-plugin`, `@vitejs/plugin-vue`, and
  `vite-plugin-vuetify` (auto-import). Entry `resources/js/main.js`.
- `npm run dev` (HMR) / `npm run build` (production bundle). The Blade shell loads the bundle
  via `@vite`.
- **Note:** Tailwind is present in `package.json` but is **not** wired into `vite.config.js` and
  is unused — all styling is Vuetify + scoped/inline styles. See
  [issues/technical-debt.md](issues/technical-debt.md).

## Key request flows

- **Submit invoice:** `POST /api/invoices` (multipart) → invoice `submitted` + documents.
- **Post / query:** `POST /api/invoices/{id}/post|query` (finance/admin) → `posted` (with
  `erp_doc_no`) or `query_raised` (with `finance_remarks`).
- **Create PRF:** `POST /api/payment-requests` (finance/admin) with `invoice_ids` + chain
  (`approvers` per level + `adhoc_approvers`) → `PaymentRequestService::create()` builds the
  ordered chain, reserves the invoices (`in_approval`), routes to stage 1.
- **Approve/reject:** `POST /api/payment-requests/{id}/approve|reject` → advances `current_stage`
  / finalizes to `approved` (invoices `approved_for_payment`), or rejects and frees the invoices.
- **Pay:** `POST /api/payment-requests/{id}/mark-paid` (finance/admin) → PRF & invoices `paid`.
- **Reports export:** `GET /api/reports/export` streams an XLSX via `maatwebsite/excel`
  (`InvoicesExport`), opened in a new tab (bypasses Axios, uses the session cookie).
