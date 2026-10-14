# Change Request

## Subject

Add a supporting-document checklist to invoice submission for the Freight Forwarding department

---

## Executive Summary

When an invoice is submitted today, every department uses the same single "Supporting Documents"
box. Files are attached as one undifferentiated list, with nothing to say what each file is.
Freight Forwarding payments depend on a specific set of evidence: the vendor invoice, the job sheet,
the job order, the buy and sell rates, Finance's approval and, when the margin is below 10%, the
approval email for that margin. Finance and the approvers currently have to open each file to find
out which of these is present and which is missing.

This change adds a **document checklist** to the invoice form. It appears only when **Freight
Forwarding** is selected as the submitting department. The checklist lists eight document types:
Vendor Invoice, Job Sheet, Job Order / Checklist, Buy Rate, Sell Rate, Finance Approval, Approval
Email for Margin Less Than 10%, and Other. The user attaches files against the item they belong to.
Each item accepts one or more files, with a "+" (Add More) button to add another. "Other" requires
the user to name the document. Before submitting, the user can view, download and remove any file.

The first six items are **mandatory**: a Freight Forwarding invoice cannot be submitted until each
of them has at least one file. The margin approval email is optional, because it applies only when
the margin is below 10%. "Other" is optional.

The checklist labels carry through to everywhere the documents are seen afterwards: the invoice
page, the approval page and the merged Payment Approval Form (PAF). Finance and the approvers can
then see at a glance what evidence was supplied.

The expected outcome is a complete, consistently organised evidence set on every Freight Forwarding
payment, faster review, and fewer queries caused by missing documents. Other departments are not
affected.

---

## Business Reason for Change

**Business challenge.** Freight Forwarding is one of the two busiest submitting departments, with
about a quarter of all invoices recorded so far. Its payments are approved against a recognised
set of documents, but the system neither records which file is which nor checks that the set is
complete. Reviewers have to open every attachment to confirm it. A missing buy rate or job sheet is
easy to overlook, and is often caught only when an approver raises a query.

**Operational need.** The Freight Forwarding team and Finance want the submission to follow the
checklist they already work to: the evidence should arrive organised, and incomplete submissions
should be stopped at the start rather than discovered at approval.

**Control.** Requiring the core documents and labelling each one when it is uploaded makes the
record complete and self-describing. That helps when a payment is reviewed later, for example in an
audit or a margin review, without relying on file names chosen by the submitter.

---

## Affected Business Areas

| Area | Impact |
|------|--------|
| **Freight Forwarding submitters** | See the checklist on the invoice form when Freight Forwarding is selected. They attach files against each item, and cannot submit until the six mandatory items each have a file. |
| **Other departments** | No change. They keep the existing single Supporting Documents section, with nothing mandatory. |
| **Finance** | Sees the document types on the invoice page. Their own corrections and later uploads use the same checklist on Freight Forwarding invoices. |
| **Approvers** | See the documents labelled by type on the request page, the emailed approval page and the PAF. |
| **Payment Approval Form (PAF)** | Invoice documents are labelled with their checklist type where they are merged or listed. |
| **Audit trail** | Each upload and removal is recorded with its document type. |
| **Reports** | No change in this request. |

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** Freight Forwarding invoices are submitted and paid today. Documents are attached,
only without labels or a completeness check.

### Workaround Availability

**Assessment:** Yes

**Justification:** Submitters can name their files to show what they are, and reviewers can open and
check each attachment. It is slower and inconsistent, but it works.

### Operational Impact

**Assessment:** No

**Justification:** Delaying the change prolongs the manual checking but causes no new financial or
reputational damage.

### Timeline Constraints

**Assessment:** No

**Justification:** No regulatory or contractual deadline applies. The change can follow the normal
process.

**Emergency Change Classification:** No

**Reason:** This is a process improvement to how evidence is collected for one department. It
follows the standard change process.

---

## Risk Assessment

### Risk Level

**Medium**

The change does not alter amounts, approvals, routing or who can see what, and the data change is
additive. It is rated Medium rather than Low because six items are mandatory: for about a quarter of
all invoices, submission is now blocked until the full document set is attached.

### Risks Identified

**Business risks**

1. **A required document is not available at submission time.** For some jobs a mandatory document
   (for example Finance Approval, or a sell rate not yet agreed) may not exist when the vendor
   invoice needs to be submitted. The submission is then blocked, and the payment is delayed.
   *Mitigation:* brief the Freight Forwarding team before go-live, monitor refused submissions
   closely in the first weeks, and design the list of mandatory items so it can be relaxed quickly
   if a genuine gap appears.
2. **The current document limit is too low for a full checklist.** An invoice accepts at most 10
   files today. Six mandatory items, plus more than one file under some of them, can exceed that.
   *Mitigation:* the limit is raised to 30 files for Freight Forwarding invoices.
3. **Changing the department after attaching files.** A submitter may attach checklist files and
   then change the department away from Freight Forwarding. *Mitigation:* the files are kept and
   moved into the ordinary Supporting Documents list, with a warning, rather than silently lost.

**Operational risks**

4. **Existing Freight Forwarding invoices have no document types.** About 240 invoices already on
   record carry unlabelled documents. *Mitigation:* they are shown as "Unclassified" and stay exactly
   as they are. No back-filling, because nothing on record reliably says which file is which.
   Invoices submitted before go-live are **exempt** from the mandatory check, so correcting one of
   them is never blocked by the new rule.
5. **Files filed under the wrong item, or everything under "Other".** A submitter could satisfy the
   check with the wrong file, or file a required document as "Other". *Mitigation:* the check
   ensures the set is present, but not that each file is correct. Reviewers still verify the
   content, and "Other" does not count towards any mandatory item.
6. **Larger submissions and PAFs.** More files per invoice means larger uploads and a longer merged
   PAF. *Mitigation:* the per-file size limit (10 MB) is unchanged, and the PAF already falls back
   to listing a file as a link when it cannot be merged.

**Security risks**

7. **New file uploads.** *Mitigation:* the checklist uses the same accepted file types, size limit,
   storage, access rules and audit as today's uploads. No new type of file is accepted. Who can see
   a document is unchanged.

### Risk Mitigation Plan

- Enforce the mandatory items on the server as well as the form, so they cannot be bypassed.
- Raise the document limit to 30 for Freight Forwarding invoices.
- Exempt invoices submitted before go-live from the mandatory check.
- Preserve files when the department is changed, and warn the user.
- Keep the existing accepted file types and size limit.
- Record the document type in the audit trail for every upload and removal.
- Monitor refused Freight Forwarding submissions daily for the first two weeks.

---

## Expected Business Impact

### Positive Impact

- **Complete evidence.** Every new Freight Forwarding payment arrives with the full core document
  set.
- **Faster review.** Finance and the approvers see which documents were supplied without opening
  each file.
- **Fewer queries.** Missing evidence is caught at submission rather than at approval.
- **Consistent records.** Every Freight Forwarding payment carries its evidence organised the same
  way.
- **Margin control.** The approval email for a margin under 10% has its own place, so its presence
  or absence is clear.

### Potential Negative Impact

- Freight Forwarding submitters spend more time preparing a submission, and cannot submit until the
  core documents are ready.
- Other departments see no change, so the two forms differ. Users who submit for several
  departments will need to know about the difference.

### User Impact

| User | Before | After |
|------|--------|-------|
| Freight Forwarding submitter | One list of files, nothing required | A checklist of eight items, files attached under each, "+" to add more, "Other" requires a name. Six items are mandatory before submitting. |
| Other submitters | One list of files | Unchanged |
| Finance | Opens files to identify and check them | Sees each file's type. Uses the checklist when correcting a Freight Forwarding invoice. |
| Approver | Opens files to identify them | Sees each file's type on the request page, the approval page and the PAF |

### Reporting Impact

None in this request. A "checklist completeness" column in the invoice report is a possible
follow-up.

### Compliance Impact

- Strengthens the evidential record for Freight Forwarding payments: the core documents are
  guaranteed present on every new submission.
- No change to access control, approval authority or segregation of duties.

---

## Checklist Rules

| # | Document type | Required for Freight Forwarding |
|---|---------------|-------------------------------|
| 1 | Vendor Invoice | **Mandatory** |
| 2 | Job Sheet | **Mandatory** |
| 3 | Job Order / Checklist | **Mandatory** |
| 4 | Buy Rate | **Mandatory** |
| 5 | Sell Rate | **Mandatory** |
| 6 | Finance Approval | **Mandatory** |
| 7 | Approval Email for Margin Less Than 10% | Optional (applies only when the margin is below 10%) |
| 8 | Other (Specify Document Name) | Optional. A document name is required for each file. Does not count towards any mandatory item. |

- "Mandatory" means at least one file under that item. Each item accepts more than one file, up to
  the invoice's limit of 30.
- The check applies when a Freight Forwarding invoice is submitted, and whenever an invoice that is
  still editable is saved with Freight Forwarding as its department. It also applies when an invoice
  is changed to Freight Forwarding. Invoices submitted before go-live are exempt.
- Other departments have no mandatory documents and keep the 10-file limit.

---

## Decisions Recorded

Confirmed by stakeholders on 2026-10-14.

1. **Mandatory items.** For Freight Forwarding only, every checklist item is mandatory except the
   Approval Email for Margin Less Than 10%, which is optional. "Other" is also optional, but each
   "Other" file needs a document name.
2. **Document limit.** 30 files per invoice for Freight Forwarding. 10 for other departments.
3. **General Supporting Documents section.** Replaced by the checklist for Freight Forwarding.
   Anything else goes under "Other" with a name, so every file has a type.
4. **Department changed after attaching.** The files are kept and moved to the ordinary Supporting
   Documents list without a type, and the user is told.
5. **Later uploads and corrections.** On a Freight Forwarding invoice, Finance corrections and late
   uploads on advance payments use the same checklist, so every document has a type.
6. **Reporting.** No report change in this request.
7. **"View".** Opens the file in a new browser tab, both before submission (from the user's own
   computer) and afterwards (from the system).
8. **Existing invoices.** Invoices submitted before go-live are exempt from the mandatory check, so
   correcting one is never blocked by the new rule.

---

## Implementation Overview

1. Add a document checklist to the invoice form, shown only when Freight Forwarding is the
   submitting department, with the eight document types and their mandatory markers.
2. Allow one or more files under each item, with a "+" (Add More) control, and require a name for
   each "Other" file.
3. Let the user view, download and remove files before submitting, and on an invoice that is still
   editable.
4. Stop a Freight Forwarding submission that is missing any of the six mandatory items, both on the
   form and on the server, naming the items still missing.
5. Record each document's type (and the name given for "Other") with the document, and show it on
   the invoice page, the request page, the emailed approval page and the PAF.
6. Raise the document limit to 30 for Freight Forwarding invoices.
7. Keep existing documents as "Unclassified", exempt invoices submitted before go-live from the
   check, and leave other departments' forms unchanged.
8. Record the document type in the audit trail for every upload and removal.

---

## Rollout Plan

1. **Development:** Build the checklist, the mandatory check, typed storage and display, as recorded
   under *Decisions Recorded*. Add automated tests for: the checklist and the check applying only to
   Freight Forwarding, each mandatory item being enforced, the margin email and "Other" being
   optional, "Other" requiring a name, multiple files per item, the 30-file limit, and the exemption
   for older invoices.
2. **Internal Validation:** Verify that other departments' forms are unchanged and nothing is
   mandatory for them. Verify that existing documents still open and merge into the PAF, that
   correcting a pre-go-live Freight Forwarding invoice is not blocked, and that a department change
   keeps the attached files.
3. **QA Verification:** Submit Freight Forwarding invoices with every document type and several files
   per item. Try to submit with each mandatory item missing in turn. Confirm the types appear on the
   invoice, approval page and PAF, and that the accepted file types and size limit are unchanged.
4. **User Acceptance Testing:** A Freight Forwarding submitter completes a full checklist, including
   "Other" with a name, removes and re-adds a file before submitting, and confirms an incomplete set
   is refused with a clear message. A Finance user and an approver confirm they can identify every
   document by type without opening it.
5. **Production Deployment:** Back up the database before release. Deploy in a scheduled window
   outside the month-end peak. Brief the Freight Forwarding team in advance on the six mandatory
   documents, so submissions are not unexpectedly blocked.
6. **Post Deployment Monitoring:** For four weeks, review refused Freight Forwarding submissions
   daily in the first two weeks and weekly after that. Also watch for files filed under "Other" that
   belong to a named type, and for approval queries about missing documents. Review with Freight
   Forwarding and Finance at the end of the period, including whether any mandatory item should be
   relaxed.

---

## Backout Plan

1. **Suspend new functionality:** First, relax the mandatory check so no submission is blocked. If
   needed, hide the checklist entirely so Freight Forwarding uses the ordinary Supporting Documents
   section again. Documents already uploaded stay attached to their invoices.
2. **Restore previous application state:** Roll back the release. The document type recorded on
   newer documents is simply no longer shown.
3. **Restore backups if required:** Only if data integrity is affected. Documents uploaded after
   deployment exist only in the live system and must be preserved before any restore.
4. **Validate business operations:** Confirm invoices can be submitted with documents, and that every
   PAF still opens with its documents merged.
5. **Notify stakeholders:** Tell the Freight Forwarding team and Finance that the checklist is
   relaxed or withdrawn, and how to label files in the meantime.

---

## Approval Requirements

### Requestor

Name: ______________________  Signature: ______________________  Date: ____________

### Department Manager — Freight Forwarding

Name: ______________________  Signature: ______________________  Date: ____________

### IT Manager

Name: ______________________  Signature: ______________________  Date: ____________

### Business Owner — Finance (Accounts Payable)

Name: ______________________  Signature: ______________________  Date: ____________

### CAB Approval (if applicable)

Not expected to be required: the change is confined to one department's submission form and does
not affect approval or payment.

Name: ______________________  Signature: ______________________  Date: ____________

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-10-14

Decisions Recorded: 2026-10-14

Risk Rating: Medium

Emergency Change: No

Analysis Confidence: High (90%)

| Measure | Confidence |
|---------|------------|
| Analysis Confidence Score | 90% |
| Affected Features Confidence | 90% |
| Business Impact Confidence | 85% |
| Security Impact Confidence | 90% |
| Risk Assessment Confidence | 85% |

---

## Implementation Record

**Status:** Implemented 2026-10-14, as recorded under *Decisions Recorded*.

- The checklist, its required items and the 30-file limit are enforced on the server as well as the
  form. A missing item is reported by name.
- The exemption is recorded per invoice when it is submitted. Every invoice submitted from go-live
  is subject to the checklist; every invoice already on record is not. No existing record was
  rewritten.
- "View" opens a stored document in a new browser tab, through the same access rule as downloading
  it. A file not yet uploaded opens from the user's own computer.
- The department that uses the checklist and its file limit are settings, so a renamed department or
  a different limit does not need a release.
- A database backup was taken before the change was applied to the development database.

**Verification:** automated tests cover a complete submission, a refused submission naming every
missing item, each required item in turn, the optional margin email and Other, file types and
"Other" names, several files per item, the 30-file limit, other departments being unchanged, the
exemption for older invoices, removing a required document, late uploads on an advance, the audit
entry, and inline viewing (`tests/Feature/FreightForwardingDocumentChecklistTest.php`). The full
suite passes apart from four failures that predate this change. User acceptance testing per the
Rollout Plan is still to be done.

---

# Technical Analysis Appendix

Included because the mandatory check, the document limit and existing records drive the effort and
the main risk.

### Affected Systems

- **Invoice form.** The department is chosen on the form from the department master list, where
  "Freight Forwarding" exists as an active department. The checklist and the mandatory check key off
  that selection, not the submitter's own department.
- **Invoice documents.** Each document gains a type and an optional name for "Other". This is an
  additive change to the existing document records: existing documents get no type and show as
  "Unclassified". The same storage, accepted file types (PDF, Word, Excel, CSV, text, JPG, PNG, WebP)
  and 10 MB per-file limit apply.
- **Mandatory check.** Enforced on the server when an invoice is created or saved while editable.
  Documents already on the invoice and files arriving in the same save both count, so a user who
  removes a file before saving must replace it. The exemption for older invoices is based on the
  submission date relative to go-live.
- **Document limit.** Currently 10 files per invoice for every department. The busiest invoice on
  record holds 8. It becomes 30 for Freight Forwarding.
- **Display.** The invoice page, request page, emailed approval page and the merged PAF list invoice
  documents, and each gains the type label. The PAF's merge and its link fallback for files it cannot
  merge are unchanged.
- **Other upload paths.** Finance corrections, corrections during approval and late uploads on
  advance payments use the same document route, so they inherit the checklist on Freight Forwarding
  invoices. They add documents to an invoice already submitted, so the mandatory check does not
  re-run on them.

### Security Controls

- No change to who may upload, view, download or remove a document. Existing ownership, visibility
  and removal rules apply.
- The type and the "Other" name are recorded in the audit trail with each upload and removal.
