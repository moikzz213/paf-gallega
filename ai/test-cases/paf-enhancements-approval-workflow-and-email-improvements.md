# Test Cases

## Related Change Request

| | |
|---|---|
| **Change Request Subject** | PAF Enhancements — Mandatory Line Descriptions, Informative Approval Emails, Earlier PAF Generation, In-Page PAF Review, and an Optional Second-Level Approver |
| **Change Request Filename** | `ai/change-requests/paf-enhancements-approval-workflow-and-email-improvements.md` |
| **Risk Rating** | Medium |
| **Emergency Change** | No |
| **Implementation Date** | 17 September 2026 |
| **Source Requirements** | PAF Enhancements (business document, five numbered items) |

---

## Objective

Confirm that the five PAF enhancements work as specified, that they do not disturb the existing
invoice submission and payment approval flows, and that the earlier availability of the PAF
document does not weaken access control or allow an unapproved document to be mistaken for an
authorised one.

---

## Scope

**In scope**

1. Mandatory line-item description on invoice submission and edit
2. Approval email subject lines carrying description, vendor, total amount and PAF number
3. PAF document availability from the moment the payment request is created
4. PAF and supporting documents shown inline on the emailed approval page
5. Optional L2-A approver inserted after the second approval level

**Out of scope**

- Correction of historical invoices with blank descriptions (separate data exercise)
- Changes to approval level thresholds, roles or permissions
- The outbound reporting API (unaffected)

---

## Test Scenarios

### Happy Path Tests

| ID | Scenario | Steps | Expected Result |
|----|----------|-------|-----------------|
| HP-01 | Submit an invoice with descriptions on every line | Create an invoice with two lines, each with a description; submit | Invoice saves and enters the Invoice Log as today |
| HP-02 | Approval email subject content | Create a payment request; open the approver's email | Subject contains the description, vendor name, formatted total with currency, and the PRF reference — in that order |
| HP-03 | PAF available at creation | Create a payment request; as Finance, download the PAF | PDF downloads successfully while status is *In Approval* |
| HP-04 | Draft marking on an unapproved PAF | Open the PAF from HP-03 | Document is clearly marked as not yet approved (draft watermark and status line) |
| HP-05 | Final PAF carries no draft marking | Approve the request through every stage; download the PAF | Draft marking is absent; approver names and dates are filled in |
| HP-06 | PAF shown on the approval page | Open the approval link from the approver's email | The PAF is displayed inline above the Approve / Reject buttons, with attachments still listed below |
| HP-07 | Approve from the page showing the PAF | From HP-06, click Approve | Request advances to the next stage; next approver is notified; audit entry written |
| HP-08 | L2-A left empty | Create a request above the L2 threshold without setting L2-A | Chain is identical to today's behaviour — no extra stage |
| HP-09 | L2-A used | Create a request above the L2 threshold, set an L2-A approver | Chain shows the L2-A stage immediately after L2 and before any higher level; each approver is notified in turn |
| HP-10 | L2-A stage on the PAF | Download the PAF for the HP-09 request | The L2-A approver appears in the approval block, marked as required |

### Negative Tests

| ID | Scenario | Steps | Expected Result |
|----|----------|-------|-----------------|
| NG-01 | Submit a line with no description | Leave a line description blank; submit | Saving is refused with a clear message naming the line; no partial record is created |
| NG-02 | Whitespace-only description | Enter spaces only as the description; submit | Refused the same way as NG-01 |
| NG-03 | Server-side enforcement | Submit the request directly to the server with a blank line description, bypassing the screen | Server refuses with a validation error — the rule cannot be bypassed from the client |
| NG-04 | Over-long description | Enter a description beyond the permitted length | Refused with a length message; existing limit unchanged |
| NG-05 | L2-A set to the request creator | Select the person raising the request as L2-A | Refused — nobody approves their own request |
| NG-06 | L2-A set to the same person as L2 | Select the L2 approver as L2-A | Refused with a clear message — the same person cannot occupy both stages |
| NG-07 | L2-A on a request that does not reach L2 | Set an L2-A approver on a low-value request whose chain has no level 2 | Refused with a message explaining L2-A applies only when level 2 is in the chain — the stage is never silently dropped |
| NG-08 | L2-A set to an ineligible user | Select an inactive user, or a user with no approval rights | Refused |
| NG-09 | PAF for a rejected request | Reject a request, then request the PAF | Behaves per specification and never presents a rejected request as approvable |

### Security Tests

| ID | Scenario | Steps | Expected Result |
|----|----------|-------|-----------------|
| SC-01 | PAF requires a valid link | Request the approval page PAF with a missing or altered token | Refused; no document content is returned |
| SC-02 | Expired / used link | Approve a stage, then reopen the same link and request the PAF | The page remains viewable per existing behaviour but no approval action is possible; document access follows the same rule |
| SC-03 | Cross-request access | Use a valid token for request A against the identifier of request B | Refused |
| SC-04 | Unauthorised in-app access | As a user with no visibility of a request, request its PAF | Refused with an access error — the earlier availability has not widened who may see it |
| SC-05 | Attachment access unchanged | Request an attachment through the public link with an invalid token | Refused, as today |
| SC-06 | Subject line content | Review the generated subject against the information-handling decision recorded on the CR | Contains only the agreed fields; no bank details, no personal data beyond the vendor name |
| SC-07 | Audit trail of the L2-A stage | Approve a request that includes L2-A; review the audit history | The L2-A approval is recorded with actor, timestamp and stage, to the same standard as a standing level |

### Regression Tests

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| RG-01 | Submit, post and pay an invoice end to end | Unchanged behaviour throughout |
| RG-02 | Existing invoices with blank descriptions | Remain viewable and reportable; existing payment requests are unaffected |
| RG-03 | Payment request with a single approval level | Routes and completes as before |
| RG-04 | Ad-hoc approvers added at the end of the chain | Still supported and still appended after the level stages, after L2-A |
| RG-05 | Rejection and resubmission | Unchanged |
| RG-06 | Reminder emails | Sent as before, with the new subject format applied consistently |
| RG-07 | Full-approval notification | Sent as before, with the new subject format |
| RG-08 | Credit-note handling and multi-line netting | Unchanged |
| RG-09 | Withdrawal after approval | Unchanged |
| RG-10 | Multi-currency and threshold routing | Unchanged — level selection still measured in base currency |
| RG-11 | PAF for an approved request | Identical in content to the pre-change document, apart from the status marking |
| RG-12 | Attachment merging into the PAF | PDFs and images render inline; other file types still appear on the trailing link page |

### User Acceptance Tests

| ID | Scenario | Acceptance Criterion |
|----|----------|----------------------|
| UA-01 | Approver reads the subject on a phone | The payment can be identified from the inbox preview without opening the email |
| UA-02 | Approver reviews on the approval page | The approver confirms they can decide without downloading anything separately |
| UA-03 | Finance recognises a draft PAF | Finance confirms an unapproved PAF cannot be mistaken for an authorised one |
| UA-04 | Finance uses L2-A | Finance sets an L2-A approver and confirms the routing matches their expectation |
| UA-05 | Submitter completes a description | A submitter confirms the requirement and the message are clear and not obstructive |
| UA-06 | Approval page performance | An approver on a mobile connection opens a request with several large attachments and finds the page usable |

---

## Expected Results

1. No invoice line can be saved without a description, enforced on screen and on the server.
2. Approval, reminder and completion emails carry description, vendor, amount and PRF number in the subject.
3. The PAF is obtainable from creation onward and reflects approval progress at the time it is produced.
4. Every PAF produced before full approval is visibly marked as not authorised.
5. The approval page presents the PAF inline, with attachments still individually reachable.
6. The L2-A stage, when used, routes immediately after L2 and is recorded in the audit trail; when unused, routing is unchanged.
7. No existing behaviour in invoice submission, approval routing, payment or reporting regresses.

---

## Pass/Fail Criteria

**Pass** — every Happy Path, Negative and Security case passes; all Regression cases show no change in behaviour; all UAT criteria are accepted by the business.

**Fail** — any of the following:

- A line without a description can be saved by any route.
- An unapproved PAF is obtainable without a visible draft marking.
- Any PAF or attachment is reachable without valid authorisation.
- Approval routing differs from expectation in any L2-A scenario.
- Any regression case shows changed behaviour.

**Conditional pass** — cosmetic issues only (wording, spacing, subject-line truncation on an uncommon mail client), logged and scheduled, with business-owner agreement.

---

## Test Execution Checklist

- [ ] HP-01 … HP-10 executed and evidenced
- [ ] NG-01 … NG-09 executed and evidenced
- [ ] SC-01 … SC-07 executed and evidenced
- [ ] RG-01 … RG-12 executed and evidenced
- [ ] UA-01 … UA-06 accepted by the business
- [ ] Automated test suite green (`php artisan test`)
- [ ] Code formatted (`./vendor/bin/pint`)
- [ ] Email subjects checked on Outlook desktop, Outlook mobile and webmail
- [ ] Approval page checked on desktop and mobile
- [ ] `/ai` documentation updated for the affected areas
- [ ] Open points on the CR resolved and the CR updated to match what was built

---

## Sign-Off

### QA Lead

Name: ______________________  Signature: ______________________  Date: ____________

Result: Pass / Conditional Pass / Fail

### Business Owner — Payment Approval Process

Name: ______________________  Signature: ______________________  Date: ____________

### UAT Sign-Off — Finance

Name: ______________________  Signature: ______________________  Date: ____________
