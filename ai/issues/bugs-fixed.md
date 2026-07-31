# Bugs Fixed

A running log of resolved bugs, so fixes aren't re-litigated and regressions are traceable.

> None recorded yet — this file was created during LIFT project initialization
> (see [../decisions/ADR-001-project-initialization.md](../decisions/ADR-001-project-initialization.md)).

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
