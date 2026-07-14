# Project Context

> Part of the `/ai` knowledge base. See [architecture.md](architecture.md), [database-schema.md](database-schema.md), [api-contracts.md](api-contracts.md).

## What this is

**PAF — Payment Approval Form / Invoice Payment Approval Platform.** A web application for
submitting vendor invoices as payment requests, routing them through a multi-level approval
chain based on amount thresholds, and processing the approved payments through finance.

It replaces a manual/email-and-spreadsheet invoice approval process with a single auditable
system: fixed-format submission, a date-wise invoice register, threshold-driven approvals,
payment scheduling, and a complete audit trail.

## Target users (four roles)

| Role | Who | Primary activity |
|------|-----|------------------|
| **Requester** | Department staff (Procurement, Operations, …) | Create and submit payment requests, attach invoice copies |
| **Approver** | Managers / directors / CFO, each pinned to an `approval_level` | Approve or reject requests routed to their level |
| **Finance** | AP accountants | Schedule and mark approved requests as paid |
| **Admin** | System administrators | Manage users, configure approval levels, view audit logs; can see everything |

## Main business workflow

```
Requester creates request (draft)
      │  attaches invoice documents
      ▼
Submit → status: pending_approval
      │  ApprovalService builds one approval row per required level (by amount)
      ▼
Approval chain (level 1 → 2 → 3 …), sequential
      │  each approver approves (advances current_level) or rejects (ends chain)
      ▼
status: approved
      │
      ▼
Finance schedules payment → status: scheduled
      │
      ▼
Finance marks paid → status: paid   (payment_reference recorded)
```

Terminal/branch states: **rejected** (returned to requester, editable + resubmittable),
**cancelled** (requester withdraws before completion).

## Approval routing (amount-threshold model)

Approval levels are **data-driven**, stored in the `approval_levels` table and configurable by
admins. Each level has a `min_amount`; a request must pass every active level whose
`min_amount <= total_amount`, in ascending `level` order.

Seeded default chain:

| Level | Name | Applies when total ≥ |
|-------|------|----------------------|
| 1 | Department Manager | 0 (always) |
| 2 | Finance Director | 10,000 |
| 3 | CFO | 50,000 |

> **Note on the demo HTML vs. this system:** the `vendor-portal-demo` HTML mockup shows a
> *fixed 8-stage* approval chain with hard-coded roles (Dept. Head → Finance Executive →
> Finance Manager → Chief Accountant → Internal Audit → GM Finance → CFO → MD). The
> implemented system instead uses a **configurable, amount-threshold** chain. Any change to
> match the demo's 8-stage model is a product decision — see [issues/technical-debt.md](issues/technical-debt.md).

## Current development status

- **Stage:** Working prototype / early build. Core end-to-end flow is implemented (auth,
  submission, documents, approval chain, payments, reports, audit, admin).
- **Data:** SQLite with seeders (`UserSeeder`, `ApprovalLevelSeeder`, `DemoDataSeeder`)
  producing demo users and ~59 sample invoices across all statuses.
- **Tests:** Only Laravel's default example tests exist — **no domain test coverage yet**.
- **Auth:** Session-based, same-origin SPA. No email verification, no password reset flow.
- **Email/ERP:** Not integrated (mail driver = `log`). The demo's "email to approver" step is
  represented in-app via the pending-approvals queue, not real email.

## Technology stack (summary)

- **Backend:** Laravel 13, PHP 8.3, SQLite (default), session auth.
- **Frontend:** Vue 3 SPA + Vuetify 4 + Pinia + Vue Router + Axios + Chart.js, built by Vite.
- **Export:** `maatwebsite/excel` for report exports.

See [architecture.md](architecture.md) for detail.

## External integrations

- **None active.** Mail is logged, not sent. No ERP, no payment gateway, no third-party auth.
- Production intent (per demo footer & existing docs): SMTP/email service, ERP integration,
  role-based access — the last is already implemented.

## Seeded demo accounts

All seeded users have password `password`.

| Email | Role | Level |
|-------|------|-------|
| admin@paf.local | admin | — |
| requester@paf.local / requester2@paf.local | requester | — |
| approver1@paf.local | approver | 1 |
| approver2@paf.local | approver | 2 |
| approver3@paf.local | approver | 3 |
| finance@paf.local | finance | — |
