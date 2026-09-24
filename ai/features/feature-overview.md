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
  **vendor credit notes** are entered as a line with a **negative amount** — flagged as a credit
  note in the form, confirmed once before saving (a stray minus sign lowers who has to approve),
  and deducted by the same header sums. A line may not be `0`, and the invoice may not net to
  exactly zero — neither a charge nor a credit. A **credit-only invoice** (the whole invoice nets
  below zero, a credit note that arrived on its own) is allowed and is settled by grouping it into
  a PRF with the invoice it offsets; the "more than zero" floor lives on the PRF (see §4).
  Negatives render in accounting style — `AED (1,500.00)` — everywhere money is shown
  (`money()` in resources/js/utils/format.js, `App\Support\Money::format` for the PDF, the public
  view and the emails),
  payment method, priority, description, supporting documents (≤10 files, ≤10 MB each).
- System reference `INV-{year}-{00001}`; submitted immediately (status `submitted`).
- The **vendor's invoice number is unique for that vendor**, which is the control against recording
  the same vendor document twice and paying it twice. A repeat is declined at entry with a message
  naming the invoice that already holds the number, so Finance can check it rather than guess.
  Three qualifications: the check is **per vendor**, so two vendors may use the same numbering
  freely; **case and surrounding spaces are ignored**, so the same document cannot slip through as
  `inv-1001` beside `INV-1001`; and **cancelled invoices release their number**, so a cancelled
  mis-entry never locks out the genuine invoice. A correction never blocks itself, and a unique
  index carries the same rule so simultaneous submissions cannot race past it. Invoices predating
  the vendor master list carry no vendor link and are outside the rule.
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
  - **Edit Posting** — correct a mistyped `erp_doc_no` (or posting date) on an invoice that is
    already `posted`. The number is keyed by hand and is what reconciles a PAF payment to the ERP
    document, so a typo had to be fixable; until this, it was permanent. Restricted to the user
    recorded in `posted_by` — they had the ERP document in front of them — **or** an admin, which is
    the way through once that person has left; the refusal names the poster. Deliberately available
    at **any** payment status, paid included: the field is a reference, not an amount, and
    reconciliation (where a wrong number surfaces) happens after payment. Correcting is **not**
    re-posting: `posted_by`, `posted_at` and the `posted` status are untouched, and the correction is
    logged separately as `posting_corrected` with the values it replaced, so the original `posted`
    entry survives.
  - Re-posting is refused while an invoice is `query_raised` **and** already has an `erp_doc_no` —
    it would overwrite that ERP document. Resolving the query returns it to `submitted`, and posting
    then records the corrected document.
- Filter by status, department, date range, and free-text search.

## 4. Payment Request / PRF (Finance)

- Finance selects **multiple eligible invoices** (posted or submitted, not already in a cycle)
  and groups them into one PRF. The selection list can be **filtered** by department, vendor name,
  invoice no, job no, customer name, and currency.
- **Marking a mis-signed credit note.** Invoices already on record carry credit notes entered as a
  **positive** amount, from before the system accepted a negative one — grouping one *added* it to
  the request. A **Credit note** tick-box on each selected row corrects that: the invoice's own
  figures (header and lines) are corrected to a deduction, so the request deducts it. Shown only on
  a positive invoice — one already recorded as a credit note is labelled as such and deducted as it
  stands. Confirmed before sending (recorded → corrected to, plus the resulting total), audited as
  `credit_note_marked` against both invoice and PRF with the replaced figures, and persisted only
  inside the creation transaction, so a refused request rewrites nothing.
- The selection must **net to more than zero**. A credit-only invoice is settled here, by being
  selected alongside the charge it offsets: the refusal names the credit and says to add the
  invoice it offsets. This is the floor that makes a credit-only invoice safe to record at all —
  a request at or below zero matches no `min_amount`, so it could be routed to nobody and there
  would be nothing to pay.
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
- **The subject line identifies the payment** — `purpose - vendor - total - PAF number`
  (`PaymentRequest::subjectSummary`), on the submitted, reminder and fully-approved emails alike, so
  an approver can tell requests apart from the inbox without opening them. Most identifying detail
  first, and the purpose is the part that gets shortened, because a truncated vendor, amount or
  reference is worse than a truncated description. The purpose is the first line description on the
  request; the vendor is the single vendor, or the first with a `+N more` suffix for a request
  spanning several.
- The token-gated page **shows the PAF itself**, with its supporting documents merged in, directly
  above the Approve and Reject buttons — the approver decides against the form rather than against a
  summary. The individual attachment links stay below it as a fallback.
- A **daily reminder email** is sent to each approver with a pending PRF (scheduled at 09:00
  via `prf:send-reminders` Artisan command).
- **Advance payments.** An invoice can be marked as an **advance payment** — money paid before the
  business has what it paid for, a deposit or part-payment against an order. It follows exactly the
  same submission, posting, grouping and approval process as any other invoice: the marker describes
  the payment, it does not route it differently. It shows on the invoice, in the Invoice Log (which
  can be filtered to advances or to ordinary payments), on the token-gated approval page, and on the
  PAF itself — both as a banner and against the individual lines, so a mixed request shows which
  invoices are advances. The marker is **frozen once a payment request holds the invoice**: it is
  part of what the approvers were shown, and it is what opens the late-upload route below. Release
  the invoice to change it. Invoices raised before this existed carry no marker and are not
  backfilled — nothing on record reliably says which of them were advances.
- **Attaching the final vendor invoice to a paid advance.** An advance is raised and paid *before*
  the vendor's final tax invoice exists, so the document that completes the record arrives after
  everything else is deliberately frozen. Finance, an admin, or the invoice's own submitter can
  attach supporting documents to an advance-payment invoice at any point in its payment cycle,
  including after it is **paid**. Documents only: the route reads the files and nothing else, so no
  amount, currency, date, line item, status or approval state can move through it — anything posted
  alongside the files is ignored rather than refused. A file attached after the chain signed is
  marked as such wherever documents are listed, and audited apart from an ordinary upload, so
  "what was in front of the approver?" stays answerable. Ordinary invoices are unaffected and stay
  closed once paid.
- **Correcting an invoice already in a PRF (Finance/Admin, no approval needed).** Finance can fix an
  invoice a PRF is holding without withdrawing anything — job no., customer, description, dates,
  even a **lower** total. The PRF keeps its approvals and its total is re-synced. Locked: the
  **currency** and any **increase** to the total, because the recorded approvals only ever covered
  the old figure (thresholds are driven by `min_amount`, so a smaller total needs a subset of the
  same levels — a larger one may need levels nobody has given). Those need a release first.
  Also locked: a correction that would leave the **PRF** at zero or below — the total only falling
  is no longer enough now that an invoice can net below zero. Withdraw the PRF instead.
- **Releasing a single invoice (Finance/Admin only).** When one invoice in a PRF is wrong and the
  others are fine, release just that invoice with a required reason: it returns to the Invoice Log
  while the rest of the PRF stays approved and payable, its total re-synced. Releasing the last
  invoice withdraws the PRF, since an approved request with nothing to pay is void. This keeps the
  approvals because the remaining total only ever *falls* — which holds only while invoice totals
  are positive, so releasing an invoice worth ≤ 0 from a multi-invoice PRF is refused (`422`)
  rather than silently raising the total past what its approvals cover.
- **Withdrawal (Finance/Admin only).** A fully approved PRF that has not been paid can be withdrawn
  with a required reason: the PRF becomes `withdrawn`, its invoices return to the Invoice Log
  (`not_initiated`, unlinked), and it can no longer be paid. Its PAF still renders — it is part of
  the record — but stamped `WITHDRAWN` and marked as not payable. The recorded approvals
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
- **L2-A — the optional second signature at level 2.** Finance can nominate one extra approver on
  the payment page who signs **immediately after L2**, ahead of any higher level and ahead of the
  ad-hoc stages. It exists for the payments the business wants a department head or business-unit
  controller to countersign without making that person a standing tier on every request. The slot is
  only offered when the amount actually reaches level 2, and the request is refused rather than
  silently rerouted if it is set when it cannot apply, or set to the person already holding L2 — a
  dropped approver is worse than a refused request, because whoever added them would never find out.
  Leaving it empty routes exactly as before. It is recorded as an ad-hoc stage (no level of its own,
  labelled `L2-A — {job title}`) and audited like any other.
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
- **Reject** → PRF `rejected`; its invoices are returned to the eligible pool for re-initiation,
  and an `invoice_rejections` row records that each one was on this PRF — the report reads the
  rejected PAF back through it, since the invoice's own `payment_request_id` is cleared here and
  reused by whatever PRF the corrected invoice goes onto next. The requestor side is emailed
  (`PaymentRequestRejected` → the **PRF creator** and **every invoice submitter**, de-duplicated),
  from both the in-app and public-link rejection paths. The notice briefly also went to the
  approval chain and the whole Finance role; that was withdrawn over notification volume, the
  rollback the change request had itself reserved. Approvers see the outcome on the request, and
  Finance find the returned invoices in the Invoice Log and the rejection in the report.
- **Approvals queue** lists the PRFs awaiting the current user's stage.
- The approval decision dialog, authenticated PRF detail, and token-gated approval page show each
  invoice submitter and the invoice-line job no., customer, description, and currency-prefixed
  line total for review.

## 6. Mark Paid (Finance)

- Once a PRF is fully `approved`, Finance records a `payment_reference` → PRF `paid`, invoices
  `paid`.
- **Edit Payment Ref** — correct a mistyped `payment_reference`, shown on the PRF detail page only
  **once there is one** (the PRF is `paid`). The reference is the transfer/cheque number keyed by
  hand and is what reconciles the payment to the bank statement, so a typo had to be fixable; until
  this, `markPaid` refusing anything not `approved` made it permanent. Restricted to the user in
  `paid_by` — they had the bank record in front of them — **or** an admin, the way through once that
  person has left; the refusal names the payer. The PRF's **creator** gets no special right: raising
  a request is not recording its payment. Correcting is **not** re-paying: `paid_by`, `paid_at`, the
  `paid` status, the total, the invoices and every approval are untouched, and it is refused on any
  non-`paid` status, so it can never become a route to marking something paid. Logged as
  `payment_reference_corrected` with the value it replaced, leaving the original `paid` entry
  intact. No uniqueness rule — one transfer often settles several PRFs.

## 7. PDF Export

- Every PRF has a **Download PAF** button (detail page and list page), available **from the moment
  the request is created** rather than only once it is fully approved — the PAF is the document the
  approval chain is signing, so it has to exist while that chain is running. It is rendered on
  demand by `PafDocumentService` and never stored, so it always reflects the approvals reached so
  far. Anything short of `approved`/`paid` carries a red `PENDING APPROVAL` watermark (or `REJECTED` /
  `WITHDRAWN`) and a banner saying it is not an authorisation to pay, so a draft in circulation
  cannot be mistaken for a signed one. The button reads "Download PAF (draft)" until then.
- The document is a landscape
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

- KPI cards (total invoices, awaiting posting, in approval, paid), an approver
  "queue waiting" alert, and Chart.js visuals (invoice-status distribution, monthly submitted vs
  paid, top vendors, spend by business unit) plus a recent-invoices table. All scoped to the viewer.
- **One period governs the whole screen.** A From/To month filter with presets (This Month, Last 3/6
  Months, Year to Date, Last 12 Months, All Time) sits at the top; every card, chart and the recent
  list reports on it, and the period is labelled next to each figure. Default is year to date.
  Before this, three cards were all-time, one was this-month and the trend chart was pinned to six
  months, with nothing on screen saying so.
- **Each metric is anchored on its own date**, not one shared column: work entering the system by
  `submitted_at`, the Paid card by the payment request's `paid_at`, and the two spend charts by
  `invoice_date`. The cards therefore overlap rather than describe one identical set — an invoice
  can be paid in a period it was not submitted in. This is also why the same From/To months can give
  different totals here and on the Reports page, which anchors everything on `invoice_date`.
- **The approver "queue waiting" alert is never period-filtered.** It is a live work queue; hiding a
  pending approval behind a date range would mean real work going unseen.

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
| Correct posting details (ERP doc no.) | | | ✓ (only what they posted) | ✓ (any) |
| Correct a payment reference | | | ✓ (only what they paid) | ✓ (any) |
| Create payment request | | | ✓ | ✓ |
| Approve / reject a PRF stage | | ✓ (assigned) | ✓ (assigned, needs a level) | ✓ |
| …but never on a PRF they created | — | — | — | — |
| Mark PRF paid | | | ✓ | ✓ |
| Withdraw an approved PRF | | | ✓ | ✓ |
| Release one invoice from a PRF | | | ✓ | ✓ |
| Correct an invoice held by a PRF | | | ✓ | ✓ |
| Download PRF PAF (any status) | ✓ (scoped) | ✓ (scoped) | ✓ | ✓ |
| Reports & export | ✓ (scoped) | ✓ (scoped) | ✓ | ✓ |
| Audit log viewer, manage users/levels | | | | ✓ |

## Not yet implemented

- Real ERP/payment-gateway integration.
- Password reset / email verification.
- Concurrency guard against selecting one invoice into two PRFs — see
  [../issues/known-issues.md](../issues/known-issues.md).
