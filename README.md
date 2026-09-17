# PAF — Payment Approval Form Platform

A web application where departments submit vendor invoices, Finance logs and posts them to the
ERP, then groups posted invoices into a **Payment Request (PRF)** routed through a **dynamic,
per-request approval chain** and marked paid — with a complete **audit trail**. Built as a
session-authenticated Vue SPA on a Laravel API.

> **Documentation:** Human/agent knowledge base lives in [`/ai`](ai/) and the working rules in
> [`AGENTS.md`](AGENTS.md); the current model is recorded in
> [`ADR-002`](ai/decisions/ADR-002-vendor-portal-workflow.md).

## Overview

Four roles:

- **Requester** — submits vendor invoices, attaches invoice copies, tracks status.
- **Finance** — posts invoices to ERP or raises queries; creates payment requests; marks paid.
- **Approver** — approves/rejects the payment-request stages assigned to them.
- **Admin** — manages users and approval levels, views the audit log; sees everything.

Flow: **Submit Invoice → Invoice Log (post to ERP / raise query) → Payment Request (group posted
invoices + build approval chain) → Payment Approval (sequential, per-stage) → Paid.** The chain
pre-fills from `approval_levels` defaults (by amount) but every stage is editable and ad-hoc
stages can be added. See [`ai/features/feature-overview.md`](ai/features/feature-overview.md).

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
