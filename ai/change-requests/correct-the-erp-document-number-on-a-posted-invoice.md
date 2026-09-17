# Change Request

## Subject

Allow the person who posted an invoice to correct a mistyped ERP document number, without re-posting the invoice

---

## Executive Summary

When Finance posts an invoice, they type in the document number the ERP gave it. That number is the link between a payment made through this system and the accounting record in the ERP — it is what a reconciliation, an audit query or a vendor dispute is traced through, and it is printed on the payment request.

It is typed by hand, so it can be typed wrong. At present there is no way to fix it. Once an invoice is posted, the posting action refuses to run again, and the ordinary invoice edit does not touch the posting fields. A single mistyped digit is therefore permanent, and it points at either nothing at all or — worse — at some other document in the ERP.

This change adds an **Edit Posting** action on the invoice view, available once an invoice is posted, that lets the document number and posting date be corrected. It is restricted to **the person who recorded the posting**, since they are the one who had the ERP document in front of them and can say what the number should have been. An administrator can also correct it, which is the way through when that person has left the business or is unavailable.

Correcting is deliberately not the same as re-posting. The invoice stays posted, and the record of **who** posted it and **when** is untouched — those are facts about what happened. Only the document number and posting date change, and the correction is written to the audit trail as its own event, alongside the values it replaced, so the original number remains visible and the change is attributable.

The change is small, touches one screen and two fields, alters no amount, and requires no change to how information is stored. It is rated Low-to-Medium risk: it permits a change to a record that was previously final, which is exactly why the restriction to the original poster and the audit trail matter.

---

## Business Reason for Change

**The business need.** The ERP document number has to be right. It is the reference that ties a payment in this system to the accounting entry in the ERP.

**The current gap.** The number can only be entered once, at the moment of posting, and can never be changed. A typo is permanent.

**What this costs the business:**

- **A broken link to the accounting record.** A wrong number points at nothing, or at a different document. Anyone reconciling the payment, answering an audit query or investigating a vendor dispute has to work out by hand which ERP document was really meant.
- **A wrong number on a document sent for approval.** The number is printed on the payment request, so approvers and the eventual payment file carry the error forward.
- **No sanctioned way to fix it.** Because the system offers no correction, the alternatives are all worse: leave it wrong, or ask for a direct change to the database — unrecorded, unattributable, and outside any control.
- **Errors surface late.** A wrong number is usually noticed during reconciliation, which happens after the invoice has been paid — precisely when nothing can be changed.

**The opportunity.** A short, restricted, fully logged correction by the person who did the posting, in the screen they are already looking at.

---

## Affected Business Areas

**Departments and teams**

- **Finance / Accounts Payable** — the only team affected and the only one that can perform the action. Gains the ability to fix their own typing.
- **Internal Audit and Compliance** — gains a recorded, attributable correction where previously there was either an uncorrected error or an untracked database change.
- **Approvers** — no change to what they do. Payment requests they see and sign carry the corrected number.
- **Invoice submitters** — no change. They cannot see or perform the action.
- **IT / Systems** — delivery and support only. Notably, this removes the need for ad-hoc database corrections, which are an IT support cost and a control weakness.

**Business processes**

- ERP posting of an invoice
- Reconciliation of PAF payments against the ERP
- Payment request preparation and printing
- Audit and vendor dispute investigation

**Reports and dashboards**

- Invoice reporting and exports, which include the ERP document number
- The printed payment request

**Explicitly not affected**

- Any amount, tax figure or total
- Approval routing, approval thresholds, or approvals already given
- Payment status, payment references, or anything about money already paid
- Who posted an invoice and when — unchanged by a correction
- User access, roles and permissions
- Any external system: this records what the ERP already did; it does not write to the ERP

---

## Emergency Change Assessment

### Business Continuity

**Assessment: No**

**Justification:** The system is operating normally. A wrong document number is a data-quality and traceability problem, not an outage, a security exposure or a payment failure.

### Workaround Availability

**Assessment: Partly — and the available workaround is itself undesirable**

**Justification:** There is no workaround within the application. The only ways to correct the number today are a direct database change, or leaving the error in place and tracking the real document number outside the system. The first is unrecorded and bypasses application controls; the second degrades the record permanently. Both are worse than the change being requested, which is the substance of the case for making it.

### Operational Impact

**Assessment: No unacceptable impact from delay**

**Justification:** Affected invoices remain payable and the payments themselves are correct — only the reference to the ERP document is wrong. The cost of delay is manual reconciliation effort and a weaker audit trail, which is real but not material to a reporting period and carries no financial exposure.

### Timeline Constraints

**Assessment: No**

**Justification:** No external deadline, audit finding or regulatory date applies.

**Emergency Change Classification: No**

**Reason:** No continuity, security or compliance breach, and no deadline. The change should follow the normal route — which suits it, because it makes a previously final record changeable, and that deserves proper testing and business sign-off rather than a fast track.

---

## Risk Assessment

### Risk Level

**Low to Medium**

The action changes no amount, no approval and no payment, and it cannot be reached by anyone outside Finance. It is not rated plainly Low because it permits a change to a record the system previously treated as final, and because the field it changes is the one an auditor uses to trace a payment.

### Risks Identified

**Business risks**

- **A correction could itself be wrong.** The replacement number is typed by hand, exactly like the original, so a correction can introduce a new error. The audit trail is what makes this detectable and fixable rather than compounding.
- **The number could be changed to point at a different, valid ERP document.** Whether by mistake or deliberately, this would mis-link a payment to accounting. Restricting the action to the original poster, and logging every change with its previous value, is the control.
- **A perception that posted records are no longer firm.** Finance and Audit need to understand precisely what can change — the document number and posting date — and what cannot: the amount, the approvals, the payment, and who posted it.

**Operational risks**

- **The original poster may be unavailable.** Restricting the action to them would otherwise leave the error unfixable, which is why an administrator can also make the correction.
- **A correction after payment.** Permitted deliberately, because reconciliation — where a wrong number surfaces — happens after payment. This must be understood as intended behaviour rather than a gap, and it changes nothing about the payment itself.

**Security and compliance risks**

- **No change to access.** The action sits behind the same Finance/administrator restriction as posting itself, and is further limited to the person who posted the specific invoice. No new route into the system and no additional data exposure.
- **Audit position improved.** It replaces either an uncorrected error or an untracked database edit with a logged, attributable, in-application correction that retains the previous value.
- **Segregation of duties unchanged.** Nothing about who approves or pays is affected.

### Risk Mitigation Plan

1. **Restricted to the person who recorded the posting.** They had the ERP document in front of them. Another Finance user, however senior, cannot correct someone else's posting; the refusal names the person to ask.
2. **An administrator override**, so an error cannot become unfixable when someone leaves. Deliberately narrow — administrators only.
3. **Enforced on the server, not just on screen.** The action is hidden when it does not apply and refused if the request is made anyway.
4. **Available only on a posted invoice.** There is nothing to correct otherwise, and a queried invoice that still carries a document number is not resolved this way — the query is.
5. **Correcting is not re-posting.** Who posted the invoice, when, and its posted status are untouched, so the correction cannot be used to reassign responsibility for the posting.
6. **Every correction is logged as its own event**, with the previous document number and posting date retained and the person who made it recorded. The original posting entry stays in the log unchanged.
7. **The screen states what will and will not change** before the correction is made, and refuses a confirmation where nothing has actually been edited.
8. **Full test coverage**, including each refusal, the audit content, and that amounts, approvals and payment status are untouched at every payment stage.

---

## Expected Business Impact

### Positive Impact

- **The link to the ERP can be made correct.** Reconciliation, audit queries and vendor disputes trace straight through.
- **No more out-of-system corrections.** Removes the need for direct database changes, which are unrecorded, unattributable and a control weakness.
- **Errors can be fixed when they are found**, which is typically at reconciliation, after payment.
- **A better audit trail than today**, in both the corrected and uncorrected cases: the change is attributable and the previous value is retained.
- **Less manual effort** for Finance, IT and Audit.

### Potential Negative Impact

- **A posted record is no longer entirely final.** Deliberate, narrow and logged, but a change in how the system has behaved until now.
- **A correction can be mistyped too.** Mitigated by the audit trail rather than prevented.
- **A number can change after a report was run.** A report produced before a correction will show the old number.

### User Impact

- **Finance:** an **Edit Posting** action on invoices they posted, prefilled with what is recorded. They cannot correct a colleague's posting, and are told who to ask.
- **Administrators:** can correct any posting, for the cases where the original poster is unavailable.
- **Approvers and submitters:** no change.
- **Audit:** a new, clearly labelled correction event in the invoice history.

### Reporting Impact

The ERP document number on a corrected invoice changes in all reports and on the printed payment request. Nothing else in any report changes. No bulk restatement is involved — corrections are made individually.

### Compliance Impact

Positive. It converts an uncorrectable error, or an untracked database edit, into a controlled in-application correction that is restricted, attributable, and retains what it replaced.

---

## Implementation Overview

An **Edit Posting** action appears on the invoice view once an invoice is posted, for the person who recorded the posting and for administrators.

- It opens with the recorded document number and posting date already filled in, since the purpose is to fix a keystroke in them.
- It states plainly that the invoice stays posted and that the person recorded as having posted it does not change.
- It refuses a confirmation where nothing has been edited.
- On confirmation, the document number and posting date are updated, and the correction is written to the invoice history alongside the values it replaced.
- The same rules are enforced by the system independently of the screen, so the action cannot be performed by anyone else or on an invoice that is not posted.

Everything else about the invoice — its amounts, approvals, payment status, and the record of who posted it and when — is untouched.

---

## Rollout Plan

1. **Development** — the action, its restriction, and the audit entry; automated tests covering every refusal and the audit content.
2. **Internal Validation** — developer verification against the Test Case document, including that amounts, approvals and payment status are unaffected at each payment stage.
3. **QA Verification** — full Test Case checklist: happy path, negative, security, and regression over posting itself.
4. **User Acceptance Testing** — Finance to correct a document number on an invoice they posted, and confirm they cannot correct a colleague's. An administrator to confirm the override. Audit to review the resulting history.
5. **Production Deployment** — standard release. No data migration and no downtime expected.
6. **Post Deployment Monitoring** — Audit or the Finance Manager to review corrections in the first month, confirming each was a genuine typing correction.

---

## Backout Plan

1. **Suspend new functionality** — redeploy the prior release, which removes the action. Posted document numbers become final again.
2. **Restore previous application state** — no stored information changes shape, so this is a straightforward reversal.
3. **Reverse individual corrections if required** — each is recorded with its previous value, so any can be identified and reversed. Corrections that were genuine should be kept: they made the record accurate.
4. **Validate business operations** — confirm posting, payment request creation, approval and payment are unaffected.
5. **Notify stakeholders** — inform Finance that a mistyped document number is again uncorrectable in the application, and agree how such cases will be handled in the interim.

---

## Approval Requirements

### Requestor

Finance / Accounts Payable — the team that posts invoices and discovered that a typo could not be fixed.

### Department Manager

Finance Manager — confirms that the person who posted an invoice is the right person to correct its document number, and that correcting after payment is acceptable.

### IT Manager

Confirms scope, effort, test coverage and the backout position, and that this removes a recurring need for ad-hoc database corrections.

### Business Owner

Finance Director / CFO — owns the decision that a posted invoice's ERP document number may be corrected in the application, under the restrictions described.

### CAB Approval (if applicable)

**Recommended but not essential.** The change is narrow and alters no financial figure, but it makes a previously final record changeable and touches a field used for audit traceability, so a CAB record is appropriate.

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 2026-09-10

**Risk Rating:** Low to Medium

**Emergency Change:** No

**Analysis Confidence:** High

| Confidence area | Rating | Basis |
|---|---|---|
| Analysis Confidence | High | The posting fields and every place they are read were inspected directly; the gap was reproduced in the running application. |
| Affected Features Confidence | High | Two fields on one screen. No amount, approval or payment figure participates. |
| Business Impact Confidence | High | Additive and opt-in per invoice; no data migration, and nothing changes for invoices nobody corrects. |
| Security Impact Confidence | High | Same role restriction as posting, narrowed further to the original poster; audit trail gains an event. |
| Risk Assessment Confidence | Medium-High | The residual risk is behavioural — a correction that is itself wrong, or points at a different valid document — which the restriction and the audit trail mitigate but cannot eliminate. |

---

## Technical Analysis Appendix

*Included only to the extent needed to explain scope, effort and risk.*

**Affected systems**

Confined to the Payment Approval application: the invoice view, the two posting fields, and the invoice history. The ERP is not written to and is not involved — this records a number the ERP already issued.

**Why a correction rather than re-posting**

Posting is refused on an invoice that is no longer awaiting posting, deliberately: re-posting would silently replace the recorded document number and rewrite who posted it and when. Those are facts about what happened and must not move. A separate, narrower correction changes only the two fields that can be mistyped, leaves the posting record itself intact, and is logged as its own event — which is also what keeps the history readable, since the original posting entry survives unchanged.

**Why it is allowed after payment**

The field is a reference to an external document, not a financial figure. It affects no amount, no approval and no payment, and reconciliation against the ERP — the point at which a wrong number is actually noticed — takes place after payment. Blocking the correction at that point would make the common case unfixable. Verified by test at each payment stage that amounts, approvals and payment status are untouched.

**Data and infrastructure impact**

None. No change to how information is stored, no migration, no new dependency, no infrastructure or configuration change, and no change to system access.

**Effort and dependency**

Small. One endpoint, one screen action with a dialog, and an audit entry, with automated test coverage. No dependency on any other team, vendor or system.
