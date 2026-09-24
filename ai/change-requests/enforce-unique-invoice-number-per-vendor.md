# Change Request

## Subject

Prevent the same vendor invoice number from being recorded twice, by enforcing invoice number
uniqueness for each vendor

---

## Executive Summary

The platform currently accepts the same vendor invoice number as many times as it is entered. A
vendor's invoice number is the vendor's own reference for the document they have issued, and it is
the single most reliable way of telling whether a particular bill has already been received and
recorded. Because the system does not check it, the same supplier invoice can be captured two or
more times — as separate records, each with its own internal reference, each able to travel through
posting, approval and payment independently of the others.

The exposure this creates is a duplicate payment. Nothing in the current process is designed to
catch it. Each duplicate looks like a perfectly valid invoice: it carries its own internal
reference, is posted to the ERP with its own document number, is grouped into a payment request,
and is approved on its own merits by approvers who have no way of knowing they have seen the same
vendor document before. The control that should prevent this — the vendor invoice number — is
captured on every invoice but never checked.

The duplication does not have to be deliberate or careless to occur. It happens routinely when a
vendor re-sends a copy of an unpaid invoice as a reminder, when the same invoice is forwarded by
two people in the same department, when a submission is re-entered after an interruption, or when a
correction is entered as a new invoice rather than by amending the original.

This change makes the vendor invoice number unique for each vendor. When someone enters an invoice
number that has already been recorded against that same vendor, the system will decline it and tell
them which existing invoice already holds that number, so they can check it rather than guess. The
number is only checked within a vendor — two different vendors may quite legitimately use the same
numbering, and nothing about that changes.

The expected outcome is that the most common cause of duplicate vendor payments is closed off at
the point of entry, before an invoice can consume approval time or reach the ERP, and that the
control is enforced consistently by the system rather than relying on the person entering the
invoice to notice.

---

## Business Reason for Change

**Business challenge.** Duplicate payment is one of the standard loss and audit findings in accounts
payable, and the vendor invoice number is the standard control against it. The platform captures
that number on every invoice — it is a mandatory field on the submission form and it is displayed
on the invoice record, in the Invoice Log and on the payment approval form — but it has never been
checked for repetition. The field carries the appearance of a control without performing as one.

**Operational need.** Finance currently has no systematic way of knowing that a vendor invoice has
already been recorded. Detection depends on someone recognising the number by eye, which works only
if the same person handles both submissions, remembers the number, and is looking for the problem.
Across multiple departments, multiple submitters and a growing invoice history, that is not a
control that can be relied upon.

**Compliance and audit.** Duplicate-payment prevention is a routine expectation in an audit of the
payables process, and the usual question is how the system prevents the same vendor document from
being recorded twice. At present the answer is that it does not. Recording the control in the
system also produces evidence that it operated — an attempt to record a duplicate is refused, and
the refusal is visible to the person making it.

**Cost of inaction.** Every duplicate that is not caught at entry consumes approver time on an
invoice that should never have existed, occupies a place in a payment request, and reaches the ERP
as a second posting for the same vendor document. Recovering a duplicate payment after it has left
the business is materially harder than declining it at entry, and depends on the vendor's
cooperation.

---

## Affected Business Areas

**Departments and teams.** Accounts Payable and the wider Finance team, as the people who post,
group and pay invoices. All departments that submit invoices, since the check applies at the point
of submission.

**Users.** Anyone who submits an invoice, and Finance and administrators who correct invoices on
others' behalf. Approvers are not affected directly, although they benefit from not being asked to
approve invoices that duplicate one they have already seen.

**Processes.** Invoice submission and invoice correction. The posting, payment request, approval and
payment processes are unchanged — this change acts before an invoice enters any of them.

**Reports.** No change to report content, layout or export. Historic data is not altered.

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No.

**Justification:** The platform is operating normally and no outage or security breach is involved.
The weakness is a missing preventive control rather than a failure of the system in use.

### Workaround Availability

**Assessment:** Yes, a workaround exists, but it is weak.

**Justification:** Finance can search the Invoice Log for a vendor's invoice number before
accepting a submission. This is a manual, discretionary check that depends on the reviewer
remembering to perform it on every invoice, and it does not scale. It mitigates the risk in the
short term but does not substitute for the control.

### Operational Impact

**Assessment:** Moderate if delayed.

**Justification:** Each month of delay is another month in which a duplicate vendor invoice can be
recorded, approved and paid without the system objecting. The financial exposure of a single
duplicate payment can exceed the cost of the change.

### Timeline Constraints

**Assessment:** No.

**Justification:** The change is small, well-bounded and can follow the normal assessment, testing
and release process without a material increase in risk.

**Emergency Change Classification:** No.

**Reason:** This is a preventive control improvement with a viable, if weak, manual workaround. It
warrants prompt scheduling but not the elevated risk of an emergency release.

---

## Risk Assessment

### Risk Level

**Medium.**

The change itself is small and confined to the point of invoice entry. The rating reflects the fact
that it introduces a rule that can decline a submission a user could previously make, and that it
touches a field on every invoice record.

### Risks Identified

**Business risk — a legitimate invoice is declined.** A vendor may genuinely issue two different
documents carrying the same number, or a number may have been recorded incorrectly on the earlier
invoice. In these cases a valid invoice would be refused until the earlier record is corrected.
Assessed as uncommon, and visible immediately to the person entering the invoice rather than
failing silently.

**Business risk — a number becomes permanently unusable.** If an invoice is entered in error and
then cancelled, its number would be locked out for that vendor unless cancellations are excluded.
This change excludes cancelled invoices for exactly that reason, so a cancelled mis-entry does not
prevent the real invoice from being recorded.

**Operational risk — existing duplicates already in the data.** If the live database already
contains duplicate numbers for a vendor, the control cannot simply be switched on over them. The
current position must be measured before release, and any existing duplicates identified and
resolved by Finance as a data matter. Verification on the development database found no duplicates,
but the live database must be checked separately.

**Operational risk — inconsistent matching.** Vendors are not consistent about the way they write
their own numbers, so the same document may arrive as "INV-1001" on one submission and "inv 1001"
on another. A check that only catches an exact repetition would let obvious duplicates through and
give false assurance that the control is working. This change therefore ignores capitalisation and
surrounding spaces when comparing.

**Security risk — none identified.** No change to authentication, permissions, visibility rules or
data exposure. The check is applied to data the user is already entitled to submit, and the message
returned identifies only an invoice belonging to the same vendor.

**Compliance risk of not proceeding.** Continuing without the control leaves a known and documented
gap in the payables process.

### Risk Mitigation Plan

1. Measure the live database for existing duplicate numbers per vendor before deployment, and have
   Finance resolve any found as a data-correction exercise beforehand.
2. Exclude cancelled invoices from the check, so a cancelled entry never blocks a genuine one.
3. Apply the check within a vendor only, so unrelated vendors using the same numbering are never
   affected.
4. Return a clear message naming the existing invoice that already holds the number, so the person
   entering it can verify rather than guess.
5. Enforce the rule both at the point of entry and in the database itself, so it cannot be bypassed
   by two people submitting the same invoice at the same moment.
6. Confirm during acceptance testing that correcting an existing invoice without changing its
   number is still possible.

---

## Expected Business Impact

### Positive Impact

- The most common route to a duplicate vendor payment is closed at the point of entry.
- Duplicate submissions are stopped before they consume approver attention or reach the ERP.
- Finance gains a system-enforced control in place of a manual, discretionary check.
- The organisation can demonstrate to auditors that duplicate invoice capture is prevented by the
  system.
- Invoice data quality improves, since the vendor invoice number becomes a dependable identifier.

### Potential Negative Impact

- A submission that was previously accepted may now be declined. Where the duplication is genuine
  this is the intended behaviour; where it is not, the earlier record must be corrected first,
  which takes Finance time.
- If the live data already contains duplicates, they must be resolved before the control can be
  enabled, which is a one-off effort.

### User Impact

Submitters see a clear message identifying the existing invoice when they enter a number already
recorded for that vendor. There is no change to the submission form, to any other field, or to the
way invoices are corrected. Users who never enter a duplicate see no difference.

### Reporting Impact

None. No report, export or dashboard changes, and no historic record is altered.

### Compliance Impact

Positive. A recognised payables control moves from absent to enforced, and its operation is
evidenced by the system declining duplicates.

---

## Implementation Overview

The vendor invoice number becomes unique for each vendor. When an invoice is submitted or
corrected, the system checks whether that vendor already has an invoice carrying the same number
and, if so, declines it and names the existing invoice.

Three qualifications apply. The check is made within a single vendor, so two different vendors may
use the same number without interference. Capitalisation and surrounding spaces are ignored, so the
same document written in different ways is still recognised as the same number. Cancelled invoices
are excluded, so a number used on an entry that was subsequently cancelled remains available.

The rule is enforced in two places: at the point of entry, which produces the helpful message, and
within the database itself, which guarantees it holds even if two submissions are made at the same
instant. Correcting an existing invoice without changing its number continues to work as it does
today.

Invoices already recorded are not altered. A small number of older invoices are not linked to a
vendor record at all, having been created before vendors were held as a master list; these cannot
be checked by vendor and are left as they are.

---

## Rollout Plan

1. **Development** — implement the control at the point of entry and in the database, with
   automated tests covering submission, correction, cancellation and the near-miss variants.
2. **Internal Validation** — measure the live database for pre-existing duplicates and report the
   findings to Finance for resolution.
3. **QA Verification** — execute the accompanying test case document.
4. **User Acceptance Testing** — Finance confirm that a genuine duplicate is declined with a useful
   message, that ordinary submission and correction are unaffected, and that a cancelled invoice's
   number can be reused.
5. **Production Deployment** — deploy during a low-activity window, after any pre-existing
   duplicates have been resolved.
6. **Post Deployment Monitoring** — review declined submissions over the first weeks to confirm
   that they represent genuine duplicates rather than false positives.

---

## Backout Plan

1. **Suspend new functionality** — remove the uniqueness rule from the point of entry, restoring
   the previous acceptance behaviour.
2. **Restore previous application state** — reverse the database constraint, which is a reversible
   structural change that removes the rule without altering any invoice data.
3. **Restore backups if required** — not expected to be necessary, as no invoice record is created,
   modified or deleted by this change.
4. **Validate business operations** — confirm invoice submission, correction, posting, approval and
   payment operate normally.
5. **Notify stakeholders** — inform Finance that the control has been suspended and that the manual
   check applies again in the interim.

---

## Approval Requirements

### Requestor

Finance / Accounts Payable

### Department Manager

Finance Manager

### IT Manager

Required — introduces a database constraint and a validation rule.

### Business Owner

Head of Finance

### CAB Approval (if applicable)

Recommended. The change is low in technical complexity but introduces a financial control and can
decline user submissions, so it warrants visibility at the Change Advisory Board.

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 2026-09-24

**Risk Rating:** Medium

**Emergency Change:** No

**Analysis Confidence:** High — the invoice submission and correction paths were reviewed directly,
and there is a single point of entry for invoice creation.

**Affected Features Confidence:** High — invoice submission and invoice correction only; posting,
approval, payment and reporting are untouched.

**Business Impact Confidence:** High — the behavioural change is confined to declining a submission
that duplicates an existing vendor invoice number.

**Security Impact Confidence:** High — no change to authentication, authorisation, visibility rules
or data exposure.

**Risk Assessment Confidence:** Medium-High — depends on the volume of pre-existing duplicates in
the live database, which must be measured before deployment. The development database contains
none.

---

# Technical Analysis Appendix

**Affected systems.** Invoice submission and invoice correction. There is a single invoice creation
path in the application, which limits the surface the control has to cover.

**Data model.** Invoices hold both a link to the vendor master record and the vendor's invoice
number. The uniqueness rule pairs the two. A small number of historic invoices carry no vendor
link, having been created before vendors were maintained as a master list; these are outside the
rule's reach and are deliberately left unchanged.

**Matching behaviour.** The comparison ignores capitalisation and surrounding spaces. This is
stated explicitly in the application rather than left to the database, because the live database
compares text without regard to capitalisation while the automated test environment does so
strictly — relying on that difference would mean the control behaved differently in testing than in
production.

**Cancelled invoices.** Excluding cancellations is the reason the database rule is expressed over a
derived value rather than the invoice number alone: a plain constraint cannot distinguish an
invoice's status.

**Integrations.** The ERP posting process is not modified. The benefit to it is indirect — a
duplicate is declined before it can be posted.

**Security controls.** No change. Existing permission and visibility rules continue to govern who
may submit and correct invoices; the new rule applies after those checks, not instead of them.

**Reversibility.** The database constraint can be removed without touching invoice data, and the
validation rule can be withdrawn independently.
