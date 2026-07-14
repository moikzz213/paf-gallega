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
  ├─ Services (ApprovalService = workflow engine; AuditLogger)
  ├─ Eloquent Models (Invoice, InvoiceApproval, InvoiceDocument, ApprovalLevel, AuditLog, User)
  └─ SQLite (database/database.sqlite) + local filesystem disk (invoice documents)
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
  - `ApprovalService` — the workflow engine. `submit()` builds the per-level approval rows from
    amount thresholds; `approve()` advances `current_level` or finalizes; `reject()` ends the
    chain. All wrapped in DB transactions.
  - `AuditLogger` — static `log(action, description, ?invoice, ?old, ?new)` writing `audit_logs`
    with the current user + request IP.
- **Models** — Eloquent. Domain constants live on the models (`Invoice::STATUS_*`,
  `User::ROLE_*`, `InvoiceApproval::STATUS_*`). Notable model behavior:
  - `Invoice::scopeVisibleTo(User)` — the central authorization scope (see below).
  - `Invoice::nextReferenceNo()` — generates `PAF-{year}-{00001}`.
  - `ApprovalLevel::requiredFor(total)` — the set of levels an amount must pass.
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
   - Inline ownership/level checks in controllers + `ApprovalService::assertActionable`
     (an approver may act only when `approval_level === invoice.current_level`).
   - **Data scoping** via `Invoice::scopeVisibleTo`: admin & finance see all; approvers see
     their own submissions plus invoices with an approval row at their level; requesters see
     only their own. Reports & dashboard have no role gate — they rely entirely on this scope.
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

- **Submit a request:** `POST /api/invoices` (multipart, `action=submit`) → controller stores
  invoice (`draft`) + documents → `ApprovalService::submit()` creates approval rows and moves to
  `pending_approval` at level 1.
- **Approve/reject:** `POST /api/invoices/{id}/approve|reject` → `ApprovalService` marks the
  current level, advances `current_level` (or finalizes/rejects) → audit log.
- **Pay:** `POST /api/invoices/{id}/schedule` then `/mark-paid` (finance/admin) → status
  `scheduled` → `paid` with a `payment_reference`.
- **Reports export:** `GET /api/reports/export` streams an XLSX via `maatwebsite/excel`
  (`InvoicesExport`), opened in a new tab (bypasses Axios, uses the session cookie).
