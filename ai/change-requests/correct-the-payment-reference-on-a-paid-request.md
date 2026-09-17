# Change Request

## Subject

Allow the person who recorded a payment to correct a mistyped payment reference on a paid payment request

---

## Executive Summary

When Finance marks a payment request as paid, they type in the payment reference — the bank transfer number, cheque number or transaction ID for the money that actually left. That reference is the link between a payment approved in this system and the entry on the bank statement. It is what a reconciliation, an audit query or a supplier chasing payment is traced through.

It is typed by hand, so it can be typed wrong. At present there is no way to fix it. Marking a request paid only works on a request that is approved and unpaid, so once paid it can never go through that step again, and nothing else in the system writes the field. A single wrong character is therefore permanent, and the payment can no longer be matched to the bank statement.

This change adds an **Edit Payment Ref** action on the payment request view, shown only once a payment reference has actually been recorded. It is restricted to **the person who recorded the payment**, since they had the bank record in front of them and can say what the reference should have been. An administrator can also correct it, which is the way through when that person has left the business or is unavailable.

Correcting is deliberately not the same as re-recording the payment. The request stays paid, and the record of **who** marked it paid and **when** is untouched — those are facts about what happened. Only the reference changes, and the correction is written to the audit trail as its own event, alongside the value it replaced, so the original reference stays visible and the change is attributable.

The change is small, touches one field on one screen, moves no money, alters no amount and no approval, and requires no change to how information is stored. It is rated Low-to-Medium risk: it permits a change to a payment record that was previously final, which is exactly why the restriction to the original payer and the audit trail matter.

---

## Business Reason for Change

**The business need.** The payment reference has to be right. It is the only thing tying a payment made through this system to the money that left the bank account.

**The current gap.** The reference can only be entered once, at the moment the payment is recorded, and can never be changed. A typo is permanent.

**What this costs the business:**

- **A payment that cannot be reconciled.** A wrong reference matches nothing on the bank statement. Whoever is reconciling has to work out by hand which payment the record refers to.
- **Slower answers to suppliers and auditors.** "Which transfer paid this invoice?" becomes a manual investigation rather than a lookup.
- **Month-end friction.** Unmatched payments are chased at exactly the point when Finance is busiest.
- **No sanctioned way to fix it.** Because the system offers no correction, the alternatives are all worse: leave the record wrong, or ask for a direct change to the database — unrecorded, unattributable, and outside any control.
- **Errors surface after the fact.** A wrong reference is normally noticed during reconciliation, which is after the payment has been recorded — precisely when nothing can be changed.

**The opportunity.** A short, restricted, fully logged correction by the person who recorded the payment, on the screen they are already looking at.

---

## Affected Business Areas

**Departments and teams**

- **Finance / Accounts Payable** — the only team affected and the only one that can perform the action. Gains the ability to fix their own typing.
- **Internal Audit and Compliance** — gains a recorded, attributable correction where previously there was either an uncorrected error or an untracked database change.
- **Treasury / whoever reconciles the bank statement** — payments can be matched to the statement.
- **Approvers and invoice submitters** — no change. They cannot see or perform the action.
- **IT / Systems** — delivery and support only. Notably, this removes a recurring need for ad-hoc database corrections, which are both a support cost and a control weakness.

**Business processes**

- Recording a payment against an approved payment request
- Reconciliation of payments against the bank statement
- Supplier payment queries and audit queries
- Month-end close

**Reports and dashboards**

- Payment reporting and exports, which include the payment reference

**Explicitly not affected**

- Any amount, invoice, or payment request total
- Approval routing, approval thresholds, or approvals already given
- Whether a request is paid, who marked it paid, or when
- The movement of money itself — this records a reference for a payment the bank has already made; it does not instruct or alter any payment
- User access, roles and permissions
- Any external system or bank integration

---

## Emergency Change Assessment

### Business Continuity

**Assessment: No**

**Justification:** The system is operating normally and payments are unaffected. A wrong reference is a reconciliation and traceability problem, not an outage or a payment failure.

### Workaround Availability

**Assessment: Partly — and the available workaround is itself undesirable**

**Justification:** There is no workaround within the application. The only options today are a direct database change, or leaving the record wrong and tracking the real reference outside the system. The first is unrecorded and bypasses application controls; the second degrades the record permanently. Both are worse than the change being requested, which is the substance of the case for making it.

### Operational Impact

**Assessment: No unacceptable impact from delay**

**Justification:** The payments themselves are correct and complete — only the reference recorded against them is wrong. The cost of delay is manual reconciliation effort and slower answers to queries. Real, and irritating at month-end, but no financial exposure and not material to a reporting period.

### Timeline Constraints

**Assessment: No**

**Justification:** No external deadline, audit finding or regulatory date applies.

**Emergency Change Classification: No**

**Reason:** No continuity, security or compliance breach, and no deadline. The change should follow the normal route — which suits it, because it makes a previously final payment record changeable, and that deserves proper testing and business sign-off rather than a fast track.

---

## Risk Assessment

### Risk Level

**Low to Medium**

The action moves no money, changes no amount and no approval, and cannot be reached by anyone outside Finance. It is not rated plainly Low because it permits a change to a **payment** record the system previously treated as final, and because the field it changes is the one an auditor uses to trace money out of the business.

### Risks Identified

**Business risks**

- **A correction could itself be wrong.** The replacement reference is typed by hand, exactly like the original, so a correction can introduce a new error. The audit trail is what makes this detectable and fixable rather than compounding.
- **A reference could be changed to point at a different, real transfer.** Whether by mistake or deliberately, this would mis-link a payment record to the bank statement. Restricting the action to the person who recorded the payment, and logging every change with its previous value, is the control.
- **A perception that paid records are no longer firm.** Finance and Audit need to understand precisely what can change — the reference text — and what cannot: the amount, the invoices, the approvals, the paid status, and who recorded the payment.

**Operational risks**

- **The original payer may be unavailable.** Restricting the action to them alone would leave the error unfixable, which is why an administrator can also make the correction.
- **Two requests may legitimately share a reference.** One transfer often settles several payment requests, so no uniqueness rule is applied. Finance should not read a repeated reference as an error.

**Security and compliance risks**

- **No change to access.** The action sits behind the same Finance/administrator restriction as recording a payment, and is further limited to the person who recorded the specific payment. No new route into the system and no additional data exposure.
- **Audit position improved.** It replaces either an uncorrected error or an untracked database edit with a logged, attributable, in-application correction that retains the previous value.
- **Segregation of duties unchanged.** Nothing about who approves or who pays is affected, and the action cannot mark anything paid.

### Risk Mitigation Plan

1. **Restricted to the person who recorded the payment.** They had the bank record in front of them. Another Finance user — including the person who raised the request — cannot correct it; the refusal names the person to ask.
2. **An administrator override**, so an error cannot become unfixable when someone leaves. Deliberately narrow — administrators only.
3. **Enforced on the server, not just on screen.** The action is hidden when it does not apply and refused if the request is made anyway.
4. **Only where there is a reference to correct.** The action does not exist on a request that has not been paid, so it can never be a route to marking something paid.
5. **Correcting is not re-recording the payment.** Who marked it paid, when, and the paid status are untouched, so the correction cannot be used to reassign responsibility for the payment.
6. **Every correction is logged as its own event**, with the previous reference retained and the person who made it recorded. The original payment entry stays in the log unchanged.
7. **The screen states what will and will not change** before the correction is made, names the person who remains recorded as having taken the payment, and refuses a confirmation where nothing has actually been edited.
8. **Full test coverage**, including each refusal, the audit content, and that amounts, invoices and approvals are provably untouched.

---

## Expected Business Impact

### Positive Impact

- **Payments can be matched to the bank statement.** Reconciliation, audit queries and supplier queries trace straight through.
- **No more out-of-system corrections.** Removes the need for direct database changes, which are unrecorded, unattributable and a control weakness.
- **Errors can be fixed when they are found**, which is typically at reconciliation, after the payment was recorded.
- **A better audit trail than today**, in both the corrected and uncorrected cases: the change is attributable and the previous value is retained.
- **Less manual effort** for Finance, IT and Audit, especially at month-end.

### Potential Negative Impact

- **A paid record is no longer entirely final.** Deliberate, narrow and logged, but a change in how the system has behaved until now.
- **A correction can be mistyped too.** Mitigated by the audit trail rather than prevented.
- **A reference can change after a report was run.** A report produced before a correction will show the old reference.

### User Impact

- **Finance:** an **Edit Payment Ref** action on requests whose payment they recorded, prefilled with what is recorded. They cannot correct a colleague's payment, and are told who to ask.
- **Administrators:** can correct any payment reference, for the cases where the original payer is unavailable.
- **Approvers and submitters:** no change.
- **Audit:** a new, clearly labelled correction event in the payment request history.

### Reporting Impact

The payment reference on a corrected request changes in all reports. Nothing else in any report changes. No bulk restatement is involved — corrections are made individually.

### Compliance Impact

Positive. It converts an uncorrectable error, or an untracked database edit, into a controlled in-application correction that is restricted, attributable, and retains what it replaced.

---

## Implementation Overview

An **Edit Payment Ref** action appears on the payment request view once a payment reference has been recorded, for the person who recorded the payment and for administrators.

- It opens with the recorded reference already filled in, since the purpose is to fix a keystroke in it.
- It states plainly that the request stays paid and names the person who remains recorded as having taken the payment, with the date.
- It refuses a confirmation where nothing has been edited, and refuses an empty reference.
- On confirmation, the reference is updated and the correction is written to the request's history alongside the value it replaced.
- The same rules are enforced by the system independently of the screen, so the action cannot be performed by anyone else or on a request that is not paid.
- No uniqueness rule is applied, because one transfer legitimately settles several requests.

Everything else about the request — its amount, its invoices, its approvals, its paid status, and the record of who marked it paid and when — is untouched.

---

## Rollout Plan

1. **Development** — the action, its restriction, and the audit entry; automated tests covering every refusal and the audit content.
2. **Internal Validation** — developer verification against the Test Case document, including that amounts, invoices and approvals are provably unaffected.
3. **QA Verification** — full Test Case checklist: happy path, negative, security, and regression over recording a payment.
4. **User Acceptance Testing** — Finance to correct a reference on a payment they recorded, and confirm they cannot correct a colleague's. An administrator to confirm the override. Audit to review the resulting history.
5. **Production Deployment** — standard release. No data migration and no downtime expected.
6. **Post Deployment Monitoring** — Audit or the Finance Manager to review corrections in the first month, confirming each was a genuine typing correction.

---

## Backout Plan

1. **Suspend new functionality** — redeploy the prior release, which removes the action. Payment references become final again.
2. **Restore previous application state** — no stored information changes shape, so this is a straightforward reversal.
3. **Reverse individual corrections if required** — each is recorded with its previous value, so any can be identified and reversed. Corrections that were genuine should be kept: they made the record accurate.
4. **Validate business operations** — confirm recording a payment, approval and payment request creation are unaffected.
5. **Notify stakeholders** — inform Finance that a mistyped payment reference is again uncorrectable in the application, and agree how such cases will be handled in the interim.

---

## Approval Requirements

### Requestor

Finance / Accounts Payable — the team that records payments and found that a typo could not be fixed.

### Department Manager

Finance Manager — confirms that the person who recorded a payment is the right person to correct its reference, and that the paid record may be amended in this narrow way.

### IT Manager

Confirms scope, effort, test coverage and the backout position, and that this removes a recurring need for ad-hoc database corrections.

### Business Owner

Finance Director / CFO — owns the decision that a paid payment request's reference may be corrected in the application, under the restrictions described.

### CAB Approval (if applicable)

**Recommended.** The change alters no financial figure and moves no money, but it makes a previously final **payment** record changeable and touches a field used for audit traceability, so a CAB record is appropriate.

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 2026-09-10

**Risk Rating:** Low to Medium

**Emergency Change:** No

**Analysis Confidence:** High

| Confidence area | Rating | Basis |
|---|---|---|
| Analysis Confidence | High | The payment fields and every place they are written were inspected directly; the gap was reproduced in the running application. |
| Affected Features Confidence | High | One field on one screen. No amount, invoice, approval or paid status participates. |
| Business Impact Confidence | High | Additive and opt-in per request; no data migration, and nothing changes for payments nobody corrects. |
| Security Impact Confidence | High | Same role restriction as recording a payment, narrowed further to the original payer; the audit trail gains an event. |
| Risk Assessment Confidence | Medium-High | The residual risk is behavioural — a correction that is itself wrong, or points at a different real transfer — which the restriction and the audit trail mitigate but cannot eliminate. |

---

## Technical Analysis Appendix

*Included only to the extent needed to explain scope, effort and risk.*

**Affected systems**

Confined to the Payment Approval application: the payment request view, the payment reference field, and the request's history. No bank or payment system is involved — this records a reference for a payment already made.

**Why a correction rather than re-recording the payment**

Recording a payment is refused on a request that is not awaiting payment, deliberately: doing it again would replace the reference *and* rewrite who marked it paid and when. Those are facts about what happened and must not move. A separate, narrower correction changes only the field that can be mistyped, leaves the payment record itself intact, and is logged as its own event — which also keeps the history readable, since the original payment entry survives unchanged. As a side effect, the correction can never be a back door to marking something paid: it refuses anything that is not already paid.

**Why no uniqueness rule**

One bank transfer commonly settles several payment requests, so two requests sharing a reference is normal business practice rather than an error. Enforcing uniqueness would block a legitimate case; the field is not, and never has been, a unique key.

**Related earlier change**

Follows the same pattern as *Allow the person who posted an invoice to correct a mistyped ERP document number* ([correct-the-erp-document-number-on-a-posted-invoice.md](correct-the-erp-document-number-on-a-posted-invoice.md)) — the person who keyed a hand-typed external reference is the one who may correct it, the surrounding record is left intact, and the correction is audited with what it replaced. Applying one rule to both fields keeps the system predictable for Finance and for Audit.

**Data and infrastructure impact**

None. No change to how information is stored, no migration, no new dependency, no infrastructure or configuration change, and no change to system access.

**Effort and dependency**

Small. One endpoint, one screen action with a dialog, and an audit entry, with automated test coverage. No dependency on any other team, vendor or system.
