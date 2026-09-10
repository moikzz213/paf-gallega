# Change Request

## Subject

Let Finance mark an invoice as a credit note when raising a payment request, so a credit already recorded as a positive amount is deducted instead of added

---

## Executive Summary

Invoices already on file include vendor credit notes that were entered as ordinary **positive** amounts. That was not a mistake by the person entering them — until recently the system refused to accept a negative amount at all, so a credit note could only be recorded by typing it as a positive figure and remembering, outside the system, that it was really a deduction.

Those invoices are still in the system, and they are wrong in a way that costs money. When Finance groups one into a payment request, the system **adds** it to the amount requested instead of subtracting it. A payment request that should ask for AED 14,750 asks for AED 25,250 — and because the amount requested is what determines which managers must approve it, the request is also routed to a more senior approver than it needs.

This change gives Finance a **Credit note** tick-box on each invoice in the payment request screen. Ticking it corrects that invoice's sign, so the credit is deducted from the request rather than added to it, and the request goes for approval on the amount that is genuinely payable. The tick-box appears only where it can apply: on an invoice recorded as a positive amount. An invoice already recorded as a credit note is shown as one and is deducted as it stands, with nothing to tick.

The correction is a real correction, not a note attached to one request. The invoice's own recorded amount is put right, because that is the truth of the document — it always was a credit note. This is also what keeps everything else honest: the payment request total, the approval routing, the printed request, the approval emails and every report all read the invoice's recorded amount, so correcting it once makes all of them right at the same time, with no chance of one disagreeing with another. Every correction is recorded in the audit trail together with the figures it replaced, so the original values remain recoverable.

The change is small, affects only the payment request screen and the invoices Finance explicitly ticks, and requires no change to how information is stored. Its significance comes from the fact that it changes an invoice's recorded amount and the amount a payment is approved on, which is what drives the Medium risk rating and the controls below.

---

## Business Reason for Change

**The business need.** Credit notes reduce what we owe. When one is grouped into a payment request, the payment must fall by that amount.

**The current gap.** Credit notes recorded as positive amounts are treated as charges. Grouping one **increases** what the request asks for, by twice the value of the credit relative to the correct figure. Finance has no way to tell the system otherwise, short of correcting each invoice by hand before grouping it.

**What this costs the business:**

- **Overpayment risk.** A request that asks for more than is owed can be approved and paid. The credit is then not merely unclaimed — it has been paid to the vendor as though it were a charge.
- **Approval routing on the wrong figure.** The inflated amount can push a request to a more senior approval tier than the real payable warrants, wasting senior management's time on requests that do not need them. In the reverse direction the exposure is worse: the credit is never applied, so the amount paid is simply too high.
- **A manual correction step, done under time pressure.** The alternative today is for Finance to edit each affected invoice before grouping it, which is slower, easy to forget, and unrecorded as a deliberate reclassification.
- **Reports that overstate spend.** While these invoices sit on file as positive amounts, spend figures and vendor rankings include credits as though they were charges.

**The opportunity.** A single tick at the moment Finance is already looking at the invoice puts the figure right, deducts it from the payment, routes the request on the true amount, and records the reclassification for audit.

---

## Affected Business Areas

**Departments and teams**

- **Finance / Accounts Payable** — primary beneficiary and the only team that performs the action. Gains a one-tick reclassification in the screen they already use to group invoices.
- **Approvers (department heads and above)** — will see payment requests whose amount is net of a deducted credit. Some requests will now route to fewer approvers, because the true payable is lower than the inflated figure was.
- **Internal Audit and Compliance** — gains a recorded reclassification for every credit that was previously mis-signed, including the figures it replaced.
- **Finance reporting / management reporting** — spend figures become more accurate as mis-signed credits are corrected.
- **Invoice submitters** — no change. They neither see nor perform the action.
- **IT / Systems** — delivery and support only; no ongoing operational change.

**Business processes**

- Payment request (PRF) creation and grouping
- Approval routing based on amount thresholds
- Payment processing
- Invoice records and their audit history
- Month-end reporting and vendor statement reconciliation

**Reports and dashboards**

- Management dashboard spend indicators and trend charts
- Top-vendor and business-unit spend rankings
- Invoice and payment reporting, including exports to Excel/CSV

**Explicitly not affected**

- Approval threshold amounts themselves (unchanged)
- User access, roles and permissions (unchanged) — only Finance and administrators raise payment requests, and only they can mark
- Invoice entry by submitters (unchanged)
- The rule that a payment request covers one currency and must come to more than zero (unchanged, and still enforced)
- Any external system or third-party integration

---

## Emergency Change Assessment

### Business Continuity

**Assessment: No**

**Justification:** The system is operating and payments are being processed. The defect produces a wrong figure on affected requests, not an outage, a security exposure or a compliance breach.

### Workaround Availability

**Assessment: Yes — a workaround exists**

**Justification:** Finance can correct each affected invoice by hand before grouping it, changing its amount to a deduction using the ordinary invoice edit. This produces the correct payment. It is manual, it must be remembered for every affected invoice, and it is not recorded as a deliberate reclassification, but it is available today.

### Operational Impact

**Assessment: Delay carries a real financial exposure**

**Justification:** This is the one assessment that is not comfortably negative. Every payment request raised with a mis-signed credit note in it asks for more than is owed, and if approved and paid, the business has overpaid a vendor by the value of that credit. The exposure is bounded by the value of credits sitting on file as positive amounts, and the workaround does mitigate it, but the exposure is ongoing until either the change ships or Finance applies the manual correction consistently.

### Timeline Constraints

**Assessment: No**

**Justification:** No external deadline, audit finding or regulatory date applies. The change is small enough to follow the normal assessment process without material delay.

**Emergency Change Classification: No**

**Reason:** A workaround is available and in Finance's hands today, and there is no continuity, security or compliance breach, so the normal route applies — which matters here, because the change alters both an invoice's recorded amount and the figure a payment is approved on, and that deserves full testing and business sign-off rather than a fast track. **Recommended as a priority change rather than an emergency:** Finance should be told to apply the manual correction on any affected request in the meantime, so the overpayment exposure is not simply carried until release.

---

## Risk Assessment

### Risk Level

**Medium**

The action is deliberate, limited to Finance, and available only where it can apply. It is rated Medium because it changes an invoice's recorded amount and, through it, both what is paid and who must approve it — the same relationship that drove the Medium rating on the two preceding credit-note changes.

### Risks Identified

**Business risks**

- **A charge could be ticked by mistake.** Ticking an ordinary invoice turns a genuine payable into a deduction, understating the request and potentially underpaying the vendor. This is the principal risk of the change.
- **Fewer approvers on a corrected request.** A deducted credit lowers the amount and therefore the approval tier required. This is the correct treatment — the business approves what it pays — but it must be visible to approvers rather than discovered later.
- **The correction changes an invoice on file.** Anyone comparing that invoice to an earlier report or export will see a different figure. The change is deliberate and recorded, but it is a change to a record already in use.

**Operational risks**

- **A misunderstanding of what the tick means.** Finance must understand that ticking reclassifies the invoice itself, not just its treatment on this one request. Incomplete communication leads to unintended corrections.
- **Ticking every invoice in a selection.** A request that nets to nothing is refused, which is correct, but Finance needs to recognise the refusal as a consequence of over-ticking.

**Security and compliance risks**

- **No change to access or permissions.** Only Finance and administrators can raise a payment request, and the action is available nowhere else. No new route into the system and no new data exposure.
- **Audit position improved.** Each reclassification is recorded against both the invoice and the payment request, with the replaced figures retained, so a correction that was previously an untracked manual edit becomes a documented event.
- **Segregation of duties unchanged.** The person raising a request still cannot approve it.

### Risk Mitigation Plan

1. **Offered only where it can apply.** The tick-box appears only on an invoice recorded as a positive amount. One already recorded as a credit note is labelled as such and cannot be marked — which would otherwise turn a credit back into a charge. Enforced by the system, not by the screen alone.
2. **A deliberate confirmation before sending.** Marking one or more invoices raises a confirmation listing each invoice, the amount recorded, the amount it will be corrected to, and the total the request will then ask for. This mirrors the confirmation the invoice form already requires for a credit line, and is the main control against ticking a genuine charge.
3. **A mark only counts on a selected invoice**, so a tick left behind on an invoice that is then deselected cannot silently affect the total.
4. **Nothing is changed unless the request is created.** The correction is applied and saved as one operation with the payment request. A request refused for any reason — nothing payable, mixed currencies, no approver, the creator on their own chain — leaves every invoice exactly as it was.
5. **The recorded amount stays consistent everywhere.** Because the invoice itself is corrected, the request total, the approval routing, the printed request, the emails and the reports all agree by construction; none of them has to know a mark was applied.
6. **Every correction is recoverable.** The audit trail records the reclassification against both the invoice and the payment request, with the figures it replaced, so an incorrect tick can be identified and reversed.
7. **The payable floor still applies.** A request must come to more than zero, so over-ticking is refused rather than paid.
8. **Full test coverage before release**, including that a refused request leaves no rewritten invoice behind, and that routing never loses an approver the corrected amount still requires.

---

## Expected Business Impact

### Positive Impact

- **Payments stop being overstated.** A credit note grouped into a request now reduces the payment, as it should.
- **Approval routing on the true figure.** Requests are approved by the tier the real payable warrants.
- **The manual pre-correction step disappears**, along with the risk of forgetting it.
- **Historical records are put right as they are used.** Each affected invoice is corrected the first time Finance handles it, rather than staying wrong indefinitely.
- **A documented reclassification.** What was an untracked manual edit becomes a recorded event with its original figures retained.
- **More accurate reporting.** Spend figures and vendor rankings stop counting credits as charges.

### Potential Negative Impact

- **An invoice's recorded amount can change.** Deliberate and audited, but a figure someone may have seen before will differ afterwards.
- **A new decision at grouping time.** Finance must judge, per invoice, whether it is a credit note — a judgement they already make outside the system today.
- **A mis-tick is possible.** The confirmation is the control; the audit trail is the remedy.

### User Impact

- **Finance:** a tick-box in the screen they already use, plus one confirmation before sending. Less manual work than the current workaround.
- **Approvers:** no change to what they do. Some requests will carry fewer approval stages, because the amount is now correct.
- **Invoice submitters:** no change.
- **Management:** more accurate payment amounts and spend reporting.

### Reporting Impact

Figures for corrected invoices change from a charge to a deduction, which is the accurate treatment. Reports run before and after a correction will differ for that invoice. No bulk restatement is performed — invoices are corrected individually, as Finance handles them.

### Compliance Impact

Positive. A correction that is performed manually and untracked today becomes a recorded, attributable event with its prior values retained. No new access path and no additional data exposure; segregation of duties is untouched.

---

## Implementation Overview

The payment request screen gains a **Credit note** tick-box against each invoice in the selection list.

- It appears only on an invoice recorded as a positive amount. An invoice already recorded as a credit note is labelled as one and is deducted as it stands.
- It can be ticked only on an invoice that is actually selected for the request.
- Ticking it shows that invoice as a deduction and updates the request total immediately, so Finance sees the real figure before sending.
- Sending a request that contains any marked invoice raises a confirmation showing each invoice, its recorded amount, the corrected amount, and the resulting request total.
- On confirmation, the marked invoices' recorded amounts are corrected to deductions and the payment request is created for the net figure, as a single operation. If anything prevents the request from being created, no invoice is changed.
- Each correction is recorded in the audit trail against both the invoice and the payment request, together with the figures it replaced.

The existing rules continue to apply unchanged: one currency per request, and a request must come to more than zero.

---

## Rollout Plan

1. **Development** — the tick-box, the confirmation, and the correction applied as one operation with request creation; automated tests covering the correction, the refusals, the routing and the audit trail.
2. **Internal Validation** — developer verification against the Test Case document, including that a refused request leaves invoices untouched.
3. **QA Verification** — full Test Case checklist: happy path, negative, security, and regression over ordinary requests with no marks.
4. **User Acceptance Testing** — Finance / Accounts Payable to mark a real mis-signed credit note, confirm the request total and routing, and take it through approval. Approver representative to confirm the deduction is visible on screen and on the printed request.
5. **Production Deployment** — standard release. No data migration and no downtime expected.
6. **Post Deployment Monitoring** — Finance to review the first week's corrections against the audit trail, confirming each marked invoice was genuinely a credit note.

---

## Backout Plan

1. **Suspend new functionality** — redeploy the prior release, which removes the tick-box. Finance returns to correcting affected invoices by hand before grouping them.
2. **Restore previous application state** — no stored information changes shape, so this is a straightforward reversal.
3. **Reverse individual corrections if required** — corrections already applied remain, and each is recorded in the audit trail with the figures it replaced, so any of them can be identified and reversed through the ordinary invoice edit. Corrections that were genuine should be kept: they made the record accurate.
4. **Validate business operations** — confirm payment request creation, approval and payment are functioning, and that any request raised with a correction still reconciles to its invoices.
5. **Notify stakeholders** — inform Finance / Accounts Payable that the manual pre-correction step applies again, and warn that the overpayment exposure returns with it.

---

## Approval Requirements

### Requestor

Finance / Accounts Payable — the team that discovered the defect and performs the action.

### Department Manager

Finance Manager — confirms that reclassifying a mis-signed credit note at grouping time, and correcting the invoice's own figures, matches the intended accounting treatment.

### IT Manager

Confirms scope, effort, test coverage and the backout position.

### Business Owner

Finance Director / CFO — owns the decision that Finance may correct an invoice's recorded amount from a charge to a deduction as part of raising a payment, and that approval on the corrected amount is right.

### CAB Approval (if applicable)

**Recommended.** The change alters recorded invoice amounts and the figure a payment is approved on. A CAB record is appropriate for the audit trail, and the priority-change recommendation above should be recorded with it.

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 2026-09-10

**Risk Rating:** Medium

**Emergency Change:** No (recommended as a priority change)

**Analysis Confidence:** High

| Confidence area | Rating | Basis |
|---|---|---|
| Analysis Confidence | High | The defect and the figures it affects were reproduced directly, and every place the amount is read was inspected. |
| Affected Features Confidence | High | Payment request creation is the only workflow that gains the action; the invoice correction it performs uses the existing recorded amounts. |
| Business Impact Confidence | High | The change is opt-in per invoice; a request with no marks behaves exactly as before, with no data migration. |
| Security Impact Confidence | High | No change to authentication, authorisation, roles or visibility scoping; the audit trail gains detail rather than losing it. |
| Risk Assessment Confidence | Medium | The residual risk is behavioural — ticking a genuine charge — which the confirmation and the audit trail mitigate but cannot eliminate. |

---

## Technical Analysis Appendix

*Included only to the extent needed to explain scope, effort and risk.*

**Affected systems**

Confined to the Payment Approval application: payment request creation, the invoice records it corrects, and the approval routing that reads the request amount. No other system participates.

**Why the invoice is corrected rather than flagged**

The payment request total, the approval routing, the release and correction safeguards, the printed request, the approval emails, the dashboard and every report all read the invoice's recorded amount. A flag that deducted the invoice on one request only would leave each of those reading the uncorrected figure, so the request total would no longer equal the sum of its invoices and the safeguards built on that equality would no longer hold. Correcting the amount once makes every surface right simultaneously, and is in any case the accurate record: the document always was a credit note.

**Why nothing is changed unless the request is created**

The correction is evaluated before the request is validated, so the amount, the currency check and the approval chain are all measured on the corrected figure — but it is written to the database only inside the same operation that creates the request. A request refused at any point therefore leaves every invoice as it was, which is verified by automated test.

**Related earlier changes**

Completes the credit-note work begun by *Allow vendor credit notes to be recorded on an invoice and deducted from the payment request total* ([allow-negative-line-amounts-for-credit-notes.md](allow-negative-line-amounts-for-credit-notes.md)) and *Allow a credit-only invoice to be recorded* ([allow-credit-only-invoices-netted-in-payment-requests.md](allow-credit-only-invoices-netted-in-payment-requests.md)). Those two made it possible to record a credit correctly going forward; this one addresses the invoices already on file, recorded before that was possible.

**Data and infrastructure impact**

No change to how information is stored, no migration, no new dependency, and no infrastructure or configuration change. Individual invoice amounts are corrected as Finance marks them, each one audited.

**Effort and dependency**

Small. One screen addition with a confirmation, one correction applied inside the existing creation operation, with automated test coverage. No dependency on any other team, vendor or system.
