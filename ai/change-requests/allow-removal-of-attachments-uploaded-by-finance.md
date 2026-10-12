# Change Request

## Subject

Allow attachments added after posting, including those Finance adds to payment requests and posted
invoices, to be removed, while keeping the removed file and a record of the removal for audit.

---

## Executive Summary

Finance can attach supporting documents in two places: to a payment request (PAF) as a whole, and
to an invoice after it has been posted, while correcting it or while it sits in a payment request.
Neither place offers a way to take a document off again. If the wrong file is attached, a duplicate
is uploaded, or a document belongs to a different request, it stays on the record permanently. It
is shown to approvers and merged into the PAF they sign. The only remedy today is to attach the
correct file next to the wrong one and explain the mix-up in the approval comments.

This change adds a **Remove** button beside each such document. Removing a document takes it off
the screen, off the PAF and off the approvers' links straight away. Nothing is destroyed: the file
itself is kept on the server, and the removal is recorded in the record's history, showing which
file was removed, by whom and when. Evidence that approvers may already have seen is therefore
never lost.

Removal is limited to the person who uploaded the document, or an Administrator. It is allowed only
until payment is made. Once a request or invoice is paid, its documents are locked, as the rest of
the record already is.

The recommended risk rating is **Low**. Nothing is permanently deleted, every removal is audited,
the permission is narrow, and the change can be reversed.

---

## Business Reason for Change

**Operational need.** Mistakes in attaching files are routine: the wrong PDF, the same file twice,
an outdated version, or a document meant for another vendor. Today, any such mistake is permanent
on a payment request or a posted invoice. The wrong file is shown to every approver and merged into
the PAF, which then carries incorrect or confusing evidence through approval and into the archive.

**The current workaround is unsatisfactory.** Finance attaches the correct file alongside the wrong
one and explains in comments. Approvers then have to work out which file to rely on. The PAF grows
with irrelevant pages, and a misattached document, possibly another vendor's, remains in front of
people who should not be reviewing it.

**Known gap.** The platform's issue log already records that request-level documents cannot be
removed (known issue #22). It was deliberately left out when the attachment feature was built,
pending a rule that protects evidence approvers may have seen. This change supplies that rule:
remove from view, but never destroy.

---

## Affected Business Areas

### Departments and teams

- **Finance / Accounts Payable.** Can correct their own attachment mistakes.
- **Approvers.** See a cleaner, correct PAF. A removed document disappears from their view and from
  their approval links.
- **Requesters.** The same rule applies to documents they uploaded to their own invoice after it was
  posted: their corrections while a request is in approval, and the final invoice they add to an
  advance payment.
- **Administrators.** Can remove any such document. They remain the route for files whose uploader
  has left.
- **Audit / Compliance.** Removed files are retained, and each removal appears in the record's
  history.

### Processes and screens

- **Payment request page.** A Remove button appears on request-level documents.
- **Invoice detail and edit pages.** A Remove button appears on documents of posted invoices that
  are not yet paid.
- **PAF PDF and approvers' email links.** Removed documents are no longer included.
- **Record history (audit trail).** A "document removed" entry is added.
- **Unchanged:**
  - Documents on invoices that are still editable (submitted or under query). The uploader can
    already remove these today, and that behaviour stays as it is.
  - Uploading itself.
  - Who can see documents.
  - Approval routing.

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** Operations continue. Wrong attachments are an inconvenience and a data-quality
issue, not an outage.

### Workaround Availability

**Assessment:** Yes

**Justification:** Finance can attach the correct file and explain in comments, or ask IT to remove
a file manually in an exceptional case.

### Operational Impact

**Assessment:** No unacceptable impact from delay

**Justification:** Delay prolongs a cluttered and occasionally confusing approval record. It causes
no financial or regulatory damage.

### Timeline Constraints

**Assessment:** No

**Justification:** No deadline applies.

**Emergency Change Classification:** **No**

**Reason:** This is a planned usability and data-quality improvement with a workable interim
process.

---

## Risk Assessment

### Risk Level

**Low**

### Risks Identified

1. **Removing evidence approvers relied on (compliance).** An approver may have signed partly on
   the strength of a document that is later removed. *Mitigated by design:* the file is kept, and
   the removal is recorded with who, when and which file. A request can always be reconstructed as
   it stood when approved.
2. **Removal of someone else's document (control).** Finance could remove a supplier invoice that
   a requester attached, or one colleague could remove another's upload. *Mitigated:* only the
   uploader or an Administrator can remove a document.
3. **Removal after payment (control).** Changing the evidence on a closed, paid record would
   undermine the archive. *Mitigated:* removal closes at payment.
4. **Removed documents still reachable (confidentiality).** A misattached file, for example another
   vendor's, should stop being visible as soon as it is removed. *Mitigated:* removed documents
   disappear from every screen, the PAF and the approvers' links, and their download links stop
   working.
5. **Removed files cannot be retrieved through the screens (operational).** A removed file can be
   recovered only by IT on request. This is accepted for now and recorded as a known limitation.

### Risk Mitigation Plan

- Removal hides the document but keeps the file, never permanently deleting it.
- Removal is limited to the uploader or an Administrator, and only until payment.
- Every removal is written to the record's audit trail.
- Automated tests cover:
  - who can remove, and who is refused;
  - the payment cut-off;
  - removed files vanishing from the screens, the PAF and the links;
  - the file being retained;
  - the audit entry.
- The database is backed up before the release adds the removal record to the two document lists.

---

## Expected Business Impact

### Positive Impact

- Attachment mistakes can be fixed in seconds by the person who made them.
- Approvers see only the documents that are meant to be there, so PAFs are shorter and clearer.
- Misattached documents, for example another vendor's, stop circulating to approvers.
- The audit position is stronger than today's workaround: a removal is an explicit, recorded act
  rather than an unexplained extra file.

### Potential Negative Impact

- A removed file can be recovered only by IT, not from the screen.
- A removal on a request already in approval changes what later approvers see. This is the
  intended effect, and it is recorded.

### User Impact

- Finance, requesters and Administrators see a Remove button, with a confirmation step, on
  documents they are entitled to remove.
- No other change. No retraining is needed beyond a short note.

### Reporting Impact

None. Reports do not list documents.

### Compliance Impact

- No document is ever destroyed by this feature. The audit trail records every removal.
- Payment-time records are locked against removal.

---

## Implementation Overview

Each document on a payment request or invoice gains a "removed" marker, which records who removed
it and when. A removed document is treated as absent everywhere:

- in the screens;
- in the PAF;
- on the public approval page;
- through its download link.

Its file stays in storage. A Remove button, with a confirmation step, appears on the payment-request
page and on the invoice detail and edit pages, for documents the user is entitled to remove. Each
removal writes an entry to the record's audit history, which is already shown on those pages.

Removal on invoices that are still editable keeps its current behaviour.

The release adds the removal marker to the two existing document lists. No existing data is
changed, and the database is backed up before the update.

---

## Rollout Plan

1. **Development.** Add the removal marker and the removal action for request-level and invoice
   documents, hide removed documents everywhere, add Remove buttons, write the audit entries, and
   add automated tests.
2. **Internal Validation.** Remove documents at each stage (in approval, approved, paid) and as each
   role. Confirm the PAF and the approvers' links no longer show them, and that the history records
   each removal.
3. **QA Verification.** Run the full automated suite.
4. **User Acceptance Testing.** A Finance user removes a wrong attachment from a request in
   approval. An approver confirms it is gone from their view and their link.
5. **Production Deployment.** Back up the database, apply the update, and deploy the refreshed
   screens.
6. **Post Deployment Monitoring.** For two weeks, review the removal entries in the audit log for
   unexpected patterns.

---

## Backout Plan

1. **Suspend new functionality.** Redeploy the previous version. Remove buttons disappear.
2. **Restore previous application state.** Documents removed in the meantime would reappear under
   the old version. Their files were never deleted. If that is not wanted, IT re-hides them from the
   audit log, or keeps the new version with the buttons disabled.
3. **Restore backups if required.** A backup is taken before the release.
4. **Validate business operations.** Confirm uploads, the PAF and approvals work normally.
5. **Notify stakeholders.** Inform Finance and IT.

---

## Approval Requirements

### Requestor

Finance / Accounts Payable *(name to be confirmed)*.

### Department Manager

Finance Manager. Confirms the removal rule (uploader or Administrator, until payment).

### IT Manager

Required. Confirms the approach, the backup and the backout plan.

### Business Owner

Finance. Owns the payment-request record and its evidence.

### CAB Approval

Not required. This is a low-risk, standard change.

---

## Decisions Already Taken (2026-10-09)

- **Scope.** Both request-level documents and documents on posted invoices.
- **Removal rule.** The document is removed from view, its file is kept for audit, and removal is
  allowed until payment.

---

## Implementation Status

**Approved:** 2026-10-09. **Implemented** as described, with no divergence.

- **Backup.** Before the update, the database was backed up to
  `C:/xampp8.2/paf-backups/gallega_paf_db_2026-10-09_before-document-removal.sql`, outside the
  repository. The backup holds all 26 tables.
- **Update applied.** The removal marker was added to both document lists. All 1,711 existing
  invoice documents are unaffected and remain visible.
- **Remove buttons**, each with a confirmation step, are on the payment-request page, the invoice
  detail page and the invoice edit page.
- **Tests.** 13 automated tests cover:
  - who may remove a document;
  - the payment cut-off;
  - removed documents disappearing from the screens, the PAF, the approvers' links and downloads;
  - the file being retained;
  - the audit entries;
  - unchanged deletion on editable invoices.

  The full suite shows no new failures.
- **Known limitation.** A removed file can be recovered only by IT. This is recorded as known
  issue #27.

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-10-09

Risk Rating: **Low**

Emergency Change: **No**

Analysis Confidence: **92%**

- **Affected Features Confidence: 94%.** Every place that reads documents goes through the two
  document lists. These include the screens, the PAF, the public approval page and the downloads.
- **Business Impact Confidence: 92%.**
- **Security Impact Confidence: 93%.**
- **Risk Assessment Confidence: 90%.**

---

## Technical Analysis Appendix

*For the implementation team and IT reviewers. Business approvers do not need to read it.*

### Affected Systems

- **The two document tables (invoice, payment request).** A soft-removal marker and a "removed by"
  reference are added. Removed rows are excluded by default, so every relation, eager load, PDF
  merge, public view and download binding ignores them without per-site changes.
- **Removal endpoints.**
  - Request-level documents: a new removal action.
  - Invoice documents: the existing removal widens. The current permanent deletion is kept while the
    invoice is editable. Soft removal applies once it is posted and until it is paid, covering
    correction after posting, correction in place, and late uploads on an advance payment.
- **Screens.** The payment-request page, the invoice detail page and the invoice edit page.

### Removal windows

| Document | Who | When |
|----------|-----|------|
| Payment request (whole request) | uploader or Administrator | request in approval or approved (not paid, rejected or withdrawn) |
| Invoice, editable invoice | uploader or Administrator | unchanged: permanent deletion while submitted or under query |
| Invoice, posted invoice | uploader or Administrator | posted and not yet paid, not cancelled |

### Data Storage

The two existing tables gain two nullable columns each. Existing rows are unaffected, and no row is
rewritten. The migration alters existing tables, so a database backup is taken first.

### Security Controls

- Uploader-or-Administrator check, enforced on the server.
- Status window enforced on the server.
- Removed files are refused on every download route.
- Audit entry for every removal.
