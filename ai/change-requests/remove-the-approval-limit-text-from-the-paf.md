# Change Request

## Subject

Remove the Approval Limit Text from the PAF

---

## Executive Summary

This change blanks the **Approvals Limit for Payment** line on the Payment Approval Form (PAF). This is the PDF that approvers sign and that Finance keeps as the payment record. The heading stays on the form, but the sentence beside it is removed.

Today every PAF prints the same fixed sentence: *"Up to AED 50,000 by Finance Manager. All above AED 50,000 by Gallega CEO or SVP - Group Finance."* It was written into the form once. It does not come from the approval limits the system actually uses to route a payment. Those limits are managed separately and can be changed by administrators. When they change, the form does not.

So the PAF can state one approval rule while the approvals recorded on the same page follow another. Removing the sentence stops the form contradicting its own approval record. The approvers listed on the PAF remain the authoritative record of who approved the payment.

This is a small, contained change to the printed form only. It does not change how payments are routed, approved or paid. The risk is assessed as **Low**.

---

## Business Reason for Change

**The form can disagree with the approvals.** The approval limits that decide who must approve a payment are set in the system and can be changed. The sentence on the PAF is fixed and does not follow them. When the limits change, every new PAF would still state the old rule beside approvals that follow the new one.

**A signed form should not state a rule nobody followed.** The PAF is evidence. An auditor reading a PAF that names one approval limit and shows a different set of approvers would reasonably ask why. Removing the fixed text means the form only shows what actually happened.

**The heading is kept.** The PAF mirrors the company's paper form. Keeping the heading keeps the layout familiar to approvers and Finance, and leaves room to show a correct, system-driven value later if the business wants one.

---

## Affected Business Areas

**Departments and teams**

- Finance, who generate, download and file the PAF
- Approvers at every level, who review and sign the PAF
- Internal Audit and Compliance, who rely on the PAF as payment evidence

**Business processes**

- Producing the PAF for a payment request
- Approval through the system and through the emailed approval link, both of which show the PAF

**Reports and documents**

- The PAF (Payment Approval Form): the approval-limit line is left blank
- No other report, export or screen changes

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** Payments are raised, approved and paid normally today. The fixed sentence is a presentation issue on the form and does not stop any work.

### Workaround Availability

**Assessment:** Yes, a workaround exists

**Justification:** Approvers and auditors can rely on the list of approvers printed on the PAF rather than on the limit sentence.

### Operational Impact

**Assessment:** No

**Justification:** A delay leaves possibly outdated wording on the form for longer. It causes no financial loss and blocks no payment.

### Timeline Constraints

**Assessment:** No

**Justification:** There is no external deadline. The change can follow the normal change process.

**Emergency Change Classification:** No

**Reason:** This is a small correction to the printed form with a workable interim arrangement. It should follow the standard change process.

---

## Risk Assessment

### Risk Level

**Low**

### Risks Identified

**Business risks**

1. **Readers may miss the stated rule.** Some approvers may have used the sentence as a reminder of who signs at which amount. With it gone, they need another reference for that.
2. **Older and newer copies will differ.** The PAF is produced fresh each time it is opened, so any request opened after the change shows the blank line, including requests approved before it. Copies already downloaded, printed or emailed keep the old sentence.

**Operational risks**

3. **Layout shift.** An empty line could change the form's height and move the approval section onto a second page.

**Security risks**

4. None identified. Who can see or approve a payment does not change, and no data is added or removed. The change only affects text on the printed form.

### Risk Mitigation Plan

| # | Mitigation |
|---|------------|
| 1 | Tell Finance and approvers in the rollout notice that the line is now blank by design. The approval limits themselves are unchanged and still applied by the system. |
| 2 | State in the rollout notice that the PAF is regenerated on demand, so reopened older requests also show the blank line. Filed copies remain valid records of what was signed at the time. |
| 3 | The line keeps its normal height when empty. During validation, check PAFs with one invoice and with several invoices to confirm the approval section stays on the first page where it did before. |

---

## Expected Business Impact

### Positive Impact

- The PAF no longer states an approval rule that may differ from the approvals actually recorded.
- A clearer audit position: the form shows only who actually approved the payment.
- No need to change the form again each time the approval limits are adjusted.

### Potential Negative Impact

- Approvers lose the on-form reminder of the approval limits.

### User Impact

Finance and approvers see the **Approvals Limit for Payment** heading with an empty value on the PAF. The steps for raising, approving and paying a request do not change.

### Reporting Impact

Only the PAF changes. No report, dashboard or export is affected.

### Compliance Impact

Positive. The signed form no longer contains a statement that can conflict with the recorded approval chain.

---

## Implementation Overview

The fixed approval-limit sentence is removed from the PAF. The **Approvals Limit for Payment** heading stays in place with an empty value, so the form keeps the layout of the paper original. The approval limits the system uses to route payments, and the approvers listed on the form, are not touched.

The change applies wherever the PAF is shown: the download from the payment request screen, the approval page, and the emailed approval link. All of these use the same form.

---

## Rollout Plan

1. **Development:** remove the sentence from the PAF and leave the heading with an empty value.
2. **Internal Validation:** generate PAFs for a single-invoice request, a multi-invoice request, and a request with supporting documents. Confirm the line is blank, the heading remains, and the approval section stays where it was.
3. **QA Verification:** confirm the same result from the payment request screen and from the emailed approval link. Run the automated test suite to confirm nothing else changed.
4. **User Acceptance Testing:** Finance reviews a sample PAF and confirms the form is acceptable for filing.
5. **Production Deployment:** release with the rollout notice to Finance and approvers.
6. **Post Deployment Monitoring:** for the first week, check with Finance that PAFs print and file as expected.

---

## Backout Plan

1. **Suspend new functionality:** not needed. No new function is introduced.
2. **Restore previous application state:** restore the previous version of the form, which brings back the fixed sentence.
3. **Restore backups if required:** not required. No data is changed.
4. **Validate business operations:** generate a PAF and confirm the sentence is back.
5. **Notify stakeholders:** tell Finance and approvers that the previous form is back.

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

Not required for a Low-risk, non-emergency change to document wording, unless local policy requires it.

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-10-05

Risk Rating: Low

Emergency Change: No

Analysis Confidence: High (95%)

- Affected Features Confidence: High (95%). The sentence is set in one place and appears on one form.
- Business Impact Confidence: High (90%)
- Security Impact Confidence: High (95%)
- Risk Assessment Confidence: High (90%)

---

# Technical Analysis Appendix

### Affected Systems

- The PAF template only. The sentence is a fixed value in the form's template, used for the single "Approvals Limit for Payment" line.
- The same template produces the PAF for the in-app download, the approval page and the public emailed approval link. One change covers all of them.

### Not Affected

- The approval-level settings and the rules that build each request's approval chain
- The database, APIs, permissions and audit trail
- The existing automated tests. None of them check this sentence.

### Scope Note

The original request also asked to let Finance correct a PAF. On review, Finance and administrators can already correct an invoice held by a payment request, both in approval and once approved. That part was withdrawn by the requestor on 2026-10-05 and is not part of this change.
