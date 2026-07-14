# PAF — Payment Approval Form Platform

A web application for submitting vendor invoices as **payment requests**, routing them through a
multi-level **approval chain** based on amount thresholds, and processing approved payments — with
a complete **audit trail**. Built as a session-authenticated Vue SPA on a Laravel API.

> **Documentation:** Human/agent knowledge base lives in [`/ai`](ai/) and the working rules in
> [`AGENTS.md`](AGENTS.md). A detailed reference also exists at
> [`docs/PAF-Documentation.html`](docs/PAF-Documentation.html).

## Overview

Four roles collaborate on each request:

- **Requester** — creates and submits payment requests, attaches invoice copies.
- **Approver** — approves/rejects requests routed to their approval level.
- **Finance** — schedules and marks approved requests as paid.
- **Admin** — manages users and approval levels, views the audit log; sees everything.

Lifecycle: `draft → pending_approval → approved → scheduled → paid`, with `rejected` and
`cancelled` branches. Approval routing is data-driven: a request must pass every active approval
level whose `min_amount ≤ total_amount`, in order. See
[`ai/features/feature-overview.md`](ai/features/feature-overview.md).

## Technology stack

- **Backend:** Laravel 13, PHP 8.3, SQLite (default; portable to MySQL/Postgres), session auth.
- **Frontend:** Vue 3 (Composition API) + Vuetify 4 + Pinia + Vue Router + Axios + Chart.js.
- **Build:** Vite (`laravel-vite-plugin`, `vite-plugin-vuetify`).
- **Excel export:** `maatwebsite/excel`.

## Requirements

- PHP **8.3+** (with SQLite + standard Laravel extensions)
- Composer
- Node.js + npm

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed      # schema + demo users & sample data
npm install
npm run build                   # production assets (or `npm run dev` for HMR)
```

Or use the bundled script: `composer setup`.

## Environment setup

Configure `.env` (see `.env.example`). Defaults suit local dev: `DB_CONNECTION=sqlite`,
`SESSION_DRIVER=database`, `MAIL_MAILER=log` (email is **not** actually sent yet). For production,
set `APP_ENV=production`, `APP_DEBUG=false`, a real database and mailer. Full notes:
[`ai/deployment.md`](ai/deployment.md).

## Running

```bash
composer dev        # runs php artisan serve + queue + logs + vite together
# or individually:
php artisan serve   # backend at http://127.0.0.1:8000
npm run dev         # frontend dev server (HMR)
```

### Demo accounts (after seeding)

All use password `password`:

| Email | Role |
|-------|------|
| `admin@paf.local` | admin |
| `requester@paf.local`, `requester2@paf.local` | requester |
| `approver1@paf.local` / `approver2@paf.local` / `approver3@paf.local` | approver (levels 1/2/3) |
| `finance@paf.local` | finance |

## Testing

```bash
php artisan test           # PHPUnit, in-memory SQLite
./vendor/bin/pint          # PHP code style
```

> Note: only Laravel's default example tests exist today — domain tests are a priority (see
> [`ai/issues/technical-debt.md`](ai/issues/technical-debt.md)).

## Deployment

No CI/CD or hosting config is committed yet; this is a working prototype. Standard Laravel
deployment applies (build assets, cache config/routes/views, run migrations, use a real DB and
mailer). See [`ai/deployment.md`](ai/deployment.md) for the checklist.

## Project structure (high level)

```
app/
  Http/Controllers/   thin controllers (validate → authorize → delegate)
  Http/Middleware/    EnsureRole (role: middleware)
  Services/           ApprovalService (workflow engine), AuditLogger
  Models/             Invoice, InvoiceApproval, InvoiceDocument, ApprovalLevel, AuditLog, User
  Exports/            InvoicesExport (Excel)
config/paf.php        domain enums + upload limits
database/migrations/  schema (2026_07_02_* = PAF tables)
database/seeders/     UserSeeder, ApprovalLevelSeeder, DemoDataSeeder
resources/js/         Vue 3 SPA (pages, components, stores, services, router)
routes/web.php        API (prefix /api) + SPA catch-all
ai/                   AI/human knowledge base
```

## Contributing

Follow the **LIFT workflow** (Learn → Intend → Forge → Tune) and the rules in
[`AGENTS.md`](AGENTS.md). Keep the [`/ai`](ai/) docs in sync with code changes.
