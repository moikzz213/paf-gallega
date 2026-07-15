# Database Schema

> Source of truth: `database/migrations/`. The `2026_07_02_*` series creates the base tables;
> the `2026_07_14_*` series adds the vendor-portal model (see
> [decisions/ADR-002](decisions/ADR-002-vendor-portal-workflow.md)). Update this file whenever a
> migration changes.

## Engine & strategy

- Portable SQL via Eloquent (no engine-specific SQL). `.env.example` ships **SQLite**; the dev
  instance runs **MySQL**. Tests use in-memory SQLite (`phpunit.xml`).
- Seeders: `DatabaseSeeder` → `UserSeeder`, `ApprovalLevelSeeder` (sets default approvers),
  `DemoDataSeeder` (invoices + PRFs across all states).

## Entity overview

```
users ──< invoices >── payment_requests ──< payment_request_approvals
  │           │                 │
  │           └── belongs to a  │  (invoice.payment_request_id, nullable)
  │                             │
  └──< audit_logs (actor) ; audit_logs ── invoice_id? / payment_request_id?

approval_levels  (config: threshold + default approver; pre-fills a PRF chain)
invoice_documents  ──< invoices
```

- An **invoice** is submitted by a user, optionally posted by a user, and may belong to one
  **payment_request** (its current cycle).
- A **payment_request** groups many invoices (`invoices.payment_request_id`) and has an ordered
  set of **payment_request_approvals** (the chain).
- **approval_levels** is configuration; it seeds a PRF's chain but is not FK-linked to it.

## Tables

### users (PAF extensions)

Base Laravel columns plus (`add_paf_fields_to_users_table`): `role`
(`admin|requester|approver|finance`), `approval_level` (tinyint, approvers only),
`department`, `job_title`, `is_active` (bool).

### approval_levels

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `level` | tinyint, **unique** | ordinal |
| `name` | string | e.g. "Finance Director" |
| `min_amount` | decimal(15,2) | level applies when PRF `total ≥ min_amount` |
| `default_approver_id` | FK users, nullable (nullOnDelete) | pre-fills the chain stage |
| `is_active` | boolean | inactive levels are skipped |
| timestamps | | |

### invoices

Intake entity (the Invoice Log). Base fields: `reference_no` (unique, `PAF-{year}-00001`),
`vendor_name` (idx), `vendor_email`, `vendor_trn`, `invoice_no`, `invoice_date`, `due_date`,
`currency`, `amount`, `tax_amount`, `total_amount`, `business_unit`, `department` (idx), `location`,
`payment_method`, `priority`, `description`.

Lifecycle & posting columns:

| Column | Type | Notes |
|--------|------|-------|
| `status` | string, default `submitted` | `submitted \| posted \| query_raised \| cancelled` |
| `submitted_by` | FK users | requester |
| `submitted_at` | timestamp | submission date (drives aging) |
| `posting_date` | date, nullable | when posted in ERP |
| `erp_doc_no` | string, nullable | ERP document number |
| `finance_remarks` | text, nullable | query text |
| `posted_by` | FK users, nullable | Finance user who posted |
| `posted_at` | timestamp, nullable | |
| `payment_status` | string, default `not_initiated` | `not_initiated \| in_approval \| approved_for_payment \| paid` |
| `payment_request_id` | FK payment_requests, nullable (nullOnDelete) | current PRF |
| timestamps | | |

**Index:** `(status, payment_status)`, `department`, `vendor_name`.
`isEditable()` = status ∈ {submitted, query_raised} **and** payment_status = not_initiated.
`isPayable()` = payment_status = not_initiated **and** status ∈ {posted, submitted}.

### payment_requests (PRF)

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `reference_no` | string, **unique** | `PRF-{year}-00001` |
| `created_by` | FK users | Finance user who initiated |
| `status` | string, default `draft` | `draft \| in_approval \| approved \| rejected \| paid` (idx) |
| `current_stage` | uint, nullable | sequence of the stage awaiting action |
| `total_amount` | decimal(15,2) | sum of member invoices |
| `sent_at`, `approved_at`, `rejected_at` | timestamp, nullable | |
| `rejection_reason` | text, nullable | |
| `paid_at` | timestamp, nullable | |
| `payment_reference` | string, nullable | |
| `paid_by` | FK users, nullable | |
| timestamps | | |

### payment_request_approvals

One row per chain stage, sequence-ordered.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `payment_request_id` | FK, **cascade delete** | |
| `sequence` | uint | position in the chain (1..N) |
| `level` | tinyint, nullable | source approval level; null for ad-hoc |
| `label` | string | role / stage name |
| `is_adhoc` | boolean | added beyond the amount-based levels |
| `status` | string, default `pending` | `pending \| approved \| rejected` |
| `approver_id` | FK users, nullable | the assigned approver (and actor) |
| `comments` | text, nullable | |
| `acted_at` | timestamp, nullable | |
| timestamps | | |

**Unique:** `(payment_request_id, sequence)`.

### invoice_documents

`invoice_id` (cascade delete), `uploaded_by`, `original_name`, `file_path`, `mime_type`, `size`.
Limits (config `paf.php`): 10 files, 10 MB, `pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt`.

### audit_logs

Append-only (`created_at` only). `user_id` (actor), `invoice_id` (nullOnDelete),
`payment_request_id` (nullOnDelete), `action`, `description`, `old_values`/`new_values` (json),
`ip_address`. **Indexes:** `(invoice_id, created_at)`, `action`.

## Referential integrity

- `invoice_documents` and `payment_request_approvals` **cascade delete** with their parent.
- `audit_logs.invoice_id` / `payment_request_id` are **null on delete** — history survives.
- `invoices.payment_request_id` is null on delete — clearing a PRF frees its invoices.
- **Retired** by ADR-002: the `invoice_approvals` table and the per-invoice approval/payment
  columns.
