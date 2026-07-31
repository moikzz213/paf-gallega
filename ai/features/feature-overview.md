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
  - **Raise Query** — record `finance_remarks` back to the department → status `query_raised`;
    **emails the invoice submitter** (`InvoiceQueryRaised`) so they can correct and resubmit.
- Filter by status, department, date range, and free-text search.

## 4. Payment Request / PRF (Finance)

- Finance selects **multiple eligible invoices** (posted or submitted, not already in a cycle)
  and groups them into one PRF. The selection list can be **filtered** by department, vendor name,
  invoice no, job no, customer name, and currency.
- Builds the **approval chain** for the PRF total (pre-filled from level defaults, fully
  editable, ad-hoc stages allowed) and sends it for approval in one step.
- Selected invoices are reserved (`payment_status = in_approval`) and linked to the PRF.
- An **email notification** is sent to the stage-1 approver when the PRF is created.
  The email includes a **unique token-gated link** (no login required) to view and approve/reject
  the PRF. Each approval stage has its own token — after approval, the old token becomes
  view-only and the next approver receives their own link via email. Approval and reminder emails
  show each invoice submitter and the invoice-line job no., customer, description, and
  currency-prefixed line total.
- A **daily reminder email** is sent to each approver with a pending PRF (scheduled at 09:00
  via `prf:send-reminders` Artisan command).

## 5. Payment Approval (Approver / Admin)

- Each PRF routes **stage-by-stage**; the request sits at `current_stage` and only the assigned
  approver (or an admin) can act.
- **Approve** → advances to the next stage, or finalizes the PRF to `approved` (invoices →
  `approved_for_payment`). On **final approval**, the requestors are emailed
  (`PaymentRequestApproved` → the PRF creator + every invoice submitter), from both the in-app and
  public-link approval paths.
- **Reject** → PRF `rejected`; its invoices are returned to the eligible pool for re-initiation.
- **Approvals queue** lists the PRFs awaiting the current user's stage.
- The approval decision dialog, authenticated PRF detail, and token-gated approval page show each
  invoice submitter and the invoice-line job no., customer, description, and currency-prefixed
  line total for review.

## 6. Mark Paid (Finance)

- Once a PRF is fully `approved`, Finance records a `payment_reference` → PRF `paid`, invoices
  `paid`.

## 7. PDF Export

- Every PRF has a **Download PDF** button (detail page and list page) that generates a landscape
  Payment Approval Form matching the company PAF layout, including the Gallega logo and aligned
  approval-flow connectors: voucher/request and accounts-document
  details, supplier line items, totals and amount in words, payment-approval limits, comments,
  separate requisition/approval/accounts sign-off areas, and invoice attachments on following
  pages. The PRF status is intentionally omitted. Approval stages are grouped directly from their
  configured approval levels: levels with `min_amount = 0.00` appear in **For Requisition Dept.
  Use**, while levels above zero (plus ad-hoc stages) appear in **For Approval**. The accounts area
  stays outside the approval chain.
- Attachments are merged into that single file by `PdfMergeService`: PDF attachments are appended
  page for page, images get one centred page each, and file types that cannot be rendered (Excel,
  Word, archives) are listed per invoice on a trailing **Additional Documents** page as clickable
  download links. That link page is drawn by FPDF rather than the Blade view because the FPDI merge
  step discards dompdf's link annotations — see
  [../decisions/ADR-003](../decisions/ADR-003-pdf-attachment-merging.md).

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
- **Master data:** CRUD for vendors, customers, business units, departments and locations.
  Administrators can also download a formatted entity-specific Excel template and bulk import up
  to 1,000 new rows. Imports validate all rows first, reject database/workbook duplicates with
  Excel row numbers, default every new record to active, and commit atomically so a partial
  master-data load cannot occur. The templates omit status and retain normal Excel gridlines.

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
