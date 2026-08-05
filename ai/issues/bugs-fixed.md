# Bugs Fixed

A running log of resolved bugs, so fixes aren't re-litigated and regressions are traceable.

> None recorded yet — this file was created during LIFT project initialization
> (see [../decisions/ADR-001-project-initialization.md](../decisions/ADR-001-project-initialization.md)).

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
