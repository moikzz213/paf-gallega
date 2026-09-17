# Change Request

## Subject

Flag Advance Payment Invoices, and Allow the Final Vendor Invoice to Be Attached After Approval

---

## Executive Summary

The business regularly pays vendors in advance — typically a percentage of an order before the work is done or the goods are shipped. The platform has no way of recording that. An advance is entered as an ordinary invoice, and the only sign of what it is sits in free text: a description such as *"50% Advance payment"* typed by whoever raised it. Nothing in the system distinguishes an advance from a normal payable, so nobody can see, count, report or control them.

This change asks for two things. First, a clear **advance payment indicator** set when an invoice is created or edited, shown wherever the invoice appears, so approvers know at a glance that they are authorising money ahead of delivery. Second, the ability to **attach the vendor's final tax invoice to that record later** — because an advance is settled before the real invoice exists, and today the record is locked by the time that document arrives.

The second half is the substantive part. Finance and administrators can already correct an invoice held by a payment request that is in approval or approved, within limits that protect the approvals it carries. What they cannot do is touch it once it has been **paid**, which is precisely when the vendor's final invoice turns up. The requester who raised it cannot attach anything at all once it enters a payment cycle. So the supporting document that completes the record has nowhere to go, and ends up in email or a shared folder instead of against the payment it belongs to.

The requirement offers two ways to solve this: keep the invoice fully editable after approval, or enable only the document upload section. **We recommend upload-only, and this Change Request is written on that basis.** Reopening amounts on an approved or paid payment defeats the purpose of having approved it, and the platform's existing rules go to some length to prevent exactly that. Allowing documents to be added changes nothing that anyone signed off — it completes the evidence behind it. If the business genuinely needs figures to change after payment, that is a different and much higher-risk change, and should be raised as one.

The expected outcome is visibility and a complete audit trail: advances can be identified, counted and reconciled, and every advance carries its final vendor invoice in the same place as the payment it settles. Risk is assessed as **Low to Medium** — the indicator itself is a small, contained addition; the document rules touch a deliberate control and need care.

---

## Business Reason for Change

**Advances are invisible.** An advance payment carries a different commercial risk from an ordinary payable: the money leaves before the business has what it paid for. Today that distinction exists only in someone's wording. Nobody can produce a list of outstanding advances, reconcile them against deliveries, or see how much is sitting with vendors unfulfilled. An approver reviewing a request cannot tell without reading the description carefully that they are approving a prepayment rather than settling a delivered service.

**Approvers lack the context to weigh the risk.** Approving an advance is a different decision from approving a completed invoice, and it deserves to be labelled as one. The same argument that made line descriptions mandatory applies here: the information exists in someone's head, and the system should carry it.

**The record cannot be completed.** An advance is, by its nature, raised and paid before the vendor's final tax invoice exists. When that invoice arrives — often weeks later — the payment record is closed to change and the document has nowhere to go. It is filed in email or a shared drive, and the payment record stays permanently incomplete. This is an audit weakness on precisely the payments that most need documentary support, and it is the practical problem the requesting team feels day to day.

**Reconciliation is manual.** Because advances cannot be identified or traced to their final invoice, matching them off is done by hand, from memory and correspondence.

---

## Affected Business Areas

**Departments and teams**

- Finance — raises and pays advances, and holds the reconciliation problem
- Staff who submit vendor invoices, including those raising advance requests
- Approvers at every level, who gain the context the indicator provides
- Internal Audit and Compliance — beneficiaries of the completed document trail
- Procurement, where advance terms are agreed with the vendor

**Business processes**

- Invoice submission and validation
- Payment request creation and approval
- Post-payment documentation and record retention
- Vendor advance reconciliation

**Users**

- Invoice submitters — one additional indicator to set when relevant
- Approvers — no new action; the indicator appears where they already look
- Finance — the ability to complete an advance's record after payment

**Reports and documents**

- The Invoice Log and its filters, which can now separate advances from ordinary payables
- The Payment Approval Form, where the indicator should be visible to anyone signing
- Any future reporting on outstanding advances, which this change makes possible for the first time

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** Advances are being processed today and payments are not at risk. This is a visibility and record-completeness improvement, not a repair.

### Workaround Availability

**Assessment:** Yes — workarounds exist, but they are poor

**Justification:** Advances are currently identified by wording in the description, and final vendor invoices are kept outside the platform in email or shared folders. Both work in the sense that the payment gets made, but neither is searchable, reportable or auditable, which is the reason for the request.

### Operational Impact

**Assessment:** No unacceptable impact from delay

**Justification:** Delay prolongs manual reconciliation and leaves the document gap open on advance payments. It causes no financial loss or regulatory breach, though the audit weakness compounds with every advance raised.

### Timeline Constraints

**Assessment:** No

**Justification:** No external deadline, audit finding or regulatory date is attached. The standard change process applies in full.

**Emergency Change Classification: No**

**Reason:** A planned business-improvement change with workable, if unsatisfactory, interim arrangements and no continuity or compliance pressure.

---

## Risk Assessment

### Risk Level

**Low to Medium**

The indicator itself is a small, contained addition carrying little risk. The medium element comes entirely from the second half: relaxing when a record can be added to after approval touches a control that exists on purpose. Scoped to document upload only, as recommended, the risk stays at the low end of medium; scoped to full editability, it would be High.

### Risks Identified

**Business risks**

1. **Scope creep from "editable" to "changeable".** If the relaxation is read as permission to alter amounts after approval, the platform would allow a payment to be changed after it was authorised and paid. This is the single most consequential decision in the change and the reason for the recommendation above.
2. **The indicator is set inconsistently.** If it is optional and unprompted, some advances will be flagged and others not, and a partially reliable flag is worse than none — it invites people to trust a count that is wrong.
3. **Reconciliation is still manual.** The indicator makes advances identifiable; it does not by itself match them to their final invoices or close them off. Expectations should be set that this is the enabling step, not the complete solution.

**Operational risks**

4. **Documents added after payment can change what the record appears to say.** A file attached months later sits alongside the ones the approvers saw. Without a clear distinction, it becomes unclear what was actually in front of the approver at the time of signing.
5. **No natural end point.** If documents can be added to a paid record indefinitely, records never settle. A boundary — who may add, and until when — needs to be agreed.
6. **Historical advances are unflagged.** Every advance already in the system will show as an ordinary invoice, so any early report will understate the true figure.
7. **The Payment Approval Form changes.** Adding the indicator to the printed form alters a document the business is used to reading, and it must not crowd or displace existing fields.

**Security and compliance risks**

8. **Widening who may add to an approved record.** Today, only Finance and administrators may touch an invoice held by a payment request. Any relaxation must be deliberate about who gains that right, and must not become a route to changing approved figures.
9. **Audit completeness.** Every document added after approval must be recorded in the audit trail — who added it, when, and to which payment — to the same standard as any other change.
10. **A late upload must not reopen the approval.** Attaching a document must not return the payment to an approval state, alter its status or make it payable again.

### Risk Mitigation Plan

| # | Mitigation |
|---|------------|
| 1 | Scope the relaxation to **document upload only**. Amounts, currency, vendor, dates and line items stay locked exactly as they are today. Confirm this with the business owner before build; if full editability is genuinely required, raise it as a separate High-risk change with its own approval. |
| 2 | Make the indicator an explicit choice on the invoice form rather than a box that is easy to miss, and brief the submitting teams on when to use it. |
| 3 | State plainly in the rollout communication that this change makes advances **identifiable**; reconciliation reporting is a possible follow-up, not part of this change. |
| 4 | Show clearly, wherever documents are listed, which were present at approval and which were added afterwards, with the date and the person who added them. |
| 5 | Agree who may upload after payment (recommended: Finance and administrators, as today, plus the original submitter) and whether any cut-off applies. |
| 6 | Treat historical advances as unflagged and say so on any report. If the business wants them identified, handle it as a separate, scheduled data exercise. |
| 7 | Place the indicator on the Payment Approval Form where it is unmissable but displaces nothing; confirm the layout with Finance before release. |
| 8 | Keep the existing role rules as the default and change only what the agreed scope requires. No new permission or role is introduced. |
| 9 | Audit every post-approval upload as a distinct, named action, and include the evidence in the test results. |
| 10 | Verify explicitly that uploading changes no status, no total and no approval state — this is a specific regression test, not an assumption. |

---

## Expected Business Impact

### Positive Impact

- **Advances become visible.** They can be identified, listed, filtered and counted for the first time.
- **Approvers know what they are signing.** A prepayment is labelled as one at the point of decision.
- **The record can be completed.** The vendor's final invoice lives against the payment it settles, rather than in an inbox.
- **Stronger audit position** on exactly the payments that carry the most commercial risk.
- **Groundwork for reconciliation.** Future reporting on outstanding advances becomes possible; it is not possible today.

### Potential Negative Impact

- One more decision when raising an invoice, and a period during which people forget to set it.
- Early figures will understate advances, because historical records carry no flag.
- Documents will exist on records that are otherwise closed, which needs to be presented clearly so nobody misreads what the approvers saw.

### User Impact

**Submitters** gain one indicator to set when an invoice is an advance. No other change to how they work.

**Approvers** see the indicator wherever they already review — the invoice, the payment request and the Payment Approval Form. No new action, and no retraining beyond a note explaining what the label means.

**Finance** gains the ability to attach a final vendor invoice to an advance after it has been paid, closing a gap they currently work around outside the system.

### Reporting Impact

No existing report changes. The Invoice Log gains a way to separate advances from ordinary payables. A dedicated outstanding-advances report is a natural follow-up but is **not** in this change's scope.

### Compliance Impact

Net positive. Advance payments become identifiable rather than inferred from free text, and their supporting documentation can be held against the payment record instead of outside the system. The one control to watch is that adding a document after approval must never become a route to changing an approved amount — addressed in the mitigation plan and tested explicitly.

---

## Implementation Overview

The work divides into three deliverables.

1. **The advance payment indicator.** A clear marker set when an invoice is created or edited, recording that the payment is an advance. It appears on the invoice record, in the Invoice Log listing, on the payment request, and on the Payment Approval Form the approvers sign. The invoice follows exactly the same submission, posting, grouping and approval process as any other — the indicator describes the payment, it does not route it differently.

2. **Finding advances.** The Invoice Log gains the ability to filter to advance payments, so Finance can see them as a set rather than hunting through descriptions.

3. **Attaching the final vendor invoice after payment.** Supporting documents may be added to an advance-payment invoice after its payment request has been approved and paid. Everything else about the record stays locked: amounts, currency, vendor, dates and line items are unchanged and unchangeable, so nothing anyone approved can be altered. Documents added after approval are clearly distinguished from those that were present when the approvers signed, and each upload is recorded in the audit trail.

### Open Points for Decision

These need a business answer before build. The first is the significant one.

1. **Upload-only, or fully editable?** The request offers both. We recommend **upload-only**, for the reasons in the Executive Summary and risk 1 above. Confirmation is needed before development starts, because the two options carry very different risk ratings and very different amounts of work.
2. **Who may upload after payment?** Recommended: Finance and administrators (who already hold correction rights), plus the invoice's original submitter. Confirmation needed.
3. **Does the relaxation apply only to advance-payment invoices, or to all invoices?** The request describes it in the context of advances. Limiting it to advances is the narrower, safer option and is what this Change Request assumes.
4. **Should existing advance payments be flagged retrospectively?** If so, by whom, against what source, and by when.

---

## Rollout Plan

1. **Development** — Build the indicator first, as the self-contained part; the post-payment upload rules second, once open point 1 is confirmed.
2. **Internal Validation** — Developer verification that the indicator carries through every screen and the printed form, and that uploading after payment changes no amount, status or approval state.
3. **QA Verification** — Execution of the Test Case document, concentrating on what must *not* change: totals, currency, payment status, approval history and payability.
4. **User Acceptance Testing** — Finance confirms an advance is recognisable everywhere they need it, that they can attach a final vendor invoice to a paid advance, and that the distinction between documents seen at approval and documents added later is clear.
5. **Production Deployment** — Released in a scheduled window outside the month-end payment peak, with submitting teams briefed on when to set the indicator.
6. **Post Deployment Monitoring** — Two weeks covering how consistently the indicator is being set, any attempt to use the upload path to change figures, and audit entries for post-payment uploads. Review with Finance at the end of the period, including whether an outstanding-advances report should follow.

---

## Backout Plan

1. **Suspend new functionality** — Stop offering the indicator on new invoices and close the post-payment upload route. Records already flagged keep their flag; documents already attached stay attached.
2. **Restore previous application state** — Roll back the release, returning invoice editing and document rules to their current behaviour.
3. **Restore backups if required** — Restore from the pre-deployment backup only if data integrity is affected. Documents legitimately uploaded after deployment must be preserved or re-supplied before any restore is considered, since they may exist nowhere else.
4. **Validate business operations** — Confirm invoices can be submitted, posted, grouped, approved and paid end to end, and that existing documents remain reachable.
5. **Notify stakeholders** — Inform Finance, submitters and approvers of the rollback and the interim arrangement for final vendor invoices.

---

## Approval Requirements

### Requestor

Name: ______________________  Signature: ______________________  Date: ____________

### Department Manager — Finance

Name: ______________________  Signature: ______________________  Date: ____________

### IT Manager

Name: ______________________  Signature: ______________________  Date: ____________

### Business Owner — Payment Approval Process

Name: ______________________  Signature: ______________________  Date: ____________

### CAB Approval

Required: **Yes** — the change relaxes a control on records that have already been approved and paid.

Name: ______________________  Signature: ______________________  Date: ____________

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 17 September 2026

**Source:** Business request — advance payment indicator on invoices, and continued editability or upload access after approval

**Risk Rating:** Low to Medium (Medium if scoped to full editability rather than upload-only)

**Emergency Change:** No

**Analysis Confidence:** Medium-High (80%)

| Dimension | Confidence | Note |
|-----------|-----------|------|
| Analysis Confidence | Medium-High (80%) | Current behaviour verified against the live rules; the request itself offers two scopes and does not pick one. |
| Affected Features Confidence | High (90%) | The invoice record, log, payment request, printed form and document rules are all clearly identified. |
| Business Impact Confidence | Medium-High (78%) | The visibility benefit is clear; how much manual reconciliation effort it removes depends on a follow-up report that is out of scope. |
| Security Impact Confidence | Medium-High (82%) | The control being relaxed is well understood; who should gain upload rights is still open. |
| Risk Assessment Confidence | Medium (75%) | The rating moves materially on open point 1, which the business has not yet answered. |

---

## Technical Analysis Appendix

*Included only where needed to explain risk, effort or dependency.*

### Affected Systems

| Area | Nature of change | Effort |
|------|------------------|--------|
| Invoice record | One new stored indicator, set on create and edit | Low |
| Invoice form and detail screens | Capture and display the indicator | Low |
| Invoice Log listing and filters | Show the indicator and allow filtering to advances | Low |
| Payment request and Payment Approval Form | Surface the indicator where approvers review and sign | Low |
| Document rules | Permit upload against an advance invoice after its payment request is approved or paid, with everything else still locked | Medium |
| Audit trail | Record post-payment uploads as a distinct action | Low |

### Dependencies and Notes

- **Part of the request already exists.** Finance and administrators can already correct an invoice held by a payment request that is *in approval* or *approved for payment*, under rules that stop the total rising, the currency changing, or the request being left with nothing to pay. What is genuinely missing is (a) access once the request has been **paid**, which is exactly when a final vendor invoice arrives, and (b) any route for the original submitter. This is why the change is smaller than it first appears, and why upload-only is a modest extension of an existing, deliberately bounded capability rather than a new one.
- **There is no upload-only path today.** Documents are attached as part of a full invoice update, which re-validates and re-saves the whole record. Enabling upload alone means giving documents their own route, which is the bulk of the medium effort above — and is also what makes upload-only genuinely safe rather than merely intended to be.
- **No new permission or role is introduced.** The change adjusts when existing roles may act, not who exists.
- **The approval chain is untouched.** The indicator describes the payment; it does not change routing, thresholds or approvers. If the business later wants advances routed differently, that is a separate change.
- **A stored indicator, not derived text.** Reading "advance" out of free-text descriptions would be unreliable and would silently miss records; the flag has to be a field people set.

### Security Controls

- Existing visibility rules on invoices and documents are unchanged — the same people can see the same records.
- Every upload continues to be written to the audit trail, with post-approval uploads named distinctly.
- Uploading must not alter status, totals, approval state or payability; this is stated as a pass/fail criterion rather than left as an assumption.
