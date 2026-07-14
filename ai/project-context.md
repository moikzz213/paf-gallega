# Project Context

> Part of the `/ai` knowledge base. See [architecture.md](architecture.md),
> [database-schema.md](database-schema.md), [api-contracts.md](api-contracts.md),
> and [decisions/ADR-002](decisions/ADR-002-vendor-portal-workflow.md) (the current model).

## What this is

**PAF — vendor-invoice payment platform.** Departments submit vendor invoices; Finance logs and
posts them to the ERP; Finance then groups posted invoices into a **Payment Request (PRF)** that
is routed through a **dynamic, per-request approval chain** and finally marked paid — all with a
full audit trail.

## Target users (four roles)

| Role | Who | Primary activity |
|------|-----|------------------|
| **Requester** | Department staff | Submit vendor invoices; track their status |
| **Finance** | AP / accounts | Post invoices to ERP or raise queries; create payment requests; mark paid |
| **Approver** | Managers / directors / CFO … | Approve or reject the payment-request stages assigned to them |
| **Admin** | System administrators | Manage users & approval levels; view audit logs; can do everything |

## Main business workflow (the four steps)

```
1. Submit Invoice   requester submits a vendor invoice     → status: submitted
2. Invoice Log      Finance posts to ERP  (erp_doc_no)      → status: posted
                    or raises a query     (finance_remarks) → status: query_raised
3. Payment Request  Finance selects posted invoices and
                    groups them into a PRF + approval chain  → invoice payment_status: in_approval
4. Payment Approval PRF routed stage-by-stage to each
                    assigned approver (approve → next stage;
                    reject → back to Finance, invoices freed)
                    all approved → approved_for_payment
                    Finance marks paid   (payment_reference) → paid
```

- **Invoice status:** `submitted → posted | query_raised`, plus `cancelled`.
- **Invoice payment_status:** `not_initiated → in_approval → approved_for_payment → paid`
  (a rejected PRF returns its invoices to `not_initiated`).
- **PRF status:** `draft → in_approval → approved → paid`, or `rejected`.

## Approval chain (dynamic, with defaults)

The chain belongs to the **PRF**, not the individual invoice. When Finance creates a PRF:

- The chain is **pre-filled** from the `approval_levels` a request's total must pass
  (`min_amount ≤ total`), using each level's **default approver**.
- Finance can **change any stage's approver, and add ad-hoc extra stages** — every approver is
  chosen from the pool of **active users with the Approver or Admin role**.
- Stages route **in sequence**; only the assigned approver (or an admin) can act on the current
  stage.

Seeded default levels: L1 Department Manager (≥0), L2 Finance Director (≥10,000),
L3 CFO (≥50,000), each with a seeded default approver.

## Current development status

- **Stage:** Working prototype, reworked to the vendor-portal scenario (see ADR-002).
- **Data:** seeders produce demo users + ~46 invoices across statuses and 11 PRFs across states.
- **Tests:** 12 feature tests (workflow + endpoint smoke). Verified via browser walkthrough.
- **Auth:** session-based; no email verification / password reset.
- **Email/ERP:** not integrated. "Post to ERP" records a doc number but calls no external system;
  approvals happen in-app (no email is sent — mail driver is `log`).

## Technology stack (summary)

- **Backend:** Laravel 13, PHP 8.3, session auth. DB is portable SQL (`.env.example` ships
  SQLite; this dev instance runs MySQL).
- **Frontend:** Vue 3 SPA + Vuetify 4 + Pinia + Vue Router + Axios + Chart.js, built by Vite.
- **Export:** `maatwebsite/excel` for report exports.

## External integrations

- **None active.** No real ERP, mailer, or payment gateway.

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

> Note: this dev instance's seeded users were customized to real names/emails; the demo logins
> above reflect the seeder defaults. Passwords are set by an admin (no self-service reset).
