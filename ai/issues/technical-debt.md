# Technical Debt

Structural/maintainability items (distinct from behavioral gaps in
[known-issues.md](known-issues.md)).

## Frontend

- **Tailwind is dead weight.** `tailwindcss` + `@tailwindcss/vite` are in `package.json` but
  Tailwind is not wired into `vite.config.js` and no component imports its directives. Either
  wire it in intentionally or remove it to avoid confusion. All styling is Vuetify +
  scoped/inline styles today.
- **Duplicated authorization logic.** Router `meta.roles` and `AppLayout` nav getters encode the
  same role rules as the server, in a second place. Consider deriving nav/route guards from a
  single role→capability map shared across the client.
- **Ad-hoc file access.** Document download and report export bypass the Axios instance and use
  `window.open('/api/...')`. It works (session cookie), but it sidesteps the shared error/401
  handling — keep this deliberate and documented.

## Backend

- **Authorization is scattered.** Some checks are in `role:` middleware, some inline in
  controllers, some in `PaymentRequestService`. There are no Policies / Form Requests. As the app
  grows, consolidating into Form Requests + Policies would reduce drift and duplication.
- **Controllers do validation inline.** Fine at current size; extract Form Requests if rules
  start to be reused across store/update.
- **No API resources.** Controllers return raw Eloquent models, so the wire shape is coupled to
  the DB schema and column-selection in `with(...)`. An API Resource layer would decouple them
  and remove the `pending`/`pending_approval` type of ambiguity.
- **Config-as-enums.** Domain enums live in `config/paf.php` and as class constants. Native PHP
  enums would give type safety, but the current approach is consistent — don't mix the two
  piecemeal.

## Data / schema

- **`approval_levels` referenced by value.** Approvals snapshot `level`/`level_name` (good for
  history) but there's no FK, so nothing prevents an approver's `approval_level` from pointing at
  a non-existent level after deletion.
- **One approval level per user.** `users.approval_level` is a single integer, so a person can be
  offered at exactly one level. Two nominated Finance users cannot both be candidates for L1 *and*
  L2 — one takes each, and the ad-hoc stages cover the rest. If "eligible at several levels" becomes
  a real requirement, the upgrade is a `user_approval_levels` pivot plus a migration of the existing
  values; `User::scopeEligibleApprovers()` and the chain builder's per-level filter are the two
  places that would change.

## Testing & tooling

- **Partial test coverage.** 12 feature tests cover the PRF workflow + endpoint smoke; there are
  no frontend tests and limited coverage of invoice edit/query edge cases. Grow it before major
  feature work.
- **No CI/CD**, no static analysis (PHPStan/Larastan), no frontend lint/format config beyond
  `.editorconfig`. Pint is available but not enforced.
- **Three pre-existing failing tests** (unrelated to the code they cover being wrong):
  `MasterDataImportTest::test_non_admin_cannot_import_or_download_templates` still expects finance
  to be blocked from templates/import, which stopped being true when finance was granted
  master-data access; and two `PaymentRequestPdfTest` approver-rendering assertions. Fix or retire
  them — a red baseline hides real regressions.

## Product-model note

- The **demo's fixed 8-stage chain** was intentionally **not** adopted; the app uses a dynamic
  per-PRF chain with level-based defaults (see
  [ADR-002](../decisions/ADR-002-vendor-portal-workflow.md)). No action needed — recorded so the
  divergence from `vendor-portal-demo` is not mistaken for a gap.
