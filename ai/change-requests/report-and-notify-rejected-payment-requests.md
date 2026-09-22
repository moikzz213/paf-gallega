# Change Request

## Subject

Make rejected payment requests visible in the Reports output, and notify everyone involved when a
payment request is rejected

---

## Executive Summary

When a payment request (PAF) is rejected during the approval journey, the rejection effectively
disappears from the organisation's reporting. The invoices that were on the rejected request are
returned to Finance for correction, but in doing so they lose their link to the request that was
rejected. As a result, the Reports page and the Excel/BI export show those invoices as though they
had never been through an approval cycle at all: no payment request reference, no rejection reason,
and none of the approver remarks recorded along the way.

This has two practical consequences. Management cannot see, from reporting alone, how many payment
requests are being rejected, by whom, at which stage, or why — which removes visibility of one of
the most important quality and control signals in the payment process. And Finance and the business
lose the written rejection reason at exactly the point they need it, because the only place it
survives is inside the rejected request record itself.

The second part of this change concerns communication. Today a rejection notice is sent only to the
person who created the payment request and to the people who submitted the invoices on it. The
Finance team, who must act on the rejection and re-initiate the corrected request, and the approvers
who already reviewed and approved earlier stages, are not told. Approvers who signed off in good
faith have no way of knowing that a later stage overturned the request, and Finance discovers the
rejection only by chance or by reopening the request.

This change request proposes two corrections: preserve the link between an invoice and the payment
request that was rejected so that rejections appear in reporting with their reason and remarks
intact, and widen the rejection notification so that the invoice submitter, the Finance team, and
every approver on the request's approval chain are informed at the same time as the requestor.

The expected outcome is complete, auditable reporting of rejected payment requests and a single,
timely notification that reaches everyone who needs to act on or be aware of the rejection.

---

## Business Reason for Change

**Business challenge.** Rejection is a normal and necessary outcome in the approval process, but at
present it is the only outcome that leaves no trace in reporting. Approved and paid requests are
fully reported; rejected ones are silently erased from the invoice report. This creates a blind spot
in management reporting and weakens the control environment: there is no reliable way to measure
rejection rates, identify recurring causes, or evidence to an auditor that rejections were recorded
with a reason and an accountable decision-maker.

**Operational need.** The people who must respond to a rejection — Finance, who correct and
re-submit, and the original invoice submitter, who may need to supply missing information — are
either not notified at all or are notified without the wider team's awareness. This causes avoidable
delay between a rejection and the corrective action, and creates duplicated chasing by email and
phone.

**Governance need.** Approvers who have already approved an earlier stage of a request carry
accountability for that decision. When a later stage rejects the request, those approvers are
currently left uninformed. Telling them closes the loop on their own approval and supports a clean
audit trail of who knew what, and when.

---

## Affected Business Areas

**Departments and teams**

- Finance (invoice posting, payment request initiation and re-initiation)
- All approving departments and business units represented in the approval chains
- Requesting departments that submit vendor invoices
- Internal Audit and Compliance (as consumers of the reporting)
- Management and department heads (as consumers of dashboards and exports)

**Users**

- Invoice submitters / requestors
- Finance users
- Approvers at every stage of the approval chain
- Administrators

**Processes**

- Payment request rejection handling and re-initiation
- Invoice correction and re-submission
- Approval chain communication

**Reports**

- The Reports page (on-screen invoice report)
- The Excel download of the invoice report
- The key-authenticated report feed used by connected spreadsheets and BI tools

All three read from a single shared report definition, so the correction applies consistently to
every channel — there is no risk of the screen and the download disagreeing.

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** The payment process continues to operate. Invoices on a rejected request are
correctly returned to Finance and can be corrected and re-submitted. The defect affects visibility
and communication, not the ability to process payments.

### Workaround Availability

**Assessment:** Yes — a workaround exists, but it is manual and unreliable

**Justification:** The rejection reason and the approver remarks can still be found by opening the
rejected payment request directly, and the rejection is recorded in the audit trail. However, this
requires knowing that a rejection occurred in the first place, which is precisely what is missing.
For reporting purposes there is no workaround: the rejected requests simply cannot be extracted.

### Operational Impact

**Assessment:** Moderate, and cumulative

**Justification:** Each individual rejection causes only modest delay, but the absence of reporting
means the organisation cannot quantify how often this happens or where the recurring causes lie.
Delaying the change prolongs a reporting blind spot rather than causing an immediate operational
failure.

### Timeline Constraints

**Assessment:** No

**Justification:** The change can follow the normal assessment, development and testing cycle. It is
contained, well understood, and does not depend on an external deadline.

### Emergency Change Classification

**No**

**Reason:** No immediate threat to continuity, security or compliance; a partial manual workaround
exists; and the change can be delivered through the standard process with proper testing. It should,
however, be treated as a **high-priority scheduled change**, because it restores a control and
reporting capability that management and audit reasonably expect to already be in place.

---

## Risk Assessment

### Risk Level

**Medium**

### Risks Identified

**Business risks**

- Reports will begin to show rejected payment requests that were previously invisible. Historical
  comparisons of report output before and after the change will not match, and this must be
  explained to report consumers so it is not mistaken for a data error.
- Rejection reasons and approver remarks will become visible to everyone who can run the report.
  These are internal comments written by approvers and may be candid. The audience is already
  restricted to authorised internal users, but the wider visibility should be communicated.

**Operational risks**

- A noticeable increase in notification volume. Every rejection will now generate messages to the
  Finance team and to every approver on the chain, in addition to the requestor and invoice
  submitters. On large approval chains this is a meaningful increase in email traffic, and risks
  recipients treating the notices as routine.
- Finance and approvers may receive notices for requests they consider outside their day-to-day
  remit, if the recipient list is drawn too widely.

**Security and compliance risks**

- Notifications will carry payment request and invoice details to a wider internal audience than
  today. The recipients are all internal, named system users with an existing role in the process,
  so this is an extension of existing internal disclosure rather than a new category of exposure.
- Report visibility rules must continue to apply unchanged. Restoring the payment request link must
  not allow any user to see invoices, requests or remarks that their role and business unit do not
  already entitle them to see.

### Risk Mitigation Plan

1. Preserve visibility rules exactly as they are today. Every report row remains subject to the same
   role-based and business-unit scoping, and this is explicitly tested.
2. Confirm that returning invoices to Finance for correction and re-submission continues to work
   unchanged — the invoices must remain fully available for a new payment request. This is the
   single most important regression test in this change.
3. Define the notification recipient list deliberately and document it: the payment request creator,
   the submitters of the invoices on the request, the Finance team, and every approver who is part
   of the request's approval chain, each addressed once regardless of how many roles they hold.
4. Send one consolidated notice per recipient per rejection, so that a rejection cannot generate
   repeated messages to the same person.
5. Ensure that a failure to send a notification can never cause the rejection itself to fail. The
   rejection is recorded first; notification failures are logged for follow-up.
6. Communicate the reporting change to report consumers before deployment, so the appearance of
   previously missing rows is understood as a correction.
7. Confirm the full audit trail continues to record every rejection with its reason, stage and
   decision-maker.

---

## Expected Business Impact

### Positive Impact

- Complete reporting of the payment process, including rejections, in the on-screen report, the
  Excel download and any connected spreadsheet or BI tool.
- Management gains a measurable view of rejection volume, the stages at which rejections occur, and
  the stated reasons — enabling root-cause analysis and process improvement.
- Faster correction cycles, because Finance learns of a rejection immediately rather than by
  discovery.
- Stronger audit position: rejections are evidenced in reporting with reason and accountability, not
  only in the underlying records.
- Approvers receive closure on requests they previously approved.

### Potential Negative Impact

- An increase in internal email traffic per rejection.
- Reports will contain more rows and more populated fields than before, which may require report
  consumers to refresh saved filters or adjust downstream spreadsheets.
- Approver remarks and rejection reasons become more widely readable within the authorised user
  base, which may make some approvers more guarded in what they write.

### User Impact

- **Finance:** receive rejection notices and can act sooner; gain report visibility of rejected
  requests.
- **Invoice submitters:** continue to be notified, with no change to what they receive.
- **Approvers:** receive a new notification when a request they were involved in is rejected.
- **Management and audit:** gain a reporting capability that did not previously exist.
- No change to how any user submits, approves, rejects or pays. No retraining is required beyond a
  short communication.

### Reporting Impact

Rejected payment requests, their rejection reasons and the associated approver remarks will appear
in the invoice report where they are currently blank. Row counts and column completeness will change
relative to previous periods. Existing filters, columns and file formats are otherwise unchanged, so
connected spreadsheets and BI tools will not break.

### Compliance Impact

Positive. The change strengthens the completeness of management reporting and the evidence trail
around rejected payments, and improves the timeliness of communication to accountable parties. No
new categories of personal or financial data are introduced, and no external party receives
information they do not receive today.

---

## Implementation Overview

The change has two parts, delivered together.

**Part one — reporting.** When a payment request is rejected, its invoices are returned to Finance so
they can be corrected and re-submitted. The system will continue to do exactly that, but will retain
the record of which payment request was rejected, so that the report can show the request reference,
the rejection reason and the approver remarks alongside the invoice. The key constraint is that the
invoices must remain freely available for a new payment request; retaining the historical link must
not block re-initiation in any way.

**Part one (b) — finding the rejections.** Discovered during implementation: the report's status
filter offered "Rejected" (and Paid, Approved, and several others) but never matched anything,
because it only ever compared the selection against the invoice's own status. Making rejections
visible in the report is of little use if the filter people reach for to find them returns an empty
page, so the filter now sends each selected status to the field that actually holds it. This also
repairs the same silent-empty-result behaviour for the other affected options.

**Part two — notification.** The existing rejection notice will be extended to reach a defined
recipient list: the person who created the payment request, the people who submitted the invoices on
it, the Finance team, and every approver on that request's approval chain. Each person is contacted
once, the message content is unchanged in substance, and a notification problem can never prevent a
rejection from being recorded.

Both parts are contained within the existing payment request and reporting functionality. No new
system, integration or supplier is involved, and no change is required to how users work.

---

## Rollout Plan

1. **Development** — implement both parts in a single change, with the reporting correction and the
   notification extension developed and reviewed together.
2. **Internal Validation** — verify against realistic data that rejected requests appear correctly in
   the report and that corrected invoices can still be re-submitted without obstruction.
3. **QA Verification** — execute the associated test case document, with particular emphasis on the
   re-initiation regression and on report visibility rules.
4. **User Acceptance Testing** — Finance and a representative group of approvers confirm the report
   output and the notification content and recipient list.
5. **Production Deployment** — deploy during a low-activity window, with a short advance
   communication to report consumers and to Finance explaining the reporting and notification
   changes.
6. **Post Deployment Monitoring** — monitor for one full reporting cycle: confirm rejected requests
   appear in reports, confirm notification delivery, and gather feedback on notification volume so
   the recipient list can be tuned if it proves too wide.

---

## Backout Plan

1. Suspend the widened notification, returning to the previous recipient list, if notification volume
   proves unacceptable. This part can be reverted independently of the reporting correction.
2. Restore the previous application state if the reporting correction causes any issue with invoice
   re-submission.
3. Restore from backup only if data integrity is affected — not expected, as the change preserves
   information rather than removing it.
4. Validate that rejection, invoice return to Finance and re-initiation all operate normally after
   any backout.
5. Notify Finance, approvers and report consumers of the backout and the revised timeline.

---

## Approval Requirements

### Requestor

Name: ________________________  Signature: ________________  Date: __________

### Department Manager

Name: ________________________  Signature: ________________  Date: __________

### IT Manager

Name: ________________________  Signature: ________________  Date: __________

### Business Owner (Finance)

Name: ________________________  Signature: ________________  Date: __________

### CAB Approval

Required: Yes — the change alters management reporting output and internal notification
distribution.

Name: ________________________  Signature: ________________  Date: __________

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 2026-09-22

**Risk Rating:** Medium

**Emergency Change:** No (high-priority scheduled change)

**Analysis Confidence:** High

| Confidence Area | Rating |
|---|---|
| Analysis Confidence | High — root cause identified directly in the rejection and reporting logic |
| Affected Features Confidence | High — the report is defined in one shared place used by all three output channels |
| Business Impact Confidence | High |
| Security Impact Confidence | High — no change to visibility rules is required or intended |
| Risk Assessment Confidence | Medium-High — notification volume impact depends on typical approval chain length, which should be confirmed during UAT |

---

# Technical Analysis Appendix

*Included only to explain risk, effort and dependency. Kept deliberately brief.*

**Affected systems**

- Payment request rejection handling within the PAF application.
- The single shared report definition used by the Reports page, the Excel download and the
  key-authenticated export feed. Because all three read the same definition, the correction reaches
  every channel at once — this materially reduces both effort and the risk of inconsistency.

**Cause of the reporting gap**

Rejection returns the invoices to Finance by clearing the invoice's association with the payment
request. The report reads the request reference, the rejection reason and the approver remarks
through that same association, so clearing it removes all three from the report at the moment of
rejection. The fix is to retain the historical association while continuing to make the invoices
available for a new request — the two are independent, and are currently conflated.

**Notification recipients**

The rejection notice currently addresses the request creator and the invoice submitters. The change
adds the Finance team and the approvers recorded on the request's approval chain, de-duplicated so
each person receives one message.

**Security controls**

No change. Report rows remain filtered by the existing role and business-unit visibility scoping, and
notification recipients are all existing internal system users already involved in the request.

**Dependencies**

None external. No new supplier, integration or licence.

**Effort**

Small-to-moderate, concentrated in verification rather than development. The regression test
confirming that corrected invoices can still be re-submitted after a rejection is the most important
element of the work.
