# Coding Standards

Conventions observed in this codebase. Follow them so new code reads like the existing code.

## PHP / Laravel

- **PHP 8.3, Laravel 13.** Use modern PHP: typed properties, return types, `match`, constructor
  promotion, enums-as-consts (this codebase uses class constants, e.g. `Invoice::STATUS_*`, not
  native enums — stay consistent).
- **Formatting:** Laravel Pint is a dev dependency. Run `./vendor/bin/pint` before committing.
  4-space indent, `.editorconfig` present.
- **Controllers stay thin.** Validate → check authorization → delegate to a service or model →
  return a model/paginator. Put multi-step domain logic in a **service** (see
  `ApprovalService`), not the controller.
- **Validation** lives in the controller via `$request->validate([...])` or a
  `validated()`-style array. Keep enum-backed rules pointed at `config('paf.*')` /
  model constants so there is one source of truth.
- **Authorization:** use the `role:` middleware for coarse role gates; use inline ownership/level
  checks (or `ApprovalService::assertActionable`) for row-level rules. Always enforce visibility
  through `Invoice::scopeVisibleTo` on list/detail queries — never return unscoped invoice data.
- **Eligibility rules that outgrow a role list get one definition.** "May approve" is not a role
  list any more (finance qualifies per user, by `approval_level`), so it lives once as
  `User::canApprove()` / `User::scopeEligibleApprovers()` and every controller and `exists` rule
  refers to that. The client copy is the `canApprove` getter in `stores/auth.js`, and a route gates
  on it with `meta.gate: 'canApprove'` rather than `meta.roles` — when the rule changes, both sides
  move together.
- **Domain constants on models** — statuses/roles as `public const`, with `*_STATUSES`/`ROLES`
  arrays for validation. Reference them, don't hard-code the strings.
- **Transactions:** wrap multi-write workflow operations in `DB::transaction(...)` (see
  `ApprovalService`).
- **Audit everything state-changing:** call `AuditLogger::log($action, $description, $invoice,
  $old, $new)` on create/update/submit/approve/reject/schedule/pay/delete and admin mutations.
- **Money:** `decimal(15,2)` columns, cast `decimal:2`. Compute `total_amount` server-side.
- **Eloquent:** eager-load with column selection (`with('submitter:id,name')`) to keep payloads
  lean, as existing controllers do. Use query scopes for reusable filters.
- **Migrations** are the schema source of truth; keep [database-schema.md](database-schema.md)
  in sync when you change them.

## JavaScript / Vue

- **Vue 3 Composition API** with `<script setup>`. Vuetify 4 components; Pinia stores; Vue
  Router (history mode, lazy-loaded routes).
- **State:** put shared/server state in a Pinia store (`auth`, `meta`, `notify`); cache
  reference data via a `loaded` flag and force-refresh after admin mutations.
- **API calls:** always go through the shared Axios instance in `services/api.js` (so CSRF,
  credentials, and the 401 interceptor apply). Surface errors with `errorMessage(error)` into
  the `notify` store. (File download/export intentionally use `window.open` on `/api/...`.)
- **Status/priority colors & labels:** always resolve from `utils/format.js` (`STATUS_META`,
  `PRIORITY_META`) and render statuses via `StatusChip` — do not invent per-page colors.
- **Formatting:** use the `money`, `shortDate`, `dateTime`, `fileSize` helpers in
  `utils/format.js`.
- **Routing/authorization:** when adding a route, set `meta.auth`/`meta.roles` on the route AND
  add the nav item guard in `AppLayout` — the two authorization vocabularies must stay in sync.
- **Do not add Tailwind classes** — Tailwind is installed but not wired in; styling is Vuetify +
  scoped/inline styles.

## Naming & structure

- Backend: PSR-4 (`App\`), controllers `*Controller`, services `*Service`, one Eloquent model
  per table. Routes grouped by resource in `routes/web.php`.
- Frontend: pages under `resources/js/pages/` (nested by area, e.g. `invoices/`, `admin/`),
  reusable pieces under `components/`, cross-cutting under `stores/`, `services/`, `utils/`.
- Reference identifiers: invoice `reference_no` is `INV-{year}-{00001}`; payment request
  `reference_no` is `PAF-{year}-{00001}`. Keep the two prefixes distinct — one identifier must
  never name both an invoice and a payment request.

## Testing

- PHPUnit is configured (`phpunit.xml`, in-memory SQLite). **Only example tests exist today** —
  new domain code should ship with feature tests (approval chain transitions, authorization
  scoping, payment lifecycle). Run with `php artisan test`.
