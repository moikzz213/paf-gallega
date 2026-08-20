# Deployment & Environment

## Local development setup

This project runs under XAMPP on Windows (`C:\xampp8.2\htdocs\paf`) but is a standard Laravel
app and runs anywhere with PHP 8.3+ and Node.

Requirements:
- PHP **8.3+** with SQLite (and the usual Laravel extensions: mbstring, openssl, pdo, ...)
- Composer
- Node.js + npm (for Vite / the Vue build)

First-time setup:

```bash
composer install
cp .env.example .env          # then set values (see below)
php artisan key:generate
php artisan migrate --seed     # creates SQLite schema + demo users/data
npm install
npm run build                  # or: npm run dev  (HMR during development)
php artisan serve              # http://127.0.0.1:8000
```

`composer setup` and `composer dev` scripts exist:
- `composer setup` — install, env, key, migrate, npm install, npm build.
- `composer dev` — runs `php artisan serve`, `queue:listen`, `pail` (logs), and `npm run dev`
  concurrently.

Seeded demo accounts (all password `password`): `admin@paf.local`, `requester@paf.local`,
`approver1..3@paf.local`, `finance@paf.local`. See [project-context.md](project-context.md).

## Background processes (both are required)

Two OS-level processes have to be running, or features that look wired up simply never fire.

**1. Queue worker** — mail is queued (`ShouldQueue` on every mailable), so nothing is delivered
without a worker:

```bash
php artisan queue:work --tries=3 --sleep=3
```

Run it under a supervisor (systemd/Supervisor on Linux, a Windows service or Task Scheduler
"run at startup" on Windows) so it restarts after a crash or reboot. After changing `MAIL_*` or any
config, run `php artisan queue:restart` — a long-running worker holds the old config in memory.
Inspect failures with `php artisan queue:failed`, re-run them with `php artisan queue:retry all`.

**2. Scheduler** — `routes/console.php` schedules `prf:send-reminders` daily at 09:00
(`config('app.timezone')` is `Asia/Dubai`, hardcoded, so 09:00 means 09:00 Dubai in every
environment). Laravel's schedule only advances when something calls `schedule:run` **every minute**:

```bash
# Linux (crontab -e)
* * * * * cd /path/to/paf && php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

On Windows, cron does not exist — create a Task Scheduler task that repeats every minute,
indefinitely, running `php artisan schedule:run` with the project directory as "Start in".

Since mail became queued, the daily reminder needs **both** processes: scheduler → command → queue →
worker → SMTP.

**Checking whether the reminder is actually running.** `php artisan schedule:list` shows what is
registered, but not that anything invokes it. The data tells you: `last_reminder_sent_at` is stamped
at creation by `PaymentRequestService::create`, so a request still awaiting approval a day later
should have a *later* timestamp. If none do, the job has never run.

```sql
SELECT reference_no, sent_at, last_reminder_sent_at
FROM payment_requests WHERE status = 'in_approval' ORDER BY sent_at;
```

## Environment variables

Driven by `.env` (see `.env.example`). Key settings for this project:

| Var | Dev value | Notes |
|-----|-----------|-------|
| `APP_ENV` | `local` | `production` in prod |
| `APP_DEBUG` | `true` | **must be `false` in production** |
| `APP_KEY` | generated | `php artisan key:generate` |
| `DB_CONNECTION` | `sqlite` | file `database/database.sqlite`; switch to mysql/pgsql for prod |
| `SESSION_DRIVER` | `database` | auth is session-based — sessions table required |
| `QUEUE_CONNECTION` | `database` | |
| `CACHE_STORE` | `database` | |
| `FILESYSTEM_DISK` | `local` | invoice documents stored here (`storage/app`) |
| `MAIL_MAILER` | `log` | **email is not actually sent** — logged only |

> `config/app.php` sets timezone `Asia/Dubai` (note: this is a committed edit, not env-driven).

**Domain lists** are env-driven via `config/paf.php`: `PAF_BUSINESS_UNITS`, `PAF_DEPARTMENTS`,
`PAF_LOCATIONS`, `PAF_CURRENCIES`, `PAF_PRIORITIES`, `PAF_PAYMENT_METHODS` (key:label pairs),
`PAF_MAX_DOCUMENTS`, `PAF_MAX_DOCUMENT_KB`, `PAF_DOCUMENT_MIMES` — all comma-separated with
sensible defaults, so the app runs without them. If you run `php artisan config:cache`, re-cache
after changing any `PAF_*` value.

## Database

- Default **SQLite** (`database/database.sqlite`, git-ignored). Migrations in
  `database/migrations`. Portable to MySQL/Postgres — no SQLite-specific SQL in app code.
- Seed with `php artisan db:seed` (or `migrate --seed`). `DemoDataSeeder` is idempotent (skips
  if invoices already exist).

## Build & serving

- **Frontend:** Vite. `npm run build` emits the production bundle; `resources/views/app.blade.php`
  loads it via `@vite`. The Laravel catch-all route serves this shell for all non-`/api` paths.
- **Backend:** any standard Laravel host (Apache/Nginx + PHP-FPM, or `php artisan serve` for
  dev). Ensure `storage/` and `bootstrap/cache/` are writable; run `php artisan storage:link` if
  serving uploaded files publicly (currently downloads stream through the app, so not required).

## Production checklist (not yet done — this is a prototype)

- Set `APP_ENV=production`, `APP_DEBUG=false`, a strong `APP_KEY`.
- Move off SQLite to a managed DB; configure real `SESSION_DOMAIN`/HTTPS cookies.
- Configure a real mailer (SMTP/API) — email notifications are **not implemented** yet, only the
  driver is a placeholder.
- `php artisan config:cache route:cache view:cache`, `composer install --no-dev -o`,
  `npm run build`.
- Add ERP/payment integration (planned, not present).

## CI/CD

- **None configured.** No GitHub Actions / pipeline files in the repo. Tests run locally via
  `php artisan test` (in-memory SQLite per `phpunit.xml`).

## Hosting

- No hosting/deployment config committed (no Dockerfile, no Forge/Vapor/Envoy files). Local
  XAMPP only at present.
