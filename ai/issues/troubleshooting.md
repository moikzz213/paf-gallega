# Troubleshooting

Common local-dev issues for this PAF (Laravel 13 + Vue 3) project and how to resolve them.

## Setup / environment

- **`APP_KEY` / "No application encryption key" error** — run `php artisan key:generate`.
- **Database errors on first run** — the SQLite file may be missing. Ensure
  `database/database.sqlite` exists (`php artisan migrate` creates the schema; the file is
  git-ignored). Then `php artisan migrate --seed`.
- **Login always fails with seeded users** — you haven't seeded, or `is_active` is false. Reseed
  with `php artisan migrate:fresh --seed`. All demo users use password `password`.
- **Blank page / assets 404** — the frontend isn't built. Run `npm run build` (or `npm run dev`
  for HMR). The Blade shell loads the bundle via `@vite`.

## Auth / API

- **Getting 419 (CSRF token mismatch)** — the SPA relies on the `XSRF-TOKEN` cookie and the
  `<meta name="csrf-token">` tag. A stale full-page cache can desync it; hard-reload. Ensure API
  calls go through `services/api.js` (which sets the headers), not raw `fetch`.
- **Redirected to `/login` unexpectedly** — a `401` triggers the Axios interceptor's hard
  redirect. Usually an expired/absent session (`SESSION_DRIVER=database` — is the sessions table
  migrated?).
- **403 on an approval action** — an approver can only act when their `approval_level` equals the
  invoice's `current_level`. Admins can act at any level. Check the user's level vs the invoice.
- **403 on payments/audit/users/levels** — these are gated by `role:` middleware
  (`finance,admin` / `admin`). Confirm the user's role.

## Workflow gotchas

- **"No active approval levels are configured"** on submit — the `approval_levels` table is empty
  or all inactive. Seed (`ApprovalLevelSeeder`) or create levels in Admin → Approval Levels.
- **Invoice won't edit/delete** — only `draft`/`rejected` are editable; only `draft` is
  deletable. Cancel is allowed for `draft`/`pending_approval`.
- **Approver sees no queue** — non-approver roles have no queue (403); approvers only see items
  at their own level; admins see all pending.

## Email / notifications

- **"Approval emails aren't sending"** — expected. `MAIL_MAILER=log`; mail is written to the log,
  not delivered. Notifications are not implemented (see
  [known-issues.md](known-issues.md) #1).

## Frontend build

- **Vuetify components render unstyled / missing** — ensure `vite-plugin-vuetify` auto-import is
  active (it is in `vite.config.js`) and MDI font CSS is loaded (`plugins/vuetify.js`).
- **Don't add Tailwind classes expecting them to work** — Tailwind is installed but not wired in
  (see [technical-debt.md](technical-debt.md)).
