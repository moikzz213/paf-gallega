# Bugs Fixed

A running log of resolved bugs, so fixes aren't re-litigated and regressions are traceable.

> None recorded yet — this file was created during LIFT project initialization
> (see [../decisions/ADR-001-project-initialization.md](../decisions/ADR-001-project-initialization.md)).

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
