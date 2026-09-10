# Bugs Fixed

A running log of resolved bugs, so fixes aren't re-litigated and regressions are traceable.

> None recorded yet — this file was created during LIFT project initialization
> (see [../decisions/ADR-001-project-initialization.md](../decisions/ADR-001-project-initialization.md)).

## [2026-09-10] A mistyped payment reference was permanent once a PRF was marked paid

- **Symptom:** `payment_reference` — the transfer/cheque number — is keyed by hand in the Mark Paid
  dialog, and could not be corrected afterwards. `markPaid()` refuses a PRF that is not `approved`,
  so one already `paid` can never pass through it again, and nothing else writes the field. It is
  what reconciles a PAF payment to the bank statement, so a wrong one leaves the payment unmatchable
  and every supplier or audit query about it a manual investigation. The only remedies were to leave
  it wrong or edit the database directly.
- **Cause:** Same shape as the ERP-doc-no gap below: no correction path existed, and re-recording the
  payment is deliberately refused because it would replace the reference *and* rewrite
  `paid_by`/`paid_at`.
- **Fix:** `POST /api/payment-requests/{paymentRequest}/payment-reference`
  (`PaymentRequestService::updatePaymentReference`) plus an **Edit Payment Ref** action on
  `PaymentRequestDetailPage`, shown only once there is a reference to correct (status `paid`).
  Restricted to the user in `paid_by` — they had the bank record in front of them — or an admin, the
  way through once that person has left; the 403 names the payer. The PRF **creator** gets no
  special right, which the user confirmed when asked: raising a request is not recording its
  payment. Corrects `payment_reference` only: `paid_by`, `paid_at`, `status`, the total, the invoices
  and every approval are untouched, and any non-`paid` status is refused — so it can never be a back
  door to marking something paid. No uniqueness rule, because one transfer legitimately settles
  several PRFs. Logged as `payment_reference_corrected` with the replaced value; the original `paid`
  entry survives. The dialog prefills what is recorded, names the payer and payment date, and
  refuses a confirm that changes nothing.
- **Verified:** `PaymentReferenceCorrectionTest` (18 tests) covers the correction, the admin
  override, repeat corrections, two PRFs sharing a reference, that `paid_by`/`paid_at`/status never
  move, that the total, invoice statuses and the whole approval chain are byte-for-byte unchanged,
  every refusal (another Finance user, **the PRF creator**, requester, approver, and each of
  in-approval / approved / rejected), validation, and the audit content. Exercised in the running app
  as Abrar on PRF-2026-00003 (which she paid): the button appeared, the dialog prefilled
  `TRF-ECCBC87E` and named "Abrar … on 28 Apr 2026", the no-change confirm was refused, and
  `TRF-ECCBC87E → TRF-CORRECTED-01` persisted with `paid_by` still 7 and the original `paid` entry
  intact. On an in-approval PRF the button was correctly absent. Demo value restored afterwards.

## [2026-09-10] A mistyped ERP document number was permanent once an invoice was posted

- **Symptom:** `erp_doc_no` is keyed by hand from the ERP at posting time, and a typo could not be
  corrected afterwards. `post()` refuses an invoice that is no longer `submitted`/`query_raised`, and
  `update()` never touches the posting fields, so the number was final. It is what reconciles a PAF
  payment to the ERP document and it is printed on the PRF, so a wrong one either points at nothing
  or — worse — at a different document. The only remedies were to leave it wrong or edit the database
  directly, which is unrecorded and bypasses the application's controls.
- **Cause:** No correction path existed. Re-posting is deliberately refused (it would silently
  replace the number *and* rewrite `posted_by`/`posted_at`), and nothing narrower was offered.
- **Fix:** `POST /api/invoices/{invoice}/posting` (`InvoiceController::updatePosting`) plus an
  **Edit Posting** action on `InvoiceDetailPage`, offered while the invoice is `posted`. Restricted to
  the user in `posted_by` — they had the ERP document in front of them — or an admin, the way through
  once that person has left; the 403 names the poster to ask. Corrects `erp_doc_no` and
  `posting_date` only: `posted_by`, `posted_at` and `status` are untouched, so a correction cannot
  reassign responsibility for the posting. Allowed at **any** payment status, paid included, on the
  grounds that the field is a reference to an external document rather than an amount, and
  reconciliation — where a wrong number actually surfaces — happens after payment. Logged as
  `posting_corrected` with the replaced values; the original `posted` entry survives, so the history
  is added to rather than rewritten. The dialog prefills what is recorded and refuses a confirm that
  changes nothing.
- **Verified:** `InvoicePostingCorrectionTest` (18 tests) covers the correction, the date handling,
  the admin override, that `posted_by`/`posted_at` never move, correction at in-approval / approved /
  **paid** with the PRF total, approvals and payment status asserted unchanged, every refusal
  (another Finance user, requester, approver, the invoice's own submitter, unposted and
  query-carrying-a-doc-no states), validation, and the audit content. Exercised in the running app
  both ways: as Abrar (finance) on an invoice posted by System Admin the button was absent and the
  endpoint returned 403 naming System Admin; on an invoice Abrar posted, **Edit Posting** appeared
  prefilled, the no-change confirm was refused, and `5112345678 → 5187654321` with
  `2026-09-10 → 2026-09-08` persisted with `posted_by` still 7 and the audit row rendering on the
  timeline.

## [2026-09-10] A credit note recorded as a positive amount was added to a PRF, not deducted

- **Symptom:** Production invoices hold vendor credit notes entered as ordinary **positive**
  amounts. Grouping one into a payment request *increased* what the request asked for — a PRF that
  should have asked AED 14,750 asked AED 25,250 — and, because `ApprovalLevel::requiredForInvoices`
  measures the same figure, it also routed to a more senior tier than the real payable needed. An
  approved PRF of that kind overpays the vendor by the value of the credit.
- **Cause:** Not a coding error but a data one, created by an earlier constraint: until
  [allow-negative-line-amounts-for-credit-notes](../change-requests/allow-negative-line-amounts-for-credit-notes.md)
  the system refused a negative amount, so a credit note could only be recorded as a positive
  figure. Every total in the app is a plain sum of `invoices.total_amount`, so those rows are
  indistinguishable from a charge. Finance's only remedy was to hand-edit each invoice before
  grouping it.
- **Fix:** A **Credit note** tick-box per selected invoice on the PRF creation screen
  (`PaymentRequestService::applyCreditMarks`, `credit_invoice_ids[]` on `POST /api/payment-requests`).
  Marking **corrects the invoice's sign** — header and every line together — rather than flagging
  it, because the PRF total, `syncTotal`, the release and correction guards, the PDF, the emails and
  every report all read `total_amount`; a PRF-local flag would leave each of those reading the
  uncorrected figure and break the equality those guards rest on. Offered only on a positive
  invoice: marking a negative one would turn a credit back into a charge, refused on the server as
  well as hidden on the screen. Applied in memory for the currency/payable checks and the chain,
  persisted only **inside** the creation transaction, so a refused PRF rewrites nothing. Audited as
  `credit_note_marked` against both invoice and PRF, keeping the replaced figures, so a mis-tick is
  recoverable. Confirmed before sending, mirroring the invoice form's credit-line confirmation.
- **Verified:** `PaymentRequestCreditMarkTest` (17 tests) covers the deduction, the header/line
  correction, `syncTotal` agreement, routing on the corrected total, the refusals, the audit trail,
  and — the one that matters most — that a PRF refused for a missing approver or its own chain
  leaves the invoice and its lines untouched. Exercised in the running app end to end: the running
  total moved from AED 25,250.00 to AED 14,750.00 on ticking, the confirmation listed
  `5,250.00 → (5,250.00)`, PAF-2026-00024 persisted at 14,750.00 with `invoices()->sum()` agreeing,
  routing on L1+L2, and the audit entry holding the original `5250.00`. An already-negative row
  showed a "Credit note" label and no tick-box.
- **Note:** no bulk restatement — each affected invoice is corrected the first time Finance handles
  it, so historical reports run before a correction will differ for that invoice.

## [2026-08-17] A query on an invoice inside an approved PRF stranded it permanently
- **Symptom:** Production invoice INV-2026-00054 sat at `status = query_raised` **and**
  `payment_status = approved_for_payment` inside the fully approved PAF-2026-00018. The query asked
  for the currency to change from AED to USD, but the invoice could not be edited, cancelled or
  deleted (`isEditable()` requires `not_initiated`), and the PRF could not be rejected
  (`assertActionable()` requires `in_approval`). Mark Paid was still live, so the wrong-currency
  payment could still have gone out. The invoice's "Post to ERP" button was also still enabled and
  would have silently overwritten ERP doc PINGGF00012347.
- **Cause:** `InvoiceController::raiseQuery()` validated only `status`, never `payment_status`, so a
  query could be raised on an invoice already held by a PRF. Nothing else in the app could move an
  `approved` PRF backwards — rejection is the only path that returns invoices, and it is restricted
  to `in_approval`. `post()` likewise never checked for an existing `erp_doc_no`.
- **Fix:** (1) `raiseQuery()` now requires `payment_status = not_initiated`; (2) new
  `PaymentRequestService::withdraw()` + `POST /api/payment-requests/{id}/withdraw`
  (`role:finance,admin`) sets an `approved` PRF to the new `withdrawn` status, returns its invoices
  to `not_initiated`/unlinked, and records `withdrawn_at/withdrawn_by/withdrawal_reason`;
  (3) `post()` refuses to re-post a `query_raised` invoice that already carries an `erp_doc_no`.
  Matching UI gates on `InvoiceDetailPage` (`canPost`, `canQuery`) and a Withdraw action on
  `PaymentRequestDetailPage`.
- **Verified:** `PaymentRequestWithdrawalTest` (7 tests) reconstructs the production state and covers
  the guard, the withdrawal, the loss of Mark Paid, the role restriction, and the full recovery route
  (withdraw → query → correct the currency to USD → re-post → payable again).
- **Follow-up (same day):** the local repro (INV-10732 in PRF-2026-00009) showed the PRF also held a
  perfectly good invoice (INV-65479), which whole-PRF withdrawal would have dragged back through
  approval. Added two finer-grained finance/admin paths, neither needing approver sign-off:
  **in-place correction** of an invoice held by a PRF (`InvoiceController::update` +
  `Invoice::isCorrectableInPlace`), refusing a currency change or a higher total; and
  **`POST /api/invoices/{invoice}/release`** (`PaymentRequestService::releaseInvoice`) to return one
  invoice while the rest of its PRF stays approved, withdrawing the PRF only if nothing is left.
  `PaymentRequest::syncTotal()` keeps the request total honest in both cases.
  Verified by `InvoiceInPlaceCorrectionTest` (10 tests) built on the local PRF-2026-00009 shape.

## [2026-08-05] Same-named vendors were selected together on invoice entry
- **Symptom:** Selecting either of two vendors named `Al Noor Logistics` highlighted both entries
  despite their different vendor codes; credit days and PDF supplier codes could also resolve to
  the wrong master record.
- **Cause:** The invoice form and database used `vendor_name` as the vendor identity.
- **Fix:** Invoices now link to vendors by `vendor_id`, retain `vendor_name` as a snapshot, and the
  form uses vendor IDs as autocomplete values. Unique legacy names are backfilled automatically.
- **Verified:** Added a feature test submitting an invoice against the second of two same-named
  vendors and asserting the saved relationship and returned vendor code.

## [2026-08-04] Code-uniqueness migration failed after manual index removal
- **Symptom:** Production migration failed with MySQL error 1091 while dropping
  `vendors_name_unique` after that index had already been removed manually.
- **Cause:** The migration assumed the original name indexes always existed and that the new code
  indexes never existed.
- **Fix:** The migration now inspects the live table indexes, conditionally drops unique name
  indexes, and conditionally creates unique vendor/customer code indexes.
- **Verified:** Added a regression test that removes the code indexes from a schema where the name
  indexes are already absent, reruns the migration, and verifies the intended final indexes.

## [2026-07-31] User editing rejected master-data departments
- **Symptom:** Editing a user returned `The selected department is invalid` for a department shown
  in the UI.
- **Cause:** The UI loaded active departments from the database, while `UserController` still
  validated against the legacy static `PAF_DEPARTMENTS` configuration.
- **Fix:** User create/update validation now checks the active Departments master-data table.
- **Verified:** Added an import-to-user-edit regression test and ran the endpoint smoke tests.

## [2026-07-21] PDF approval arrows were vertically misaligned
- **Symptom:** Approval-flow arrowheads did not sit on the connector line between signature boxes.
- **Cause:** A font glyph was used as the arrowhead, so Dompdf's text baseline shifted it away from
  the connector's center.
- **Fix:** Replaced the glyph styling with a CSS border triangle and added the logo embedded in the
  approved Excel PAF template to the PDF header.
- **Verified:** Rendered the generated landscape PDF to PNG for visual inspection and ran the full
  PHPUnit suite.

## Format

Add newest first:

```
## [YYYY-MM-DD] Short title
- **Symptom:** what was observed
- **Cause:** root cause
- **Fix:** what changed (commit / files)
- **Verified:** how it was confirmed (test, manual steps)
```
