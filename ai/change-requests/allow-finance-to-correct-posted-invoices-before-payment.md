# Change Request

## Subject

Allow Finance to Correct Posted Invoices Before Payment

---

## Executive Summary

This change lets **Finance and administrators correct an invoice that has already been posted to the ERP**, as long as it is not yet part of a payment request.

Today, once Finance posts an invoice, its details are locked. The only part Finance can change is the ERP document number. To fix anything else, such as an amount, a job number, a description or a date, Finance has to raise a query to the submitter. The submitter then corrects the invoice, and Finance posts it a second time. This round trip also applies to every invoice that comes back from a rejected or withdrawn payment request, or that is released from one. All of these return to the Invoice Log as "posted".

Under this change, Finance and administrators get a **Correct** option on such invoices. The invoice stays posted and keeps its ERP document number. Every correction is recorded in the audit trail with the values it replaced. The submitter is told by email that their invoice was changed.

No approvals exist yet at this stage, so the change leaves every approval already given untouched. The invoice still goes through the full approval chain when it is placed on a payment request. The main risk is the step this removes. Today a query puts the submitter in the loop, and this change lets Finance correct a posted invoice alone. The record could also drift from the ERP entry posted under the old figures. With the mitigations below, the risk is assessed as **Medium**.

---

## Business Reason for Change

**Small fixes take a full round trip.** A wrong job number or a mistyped amount on a posted invoice needs a query to the submitter, a wait for their correction, and a second posting by Finance. Finance often already knows the correct value. The invoice cannot be paid until the loop completes.

**Returned invoices cannot be fixed directly.** When a payment request is rejected or withdrawn, or one invoice is released from it, the invoice comes back to the Invoice Log so it can be corrected. Today the system tells Finance it "can be corrected", but Finance cannot do so. Each one must go back to the submitter.

**Finance already holds this right later in the cycle.** Finance and administrators can correct an invoice once it is in a payment request, within the limits that protect the approvals. Being unable to correct the same invoice *before* any approval exists is inconsistent. The earlier point is where a correction is safest.

---

## Affected Business Areas

**Departments and teams**

- Finance, who gain the correction option and raise fewer queries
- Invoice submitters, who are told when Finance corrects their invoice and receive fewer queries
- Approvers, who see the corrected figures when the invoice reaches them on a payment request
- Internal Audit and Compliance, who rely on the record of who changed what after posting

**Business processes**

- Invoice correction after ERP posting
- Handling invoices returned from rejected, withdrawn or released payment requests
- The query process, which is still available but no longer the only route

**Users**

- Finance and administrators: a **Correct** option on posted invoices that are not in a payment request
- Submitters: an email when their invoice is corrected
- Approvers: no change in what they do

**Reports**

- No change to reports or exports. They show the invoice's current figures, as today.

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** Invoices are corrected today through the query process. The change removes delay. It does not address a threat to operations.

### Workaround Availability

**Assessment:** Yes, a workaround exists

**Justification:** Finance can raise a query, the submitter corrects the invoice, and Finance posts it again.

### Operational Impact

**Assessment:** No

**Justification:** Delay keeps the extra round trip and its effect on payment timing. It causes no direct financial loss.

### Timeline Constraints

**Assessment:** No

**Justification:** There is no external deadline. The change can follow the normal change process.

**Emergency Change Classification:** No

**Reason:** This is an efficiency improvement with a working interim process. It widens who may change a posted record, which is a further reason for the normal level of review.

---

## Risk Assessment

### Risk Level

**Medium**

### Risks Identified

**Business risks**

1. **The ERP may no longer match.** The invoice was posted to the ERP with its old figures. A correction to the amount, vendor or reference details leaves the ERP entry out of step until Finance adjusts it there.
2. **The submitter is no longer in the loop.** Today a query means the submitter makes the correction, which acts as a second pair of eyes. This change lets a Finance user change a posted invoice, including its amount or vendor, without the submitter's involvement.

**Operational risks**

3. **Corrections may replace queries that should have been raised.** Some errors are the submitter's to resolve, for example a dispute with the vendor. Finance correcting these directly could hide recurring problems from the submitting department.
4. **A correction may collide with payment request creation.** If Finance places the invoice on a payment request while another Finance user is correcting it, the request must not be built on half-changed figures.

**Security and compliance risks**

5. **Widening who may change a posted record.** Only Finance and administrators gain the right. Submitters, approvers and other roles gain nothing.
6. **Audit completeness.** Every correction must record who made it, when, the values it replaced, and the ERP document it was posted under. This is needed to explain any difference between the system and the ERP.

### Risk Mitigation Plan

| # | Mitigation |
|---|------------|
| 1 | Before saving, the correction screen states that the invoice is posted in the ERP under its document number, and that the ERP entry must be adjusted to match. The existing **Edit Posting** option remains available if the ERP document number itself changes. |
| 2 | Email the invoice's submitter whenever Finance corrects it, listing what changed. The invoice history shows the correction as a separate, clearly named entry. |
| 3 | Keep **Raise Query** available beside **Correct**, and brief Finance to use the query route for issues the submitter must resolve. |
| 4 | The correction is refused once the invoice is in a payment request. From then on the existing in-payment-cycle correction rules apply. The check is made at the moment of saving, not only when the screen opens. |
| 5 | Grant the right only to Finance and administrators, and only for posted invoices that are not cancelled and not yet in a payment request. Both the screen and the server enforce it. |
| 6 | Record every correction in the audit trail as "corrected after posting", with the old and new values and the ERP document number. Cover this with automated tests. |

---

## Expected Business Impact

### Positive Impact

- Faster corrections: Finance fixes a posted invoice in one step instead of a query round trip.
- Returned invoices from rejected, withdrawn or released payment requests can be corrected straight away.
- Consistent rules: Finance can correct an invoice both before and after it enters a payment request.
- A clear audit record of every change made after posting.

### Potential Negative Impact

- The ERP entry and the system record may differ until Finance adjusts the ERP.
- Submitters are informed rather than consulted when Finance corrects their invoice.

### User Impact

**Finance and administrators** see a **Correct** option on a posted invoice that is not in a payment request. The screen reminds them of the ERP posting. The invoice stays posted after the correction.

**Submitters** receive an email when Finance corrects their invoice. Their own ability to edit does not change.

**Approvers** take no new action.

### Reporting Impact

None. Reports show current invoice figures, as today.

### Compliance Impact

Neutral, provided the mitigations are in place. Removing the submitter's involvement is offset by the submitter notification and a full before-and-after audit record. Every payment still passes through the full approval chain afterwards.

---

## Implementation Overview

Finance and administrators gain a **Correct** option on an invoice that is posted, not cancelled, and not in a payment request. The correction uses the same invoice form, rules and checks as any invoice edit, including the duplicate invoice-number check for the vendor.

After the correction:

- The invoice stays **posted**, and keeps its ERP document number, posting date and the record of who posted it.
- The audit trail records a distinct "corrected after posting" entry with the old and new values.
- The submitter receives an email listing what changed. The email goes out after the correction is saved, so a mail problem cannot undo it.

Once the invoice is in a payment request, the existing correction rules for that stage apply unchanged. The submitter's own editing rights, the query process and the posting-details correction are all unchanged.

### Decisions Recorded

The requestor confirmed all three recommendations on 5 October 2026.

1. **What Finance may change.** Everything the invoice form holds, including the amount, currency, vendor and advance-payment marker. No approvals have been given yet, so there is nothing for these limits to protect.
2. **Status after correction.** The invoice stays **posted**, with its ERP document number, posting date and poster kept.
3. **Submitter notification.** The submitter is emailed on every Finance correction, unless the submitter made the correction themselves.

**Noted during implementation:** Finance can add supporting documents as part of the correction. Removing an existing document is still limited to invoices that have not been posted, as it is today.

---

## Rollout Plan

1. **Development:** add the correction option for posted invoices outside a payment cycle, with the audit entry and submitter email.
2. **Internal Validation:** correct a posted invoice, a released invoice and an invoice returned from a rejected request. Confirm each stays posted and keeps its ERP document number, the audit entry is complete, and the submitter is emailed.
3. **QA Verification:** confirm that submitters, approvers and other roles cannot use the option. Confirm it is refused once the invoice is in a payment request, and refused for cancelled invoices. Run the automated test suite.
4. **User Acceptance Testing:** Finance corrects sample invoices and confirms the ERP reminder and the history entry read clearly.
5. **Production Deployment:** release with a briefing to Finance on when to correct and when to raise a query.
6. **Post Deployment Monitoring:** for the first month, review the "corrected after posting" entries with the Finance manager. Confirm the ERP entries were adjusted to match.

---

## Backout Plan

1. **Suspend new functionality:** remove the **Correct** option for posted invoices. Finance returns to the query process.
2. **Restore previous application state:** redeploy the previous version. No data structure changes, so nothing has to be converted back.
3. **Restore backups if required:** not expected. Corrections already made are valid edits and stay recorded in the audit trail.
4. **Validate business operations:** confirm posting, queries and payment request creation work as before.
5. **Notify stakeholders:** tell Finance and submitters that corrections after posting go through the query process again.

---

## Approval Requirements

### Requestor

Name / Date / Signature

### Department Manager

Name / Date / Signature

### IT Manager

Name / Date / Signature

### Business Owner

Head of Finance — Name / Date / Signature

### CAB Approval (if applicable)

Recommended. The change widens who may change a posted financial record.

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-10-05

Risk Rating: Medium

Emergency Change: No

Analysis Confidence: High (90%)

- Affected Features Confidence: High (90%)
- Business Impact Confidence: High (85%)
- Security Impact Confidence: High (90%)
- Risk Assessment Confidence: High (85%)

---

# Technical Analysis Appendix

### Affected Systems

- **Invoice editing:** today a posted invoice outside a payment request is refused as "can no longer be edited", for every role. The change opens a third correction window beside the two that exist: Finance/admin while the invoice is in a payment request, and the submitter while that request is in approval.
- **Invoice screens:** the invoice page and invoice form need the matching option and the ERP reminder. As elsewhere, the rule is enforced on the server and mirrored in the screens.
- **Notifications:** one new email to the submitter, sent after the correction is saved.
- **Audit trail:** one new, distinctly named entry.

### Not Affected

- The database structure. No new columns or tables, and no migration.
- Approval routing, payment requests, the PAF and reports
- The submitter's own editing rights, the query process and the posting-details correction

### Related Observation (not in scope)

The server already lets Finance edit a submitted invoice they did not raise, before posting, but the invoice page does not show them the **Edit** button. This change does not alter that. It can be aligned separately if wanted.
