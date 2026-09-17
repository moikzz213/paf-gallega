# Change Request

## Subject

Allow vendor credit notes to be recorded on an invoice and deducted from the payment request total

---

## Executive Summary

The business regularly receives credit notes from vendors — refunds, returns, corrections and rebates that reduce what we actually owe. The Payment Approval system currently has no way to record them. Every line entered on an invoice must be a positive amount, so a credit cannot be captured at all.

Finance works around this by subtracting the credit by hand before entering the invoice. The amount paid ends up correct, but the credit note itself never appears in the system. The invoice on file no longer matches the vendor's paperwork line for line, there is no record of which credit note was applied or for how much, and a calculation that should belong to the system sits in a manual step where it can be forgotten or mistyped.

This change allows a credit to be entered as its own line on the invoice, as a deduction. The system then calculates the net amount owed automatically and carries that net figure through to the payment request, the approval routing, and the payment.

The change is small in scope and requires no change to how information is stored, so no existing records are affected and ordinary invoices behave exactly as they do today. Its significance comes from where it sits: the invoice amount is the figure that decides which managers must approve a payment. That connection is what drives the Medium risk rating and the recommended controls below.

One business decision is needed before development begins: whether an invoice must always end up with a positive amount owed. The recommendation is yes — a credit note should reduce a genuine payable, and a credit-only invoice (where we owe nothing, or the vendor owes us) should be handled as a separate initiative with its own approval and settlement rules.

---

## Business Reason for Change

**The business need.** Vendor credit notes are a normal part of purchasing. When one arrives, the amount we owe on the related invoice drops, and the reduced figure is what should be approved and paid.

**The current gap.** The system accepts only positive amounts on an invoice, so a credit note cannot be entered. Finance must calculate the net amount outside the system and enter that single adjusted figure instead.

**What this costs the business:**

- **Weakened audit trail.** The credit note is invisible in the system. An auditor reviewing the invoice cannot see that a credit was applied, which one, or for how much — and credits are exactly the transactions auditors query most.
- **Reconciliation difficulty.** The invoice on file no longer matches the vendor's documents line by line, making disputes and vendor statement reconciliation slower.
- **Manual calculation risk.** A financial calculation performed outside the system can be mis-keyed or skipped. There is no system check on it.
- **Avoidable effort.** Every credit note adds a manual step to invoice entry.

**The opportunity.** Recording the credit as a line item puts the calculation back inside the system, makes the net amount self-documenting and auditable, and ensures the approval routing is measured against the amount that will genuinely be paid.

---

## Affected Business Areas

**Departments and teams**

- **Finance / Accounts Payable** — primary beneficiary. Gains the ability to record credit notes directly; loses the manual netting step.
- **Approvers (department heads and above)** — will see invoices containing a deduction line. The amount presented for approval is the net payable.
- **Vendor-facing staff** — invoices in the system will now reconcile against vendor documentation line by line.
- **Finance reporting / management reporting** — spend figures will reflect net amounts.
- **Internal Audit and Compliance** — gains full visibility of credits applied to payments.
- **IT / Systems** — delivery and support only; no ongoing operational change.

**Business processes**

- Invoice entry and correction
- Payment request (PAF) creation
- Approval routing based on amount thresholds
- Payment processing
- Month-end reporting and vendor reconciliation

**Reports and dashboards**

- Management dashboard spend indicators and trend charts
- Top-vendor and business-unit spend rankings
- Invoice and payment reporting, including exports to Excel/CSV

**Explicitly not affected**

- Approval threshold amounts themselves (unchanged)
- User access, roles and permissions (unchanged)
- Master data maintenance
- Any external system or third-party integration

---

## Emergency Change Assessment

### Business Continuity

**Assessment: No**

**Justification:** The system is fully operational. There is no outage, no security exposure and no compliance breach. Credit notes are being handled today, albeit manually. Nothing about the current state threatens the continuity of business operations.

### Workaround Availability

**Assessment: Yes — a workaround exists**

**Justification:** Finance can reduce the invoice amount by the value of the credit and note the credit-note reference in the description field. This produces the correct amount to pay. It is manual and it does not preserve a proper record of the credit, but it is a functioning process the team already uses.

### Operational Impact

**Assessment: No unacceptable impact from delay**

**Justification:** Delaying the change continues the manual effort and the weaker audit trail. It does not cause incorrect payments — the workaround yields the right net figure — and carries no direct financial loss or reputational exposure.

### Timeline Constraints

**Assessment: No**

**Justification:** No deadline requires bypassing normal assessment. Because the change touches the amount that determines approval authority, it specifically benefits from the full review, QA and user acceptance cycle rather than an accelerated path.

**Emergency Change Classification: No**

**Reason:** This is a standard change. There is no immediate risk to continuity, security or compliance; a viable workaround is in place; and the approval-authority implications warrant the normal assessment and testing path.

---

## Risk Assessment

### Risk Level

**Medium**

The change itself is small and self-contained. The rating reflects its position rather than its size: it alters the invoice amount, and the invoice amount decides which managers must approve a payment. The risk lies in the consequences downstream, not in the change.

### Risks Identified

**Business risks**

1. **Approval authority could be reduced.** Because a credit reduces the amount owed, it also reduces the approval level required. This is the correct behaviour — approval should follow what we actually pay — but it means a deduction is a legitimate route to a lower approval tier and must be deliberately controlled and tested.
2. **A fully or over-credited invoice has no defined handling.** If credits cancel the invoice out entirely, or exceed it, the amount owed becomes zero or negative. The system has no approval route and no payment meaning for such an amount, and would stop the payment request from being created. Finance would be blocked with an unclear message.
3. **Data entry error becomes possible.** Today a mistyped deduction is rejected outright. Once deductions are permitted, an incorrectly entered one silently lowers a payable amount. The current restriction is, incidentally, protecting against typing mistakes.

**Operational risks**

4. **Deductions could be misread.** On a dense invoice listing, a payment document or an email notification, a deduction shown only with a minus sign is easy to overlook. An approver could approve a figure they have misunderstood.
5. **Reporting figures will change.** Management spend figures, trend charts and vendor rankings will reflect net amounts. They become more accurate, but they will be lower than the equivalent figures before the change, and vendor rankings may re-order. This needs to be explained to report consumers so it is not read as a data problem.
6. **An existing safeguard rests on an assumption that no longer holds.** When an invoice is removed from a payment request already under approval, the system assumes the remaining total can only go down. Removing an invoice carrying a net credit would raise it — potentially above the level the recorded approvals covered.

**Security risks**

7. **No change to access control.** Authentication, roles, permissions and data visibility are entirely unchanged. No new information is exposed and no new user gains access to anything.
8. **Financial integrity is the genuine security concern.** The material risk is approval-threshold circumvention, as described in business risk 1 and operational risk 6, not unauthorised access. Existing controls largely hold — approval is already measured on the net amount, and the system already refuses to create a payment request with no approvers — but these controls must be explicitly proven under test rather than assumed.

### Risk Mitigation Plan

| # | Mitigation | Addresses |
|---|-----------|-----------|
| 1 | **Agree the amount policy with Finance before development.** Recommendation: individual lines may be deductions, but the invoice must end up with a positive amount owed. This closes the undefined zero/negative cases outright. | Risks 2, 6 |
| 2 | **Reject a zero-value line.** A line of zero carries no information and is always an error. | Risk 3 |
| 3 | **Require confirmation when a deduction line is entered,** so an accidental minus sign is caught at the point of entry rather than in an approval queue. | Risk 3 |
| 4 | **Present deductions unmistakably** — accounting-style formatting on screen, on the payment document, in the public view and in all notification emails. | Risk 4 |
| 5 | **Test approval routing explicitly against credited amounts,** confirming the chain generated always matches the net amount and never omits an approver that amount requires. | Risks 1, 8 |
| 6 | **Strengthen the invoice-removal safeguard** so removing an invoice that carries a net credit is either re-routed for approval or refused. | Risk 6 |
| 7 | **Record the net amount in the audit trail on submission,** and flag any invoice that nets to zero or below for review. | Risks 1, 2, 8 |
| 8 | **Brief report consumers before release** that spend figures will move to a net basis. | Risk 5 |
| 9 | **Full regression testing** confirming ordinary all-positive invoices are entirely unaffected. | All |

---

## Expected Business Impact

### Positive Impact

- **Complete audit trail for credits.** Every credit note is recorded against the invoice it applies to, with its own value and description. Auditors can see what was credited and why.
- **Invoices reconcile to vendor documents.** Line-by-line matching against vendor paperwork and statements becomes possible again, shortening dispute resolution.
- **The calculation moves back into the system.** The net amount owed is computed and audited by the system rather than by hand, removing a source of error.
- **Approvals reflect reality.** Managers approve the amount that will actually leave the business.
- **More accurate spend reporting.** Management figures reflect net spend rather than gross amounts with credits applied invisibly outside the system.
- **Less manual effort** in invoice entry.

### Potential Negative Impact

- **A deduction entered in error is no longer rejected.** Mitigated by the zero-line rule, the confirmation prompt, and clear presentation (mitigations 2–4).
- **Credit-only invoices remain unsupported.** If the business needs to record an invoice where nothing is owed, or where the vendor owes us, that is deliberately out of scope and requires a separate change request. This is stated plainly so expectations are set at approval time.
- **Reported spend figures will fall** where credits apply. This is a correction, not a regression, but it must be communicated in advance.

### User Impact

**Finance / Accounts Payable** — can enter a credit note as a line on the invoice. The amount field will accept a deduction, with a confirmation step. The manual netting step disappears. Training required: minimal, approximately one briefing.

**Approvers** — may see a deduction line on the invoices they approve. The total presented is the net payable, which is what they are approving. No change to how they act on a request. Training required: an explanatory note only.

**Administrators** — no change. Approval threshold amounts are unchanged.

**All other users** — no change.

### Reporting Impact

Spend figures across the dashboard and reports will move to a net basis. Specific items to verify before release:

- Dashboard spend indicators (pending, in approval, paid this month)
- The status breakdown and six-month submitted-versus-paid trend
- Top-vendor and business-unit spend rankings, which may re-order once credits are applied
- Report screen totals and Excel/CSV exports
- Charts, which must display a reduced or negative figure sensibly

There are no external consumers of these reports; the review is internal only.

### Compliance Impact

**Positive.** The change strengthens the financial audit trail rather than weakening it. Credits become visible transactions with their own record instead of an invisible manual adjustment.

Two compliance points to preserve:

- **A credit must remain visible as a separate line,** never collapsed into a single adjusted figure on screen or on the payment document. Visibility is the compliance benefit this change delivers.
- **Tax treatment of a credit line** — the system will calculate tax on the deduction as a reduction, which is arithmetically correct. Finance should confirm this matches the required VAT/tax reporting treatment.

No change to authentication, authorisation, permissions, data retention or data exposure.

---

## Implementation Overview

The change has four parts:

1. **Permit deduction lines.** Remove the restriction that every invoice line must be a positive amount, in both the data-entry screen and the system's own validation. Reject a zero-value line, and keep the existing upper limit on the size of any line.

2. **Enforce the agreed amount policy.** Apply the rule Finance confirms for what an invoice may net to — recommended: a positive amount owed — with a clear, actionable message when an invoice breaches it.

3. **Make deductions visible.** Apply accounting-style presentation for deductions on screen, on the payment document, in the public view and in the notification emails, and add a confirmation prompt at the point a deduction is entered.

4. **Strengthen the two dependent safeguards.** Harden the invoice-removal safeguard for credited invoices, and extend the audit trail to record the net amount and flag any non-positive one.

**Effort and dependencies.** The change is small and contained. No change to how information is stored is required, no existing record is altered, and no system outage is needed. There is no dependency on any external system, vendor or third party. Deployment follows the standard release process.

**Prerequisite.** Part 2 depends on Finance confirming the amount policy. That decision should be made before development begins, as it determines what is built.

### Implementation record — 2026-09-03

CR approved and the recommended amount policy confirmed: individual lines may be a deduction, a
zero line is rejected, and the invoice must net to a positive amount owed. All four parts are built,
with one refinement worth recording:

**Part 4, the invoice-removal safeguard.** The CR offered re-routing or refusal. Refusal was chosen,
because the positive-amount policy in part 2 means an invoice worth nothing or less can no longer be
created at all — so re-routing would be machinery for a state the system now prevents. The safeguard
is therefore a check that refuses the release with a clear message and points to withdrawing the
request instead. It protects against the case arising by any other route rather than trusting that
it cannot.

Testing is covered by the accompanying Test Case document,
[ai/test-cases/allow-negative-line-amounts-for-credit-notes.md](../test-cases/allow-negative-line-amounts-for-credit-notes.md).

---

## Rollout Plan

| Stage | Activity | Responsible |
|-------|----------|-------------|
| 0 | **Policy confirmation** — Finance confirms what an invoice may net to (prerequisite) | Finance / Business Owner |
| 1 | **Development** — implement the four parts above | IT / Development |
| 2 | **Internal validation** — developer testing of the calculation, the amount policy boundaries, and the two strengthened safeguards; peer review with specific attention to approval routing | IT / Development |
| 3 | **QA verification** — full invoice-to-payment cycle with a credit note; multi-currency; correction of an invoice already under approval; reporting, dashboard, payment document and email verification; regression confirmation that ordinary invoices are unaffected | QA |
| 4 | **User acceptance testing** — Finance validates against real vendor credit notes end to end, including the net amount on the payment request and the approvers it routes to; an approver confirms the deduction is unmistakable on screen, on the document and in the email | Finance + an Approver |
| 5 | **Production deployment** — standard release. No downtime, no data change | IT |
| 6 | **Post-deployment monitoring** — enter one real credit note on a low-value invoice and confirm the net amount, the approvers routed to, the payment document and the dashboard figures; confirm existing invoices are untouched; monitor for a defined period | IT + Finance |

**Communication:** brief Finance and approvers before release, and notify report consumers that spend figures move to a net basis.

---

## Backout Plan

Backout is straightforward. The change does not alter how information is stored and does not modify any existing record.

1. **Suspend new functionality.** Restore the positive-amount restriction. Deduction lines are immediately refused again.
2. **Restore previous application state.** Redeploy the prior release. No system downtime required.
3. **Restore backups if required.** Not required for the system's data structure, which is unchanged. **One caveat needs stakeholder awareness:** any invoice already saved with a credit line remains in the system and would fail validation the next time it is edited. Finance must be given the list of affected invoices so each can be corrected manually. Any payment request already created from a credited invoice keeps its net amount and is not affected.
4. **Validate business operations.** Confirm invoice entry and correction, payment request creation, approval routing, dashboards and reports all function normally.
5. **Notify stakeholders.** Finance must be informed immediately, as the manual netting workaround becomes mandatory again and the invoices identified in step 3 require re-entry.

---

## Approval Requirements

### Requestor

Name: ________________________  Date: ____________

### Department Manager (Finance)

Confirms the business need and — as a prerequisite to development — the policy on what an invoice may net to.

Name: ________________________  Date: ____________

### IT Manager

Confirms technical approach, risk mitigations and rollout readiness.

Name: ________________________  Date: ____________

### Business Owner (Finance / Accounts Payable)

Confirms process impact, user readiness, and acceptance of the reporting change to a net basis.

Name: ________________________  Date: ____________

### CAB Approval

**Required: Recommended.** Not mandatory for a standard change of this size, but recommended because the change affects approval authority thresholds and management reporting figures.

Name: ________________________  Date: ____________

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-09-02

Last Reviewed: 2026-09-03 (re-verified against the current codebase)

Status: Approved 2026-09-03, implemented 2026-09-03, awaiting QA and UAT

Test Cases: `ai/test-cases/allow-negative-line-amounts-for-credit-notes.md`

Risk Rating: Medium

Emergency Change: No

Analysis Confidence: 88%

| Confidence measure | Score | Basis |
|--------------------|-------|-------|
| Analysis Confidence | **88%** | The blocking restrictions and every affected calculation were located and read directly in the code. The open item is a business policy decision, not a technical unknown. |
| Affected Features Confidence | **92%** | Invoice entry, payment request creation, approval routing, reporting and all document/email outputs were traced end to end. |
| Business Impact Confidence | **90%** | Impact is well understood and confined to the invoice-to-payment process and its reporting. |
| Security Impact Confidence | **85%** | No access-control change. The approval-threshold and invoice-removal implications are identified but require confirmation under test. |
| Risk Assessment Confidence | **86%** | Small, well-understood change footprint. Residual uncertainty is concentrated in the amount policy decision and the reporting change. |

---

## Technical Analysis Appendix

Kept deliberately brief. Included because it explains the risk rating and the small effort estimate.

### Affected Systems

Payment Approval application only — the invoice module, the payment request and approval module, and the reporting module. Single application, single database.

### Integrations

**None.** No external, third-party or vendor system is involved. No integration contract changes.

### Data Storage

**No change required.** All monetary fields already permit deduction values at the storage level — the restriction was purely an application-level input rule. No data structure change, no migration and no correction of existing data. All existing records are positive by construction, so nothing needs to be reprocessed.

### Security Controls

Authentication, roles, permissions and record-ownership checks are all unchanged. Credit lines are entered through the same authorised invoice submission path with the same checks. No new field is exposed.

The relevant control is the approval-threshold rule, which measures required approval levels against the net amount owed. This already behaves correctly for a credited invoice, and the system already refuses to create a payment request with no approvers. Both behaviours have been confirmed in the code and are carried into the test plan as explicit security tests.

### Architecture Impact

**Minimal.** Two input restrictions relaxed, one amount-policy rule added, presentation adjusted, and two existing safeguards strengthened. No new component, service, layer or dependency. The system's totalling logic already handles deductions correctly throughout, which is why the change is small — most of the work is validation policy, presentation and testing.

### Existing Assumption Requiring Change

One safeguard in the payment request logic is documented on the assumption that a request's total "only ever falls" when an invoice is removed from it. That assumption is correct today and becomes incorrect once an invoice can carry a net credit. This is the single place where existing logic must be reworked rather than extended, and it is reflected in operational risk 6 and mitigation 6.
