# Database Schema

> Source of truth: `database/migrations/2026_07_02_000001..000006_*`. This document mirrors
> them — update it whenever a migration changes.

## Engine & strategy

- **Engine:** SQLite (`DB_CONNECTION=sqlite`, file `database/database.sqlite`). Portable to
  MySQL/Postgres — no SQLite-specific SQL is used in application code.
- **Migration strategy:** Standard Laravel migrations. Laravel's default framework tables
  (`users`, `cache`, `jobs`, sessions) come first (`0001_01_01_*`); PAF domain tables are added
  in the `2026_07_02_*` series. PAF user columns are added via a `Schema::table` alter migration.
- **Seeders:** `DatabaseSeeder` → `ApprovalLevelSeeder`, `UserSeeder`, `DemoDataSeeder`.

## Entity overview

```
users ──< invoices >── approval_levels (by amount threshold, not FK)
  │           │
  │           ├──< invoice_documents
  │           ├──< invoice_approvals
  │           └──< audit_logs
  └──< audit_logs (actor)
```

- A **user** submits many **invoices** (`invoices.submitted_by`).
- An **invoice** has many **invoice_documents**, many **invoice_approvals** (one per required
  level), and many **audit_logs**.
- **approval_levels** is a configuration table; approvals snapshot `level` + `level_name` onto
  each `invoice_approvals` row (no hard FK from approval to level).

## Tables

### users (PAF extensions)

Base Laravel columns (`id`, `name`, `email`, `email_verified_at`, `password`, `remember_token`,
timestamps) plus, from `add_paf_fields_to_users_table`:

| Column | Type | Notes |
|--------|------|-------|
| `role` | string, default `requester` | `admin` \| `requester` \| `approver` \| `finance` |
| `approval_level` | tinyint, nullable | Set only for approvers; matches an `approval_levels.level` |
| `department` | string, nullable | |
| `job_title` | string, nullable | |
| `is_active` | boolean, default true | Deactivated users cannot log in |

### approval_levels

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `level` | tinyint, **unique** | Ordinal position in the chain |
| `name` | string | e.g. "Finance Director" |
| `min_amount` | decimal(15,2), default 0 | Invoice required to pass this level when `total_amount >= min_amount` |
| `is_active` | boolean, default true | Inactive levels are skipped |
| timestamps | | |

### invoices

The central entity — a vendor invoice submitted as a payment request.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `reference_no` | string, **unique** | System ref, format `PAF-{year}-{00001}` (`Invoice::nextReferenceNo()`) |
| `vendor_name` | string | indexed |
| `vendor_email` | string, nullable | |
| `vendor_trn` | string, nullable | Tax registration number |
| `invoice_no` | string | Vendor's own invoice number |
| `invoice_date` | date | |
| `due_date` | date, nullable | |
| `currency` | char(3), default `AED` | AED/USD/EUR/GBP/SAR (config `paf.currencies`) |
| `amount` | decimal(15,2) | Net |
| `tax_amount` | decimal(15,2), default 0 | |
| `total_amount` | decimal(15,2) | Drives approval routing |
| `category` | string | From `config('paf.categories')` |
| `department` | string | indexed; from `config('paf.departments')` |
| `cost_center` | string, nullable | |
| `payment_method` | string, default `bank_transfer` | bank_transfer \| cheque \| cash \| card |
| `priority` | string, default `normal` | low \| normal \| high \| urgent |
| `description` | text, nullable | |
| `status` | string, default `draft` | See lifecycle below; indexed with `current_level` |
| `current_level` | tinyint, nullable | Approval level currently pending; null when not in-chain |
| `submitted_by` | FK → users | Requester |
| `submitted_at` | timestamp, nullable | |
| `approved_at` | timestamp, nullable | |
| `rejected_at` | timestamp, nullable | |
| `rejection_reason` | text, nullable | |
| `scheduled_date` | date, nullable | |
| `paid_at` | timestamp, nullable | |
| `payment_reference` | string, nullable | e.g. `TRF-XXXXXXXX` |
| `paid_by` | FK → users, nullable | Finance user who marked paid |
| timestamps | | |

**Indexes:** `(status, current_level)`, `department`, `vendor_name`.

**Status lifecycle** (`Invoice::STATUS_*`):
`draft → pending_approval → approved → scheduled → paid`, with branches
`→ rejected` (from pending) and `→ cancelled`. `draft` and `rejected` are the only editable
states (`Invoice::isEditable()`).

### invoice_documents

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `invoice_id` | FK → invoices, **cascade delete** | |
| `uploaded_by` | FK → users | |
| `original_name` | string | |
| `file_path` | string | Path on the configured filesystem disk |
| `mime_type` | string, nullable | |
| `size` | unsigned bigint, default 0 | bytes |
| timestamps | | |

Upload constraints (config `paf.php`): max 10 files, max 10 MB each, mimes
`pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt`.

### invoice_approvals

One row per required approval level, created at submit time.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `invoice_id` | FK → invoices, **cascade delete** | |
| `level` | tinyint | Snapshot of `approval_levels.level` |
| `level_name` | string | Snapshot of the level name |
| `status` | string, default `pending` | pending \| approved \| rejected |
| `approver_id` | FK → users, nullable | Who acted |
| `comments` | text, nullable | Approval note / rejection reason |
| `acted_at` | timestamp, nullable | |
| timestamps | | |

**Unique:** `(invoice_id, level)` — one approval row per level per invoice.

### audit_logs

Append-only activity trail. `public $timestamps = false;` — uses only `created_at`.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `user_id` | FK → users, nullable | Actor |
| `invoice_id` | FK → invoices, nullable, **null on delete** | |
| `action` | string | created \| updated \| submitted \| approved \| rejected \| scheduled \| paid \| cancelled \| login \| logout \| document_uploaded … |
| `description` | string | Human-readable summary |
| `old_values` | json, nullable | |
| `new_values` | json, nullable | |
| `ip_address` | string(45), nullable | |
| `created_at` | timestamp, default current | |

**Indexes:** `(invoice_id, created_at)`, `action`.

## Referential integrity

- `invoices.submitted_by`, `invoices.paid_by` → `users` (paid_by nullable).
- `invoice_documents.invoice_id` and `invoice_approvals.invoice_id` **cascade delete** with the
  invoice.
- `audit_logs.invoice_id` is set **null on delete** — audit history survives invoice deletion.
- `approval_levels` is referenced by value (snapshot), not FK, so renaming/deleting a level
  does not rewrite historical approvals.
