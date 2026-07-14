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
  controllers, some in `ApprovalService`. There are no Policies / Form Requests. As the app
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

## Testing & tooling

- **No test suite** for domain behavior (see known-issues #3). This is the highest-leverage debt
  to pay down before significant feature work.
- **No CI/CD**, no static analysis (PHPStan/Larastan), no frontend lint/format config beyond
  `.editorconfig`. Pint is available but not enforced.

## Product-model debt

- **Demo (8-stage) vs. implementation (threshold chain).** Decide the target model before
  building further approval features; retrofitting later is costly. See known-issues #4.
