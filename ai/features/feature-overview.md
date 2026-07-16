# Feature Overview

The PAF platform's capabilities, by area. See [../api-contracts.md](../api-contracts.md) for the
endpoints and [../decisions/ADR-002](../decisions/ADR-002-vendor-portal-workflow.md) for the model.

## 1. Authentication & session

- Email/password login (session cookie), throttled. Deactivated (`is_active=false`) users are
  blocked. Sign-in/out are audit-logged. No email verification / password reset.

## 2. Submit Invoice (Requester)

- Fixed-format form: vendor details (name, email, TRN), invoice no/date/due date, currency,
  amount + tax (total computed server-side), business unit (GIL/GGL/GGH), submitting department, location,
  payment method, priority, description, supporting documents (≤10 files, ≤10 MB each).
- System reference `PAF-{year}-{00001}`; submitted immediately (status `submitted`).
- A **queried** invoice can be edited and it returns to `submitted`.

## 3. Invoice Log (Finance)

- Date-wise register of every invoice with **outstanding-days aging** (green ≤7, amber 8–14,
  red >14 days from submission).
- Finance actions per invoice:
  - **Post to ERP** — record `erp_doc_no` (+ posting date) → status `posted`.
  - **Raise Query** — record `finance_remarks` back to the department → status `query_raised`.
- Filter by status, department, date range, and free-text search.

## 4. Payment Request / PRF (Finance)

- Finance selects **multiple eligible invoices** (posted or submitted, not already in a cycle)
  and groups them into one PRF.
- Builds the **approval chain** for the PRF total (pre-filled from level defaults, fully
  editable, ad-hoc stages allowed) and sends it for approval in one step.
- Selected invoices are reserved (`payment_status = in_approval`) and linked to the PRF.
- An **email notification** is sent to the stage-1 approver when the PRF is created.
  The email includes a **unique token-gated link** (no login required) to view and approve/reject
  the PRF. Each approval stage has its own token — after approval, the old token becomes
  view-only and the next approver receives their own link via email.
- A **daily reminder email** is sent to each approver with a pending PRF (scheduled at 09:00
  via `prf:send-reminders` Artisan command).

## 5. Payment Approval (Approver / Admin)

- Each PRF routes **stage-by-stage**; the request sits at `current_stage` and only the assigned
  approver (or an admin) can act.
- **Approve** → advances to the next stage, or finalizes the PRF to `approved` (invoices →
  `approved_for_payment`).
- **Reject** → PRF `rejected`; its invoices are returned to the eligible pool for re-initiation.
- **Approvals queue** lists the PRFs awaiting the current user's stage.

## 6. Mark Paid (Finance)

- Once a PRF is fully `approved`, Finance records a `payment_reference` → PRF `paid`, invoices
  `paid`.

## 7. PDF Export

- Every PRF has a **Download PDF** button (detail page and list page) that generates a PDF
  containing: PRF header with status and amount, all invoice line items, the approval chain
  timeline, and embedded invoice attachments (images inlined, PDFs embedded, other files listed).

## 7. Dashboard

- KPI cards (total invoices, awaiting posting, in approval, paid this month), an approver
  "queue waiting" alert, and Chart.js visuals (invoice-status distribution, monthly submitted vs
  paid, top vendors, spend by business unit) plus a recent-invoices table. All scoped to the viewer.

## 8. Reports & Excel export

- Filterable report (status, department, business unit, vendor, date range) with per-status summary
  and a paginated table. **Export to Excel** (24-column XLSX incl. ERP posting + PRF payment
  columns); exports are audit-logged.

## 9. Audit trail (Admin)

- Every state change writes an `audit_log` (actor, action, description, IP, timestamp), scoped to
  an invoice and/or a payment request. Admin viewer with search + action/date filters; invoice
  and PRF detail pages render their own audit timelines.

## 10. Administration (Admin)

- **Users:** create/edit (no hard delete — deactivate via `is_active`); role assignment;
  `approval_level` for approvers.
- **Approval levels:** CRUD of the threshold levels used to **pre-fill** PRF chains, each with a
  **default approver**.

## Role → feature matrix

| Feature | Requester | Approver | Finance | Admin |
|---------|:--------:|:--------:|:-------:|:-----:|
| Submit / edit own invoices | ✓ | ✓ (own) | ✓ | ✓ |
| Post to ERP / raise query | | | ✓ | ✓ |
| Create payment request | | | ✓ | ✓ |
| Approve / reject a PRF stage | | ✓ (assigned) | | ✓ |
| Mark PRF paid | | | ✓ | ✓ |
| Download PRF PDF | ✓ (scoped) | ✓ (scoped) | ✓ | ✓ |
| Reports & export | ✓ (scoped) | ✓ (scoped) | ✓ | ✓ |
| Audit log viewer, manage users/levels | | | | ✓ |

## Not yet implemented

- Real ERP/payment-gateway integration.
- Password reset / email verification.
- Concurrency guard against selecting one invoice into two PRFs — see
  [../issues/known-issues.md](../issues/known-issues.md).
