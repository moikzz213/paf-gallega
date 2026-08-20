# Feature Overview

The PAF platform's capabilities, by area. See [../api-contracts.md](../api-contracts.md) for the
endpoints and [../decisions/ADR-002](../decisions/ADR-002-vendor-portal-workflow.md) for the model.

## 1. Authentication & session

- Email/password login (session cookie), throttled. Deactivated (`is_active=false`) users are
  blocked. Sign-in/out are audit-logged. No email verification / password reset.

## 2. Submit Invoice (Requester)

- Fixed-format form: vendor selection by unique master-data record (displayed as name + code),
  invoice no/date/due date, currency (dropdown fed by the currencies master data; stored as a
  plain string on the invoice and its lines),
  amount + tax / VAT as a **percentage** per line (the cash figure and the total are computed
  server-side), business unit (GIL/GGL/GGH), submitting department, location,
  payment method, priority, description, supporting documents (≤10 files, ≤10 MB each).
- System reference `INV-{year}-{00001}`; submitted immediately (status `submitted`).
- A **queried** invoice can be edited and it returns to `submitted`.

## 3. Invoice Log (Finance)

- Date-wise register of every invoice with **outstanding-days aging** (green ≤7, amber 8–14,
  red >14 days from submission).
- Finance actions per invoice:
  - **Post to ERP** — record `erp_doc_no` (+ posting date) → status `posted`.
  - **Raise Query** — record `finance_remarks` back to the department → status `query_raised`;
    **notifies the invoice submitter** (`InvoiceQueryRaised`, queued) so they can correct and resubmit.
    The query is recorded whether or not the mail gets out, and Finance sees which happened. A
    **Resend Notification** action on the invoice sends it again — the recovery for a rotated SMTP
    password, which used to swallow the notification with no way to retrigger it.
    Only available while the invoice is **outside a payment cycle** (`not_initiated`): a query asks
    for a correction, and an invoice held by a PRF cannot be edited. Return it first (reject the PRF,
    or withdraw it if already approved).
  - Re-posting is refused while an invoice is `query_raised` **and** already has an `erp_doc_no` —
    it would overwrite that ERP document. Resolving the query returns it to `submitted`, and posting
    then records the corrected document.
- Filter by status, department, date range, and free-text search.

## 4. Payment Request / PRF (Finance)

- Finance selects **multiple eligible invoices** (posted or submitted, not already in a cycle)
  and groups them into one PRF. The selection list can be **filtered** by department, vendor name,
  invoice no, job no, customer name, and currency.
- Builds the **approval chain** for the PRF total (pre-filled from level defaults, fully
  editable, ad-hoc stages allowed) and sends it for approval in one step.
- Selected invoices are reserved (`payment_status = in_approval`) and linked to the PRF.
- The payment request list shows the **vendor name** of the invoices it groups; a PRF spanning
  several vendors shows the first with a `+N more` suffix (all names in the cell tooltip), the
  same way the currency column labels mixed sets.
- An **email notification** is sent to the stage-1 approver when the PRF is created.
  The email includes a **unique token-gated link** (no login required) to view and approve/reject
  the PRF. Each approval stage has its own token — after approval, the old token becomes
  view-only and the next approver receives their own link via email. Approval and reminder emails
  show each invoice submitter and the invoice-line job no., customer, description, and
  currency-prefixed line total.
- A **daily reminder email** is sent to each approver with a pending PRF (scheduled at 09:00
  via `prf:send-reminders` Artisan command).
- **Correcting an invoice already in a PRF (Finance/Admin, no approval needed).** Finance can fix an
  invoice a PRF is holding without withdrawing anything — job no., customer, description, dates,
  even a **lower** total. The PRF keeps its approvals and its total is re-synced. Locked: the
  **currency** and any **increase** to the total, because the recorded approvals only ever covered
  the old figure (thresholds are driven by `min_amount`, so a smaller total needs a subset of the
  same levels — a larger one may need levels nobody has given). Those need a release first.
- **Releasing a single invoice (Finance/Admin only).** When one invoice in a PRF is wrong and the
  others are fine, release just that invoice with a required reason: it returns to the Invoice Log
  while the rest of the PRF stays approved and payable, its total re-synced. Releasing the last
  invoice withdraws the PRF, since an approved request with nothing to pay is void.
- **Withdrawal (Finance/Admin only).** A fully approved PRF that has not been paid can be withdrawn
  with a required reason: the PRF becomes `withdrawn`, its invoices return to the Invoice Log
  (`not_initiated`, unlinked), and it can no longer be paid or produce a PDF. The recorded approvals
  are kept as history but never reused — a correction that changes the amount or currency changes
  which thresholds apply, so the corrected invoices go through a **new PRF with a fresh chain**.
  While a PRF is still `in_approval` the exit is an approver **rejection**, not withdrawal; once
  `paid` there is deliberately no exit. Approvers cannot withdraw — it reverses their own approval.
- Getting a returned invoice editable again takes **both** steps, in order: return it (reject or
  withdraw the PRF), *then* raise the query — a returned invoice is still `posted`, and only
  `query_raised`/`submitted` invoices are editable.

## 5. Payment Approval (Approver / Admin / nominated Finance)

- Each PRF routes **stage-by-stage**; the request sits at `current_stage` and only the assigned
  approver (or an admin) can act.
- **Who can be an approver.** Approvers and admins always. **Finance users individually**, once an
  admin sets an `approval_level` on them — Finance often raises the PRF itself, so the group members
  who sign those off (rather than a requester's own manager) need to be selectable at L1/L2. A
  Finance user without a level is not an approver anywhere: not in the chain builder, not in the
  ad-hoc list, and with no approvals queue. One level per person, so a nominated user appears under
  that level only; the ad-hoc "Additional approvers" stages still accept any eligible user.
- **Each stage carries the approver's job title**, not the approval level's name. "Department
  Manager" describes the level; the person signing at that level is often something else (an AP
  Accountant from the Finance group), and the chain, the PAF PDF and the public approval page all show
  the job title they actually hold. The level name is the fallback when a user has no job title on
  record, and the level number is still stored on the stage. Titles are **snapshotted** when the chain
  is built, so a later promotion never rewrites an approval that already happened — which means
  existing requests keep the labels they were created with.
- **Nobody approves their own request.** The creator cannot be placed on the chain of a PRF they
  create, and cannot approve or reject it afterwards — including admins on their own requests, who
  otherwise may act on any stage. This matters now that creating and approving can be the same role.
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
  and a paginated table. The table and the export both show the invoice's **job numbers** (they
  live on the invoice lines, so an invoice's distinct job numbers are joined with `, `).
  **Export to Excel** (25-column XLSX incl. job no., ERP posting + PRF payment
  columns); exports are audit-logged.
- **Export API for spreadsheets/BI.** `GET /api/export-report?key=&secret=` serves the same rows
  and columns as that download, as JSON (or `format=xlsx`), so a workbook can refresh itself
  without a login. Keys are issued per user with `api-key:issue` and carry that user's data
  visibility; the secret is stored only as a hash, use is audit-logged, and `api-key:revoke`
  kills a key immediately. Page, download and API all read one definition, so they cannot drift.

## 9. Audit trail (Admin)

- Every state change writes an `audit_log` (actor, action, description, IP, timestamp), scoped to
  an invoice and/or a payment request. Admin viewer with search + action/date filters; invoice
  and PRF detail pages render their own audit timelines.

## 10. Administration (Admin)

- **Users:** create/edit (no hard delete — deactivate via `is_active`); role assignment;
  `approval_level` for approvers.
- **Approval levels:** CRUD of the threshold levels used to **pre-fill** PRF chains, each with a
  **default approver**. The default-approver list offers only users assigned to **that** level (the
  same membership rule the chain builder applies), so a level cannot be defaulted to someone whose
  chain assignment would then be refused. A level's existing default stays selectable even if that
  user's own level has since moved, flagged in the list, so renaming a level never silently clears it.
- **Master data:** CRUD for vendors, customers, business units, departments, locations and
  currencies (a currency's name is its 3-letter code; it feeds the invoice Currency dropdown).
  Lists use server-side pagination and search; vendor/customer searches cover both name and code.
  Vendor and customer names may repeat, while each entered vendor/customer code must be unique.
  Administrators can also download a formatted entity-specific Excel template and bulk import up
  to 1,000 new rows. Imports validate all rows first, apply the same code-based uniqueness rule
  for vendors/customers (name-based for other masters), report duplicates with Excel row numbers,
  default every new record to active, and commit atomically so a partial
  master-data load cannot occur. The templates omit status and retain normal Excel gridlines.

## Role → feature matrix

| Feature | Requester | Approver | Finance | Admin |
|---------|:--------:|:--------:|:-------:|:-----:|
| Submit / edit own invoices | ✓ | ✓ (own) | ✓ | ✓ |
| Post to ERP / raise query | | | ✓ | ✓ |
| Create payment request | | | ✓ | ✓ |
| Approve / reject a PRF stage | | ✓ (assigned) | ✓ (assigned, needs a level) | ✓ |
| …but never on a PRF they created | — | — | — | — |
| Mark PRF paid | | | ✓ | ✓ |
| Withdraw an approved PRF | | | ✓ | ✓ |
| Release one invoice from a PRF | | | ✓ | ✓ |
| Correct an invoice held by a PRF | | | ✓ | ✓ |
| Download PRF PDF | ✓ (scoped) | ✓ (scoped) | ✓ | ✓ |
| Reports & export | ✓ (scoped) | ✓ (scoped) | ✓ | ✓ |
| Audit log viewer, manage users/levels | | | | ✓ |

## Not yet implemented

- Real ERP/payment-gateway integration.
- Password reset / email verification.
- Concurrency guard against selecting one invoice into two PRFs — see
  [../issues/known-issues.md](../issues/known-issues.md).
