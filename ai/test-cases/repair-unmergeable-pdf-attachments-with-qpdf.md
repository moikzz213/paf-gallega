# Test Cases

## Related Change Request

| | |
|---|---|
| **Change Request Subject** | Merge Vendor PDFs That Currently Fall Out of the Payment Approval Form |
| **Change Request Filename** | `ai/change-requests/repair-unmergeable-pdf-attachments-with-qpdf.md` |
| **Risk Rating** | Medium |
| **Emergency Change** | No |
| **Implementation Date** | 18 September 2026 |
| **Source** | Investigation of PAF-2026-00032 — two of three attachments failed to merge |

---

## Objective

Confirm that encrypted and newer-format vendor PDFs now merge into the Payment Approval Form, and —
more importantly — that every way the repair can fail still leaves the form generating correctly with
the document on the *Additional Documents* page, exactly as it behaves today.

---

## Scope

**In scope**

1. Repairing an attachment that fails the normal merge, then retrying it
2. Falling back to the link page when the repair is unavailable, times out, or does not help
3. Temporary working copies and their cleanup
4. The configuration switch and the tool's location
5. Logging that makes a missing installation discoverable

**Out of scope**

- Changing what may be uploaded (types, sizes, counts are untouched)
- Modifying stored documents — the original is never rewritten
- Opening password-protected documents
- Any change to invoices, payment requests, approvals or figures

---

## Test Scenarios

### Happy Path Tests

| ID | Scenario | Steps | Expected Result |
|----|----------|-------|-----------------|
| HP-01 | Encrypted attachment | Generate the PAF for a request holding a permissions-only encrypted PDF (`inv.17486.pdf`) | The document's pages appear inline in the form; it is **not** on the link page |
| HP-02 | Newer-format attachment | Generate the PAF for a request holding a PDF 1.6 with object/cross-reference streams (`JobProfit Document.pdf`) | Pages appear inline; not on the link page |
| HP-03 | The reported request end to end | Generate the PAF for PAF-2026-00032 | All three attachments inline; no *Additional Documents* page |
| HP-04 | Ordinary attachments are untouched | Generate a PAF whose attachments all merge today | Identical output to before the change — no repair attempted, nothing logged |
| HP-05 | Mixed set | A request with one working, one encrypted, one non-PDF (e.g. .xlsx) | The first two inline; the spreadsheet still on the link page |
| HP-06 | Content is intact | Compare a repaired document's pages against the original | Same page count, same visible content, text still selectable (not rasterised) |
| HP-07 | Repair is logged | Generate a PAF that triggers a repair, then read the log | An entry records that the document was repaired and merged |

### Negative Tests

| ID | Scenario | Steps | Expected Result |
|----|----------|-------|-----------------|
| NG-01 | Tool not installed | Point the configured path at something that does not exist, generate a PAF | Form generates; affected documents on the link page; a log entry states the tool is unavailable. **No error reaches the user.** |
| NG-02 | Feature switched off | Disable the repair by configuration | Behaviour is exactly as before the change |
| NG-03 | Password-protected document | Attach a PDF that needs a password to open, generate the PAF | Repair fails, document falls to the link page, form still generates. No password is guessed or stored |
| NG-04 | Corrupt file | Attach a truncated or malformed PDF | Repair fails cleanly, document on the link page, form generates |
| NG-05 | Repair times out | Force the conversion to exceed the time limit | Conversion abandoned, document on the link page, form generates within a reasonable time |
| NG-06 | Repair succeeds but merge still fails | A file the tool rewrites but FPDI still refuses | Falls back to the link page; no exception surfaces |
| NG-07 | Tool returns an error code | Force a non-zero exit | Treated as a failed repair, falls back |
| NG-08 | Missing file on disk | Record present, file absent | Existing behaviour unchanged — skipped with a warning |

### Security Tests

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| SC-01 | Filename with shell metacharacters | Attach files named with `;`, `&&`, `$(...)`, quotes, spaces and non-ASCII. The command is built as an argument list, so nothing is interpreted — the file either merges or falls back, and **no part of the filename is executed** |
| SC-02 | Path traversal in the stored path | A crafted path cannot make the tool read outside the documents area |
| SC-03 | The stored document is never modified | After generating a PAF, the original file's bytes and checksum are unchanged |
| SC-04 | Temporary copies are removed | After a successful generation, and after a failed one, no working copies remain |
| SC-05 | Decryption is confined | The repaired copy exists only for the merge; nothing decrypted is written to the documents area or served to a user |
| SC-06 | No new access | Visibility of documents and PAFs is unchanged — no new route, no new permission |
| SC-07 | Malformed input does not destabilise the server | A deliberately malformed PDF causes a failed repair, not a crash, hang or memory exhaustion |

### Regression Tests

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| RG-01 | PAF for a fully approved request | Unchanged apart from more attachments inline |
| RG-02 | PAF while in approval | Still generates, still watermarked `PENDING APPROVAL` |
| RG-03 | Rejected / withdrawn PAF | Still stamped correctly |
| RG-04 | Images as attachments | Still rendered one per page |
| RG-05 | Non-mergeable types (Excel, Word) | Still listed on the *Additional Documents* page with working links |
| RG-06 | Request with no attachments | Sheet only, as before |
| RG-07 | The approval page's inline PAF | Still loads and displays |
| RG-08 | Advance payment banner and line tags | Unchanged |
| RG-09 | Line-table density and approval-block spacing | Unchanged by this change |
| RG-10 | PAF download from the app | Unchanged |
| RG-11 | Generation time for a typical request | No material regression where no repair is needed |

### User Acceptance Tests

| ID | Scenario | Acceptance Criterion |
|----|----------|----------------------|
| UA-01 | Approver reads a previously affected request | The approver confirms the supporting documents are inside the form and readable |
| UA-02 | Finance regenerates PAF-2026-00032 | Finance confirms all three attachments are inline |
| UA-03 | Legibility of repaired documents | A repaired invoice is as readable as the original |
| UA-04 | Wait time is acceptable | Generating a form with several repaired attachments completes in a time the user finds reasonable |

---

## Expected Results

1. Permissions-only encrypted PDFs merge inline.
2. PDF 1.5+ documents using object/cross-reference streams merge inline.
3. Documents that already merged are unaffected and take no new path.
4. Every failure mode — tool missing, switched off, timeout, corrupt file, password-protected, non-zero exit — falls back to today's behaviour, and the form still generates.
5. No stored document is modified; no temporary copy survives the request.
6. No filename can influence the command that is run.
7. Nothing about upload rules, visibility, permissions, invoices, approvals or figures changes.

---

## Pass/Fail Criteria

**Pass** — all Happy Path, Negative and Security cases pass; all Regression cases show no change in behaviour; UAT accepted.

**Fail** — any of the following, each sufficient on its own:

- PAF generation fails, errors, or hangs in any scenario, including every failure mode above.
- A stored document is modified.
- A temporary copy survives the request.
- Any part of a filename is interpreted as a command.
- A password-protected document is opened, or a password is stored.
- Any regression case shows changed behaviour.

**Conditional pass** — a document that still cannot be merged after repair, provided it falls back cleanly and the cause is logged and understood.

---

## Test Execution Checklist

- [ ] HP-01 … HP-07 executed and evidenced
- [ ] NG-01 … NG-08 executed and evidenced
- [ ] SC-01 … SC-07 executed and evidenced
- [ ] RG-01 … RG-11 executed and evidenced
- [ ] UA-01 … UA-04 accepted by the business
- [ ] Automated test suite green (`php artisan test`)
- [ ] Code formatted (`./vendor/bin/pint`)
- [ ] Tool installed and verified on production **and** on the test environment
- [ ] PAF-2026-00032 regenerated after deployment as the acceptance check
- [ ] `/ai` documentation updated, including the deployment dependency
- [ ] Open points on the CR resolved and the CR updated to match what was built

---

## Sign-Off

### QA Lead

Name: ______________________  Signature: ______________________  Date: ____________

Result: Pass / Conditional Pass / Fail

### Business Owner — Payment Approval Process

Name: ______________________  Signature: ______________________  Date: ____________

### UAT Sign-Off — Finance

Name: ______________________  Signature: ______________________  Date: ____________
