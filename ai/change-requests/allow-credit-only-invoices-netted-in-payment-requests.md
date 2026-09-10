# Change Request

## Subject

Allow a credit-only invoice to be recorded, and require a payment request to net above zero — a credit must be grouped with the invoice it offsets

---

## Executive Summary

The business can already record a vendor credit note as a deduction on an invoice, provided that invoice still leaves something to pay. What it cannot record is a credit note that arrives on its own — a refund, a return or a rebate that stands as its own document, with no charge on the same paperwork to absorb it. Today the system refuses such a document outright, because every invoice must come to more than zero.

This is the second half of a change the business already approved in principle. When credit lines were introduced, credit-only invoices were consciously deferred as "a separate initiative with its own approval and settlement rules". That initiative is this Change Request.

Finance currently works around the gap by holding the credit note aside and hand-deducting it from a later invoice from the same vendor. The cash paid is right, but the credit note is never a document in its own right: it has no reference in the system, no audit history, and no record of when it arrived or which payment eventually absorbed it. A credit note received in one month and used in the next is invisible for the whole of the intervening period, which overstates what the business owes that vendor at month-end.

The change lets Finance record a credit-only invoice as a normal document, and then settle it where the netting genuinely belongs — in the payment request. When Finance groups documents into a payment request, the credit is selected alongside the charge it offsets, and the request goes for approval on the net figure. The system enforces the one rule that makes this safe: a payment request must still ask for more than zero. A credit selected on its own is refused, with a message telling Finance to add the invoice it offsets.

The change requires no change to how information is stored and does not alter approval thresholds, so no existing record is affected and ordinary invoices behave exactly as they do today. Its significance comes from the fact that the amount on a payment request is what decides which managers must approve it — the same connection that drove the Medium rating on the earlier credit-note change, and that drives it again here.

---

## Business Reason for Change

**The business need.** A vendor credit note is frequently a standalone document. A returned delivery, an over-charge corrected after the fact, a quarterly rebate — none of these arrive attached to a fresh charge. Finance needs to record the document when it arrives, not when a convenient invoice to offset it happens to turn up.

**The current gap.** The system requires every invoice to come to more than zero, so a standalone credit cannot be entered at all. It is held outside the system — in a mailbox, a spreadsheet or someone's memory — until a later invoice from the same vendor can absorb it by hand.

**What this costs the business:**

- **Unrecorded amounts owed to us.** A credit note held outside the system is money the vendor owes the business that appears nowhere in it. Between arrival and manual application, the business overstates its liability to that vendor.
- **Credits that are forgotten.** A credit held in a mailbox depends on the person who received it remembering to apply it. When they are on leave, or the vendor relationship changes hands, credits are missed and the business pays more than it owes.
- **No audit trail on the credit itself.** The credit note has no reference number, no arrival date, no supporting document attached and no history. An auditor cannot see when it was received or which payment absorbed it.
- **Weak vendor reconciliation.** Vendor statements list credit notes as documents. Ours do not exist until they are consumed, so statements cannot be reconciled document by document.
- **Manual calculation risk.** The netting is done outside the system, unchecked, on a figure that determines both what is paid and who has to approve it.

**The opportunity.** Recording the credit as a document from the day it arrives makes the amount owed to us visible, gives the credit its own auditable history and supporting attachments, and moves the netting into the system — where it is calculated, recorded, and measured against the approval thresholds automatically.

---

## Affected Business Areas

**Departments and teams**

- **Finance / Accounts Payable** — primary beneficiary. Gains the ability to record a credit note the day it arrives and settle it inside the system; loses the manual hold-and-deduct step.
- **Approvers (department heads and above)** — will see payment requests that contain a credit document alongside the charges. The amount presented for approval is the net payable, as it is today.
- **Invoice submitters across departments** — may record a credit-only document; the entry screen states plainly that it must be settled against a charge in a payment request.
- **Finance reporting / management reporting** — amounts owed to the business become visible from the date the credit is received rather than the date it is consumed.
- **Internal Audit and Compliance** — gains a complete record of every credit received, when it arrived, and which payment settled it.
- **IT / Systems** — delivery and support only; no ongoing operational change.

**Business processes**

- Invoice entry and correction
- Invoice Log review and posting by Finance
- Payment request (PAF) creation and grouping
- Approval routing based on amount thresholds
- Payment processing
- Month-end reporting and vendor statement reconciliation

**Reports and dashboards**

- Management dashboard spend indicators and trend charts, which will now include credit documents awaiting settlement
- Top-vendor and business-unit spend rankings
- Invoice and payment reporting, including exports to Excel/CSV

**Explicitly not affected**

- Approval threshold amounts themselves (unchanged)
- User access, roles and permissions (unchanged)
- The rule that a payment request covers a single currency (unchanged)
- Master data maintenance
- Any external system or third-party integration

---

## Emergency Change Assessment

### Business Continuity

**Assessment: No**

**Justification:** The system is fully operational. There is no outage, no security exposure and no compliance breach. Standalone credit notes are being handled today, albeit outside the system. Nothing in the current state threatens the continuity of business operations.

### Workaround Availability

**Assessment: Yes — a workaround exists**

**Justification:** Finance can hold the credit note aside and deduct it by hand from a later invoice from the same vendor, entering the deduction as a credit line on that invoice — a capability the business already has. This produces the correct payment. It is manual, it delays recognition of the amount owed to us, and it depends on an individual remembering, but it is a functioning process in use today.

### Operational Impact

**Assessment: No unacceptable impact from delay**

**Justification:** Delay prolongs a manual process and keeps credits invisible until consumed. The financial exposure is the value of unrecorded credits at any one time and the risk of one being forgotten — real, and worth correcting, but neither material to a single reporting period nor damaging to reputation.

### Timeline Constraints

**Assessment: No**

**Justification:** There is no external deadline, audit finding, regulatory date or vendor commitment attached to this change. It can and should follow the normal change assessment process.

**Emergency Change Classification: No**

**Reason:** All four assessments are negative. A workaround is in daily use, no continuity or compliance risk exists, and no deadline compresses the timeline. This change should be delivered through the standard route, with the full testing and user acceptance cycle — the appropriate treatment for a change that touches the figure approval routing is measured against.

---

## Risk Assessment

### Risk Level

**Medium**

The change is small and narrowly scoped, and it introduces no new way for money to leave the business. It is rated Medium — not Low — because it widens what the system accepts as a valid invoice amount, and the invoice amount is the figure that determines which managers must sign off on a payment. Any change touching that relationship warrants deliberate verification rather than routine release.

### Risks Identified

**Business risks**

- **A credit could be entered where a charge was intended.** A mistyped or wrongly signed figure could turn an invoice the business owes into one it is owed, or the reverse. The consequence is a misstated payable that a reviewer must catch.
- **Approval routing on the net figure.** A payment request pairing a large charge with a large credit is approved on the small net amount, which may require fewer approvers than the gross charge would. This is the correct and intended treatment — the business approves what it pays — but it must be understood and visible to approvers rather than discovered later.
- **Credits could accumulate unsettled.** Once a credit can be recorded on its own, it can also sit unsettled against any payment indefinitely. Without periodic review, the business holds credits it never claims.

**Operational risks**

- **A credit selected alone cannot be paid.** By design, a payment request must ask for more than zero. Finance will encounter a refusal when selecting a credit without the charge it offsets. If the message is unclear, this reads as a system fault and generates support traffic.
- **Ambiguity about how a credit is settled.** Users must understand that a credit is settled by grouping it with a charge, not by processing it on its own. Incomplete communication leads to repeated failed attempts.
- **Correction of a document already inside an approved payment request.** A correction could in principle reduce a request below zero after approvers have signed it. The change closes this off explicitly rather than relying on it not happening.

**Security and compliance risks**

- **No change to access or permissions.** Who may submit, post, group, approve and pay is entirely unchanged. No new route into the system and no new data exposure is created.
- **Audit trail strengthened, not weakened.** Every credit becomes a recorded document with its own history and attachments, and every netting is recorded on the payment request rather than performed off-system. This change improves the audit position.
- **Segregation of duties unchanged.** The rule that the person raising a payment request cannot approve it is untouched.

### Risk Mitigation Plan

1. **A hard floor on every payment.** A payment request must come to more than zero. This is enforced by the system, not by procedure, and it is what makes a credit-only document safe to accept: a credit can never become a payment on its own.
2. **A clear, actionable refusal.** When a credit is selected without an offsetting charge, the message names the amount and tells Finance to add the invoice it offsets — so the refusal reads as guidance, not a fault.
3. **Deliberate confirmation at entry.** The existing confirmation step for credit lines is retained, so a stray minus sign is confirmed by the person entering it before it reaches Finance.
4. **Visible netting for approvers.** The payment request continues to show the documents it contains and the net figure, so an approver sees that a credit is included and what it reduced.
5. **Corrections cannot undermine a granted approval.** A correction to a document held by a payment request may not take that request to zero or below, protecting approvals already given.
6. **Periodic review of unsettled credits.** Finance to review credit documents not yet included in a payment request as part of the month-end routine, so credits are claimed rather than accumulated.
7. **Full test coverage before release.** Automated tests plus the documented Test Case checklist, covering entry, grouping, refusal, correction and approval routing.

---

## Expected Business Impact

### Positive Impact

- **Amounts owed to the business become visible.** A credit is recorded from the day it arrives, not the day it is consumed, so the payables position is accurate throughout.
- **Credits stop being forgotten.** Once in the system, a credit is a document Finance can see, filter and act on, rather than an item depending on individual memory.
- **A complete audit trail.** Every credit has a reference, an arrival date, supporting attachments and a full history, and the payment that settled it is recorded.
- **Vendor reconciliation becomes straightforward.** Our records list credit notes as documents, matching how vendor statements present them.
- **The netting is calculated by the system.** The figure that goes for approval and payment is derived, recorded and checked, not hand-computed outside the system.
- **Less manual effort.** The hold-and-deduct routine disappears.

### Potential Negative Impact

- **A new refusal Finance will encounter.** Selecting a credit without an offsetting charge is refused. This is intentional and protective, but it is a new experience that requires explanation.
- **Spend figures include documents awaiting settlement.** Reporting over invoices will include credit documents from the date they are recorded, which is more accurate but will read differently from prior periods.
- **A brief learning curve.** Finance and submitters must understand that a credit is settled inside a payment request.

### User Impact

- **Invoice submitters:** may now record a credit-only document. The entry screen explains what happens next — it must be grouped with a charge in a payment request.
- **Finance:** gains the ability to record credits on arrival and settle them by grouping. Encounters a clear refusal when a selection does not net above zero.
- **Approvers:** no change to what they do. Payment requests continue to present a net figure and the documents behind it.
- **Management:** more accurate visibility of amounts owed to the business.

### Reporting Impact

Invoice and spend reporting will include credit documents once recorded. Net figures on payment requests are unchanged in meaning. Existing historical figures are not restated in any way.

### Compliance Impact

Positive. The change moves a financial calculation from outside the system to inside it, and gives every credit note a recorded, auditable existence. It creates no new access path, exposes no additional data, and leaves segregation of duties intact.

---

## Implementation Overview

The change adjusts *where* the "must come to more than zero" rule is applied, rather than removing it.

Today the rule sits on the individual invoice. After this change it sits on the payment request — the point at which the business actually decides to pay. An invoice may therefore be recorded as a pure credit, while a payment request must still ask for a positive amount.

Three adjustments deliver this:

1. **Invoice entry** accepts a document that comes to less than zero. A document that comes to exactly zero remains refused, as it represents neither a charge nor a credit.
2. **Payment request creation** requires the documents selected to come to more than zero, and where they do not, tells Finance to add the invoice the credit offsets.
3. **Correction of a document already held by a payment request** may not take that request to zero or below, so approvals already granted remain valid.

Both the on-screen forms and the underlying system enforce the same rules, so a user sees the position before submitting and cannot bypass it. No stored information changes shape, so no data migration is required and no existing record is altered.

---

## Rollout Plan

1. **Development** — implement the three adjustments; automated tests covering entry, grouping, refusal, correction and approval routing.
2. **Internal Validation** — developer verification against the Test Case document, including the approval routing behaviour on netted requests.
3. **QA Verification** — execute the full Test Case checklist: happy path, negative, security, and regression over ordinary invoices and existing payment requests.
4. **User Acceptance Testing** — Finance / Accounts Payable to record a credit-only document, group it with a charge, and take the resulting payment request through approval. Approver representative to confirm the netting is clear on screen and on the printed request.
5. **Production Deployment** — standard release. No data migration and no downtime expected.
6. **Post Deployment Monitoring** — Finance to confirm during the first week that credits are being recorded and settled as expected, and to review unsettled credits at the first month-end after release.

---

## Backout Plan

1. **Suspend new functionality** — restore the requirement that an individual invoice comes to more than zero, which stops further credit-only documents from being recorded.
2. **Restore previous application state** — redeploy the prior release. No stored information changes shape, so this is a straightforward reversal.
3. **Restore backups if required** — not expected to be necessary; no data migration forms part of this change.
4. **Validate business operations** — confirm ordinary invoice entry, payment request creation and approval are functioning. Any credit-only document already recorded must be reviewed by Finance and handled through the existing manual routine.
5. **Notify stakeholders** — inform Finance / Accounts Payable and approvers that credit-only documents must again be held aside and deducted manually.

---

## Approval Requirements

### Requestor

Finance / Accounts Payable — as the team recording vendor credit notes and grouping documents for payment.

### Department Manager

Finance Manager — confirms that settling a credit inside a payment request matches the intended accounting treatment, and that approval on the net figure is correct.

### IT Manager

Confirms scope, effort, test coverage and the backout position.

### Business Owner

Finance Director / CFO — owns the decision that a credit note may exist as a document in its own right, and that a payment request must always ask for more than zero.

### CAB Approval (if applicable)

**Recommended.** The change is small, but it widens what the system accepts as a valid invoice amount, and that amount determines approval routing. A CAB record is appropriate for the audit trail.

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 2026-09-10

**Risk Rating:** Medium

**Emergency Change:** No

**Analysis Confidence:** High

| Confidence area | Rating | Basis |
|---|---|---|
| Analysis Confidence | High | The rule being moved is enforced in a small number of known places, all inspected directly. |
| Affected Features Confidence | High | Invoice entry, payment request creation and in-place correction are the only workflows that apply the rule. |
| Business Impact Confidence | High | The change is additive; existing behaviour for ordinary invoices is unchanged, with no data migration. |
| Security Impact Confidence | High | No change to authentication, authorisation, roles, visibility scoping or the audit trail beyond additional recorded detail. |
| Risk Assessment Confidence | Medium-High | The principal residual risk is behavioural — a credit entered where a charge was intended — which controls mitigate but cannot eliminate. |

---

## Technical Analysis Appendix

*Included only to the extent needed to explain scope, effort and risk.*

**Affected systems**

Confined to the Payment Approval application: invoice entry and correction, payment request creation, and the approval routing that reads the request amount. No other system participates.

**Why the floor must remain somewhere**

Approval routing selects the managers who must sign off by comparing the amount against configured thresholds, the lowest of which is zero. An amount of zero or less matches no threshold at all, which means no approver could be assigned and the request could never be routed or paid. Enforcing "more than zero" at the payment request is therefore not a policy preference but a structural requirement — and it is what allows the floor to be safely lifted from the individual invoice.

**Related earlier change**

This Change Request completes the initiative deferred by *Allow vendor credit notes to be recorded on an invoice and deducted from the payment request total* ([allow-negative-line-amounts-for-credit-notes.md](allow-negative-line-amounts-for-credit-notes.md)), which recommended that credit-only invoices be handled separately, with their own settlement rules. Those rules are the subject of this document.

**Data and infrastructure impact**

None. No change to how information is stored, no migration, no new dependency, no infrastructure or configuration change, and no change to system access.

**Effort and dependency**

Small. Three enforcement points, mirrored in the corresponding screens, with automated test coverage. No dependency on any other team, vendor or system.
