# Change Request

## Subject

Merge Vendor PDFs That Currently Fall Out of the Payment Approval Form

---

## Executive Summary

When a Payment Approval Form is produced, the supporting documents are merged into it so the approver reads one continuous file. Some vendor PDFs cannot be merged. They are not lost — the system falls them back to a list of clickable links on a final page — but the approver has to leave the form, open each one separately, and come back.

Two causes account for the failures, and both are limitations of the free PDF component the platform uses rather than faults in the documents. The first is **encryption**: most "protected" vendor invoices carry permissions-only encryption, which opens without a password in any normal viewer but which the component refuses outright. The second is **newer PDF compression**: files saved as PDF 1.5 or later can use a storage format the free component cannot read. Both are common in documents produced by vendor accounting systems.

This change adds an automatic repair step. When a document fails to merge, the platform converts it into an older, plain PDF form and tries once more. Only then does it fall back to the link list. The conversion is a format change only — the same pages, the same content, the same document. Nothing is altered in the original: the file on record is untouched, and the repair happens in a temporary copy used purely to build the form.

The cost is a new dependency: a small, free, widely used command-line tool (`qpdf`) must be installed on the server. This is the only material consideration in the change, and the reason it is classified as infrastructure work. If the tool is missing or fails, the platform behaves exactly as it does today — the document falls back to the link list — so an incomplete installation degrades quietly rather than breaking form generation.

The expected outcome is that approvers see the whole payment pack in one document far more often, which is the point of merging attachments at all. On a real example examined during analysis, a request with three attachments had two fall out; both would merge after this change.

---

## Business Reason for Change

**Approvers are reading a form that is missing its evidence.** The PAF exists so the person authorising a payment sees the request and its supporting documents together. When attachments drop to a link list, that purpose is partly defeated — the approver either opens each file separately, or approves without reading them. The second is the real risk.

**The failures are not rare or exotic.** Permissions-only encryption is what vendor accounting systems apply by default when they "protect" an invoice, and PDF 1.5+ is fifteen years old and is what most modern software writes. A live request examined during analysis had two of its three attachments fall out, for one cause each.

**Nothing about the documents is wrong.** Both files open normally in any PDF reader. The limitation is in the free component used to assemble the form, so the business is losing usability to a technical constraint rather than to a data problem.

**The fallback works but is not what was intended.** The link page is a safety net, added so a difficult file is never a dead end. It was not meant to carry the everyday case.

---

## Affected Business Areas

**Departments and teams**

- Approvers at every level — the direct beneficiaries
- Finance, who assemble payment requests and field questions about missing attachments
- IT / infrastructure, who must install and maintain the new tool
- Internal Audit, for whom a complete assembled record is easier to review

**Business processes**

- Payment Approval Form generation
- Payment approval and review
- Document retention and audit

**Users**

- Approvers see more complete forms; no change to how they work
- Finance and submitters see no change at all — uploads, limits and file types are untouched

**Reports and documents**

- The Payment Approval Form itself: more attachments inline, a shorter or absent *Additional Documents* page
- No figures, totals or reports change

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** Forms generate correctly today and every document remains reachable. This improves how completely the form assembles, not whether it works.

### Workaround Availability

**Assessment:** Yes

**Justification:** The affected documents are listed as links on the final page and can be opened individually. That is inconvenient rather than blocking.

### Operational Impact

**Assessment:** No unacceptable impact from delay

**Justification:** Delay means approvers keep opening some attachments separately. There is a mild control risk in that an approver may skip a document they have to click through to reach, but no financial loss, regulatory breach or outage.

### Timeline Constraints

**Assessment:** No

**Justification:** No deadline, audit finding or regulatory date attaches to this. The standard change process applies, and the server-side installation makes a planned window preferable.

**Emergency Change Classification: No**

**Reason:** A planned improvement with a working fallback already in place. It requires a server change, which is a reason to schedule it properly rather than to rush it.

---

## Risk Assessment

### Risk Level

**Medium**

The change is small in the application and safe by construction — it only runs where the current behaviour has already failed. The Medium rating is driven entirely by the new server dependency and by the fact that the platform will be running an external program over files that arrive from outside the business.

### Risks Identified

**Business risks**

1. **The tool is not installed, so nothing improves.** The application degrades quietly to today's behaviour, which is safe — but it also means a deployment could be declared done while delivering no benefit at all, and nobody would notice from the screen.
2. **A repaired document is not identical to the original.** The conversion preserves pages and content but rewrites the file's internal structure. Interactive elements a PDF can carry — form fields, annotations, digital signatures — may not survive. For a scanned or printed vendor invoice this is immaterial; for a digitally signed document it is not.

**Operational risks**

3. **Form generation gets slower.** The repair adds a step, and it runs while somebody is waiting for a download. A large or damaged file could make that wait noticeable.
4. **A hung conversion could block the request.** An external program that never returns would hold the web request open until it timed out.
5. **Temporary files accumulate.** The repair works on a copy. If those copies are not removed, disk fills over time.
6. **Two environments drift apart.** If the tool is on production but not on the test server, forms will assemble differently in the two places and a tester may not be able to reproduce what an approver sees.

**Security and compliance risks**

7. **Running an external program over untrusted input.** The documents come from outside the business. The command must be constructed so that a crafted filename can never be interpreted as part of it, and a malformed document must fail the conversion rather than affect the server.
8. **Stripping encryption.** The repair removes permissions-only protection so the pages can be read. This is applied to a temporary working copy for the sole purpose of assembling the form, and never to the stored document — but it should be a conscious, recorded decision rather than a side effect.
9. **A password-protected document must stay protected.** Where a file genuinely requires a password, the repair must fail and the document must fall back to the link list, exactly as today.

### Risk Mitigation Plan

| # | Mitigation |
|---|------------|
| 1 | Make the installation an explicit, checked step in the deployment notes, and verify after release by generating a form from a known-affected request. Record in the system log when a repair is attempted and when the tool is absent, so the situation is visible without reading the screen. |
| 2 | Attempt the normal merge **first** and repair only on failure, so a document that already works is never rewritten. Accept that the merged copy is a rendering: the original stays on record, unmodified, and remains the file people download. |
| 3 | Repair only the documents that failed, never the whole set. |
| 4 | Apply a strict time limit to each conversion; on timeout, abandon it and fall back to the link list. |
| 5 | Write working copies to the system temporary area and delete them as soon as the merge is done, including when it fails. |
| 6 | Document the dependency in the deployment notes and state it as a requirement for any environment that generates forms. |
| 7 | Build the command as an explicit list of arguments rather than as a line of text, so no part of a filename can be read as an instruction. Treat any non-zero result as a failed repair and fall back. |
| 8 | Limit the repair to permissions-only encryption, on a temporary copy, for form assembly alone. Recorded here as the decision, and stated in the deployment notes. |
| 9 | Do not supply, guess or store passwords. A document needing one fails the repair and falls back, as now. |

---

## Expected Business Impact

### Positive Impact

- **More complete approval packs.** Approvers see the supporting documents inside the form rather than as links to chase.
- **Better-evidenced approvals.** A document that is in front of someone is more likely to be read than one behind a click.
- **Fewer questions to Finance** about attachments that "aren't in the PDF".
- **A shorter or absent link page**, which today is where the most common vendor file formats end up.

### Potential Negative Impact

- Form generation takes marginally longer where a repair is needed.
- A new component must be installed and kept current on every environment that generates forms.
- Interactive elements in a repaired document may not survive into the merged copy — immaterial for invoices, relevant for digitally signed files.

### User Impact

**Approvers:** the form is more complete. Nothing to learn, nothing to do differently.

**Finance and submitters:** no change. Upload limits, accepted file types and every screen stay as they are.

**IT:** one small, free, widely packaged tool to install and keep patched.

### Reporting Impact

None. No figure, total or report is affected.

### Compliance Impact

Positive on balance: the evidence behind an approval is more likely to be assembled into the record the approver actually reads. The point to record is that permissions-only encryption is removed from a temporary working copy in order to render the pages — the stored document is never altered, and a genuinely password-protected file is never opened.

---

## Implementation Overview

1. **Try as now.** Each attachment is merged the way it is today. Documents that already work are untouched and take no new code path.

2. **Repair on failure.** Where a document fails, the platform makes a temporary copy converted to an older, plain PDF form — removing permissions-only protection and the newer compression that the free component cannot read — and tries the merge once more.

3. **Fall back as now.** If the repair is unavailable, times out, or does not help, the document goes to the *Additional Documents* link page exactly as it does today. This is what makes the change safe: the worst outcome is the current behaviour.

4. **Clean up.** Temporary copies are deleted once the form is built, including when it fails.

5. **Install and document.** The tool is added to the deployment notes as a requirement, with the absent case logged so a missed installation is discoverable.

### Open Points for Decision

1. **Digitally signed attachments.** A repaired copy may not carry a signature through into the merged form. The original keeps its signature and remains downloadable. Confirm this is acceptable; if the business needs signatures visible inside the form, those documents should be excluded from repair and left on the link page.
2. **Where the tool is installed.** Production is the priority; the test environment should match, or testers will not see what approvers see.

---

## Rollout Plan

1. **Development** — Build the repair-and-retry step behind the existing failure path.
2. **Internal Validation** — Verify against the two known-affected documents (one encrypted, one newer-format), and confirm that a request with no problem files produces a byte-comparable form.
3. **QA Verification** — Execute the Test Case document, with particular attention to the fallback: with the tool removed, with a corrupt file, with a password-protected file, and with a deliberately slow one.
4. **User Acceptance Testing** — Finance and an approver confirm that a previously affected request now assembles completely and reads correctly.
5. **Production Deployment** — Install the tool, then release, in a scheduled window outside the month-end payment peak. Verify immediately afterwards by regenerating a form from a known-affected request.
6. **Post Deployment Monitoring** — Two weeks watching form generation times, repair successes and failures in the log, and temporary-file cleanup. Review with Finance at the end.

---

## Backout Plan

1. **Suspend new functionality** — Disable the repair step by configuration; the platform immediately returns to today's behaviour with no redeployment.
2. **Restore previous application state** — Roll back the release if the configuration switch is not sufficient.
3. **Restore backups if required** — Not expected: no stored document, invoice or payment record is modified by this change, so there is no data to restore.
4. **Validate business operations** — Generate a form and confirm it assembles, with unmergeable documents on the link page as before.
5. **Notify stakeholders** — Inform Finance and approvers that attachments have returned to the link page, and why.

---

## Approval Requirements

### Requestor

Name: ______________________  Signature: ______________________  Date: ____________

### Department Manager — Finance

Name: ______________________  Signature: ______________________  Date: ____________

### IT Manager

Name: ______________________  Signature: ______________________  Date: ____________

### Business Owner — Payment Approval Process

Name: ______________________  Signature: ______________________  Date: ____________

### CAB Approval

Required: **Yes** — the change introduces a server dependency and runs an external program over files received from outside the business.

Name: ______________________  Signature: ______________________  Date: ____________

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 18 September 2026

**Source:** Investigation of PAF-2026-00032, where two of three attachments failed to merge

**Risk Rating:** Medium

**Emergency Change:** No

**Analysis Confidence:** High (90%)

| Dimension | Confidence | Note |
|-----------|-----------|------|
| Analysis Confidence | High (90%) | Both failures reproduced and their causes confirmed against the files themselves. |
| Affected Features Confidence | High (95%) | Form assembly is the only affected area. |
| Business Impact Confidence | Medium-High (80%) | The benefit is clear; how often it applies across all vendors is extrapolated from one request and the known prevalence of the two causes. |
| Security Impact Confidence | Medium-High (82%) | The risks of running an external program over untrusted input are well understood and mitigable; the encryption decision needs recording. |
| Risk Assessment Confidence | High (88%) | The main uncertainty is operational — whether the dependency is installed consistently across environments. |

---

## Technical Analysis Appendix

*Included only where it explains risk, effort or dependency.*

### Verified after implementation

Regenerated PAF-2026-00032 with `qpdf` 12.4.1 installed:

| | Before | After |
|---|---|---|
| Pages | 3 | 6 |
| Attachments merged inline | 1 of 3 | **3 of 3** |
| *Additional Documents* link page | present | absent |
| Stored documents | — | **unchanged** (checksums identical before and after) |
| Temporary working copies left behind | — | **none** |

### The two failures, confirmed

On PAF-2026-00032, with three attachments:

| Document | Format | Result |
|---|---|---|
| `rate.pdf` | PDF 1.4, plain | merges |
| `JobProfit Document.pdf` | PDF 1.6, object streams + cross-reference streams | free FPDI parser cannot read this structure |
| `inv.17486.pdf` | PDF 1.4, encrypted | FPDI refuses encrypted files outright |

Both are limitations of the **free** FPDI parser, not of the documents, and both are already caught and diverted to the link page.

### Affected Systems

| Area | Nature of change | Effort |
|------|------------------|--------|
| PDF assembly | A repair-and-retry step inside the existing failure handler | Low–Medium |
| Configuration | Tool location and time limit, with the feature switchable off | Low |
| Deployment | A new server package, plus a check that it is present | Low, but must not be skipped |

### Dependencies and Notes

- **`qpdf`** — free, open source, packaged for every mainstream Linux distribution and available for Windows. Used to rewrite a PDF into an older, uncompressed, unencrypted form. It is a format converter, not a renderer: it does not rasterise, so text stays text and file size stays comparable.
- **The commercial alternative** is setasign's paid FPDI PDF-Parser, which handles the compressed-structure case. It was not chosen because it is licensed per application and does not address the encryption case, which is the more common of the two here.
- **The application already fails safely.** The repair is added inside the existing `catch`, so a document that merges today takes an identical path, and a repair that cannot run leaves behaviour exactly as it is now.
- **The command is built as an argument list**, not a shell string, so a filename can never be interpreted as part of the command.
- **Nothing is written back to storage.** The repaired copy lives in the temporary area for the duration of the merge and is deleted afterwards.
