# Change Request

## Subject

Requester Corrections During Approval, and Payment Request Attachments on the PAF

---

## Executive Summary

This change asks for three related improvements to how a payment moves through approval.

First, **the person who submitted an invoice may correct it while its payment request is going through approval**. That option closes once the approval chain is complete. Today, once Finance groups an invoice into a payment request, only Finance and administrators can touch it. A submitter who spots a mistake has to ask Finance to make the change for them, or have the request rejected and start again.

Second, **Finance may attach supporting documents to the payment request itself while raising it**, after selecting the invoices it covers. Today a document can only be attached to an individual invoice. Some documents belong to the payment as a whole, such as a vendor statement, a covering memo, a contract extract or a management approval. These have nowhere to go, so they travel by email beside the request instead of inside it.

Third, **those attachments are automatically included in the PAF**. The Payment Approval Form is the document approvers sign, and it already gathers each invoice's documents. A document attached to the request will be included the same way, without anyone attaching it twice.

The second and third items are a contained addition with little risk. The first item needs care. It loosens a deliberate control: it lets records change while approvers are still signing them, and it gives that right to more people than hold it today. We recommend the requester's corrections carry the **same limits Finance already works under**: the currency cannot change and the total cannot go up. We also recommend one further lock: **the vendor being paid cannot change**. On that basis we assess the overall risk as **Medium**. The business answered the open points on 2 October 2026. The decisions are recorded under *Decisions Recorded*, and this document reflects them.

---

## Business Reason for Change

**Small mistakes are expensive to fix.** A wrong job number, a mistyped description or a slightly overstated amount found during approval leaves the submitter two choices. They can ask Finance to make the correction for them, which takes Finance time on a change the submitter understands better. Or they can have the payment request rejected, which loses every approval already given and starts the chain again. Neither suits a correction that changes nothing material.

**The person who knows the invoice cannot fix it.** The submitter raised the invoice and holds the source documents. Finance processes it but often has to go back to the submitter to confirm what a correction should be. Letting the submitter correct their own invoice puts the fix with the person best placed to make it.

**Payment-level evidence is held outside the system.** Finance often groups several invoices for one vendor into a single payment. The document that justifies the payment can cover all of them: a statement of account, a reconciliation, a covering approval or a contract schedule. It cannot be attached to the payment request today. So it is emailed, or attached arbitrarily to one of the invoices. The approver then has to look for it, and the record kept against the payment is incomplete.

**Approvers decide against the PAF.** The approval page shows the PAF with its supporting documents merged in, directly above the Approve and Reject buttons. A document that is not in the PAF is, in practice, not in front of the approver.

---

## Affected Business Areas

**Departments and teams**

- Staff who submit vendor invoices: they gain a correction option during approval
- Finance: raises payment requests, gains the attachment option and sees fewer correction requests
- Approvers at every level: they see payment-level documents in the PAF, and corrections made under them
- Internal Audit and Compliance: rely on the record of what changed during approval

**Business processes**

- Invoice correction while a payment is in approval
- Payment request creation
- Payment approval, including the email approval link
- Record retention for payment evidence

**Users**

- Invoice submitters: a "Correct" option on their invoice while it is in approval
- Finance: an attachments section when raising a payment request
- Approvers: no new action; more complete information where they already review

**Reports and documents**

- The PAF (Payment Approval Form): gains a section for payment-level documents
- The payment request screen and the email approval page: list the new attachments
- Existing reports: no change

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** Payments are being raised, approved and paid normally today. The change improves efficiency and record quality; it does not remove a threat to operations.

### Workaround Availability

**Assessment:** Yes, a workaround exists

**Justification:** Finance can already correct an invoice within a payment request on the submitter's behalf. Payment-level documents can be attached to one of the invoices, or sent by email. Both workarounds are inefficient but workable.

### Operational Impact

**Assessment:** No

**Justification:** Delay keeps the current manual effort and the incomplete payment record. It does not cause financial loss or reputational damage.

### Timeline Constraints

**Assessment:** No

**Justification:** No external deadline applies. The change can follow the normal assessment and approval cycle.

**Emergency Change Classification:** No

**Reason:** This is an efficiency and record-quality improvement with working interim arrangements. It should follow the standard change process. Part of it relaxes a control on records under approval, which is a further reason for the normal level of review.

---

## Risk Assessment

### Risk Level

**Medium**

The attachment items (2 and 3) are Low risk. They add information and change nothing anyone has approved. The medium rating comes from item 1. It lets an invoice change while approvers are still signing it, and gives that ability to a wider group than today. With the recommended limits it stays at Medium. If submitters could change the amount upward, the currency or the vendor being paid, it would be **High**.

### Risks Identified

**Business risks**

1. **Approvers who have already signed may have signed different figures.** In a chain of three, the first approver may have approved a request that the submitter then corrects before the second signs. Their signature stands against a record that has since moved.
2. **A correction could change who is paid.** The rules that protect Finance's corrections today lock the currency and stop the total rising, but they do not lock the vendor. Opening corrections to submitters without closing that gap would let a payment be redirected to a different payee while it is in approval. This is the most serious risk in the change, and it already exists today for Finance (see *Dependencies and Notes*).
3. **The ERP may no longer match.** Finance may already have posted the invoice to the ERP. A submitter's correction to the amount or the reference details would leave the ERP entry showing the old figures, and Finance would not know unless told.
4. **Confusion over what the "correction option" covers.** If submitters expect to change anything, including raising the amount, they will hit refusals. The limits need to be clear on screen and in the rollout communication.

**Operational risks**

5. **The record moves while an approver is reading it.** An approver could open the PAF and approve it while the submitter is saving a correction, so the approval is given against the earlier version.
6. **Request-level documents may be duplicated or misplaced.** If Finance attaches the same document to both the request and an invoice, it appears in the PAF twice. If a document belongs to one invoice but is attached to the request, the PAF loses the link between them.
7. **The PAF grows.** Every attachment is merged into the PAF. Large or numerous files make it slower to open, particularly on the email approval page.

**Security and compliance risks**

8. **Widening who may change a record under approval.** Today only Finance and administrators may change an invoice held by a payment request. This change adds the invoice's own submitter, and nobody else.
9. **Who can see request-level documents.** A payment request can cover invoices from several submitters and departments. A document attached to the whole request may show figures for invoices a particular submitter is not entitled to see. Today submitters can open only the documents on their own invoices.
10. **Audit completeness.** Every correction and every attachment must be recorded: who, when, and what was replaced. This is needed to answer "what did each approver see when they signed?".
11. **Corrections must stop at completion, everywhere.** The option must close when the final approval is given. That includes approval through the emailed approval link, not just on screen, and it must stay closed afterwards.

### Risk Mitigation Plan

| # | Mitigation |
|---|------------|
| 1 | Keep approvals already given, because the total can only stay the same or fall, and a lower total never needs approvers the chain lacks. Mark the request clearly as **corrected during approval**, with the date and the person. Show that marking to approvers on the approval page and the PAF. The request stays with its current approver; it is not sent back to the first. |
| 2 | For submitters, **lock the vendor being paid**, as well as the currency and an increase to the total. Finance's own corrections are left as they are (decision 4). The same gap on Finance's side remains a logged known issue. |
| 3 | Email the Finance user who raised the payment request whenever a submitter corrects one of its invoices, so the ERP entry can be checked and adjusted. |
| 4 | Show the limits on the correction screen before the submitter starts, in plain words, using the explanation Finance already sees. Include them in the rollout briefing. |
| 5 | Show the approver that a correction happened after the request reached them. Test explicitly that an approval and a correction made at the same moment cannot leave the record ambiguous. |
| 6 | Label request-level documents in the PAF as covering the whole payment, kept apart from each invoice's own documents. Brief Finance to attach a document to the invoice it belongs to, and to the request only when it covers the payment as a whole. |
| 7 | Apply the same file type, size and count limits that already apply to invoice documents. Files that cannot be merged (Excel, Word) are listed as download links, as they are today. |
| 8 | Give the right only to the invoice's own submitter, for their own invoice, and only while its request is in approval. No new role or permission is created. Approvers gain nothing. |
| 9 | Request-level documents are visible to Finance, administrators and the approvers on that request's chain, including through the emailed approval link, but **not** to submitters. A submitter still sees their own invoice's documents as today. Submitters also receive a link to the request in the approved and rejected emails, so the documents are withheld on that link and on the PAF it shows. |
| 10 | Record every submitter correction in the audit trail against both the invoice and the payment request, with the figures it replaced. Record every request-level attachment the same way. Include the evidence in the test results. |
| 11 | Test that the option closes on final approval through every approval route, and that it stays closed for approved, paid, rejected and withdrawn requests. |

---

## Expected Business Impact

### Positive Impact

- **Faster corrections.** Submitters fix small errors themselves without waiting on Finance or restarting the approval chain.
- **Fewer rejections for minor errors.** Approvals already given are kept when a correction changes nothing material.
- **Complete payment evidence.** Documents that support the payment as a whole are kept with it, not in email.
- **Better-informed approvers.** Every supporting document, invoice-level and payment-level, is in the PAF in front of the approver.
- **Stronger audit position.** What changed during approval, and what evidence supported the payment, are both answerable from the system.

### Potential Negative Impact

- Approvers may see records change after they began reviewing them. This is controlled by the visible "corrected during approval" marking.
- Some corrections a submitter wants (a higher amount, a different currency or vendor) will still be refused, and still need the invoice to be taken out of the request.
- The PAF will be longer for requests with payment-level documents attached.

### User Impact

**Submitters** see a **Correct** option on their own invoice while its payment request is in approval, with the limits stated on screen. The option disappears once the request is fully approved.

**Finance** sees an **attachments** section when raising a payment request, once invoices have been selected. Attachments are optional. Finance should also receive fewer correction requests from submitters.

**Approvers** take no new action. They see payment-level documents in the PAF and on the approval page, and a clear marking when a correction was made during approval.

### Reporting Impact

No existing report or export changes. The PAF gains a section for payment-level documents, and its existing layout is otherwise unchanged.

### Compliance Impact

Neutral to positive, provided the mitigations are in place. Payment evidence becomes more complete, and every correction is traceable. The control to watch is that a submitter's correction must never change who is paid, the currency, or raise the amount. All three are stated as pass/fail criteria in testing.

---

## Implementation Overview

The work is delivered in three parts.

1. **Submitter corrections during approval.** While an invoice's payment request is in approval, the invoice's own submitter may correct it. Examples are job numbers, customers, descriptions, dates and a lower amount. They work within the limits Finance's corrections already carry: the currency cannot change, the total cannot go up, and the request must still come to more than zero. In addition, the vendor being paid and the advance-payment marker are locked. The submitter may attach further supporting documents to the invoice as part of the correction. The request keeps the approvals already given, stays with its current approver, and its total is updated to match. The option closes automatically when the final approval is given and stays closed from then on. Each correction is recorded in the audit trail, marked visibly on the request for approvers, and emailed to the Finance user who raised the request.

2. **Attachments on the payment request.** When Finance raises a payment request, once at least one invoice is selected, a new section lets them attach supporting documents to the payment as a whole. The same file types and limits as invoice documents apply. Attachments made at this point are saved in the same step as creating the request, so a request that is refused leaves no stray files. Finance and administrators can also add documents later, from the payment request screen, while the request is in approval, approved or paid. A document added after the final approval is marked as such, because it was not in front of the approvers.

3. **Automatic inclusion in the PAF.** Request-level attachments are merged into the PAF automatically, in a section of their own, alongside each invoice's documents. They are listed on the payment request screen and on the emailed approval page. Nothing has to be attached twice. Because the PAF is produced fresh each time it is opened, the documents appear on it straight away.

### Decisions Recorded

The business answered the open points on 2 October 2026.

1. **What a submitter may change during approval.** Everything except the vendor being paid, the currency and the advance-payment marker. The total may stay the same or fall, but never rise. The vendor's invoice number stays correctable and is still subject to the duplicate-number rule. **Added by the business:** the submitter may attach further supporting documents as part of the correction.
2. **Approvals already given are kept.** The request is marked **corrected during approval** and stays with its current approver. It is not sent back to the first approver.
3. **Who is told.** The Finance user who raised the payment request receives an email for every submitter correction, so the ERP posting can be checked.
4. **Finance's corrections are unchanged.** The vendor is not locked for Finance. That gap remains a logged known issue, outside this change.
5. **Who sees request-level documents.** Finance, administrators and the approvers on that request's chain. Submitters do not.
6. **When documents can be attached.** When the request is raised, **and later as well**. Finance and administrators can add documents while the request is in approval, approved or paid. A document added after the final approval is marked as added after approval. Removing a request-level document is not part of this change.
7. **When the section appears.** As soon as at least one invoice is selected. Attachments are optional.
8. **Linked to the PAF, not copied.** A request-level document is stored once against the payment request and appears in the PAF's supporting documents. It is not copied onto each invoice.

---

## Rollout Plan

1. **Development:** Build the attachment items (2 and 3) and the submitter corrections (1), as recorded under *Decisions Recorded*.
2. **Internal Validation:** Developers verify that a submitter's correction cannot change the vendor, the currency or raise the total. They also verify that the option closes on final approval through every route, and that request-level documents appear in the PAF and the approval page.
3. **QA Verification:** Test the change end to end, concentrating on what must *not* change: the vendor being paid, the currency, the approval history, and access to documents across departments.
4. **User Acceptance Testing:** A submitter corrects an invoice mid-approval, and an approver confirms the marking is clear. Finance raises a request with a covering document and confirms it appears in the PAF.
5. **Production Deployment:** Release in a scheduled window outside the month-end payment peak. Brief submitters on what they may correct, and Finance on which documents belong on the request rather than an invoice.
6. **Post Deployment Monitoring:** Monitor for two weeks. Watch how often submitters correct during approval, any refused attempts to change the vendor or raise totals, ERP mismatches reported by Finance, and the size and opening time of PAFs with attachments. Review with Finance at the end of the period.

---

## Backout Plan

1. **Suspend new functionality:** Withdraw the submitter's correction option and the attachment section. Corrections already made stand, and are recorded in the audit trail. Documents already attached remain on their requests.
2. **Restore previous application state:** Roll back the release, returning invoice correction and payment request creation to their current behaviour.
3. **Restore backups if required:** Only if data integrity is affected. Documents attached to requests after deployment may exist nowhere else. They must be preserved or re-supplied before any restore is considered.
4. **Validate business operations:** Confirm invoices can be submitted, posted, grouped, approved and paid end to end, and that every existing PAF still opens with its documents.
5. **Notify stakeholders:** Tell submitters, Finance and approvers about the rollback and the interim arrangement: corrections go through Finance, and payment-level documents are attached to an invoice.

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

Required: **Yes**. The change lets records change while they are under approval, and widens who may make such changes.

Name: ______________________  Signature: ______________________  Date: ____________

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 2 October 2026

**Source:** Business request with three parts: requester corrections during approval, payment request attachments, and automatic inclusion of those attachments in the PAF's supporting documents

**Risk Rating:** Medium (High if submitters could change the vendor, the currency or raise the total)

**Emergency Change:** No

**Analysis Confidence:** Medium-High (80%)

| Dimension | Confidence | Note |
|-----------|-----------|------|
| Analysis Confidence | Medium-High (80%) | Current correction, attachment and PAF behaviour verified against the live rules. The request was brief; its interpretations were confirmed by the business on 2 October 2026. |
| Affected Features Confidence | High (88%) | Invoice correction, payment request creation, the PAF and the email approval page are clearly identified. |
| Business Impact Confidence | Medium-High (78%) | The benefit is clear. How often submitters will use the correction option is not known. |
| Security Impact Confidence | Medium-High (80%) | The vendor gap and the cross-department visibility question are identified. Both still need a decision. |
| Risk Assessment Confidence | Medium-High (80%) | The two decisions the rating depended on (what is locked, and keeping approvals) are now settled. |

---

## Technical Analysis Appendix

*Included only where needed to explain risk, effort or dependency.*

### Affected Systems

| Area | Nature of change | Effort |
|------|------------------|--------|
| Invoice correction rules | Extend the existing in-place correction to the invoice's own submitter while the request is in approval; add the vendor lock | Medium |
| Invoice screens | Show the Correct option to the submitter, with the limits stated; lock the vendor field during a correction | Low |
| Payment request record | New store of request-level documents, linked to the payment request (an additive database change; no existing data is altered) | Low–Medium |
| Payment request creation | Accept attachments in the same step as creating the request | Medium |
| PAF and email approval page | Merge and list request-level documents in their own section | Low |
| Payment request screen | List request-level documents for those entitled to see them | Low |
| Audit trail | Record submitter corrections and request-level attachments as named actions | Low |

### Dependencies and Notes

- **Much of item 1 already exists.** Finance and administrators can already correct an invoice held by a payment request, under rules that keep the currency fixed, stop the total rising, and keep the request above zero. Item 1 extends that existing, bounded capability to the invoice's submitter for the in-approval window only. It is not a new editing route. For the submitter, the window is narrower than Finance's: Finance can also correct after approval and before payment.
- **Existing gap: the vendor is not locked during a correction.** Corrections by Finance today can change the vendor on an invoice that is in approval or already approved. Nothing on the screen or on the server prevents it. This existed before this request and has been logged as a known issue. Opening corrections to submitters makes it materially more important. The business decided to lock it for submitters only and leave Finance's corrections unchanged (decision 4).
- **Existing gap: approvers are not told about corrections.** Finance's corrections are audited but not notified (already a logged known issue). This change addresses it with the visible marking recommended above, without adding email volume.
- **The PAF needs no new mechanism.** It already merges each invoice's documents on demand and lists unmergeable files as links. Request-level documents feed the same mechanism, so item 3 is a small change.
- **No new role or permission.** The change adjusts when an existing user (the invoice's submitter) may act on their own record. It does not change who exists or who approves.
- **The database change is additive.** A new store for request-level documents is created. No existing table is altered and no existing record is rewritten.

### Security Controls

- A submitter may correct only **their own** invoice, only while its payment request is **in approval**, and never change the vendor, the currency or raise the total. Each limit is enforced on the server, not just on the screen.
- The correction option closes on final approval through both the in-app and the emailed approval routes.
- Request-level documents follow the visibility agreed under decision 5. They are served through the same protected routes as invoice documents, including the token-gated approval link.
- Every correction and attachment is written to the audit trail against both the invoice (where relevant) and the payment request.
