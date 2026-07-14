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
