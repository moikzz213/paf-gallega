# Change Request

## Subject

PAF Enhancements — Mandatory Line Descriptions, Informative Approval Emails, Earlier PAF Generation, In-Page PAF Review, and an Optional Second-Level Approver

---

## Executive Summary

Business users have raised five enhancements to the Payment Approval Form (PAF) platform, captured in the *PAF Enhancements* requirements document. Together they address one underlying complaint: approvers are being asked to authorise payments without enough context, too late in the process, and with too little flexibility in who signs off.

The five changes are: (1) make the line-item **Description** a required field when an invoice is submitted; (2) show the payment **description, vendor name, total amount, and PAF number in the subject line** of the approval email; (3) generate the **PAF document as soon as the payment request is created**, rather than only after the final approval; (4) display the **PAF itself, with its supporting documents, directly on the approval page** instead of a bare "review and approve" prompt; and (5) allow Finance to add **one optional extra approver at the second level ("L2-A")** on the payment request.

The expected business outcome is faster and better-informed approval decisions. Approvers will be able to identify a payment from the email subject alone — useful on mobile, where most approvals happen — and will see the complete payment pack on screen rather than downloading attachments one by one. Finance gains a formal route to include an additional senior reviewer where the organisation requires one, without creating a separate approval level.

Four of the five items are contained, low-to-medium-risk improvements to existing screens and notifications. The third item — generating the PAF earlier in the lifecycle — is the most significant, because the PAF document is currently produced only for fully approved or paid requests and is treated as the finished record. Producing it earlier means the organisation will circulate PAFs that are *not yet final*, and the document must be clearly marked as such to avoid a draft being mistaken for an authorised payment instruction.

Two requirements need a business decision before implementation, noted under *Open Points for Decision* below. Neither blocks the remaining work.

---

## Business Reason for Change

**Approvers lack context at the point of decision.** The current approval email identifies a request only by its internal reference number. An approver receiving several requests a day cannot tell from the inbox which payment is which, and must open each one to find out. The requirements document contrasts this with the external e-signature emails the business has been using, whose subject lines carry the purpose, vendor, amount, and reference — and asks for the same standard in the PAF platform.

**Payment lines are being submitted without a stated purpose.** The line-item Description field is optional today. When it is left blank, the approver, and later the auditor, has a job number and an amount but no statement of what was bought. Making it mandatory closes a recurring gap in the audit trail at the cheapest possible point — data entry.

**The PAF is produced too late to support the approval it is meant to support.** The formal PAF document is currently generated only once a request has been fully approved. Approvers therefore approve against an on-screen summary, and only ever see the formal document after the fact. The business wants the PAF produced at the moment the payment request is raised, so the same document travels with the request through every approval stage.

**The approval page does not show what is being approved.** Approvers following the emailed link see an action prompt and a list of downloadable attachments. Reviewing a payment properly means opening each file separately. The business wants the PAF and its supporting documents presented on the approval page itself.

**The approval chain is rigid at the second level.** Certain payments require an additional sign-off alongside the second-level approver — for example a department head or a business-unit controller — without that person becoming a permanent approval tier for every payment. Finance needs an optional slot for this, positioned within the chain rather than appended after it.

---

## Affected Business Areas

**Departments and teams**

- Finance (raises invoices and payment requests; primary operator of the change)
- Approvers at every level, including senior management approving by email
- Staff who submit vendor invoices for payment
- Internal Audit and Compliance (beneficiaries of the improved record)

**Business processes**

- Invoice submission and validation
- Payment request creation and approval routing
- Approval notification and reminder correspondence
- Payment documentation and record retention

**Users**

- All staff who submit invoices — they will no longer be able to save a line without a description
- All approvers — changed email subjects, a redesigned approval page
- Finance — an additional optional field when building the approval chain

**Reports and documents**

- The PAF document itself (now produced earlier in the lifecycle and marked with its status)
- Any saved or forwarded approval emails, whose subject format changes
- Audit history, which will record the additional approval stage where used

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** The payment approval process operates today and is not at risk of failure. These are improvements to clarity, timing, and flexibility, not repairs to a broken capability.

### Workaround Availability

**Assessment:** Yes — workarounds exist

**Justification:** Approvers can open each request to obtain its details; Finance can instruct submitters to complete descriptions manually; an additional approver can currently be added as an ad-hoc stage at the end of the chain. The workarounds are inefficient and rely on discipline rather than the system, which is precisely why the change is requested — but they do function.

### Operational Impact

**Assessment:** No unacceptable impact from delay

**Justification:** Delaying the change prolongs the current inefficiency — slower approvals, more back-and-forth, occasional missing descriptions in the audit record — but causes no financial loss, regulatory breach, or reputational damage.

### Timeline Constraints

**Assessment:** No

**Justification:** No external deadline, audit finding, or regulatory date is attached to these requirements. The normal change assessment cycle can be followed in full.

**Emergency Change Classification: No**

**Reason:** A planned, business-improvement change with available workarounds and no continuity, compliance, or timeline pressure. It should proceed through the standard change process, including QA and user acceptance testing.

---

## Risk Assessment

### Risk Level

**Medium**

The individual changes are modest, but collectively they touch the invoice submission gate, the approval notification, the formal payment document, and the approval chain — four control points in the payment process. The medium rating reflects that breadth rather than any single high-risk element.

### Risks Identified

**Business risks**

1. **A draft PAF mistaken for an authorised one.** Generating the PAF at creation means partially approved documents will exist and can be printed, forwarded, or filed. Without clear status marking, one could be mistaken for a completed authorisation — the most material risk in this change.
2. **Ambiguity where a payment request covers more than one vendor.** Payment requests can group invoices from different vendors. A subject line naming "the vendor" and "the description" has no single correct value in that case, and a misleading subject is worse than a generic one.
3. **Submission friction.** Making the description mandatory will stop submissions that would previously have gone through. Without a clear message and reasonable field guidance, this is experienced as the system "blocking" work.

**Operational risks**

4. **Existing records without descriptions.** Invoices already in the system may have blank line descriptions. If the new rule is applied retrospectively to editing or reprocessing, users may be unable to save older records without first supplying data they do not have.
5. **Approval chain changes affect routing.** Adding an optional stage changes the sequence a request travels through. An error here could route a payment to the wrong person or skip an intended reviewer.
6. **Approval page performance.** Presenting the full PAF with all supporting documents on one page increases the volume being loaded, particularly for requests with many large attachments or for approvers on a mobile connection.
7. **Email deliverability and readability.** Longer subject lines are truncated by mail clients and mobile devices, and heavier subject content can interact with spam filtering. The most important details must appear first.

**Security and compliance risks**

8. **Wider exposure of payment detail.** Subject lines are visible on lock screens, in notification previews, and in mail server logs, and are not protected in the way message content may be. Vendor names and amounts in the subject place commercially sensitive information in a more visible place.
9. **Earlier document availability widens the access window.** A PAF that exists from creation is reachable for longer, through the emailed approval links. Access controls that today only need to protect a completed document must protect it through the whole approval lifecycle.
10. **Audit completeness.** The additional approval stage must be recorded in the audit trail to the same standard as the standing levels, or the approval history becomes incomplete.

### Risk Mitigation Plan

| # | Mitigation |
|---|------------|
| 1 | Mark every PAF produced before final approval with a clear, unmistakable status indicator (for example a "Draft — pending approval" watermark and a status line), and confirm the wording with Finance before release. |
| 2 | Agree the multi-vendor subject-line convention with the business before build (see *Open Points for Decision*), and apply it consistently. |
| 3 | Provide a clear, specific validation message and short guidance on what a good description contains; brief the submitting teams before release. |
| 4 | Apply the mandatory rule to new and edited submissions going forward; confirm with Finance whether existing records should be corrected, and if so handle that as a separate, scheduled data exercise. |
| 5 | Treat approval routing as the priority test area — cover chains with and without the optional approver, rejection and resubmission, and reminder behaviour, and verify the audit trail in each case. |
| 6 | Load the PAF on the approval page progressively and keep the existing per-document download links as a fallback; test on mobile and on a constrained connection before release. |
| 7 | Order the subject line so the most identifying details appear first, and cap its overall length so it survives truncation on common mail clients. |
| 8 | Confirm with the business owner that vendor names and payment amounts may appear in email subjects; if not acceptable, fall back to reference number plus description only. Note the existing practice — the external e-signature emails already do this — as precedent. |
| 9 | Keep the emailed approval links single-use per approver, tokenised, and revoked once the stage is complete, as they are today; re-verify that the earlier document does not become reachable without a valid link. |
| 10 | Confirm the optional stage writes the same audit entries as a standing approval level, and include this in the test evidence. |

---

## Expected Business Impact

### Positive Impact

- **Faster approvals.** Approvers can recognise and prioritise a request from the inbox without opening it.
- **Better-informed decisions.** The complete payment pack — the PAF and its supporting documents — is presented where the decision is made.
- **Stronger audit record.** Every payment line will carry a stated purpose, and the formal PAF will exist from the start of the approval journey rather than only at its end.
- **Greater flexibility for Finance.** An additional senior reviewer can be included on specific payments without changing the standing approval structure for everyone.
- **Consistency with existing practice.** The new email subject format matches what the business already receives from its external signing tool, so approvers encounter one convention rather than two.

### Potential Negative Impact

- Slightly slower invoice entry, as a previously optional field must now be completed.
- More payment detail visible in email subject lines, including on device lock screens.
- Documents in circulation that are not yet final, requiring users to read the status marking.
- Where the optional approver is used, an extra stage lengthens the approval path for that request.

### User Impact

**Invoice submitters** must complete a description on every line. Expect a short adjustment period and some initial validation errors; a pre-release briefing will mitigate this.

**Approvers** will see clearer emails and a substantially more useful approval page. No new action is required of them, and no retraining beyond a short note explaining what has changed.

**Finance** gains one additional optional field when building the approval chain and must understand when the organisation expects it to be used — this should be covered by an internal guideline rather than by the system.

### Reporting Impact

No existing report changes structurally. Descriptions will be populated more consistently, which improves the usefulness of reports that include them. The PAF document gains a status indication and becomes available at an earlier point in the lifecycle.

### Compliance Impact

Net positive. A mandatory description strengthens the supporting evidence for every payment, and a PAF that exists from creation gives a consistent document of record across the approval chain. The one compliance consideration to confirm is the inclusion of vendor names and payment amounts in email subject lines, which should be reviewed against internal information-handling policy before release.

---

## Implementation Overview

The work divides into five deliverables that can be built and released together or in two stages.

1. **Mandatory line description.** The description field on each payment line becomes required at submission, enforced both on screen and on the server so the rule cannot be bypassed. Applied to new and edited submissions; historical records are addressed separately if the business asks for it.

2. **Informative approval email subjects.** Approval, reminder, and completion emails will carry the payment description, vendor name, total amount, and PAF number in the subject, ordered so the most identifying detail survives truncation. The email body is unchanged.

3. **Earlier PAF generation.** The PAF document will be produced when the payment request is created rather than at the end of the approval chain, and will remain available throughout. Every version issued before final approval will be clearly marked as not yet authorised, and the document will reflect the approval progress achieved so far.

4. **PAF review on the approval page.** The approval page reached from the email link will present the PAF and its supporting documents inline, above the approve and reject actions, replacing the current action-only prompt. The existing individual download links are retained as a fallback.

5. **Optional second-level approver (L2-A).** Finance gains one optional approver slot associated with the second approval level. When used, the request routes through that person as part of the normal sequence; when left empty, the chain behaves exactly as it does today. The stage is recorded in the audit trail on the same basis as any other.

### Open Points — Decisions Taken at Implementation

The items below were built to the following assumptions, recorded here so the business can confirm or ask for a change. Items 3 and 4 remain genuinely open and are for the business to answer; they do not affect what has been built.

1. **Multi-vendor payment requests — decided.** Where a request covers one vendor, the subject names it. Where it covers several, it reads as *first vendor* + *N more*, matching the convention the payment request list already uses for mixed vendors and currencies. Naming only the first vendor would read as the whole request's payee, which it is not. The subject's description is the first line description on the request; it is shortened before the vendor, amount or reference, because a truncated description is still readable while a truncated amount or reference is not.
2. **Positioning of the L2-A approver — decided.** L2-A signs immediately after the second-level approver, ahead of any higher level and ahead of the general additional approvers. The slot is offered only when the amount actually reaches level 2. If it is set where it cannot apply, or set to the person already holding L2, the request is refused rather than silently rerouted — a dropped approver is worse than a refused request, because whoever added them would never find out.
3. **Existing blank descriptions — open.** The rule applies to new and edited submissions. Existing records are untouched and remain readable, and no data was migrated. Whether the historical gaps should be filled is a business decision, and would be a separate exercise.
4. **Information handling — open.** Vendor names and payment amounts now appear in email subject lines, matching the format the business already receives from its external signing tool. Confirmation against internal information-handling policy is still outstanding; if it is not acceptable, the subject can be reduced to purpose and reference in a small follow-up change.

---

## Rollout Plan

1. **Development** — Build the five deliverables, with the approval routing change developed and reviewed first as the highest-risk item.
2. **Internal Validation** — Developer verification of each item against the requirements document, including the draft PAF marking and the routing behaviour with and without the optional approver.
3. **QA Verification** — Execution of the Test Case document covering happy path, negative, security, and regression scenarios, with particular attention to approval routing, email rendering across desktop and mobile clients, and approval page performance with large attachments.
4. **User Acceptance Testing** — Finance and a representative group of approvers confirm the new email subjects are readable on their own devices, the approval page shows what they need, the draft PAF marking is unambiguous, and the optional approver routes correctly.
5. **Production Deployment** — Released in a scheduled maintenance window outside the month-end payment peak. Submitting and approving teams briefed beforehand on the mandatory description and the new email format.
6. **Post Deployment Monitoring** — Two weeks of heightened monitoring covering approval completion times, submission validation failures, email delivery, approval page load behaviour, and any reported confusion over draft PAFs. A review with Finance at the end of the period.

---

## Backout Plan

1. **Suspend new functionality** — Disable the optional second-level approver slot for new requests and revert email subjects to the current short format; requests already in flight continue on the chain they were created with.
2. **Restore previous application state** — Roll back the release to the prior version, returning invoice submission, PAF generation timing, and the approval page to their current behaviour.
3. **Restore backups if required** — Restore the database from the pre-deployment backup only if data integrity is affected; requests approved after deployment must be reconciled before any restore is considered.
4. **Validate business operations** — Confirm invoices can be submitted, payment requests raised, approval emails delivered, and approvals completed end to end.
5. **Notify stakeholders** — Inform Finance, approvers, and submitting teams of the rollback, the reason, and the revised plan, including the status of any request that was mid-approval.

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

Required: Yes — the change affects the payment approval control chain and outbound correspondence.

Name: ______________________  Signature: ______________________  Date: ____________

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 16 September 2026

**Source Document:** PAF Enhancements (requirements document supplied by the business, five numbered items)

**Risk Rating:** Medium

**Emergency Change:** No

**Analysis Confidence:** High (88%)

| Dimension | Confidence | Note |
|-----------|-----------|------|
| Analysis Confidence | High (88%) | Source requirements reviewed in full and traced to the live system behaviour. |
| Affected Features Confidence | High (92%) | All five items map to identified, existing parts of the platform. |
| Business Impact Confidence | High (85%) | Impact is well understood; the exact effect on approval turnaround is an estimate. |
| Security Impact Confidence | Medium-High (80%) | The email subject and earlier document availability concerns are clear; policy confirmation is outstanding. |
| Risk Assessment Confidence | High (87%) | Main uncertainty is the multi-vendor convention and the L2-A placement, both raised as open points. |

---

## Technical Analysis Appendix

*Included only where needed to explain risk, effort, or dependency. Kept deliberately brief.*

### Affected Systems

| Area | Nature of change | Effort |
|------|------------------|--------|
| Invoice submission | Description becomes a required field, enforced on screen and on the server | Low |
| Approval notifications | Subject lines of the approval, reminder, and completion emails extended with payment detail | Low |
| PAF document generation | Produced at creation rather than on completion; status marking added; the existing restriction limiting the document to approved and paid requests is relaxed | Medium |
| Approval page (email link) | The PAF and its supporting documents rendered inline above the approve and reject actions | Medium |
| Approval chain | One optional stage added at the second level, sequenced within the chain rather than appended to its end | Medium |

### Dependencies and Notes

- **The PAF document and the approval page are linked.** Presenting the PAF on the approval page (item 4) depends on the PAF existing before final approval (item 3). These two items should be built and released together.
- **Payment requests are not currently constrained to a single vendor.** The system enforces a single currency per request but not a single vendor. This is the root of open point 1 and is a business convention question, not a technical limitation.
- **Ad-hoc approvers already exist**, but are appended at the end of the chain. The L2-A requirement asks for a slot positioned at the second level, which is a change to how the chain is sequenced rather than a wholly new capability — this is why the effort is rated medium rather than low.
- **No external system integration is affected.** The platform's outbound reporting interface is read-only and does not carry invoice line data, so the mandatory description rule has no external dependency.
- **The PAF document assembles the payment sheet together with its attachments.** Producing it at creation, and again as approvals progress, increases how often that assembly runs. If the approval page renders it on demand, caching should be considered for requests with many large attachments.

### Security Controls

- Emailed approval links remain single-use per approver, tokenised, and invalidated once the stage is actioned. This control must be re-verified once the document is available earlier in the lifecycle.
- Every state change continues to be written to the audit trail, including the new optional approval stage.
- No change to authentication, user roles, or permission structure is required by any of the five items.
