# Change Request

## Subject

Replace the Reject action in payment approval with a Query action that keeps the request active
until the query is answered and the request is resubmitted

---

## Executive Summary

Today an approver who is not satisfied with a payment request has one option besides approving it:
**Reject**. A rejection ends the request permanently. Its invoices go back to Finance, the approvals
already given are discarded, and Finance has to build a new payment request and send it through the
whole approval chain again. Most of these rejections are not refusals. The approver needs a
clarification, a missing document or a small correction.

This change removes Reject from the approval workflow and replaces it with an **Approval Query**. An
approver who needs clarification, more information or a correction raises a query with a written
question. The request is not ended. It stays active and on hold at the approver's stage. The invoice
submitter, Finance or an administrator answers the query, makes any correction needed within the
existing correction rules, and resubmits. The request then goes back to the approver who raised the
query, and the approvals already given stay in place.

When a query is raised, the system sends **one email**, with every recipient in BCC. The invoice
submitter(s) and the Finance user who raised the payment request always receive it. Earlier
approvers receive it from Level 2 upwards. From Level 4 upwards, the approver who raised the query
receives it as well. While a query is open, only the submitter, Finance and administrators can work
on the request. Approvers cannot approve it until it is resubmitted. If the query has not been
answered, a reminder goes to the submitter(s) and the Finance user who raised the request every two
days until the request is resubmitted.

The expected outcome: fewer payment requests restarted from scratch, shorter payment cycle times,
and approval evidence that is kept rather than thrown away. Approvers also get a clear, recorded way
to ask a question instead of being forced to reject.

---

## Business Reason for Change

**Business challenge.** Reject is the only alternative to approval, so approvers use it for
questions as well as for real refusals. Each rejection has a disproportionate cost. The request is
closed, its invoices go back to the Invoice Log, Finance raises a new request, and every approver who
already signed has to sign again. A question at the fourth stage means asking three people to
re-approve something they have already approved.

**Operational need.** Finance and the approvers want a question to cause a pause, not a restart.
The request should wait for the answer and then carry on from where it stopped.

**Communication.** A rejection notice currently goes only to the request's creator and the invoice
submitters. Earlier approvers are not told that a request they signed has been stopped further up the
chain. The new notification tells the people who need to know, in a single email, and keeps
reminding the people who must answer until they do.

**Control.** Every query, every answer and every resubmission is recorded in the audit trail
against the request. The record shows who asked what, at which stage, who answered, and when the
request resumed.

---

## Affected Business Areas

| Area | Impact |
|------|--------|
| **Approvers (all levels)** | The Reject button is replaced by a Query button, in the application and on the approval page opened from the email link. A query needs a written question. |
| **Invoice submitters (requesters)** | Receive the query email and the two-day reminders. Answer queries on requests that hold their invoices. Correct their invoices within the existing correction rules, then resubmit. |
| **Finance** | The Finance user who raised the request receives the query email and the reminders. Any Finance user can answer, correct and resubmit a queried request. Finance no longer rebuilds requests after a rejection. |
| **Administrators** | Can answer and resubmit any queried request, as they can act on any stage today. |
| **Payment request process** | A new "Queried" state between "In approval" and "Approved". The request keeps its reference number, invoices and approval history throughout. |
| **Email notifications** | New query notification (single email, BCC). New query reminder every two days while a query is unanswered (single email, BCC). The rejection notice is no longer sent, because nothing is rejected any more. A resubmission notifies the approver who raised the query. |
| **Daily approval reminders** | Paused while a request is queried, so an approver is not reminded about a request they cannot act on. They resume after resubmission. |
| **Payment Approval Form (PAF)** | Shows that a request is under query, and shows the query history. |
| **Reports and dashboard** | Queried requests appear as their own status. Historical rejected requests stay in the "Rejected PAFs" report unchanged. |

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** Payments are still approved and paid today. Rejection is costly in time and
rework but does not stop the business from paying vendors.

### Workaround Availability

**Assessment:** Yes

**Justification:** Approvers can reject and Finance can raise a new request. Submitters can already
correct their invoices during approval without a rejection. Approvers can also ask questions outside
the system by email or phone, though that leaves no record against the request.

### Operational Impact

**Assessment:** No

**Justification:** Delaying the change keeps the current rework but causes no new financial,
operational or reputational damage.

### Timeline Constraints

**Assessment:** No

**Justification:** There is no regulatory or contractual deadline. The change can go through the
normal assessment and testing cycle.

**Emergency Change Classification:** No

**Reason:** This is a process improvement that removes rework and adds communication. It can follow
the standard change process, and it should, because it changes how a financial control works.

---

## Risk Assessment

### Risk Level

**Medium**

The change alters a financial control: it removes an approver's right to end a request outright. It
touches every approval route (in-app and email link), adds a new request state and a new scheduled
reminder, and changes who receives notifications. The financial safeguards that protect existing
approvals are already in place and are reused, not rewritten.

### Risks Identified

**Business risks**

1. **Approvers lose the ability to refuse a payment outright.** Some requests should not proceed at
   all, for example a duplicate payment, the wrong vendor or a payment that is not owed. Without
   Reject, such a request sits in "Queried" until someone acts. *Mitigation:* Finance keeps its
   existing ways to take a request out of the payment cycle. It can release invoices from the
   request one at a time, with a recorded reason, and releasing the last invoice voids the request.
   An approver who believes a payment should not be made raises a query saying so.
2. **Queries left unanswered.** A queried request waits indefinitely, and a payment can be delayed.
   *Mitigation:* a reminder goes to the submitter(s) and the Finance user who raised the request
   every two days until the request is resubmitted. Queried requests are also shown as a separate
   status in the payment request list and on the dashboard.
3. **Resubmission without a real answer.** A request could be resubmitted unchanged. *Mitigation:*
   resubmission needs a written response, which is recorded and shown to the approver who raised the
   query. That approver can raise another query.
4. **Only one Finance user is notified.** The query email and the reminders go to the Finance user
   who raised the request, not to the whole Finance team. If that person is absent or has left, no
   one in Finance is emailed. *Mitigation:* the invoice submitter(s) are always notified as well,
   any Finance user can still answer and resubmit, and queried requests are visible to all of
   Finance in the payment request list and on the dashboard. Deactivated accounts are left out of
   every recipient list, and each omission is logged.

**Operational risks**

5. **One email to several recipients.** *Mitigation:* the recipient list is small: the submitters
   and the Finance user who raised the request, plus at most the earlier approvers on one request.
   This is well within normal mail-server limits, which are confirmed with the mail administrator
   before go-live. A person who appears in more than one role (for example a Finance user who is
   also a submitter) receives the email once.
6. **Recipients cannot see who else was informed.** This is intended with BCC, but "Reply all" will
   not reach the other recipients. *Mitigation:* the email asks readers to respond in the
   application, not by email.
7. **Reminder fatigue.** A query left open for weeks produces a reminder every two days.
   *Mitigation:* reminders go only to the people who can answer (not to approvers), stop the moment
   the request is resubmitted, and are counted during post-deployment monitoring.
8. **Existing requests at go-live.** Requests in approval at go-live must continue normally.
   Requests already rejected must stay as they are. *Mitigation:* the change applies only to actions
   taken after deployment. Historical rejections, their report entries and their PAFs are not
   altered.
9. **Confusion with the existing invoice query.** Finance can already raise a "query" on an
   individual invoice in the Invoice Log, which sends the invoice back to its submitter. The new
   approval query is a different thing on a different record. *Mitigation:* it is called
   **"Approval Query"** in the application and in the email subject.

**Security risks**

10. **Wrong people acting on a queried request.** While a query is open, only the submitter, Finance
    and administrators may work on the request. *Mitigation:* the restriction is enforced by the
    system, not just hidden on screen, on every route an approver can use, including the email
    approval link. Approvers keep read-only visibility of requests they already have access to.
11. **Approvals invalidated by a correction.** If an answer to a query changes the amount, approvals
    already given might no longer cover the payment. *Mitigation:* the existing correction rules
    apply unchanged. The currency cannot change, the total cannot increase, and a submitter cannot
    change the vendor. A change beyond those limits still means releasing the invoice and starting
    again.
12. **Email addresses exposed.** *Mitigation:* every recipient is in BCC, so no recipient sees any
    other recipient's address.

### Risk Mitigation Plan

- Enforce the "Queried" restriction on the server for both approval routes (in-app and email link).
- Reuse the existing in-approval correction rules for answering queries.
- Send query reminders every two days to the people who can answer, until resubmission.
- Confirm the mail server's BCC handling before go-live.
- Record every query, answer and resubmission in the audit trail.
- Make queried requests visible as their own status in lists, the dashboard and the PAF.
- Run a parallel check for the first four weeks: Finance reviews every queried request for answer
  time and resubmission outcome.

---

## Expected Business Impact

### Positive Impact

- **Less rework.** A question no longer forces a new payment request or another round of
  approvals.
- **Faster payments.** A resubmitted request continues from the stage that raised the query, not
  from the first stage.
- **Approval evidence kept.** Approvals already given stand and stay on the record.
- **Better communication.** The submitters, the Finance user who raised the request and the earlier
  approvers learn about a query at the moment it is raised, in one email.
- **Queries do not go quiet.** The two-day reminder keeps an unanswered query in front of the people
  who must answer it.
- **Clearer audit trail.** Questions and answers are recorded against the request instead of in
  separate emails.

### Potential Negative Impact

- Approvers who are used to rejecting will need a short briefing.
- A request that should never be paid needs Finance to take it out, instead of an approver ending it
  in one step.
- The Finance user who raised a request receives an email for every query on it, and a reminder
  every two days while it is unanswered.

### User Impact

| User | Before | After |
|------|--------|-------|
| Approver | Approve or Reject | Approve or Query. Cannot act while their own query, or one raised at another stage, is open. Notified when the request comes back to them. |
| Submitter | Learns of a rejection and waits for Finance to rebuild the request | Receives the query and reminders, answers, corrects within the rules, and resubmits |
| Finance user who raised the request | Rebuilds rejected requests | Receives the query and reminders. Can answer, correct and resubmit. |
| Other Finance users | Rebuild rejected requests | Not emailed. Can see queried requests and can answer and resubmit them. |
| Earlier approvers | Not told when a request is stopped later in the chain | Notified once when a query is raised at a later stage (from Level 2 upwards). No reminders. |

### Reporting Impact

- A new **Queried** status in the payment request list, filters and dashboard counts.
- Query history (question, response, who, when) on the request detail page and the PAF.
- The **Rejected PAFs** report column keeps showing historical rejections. No new rejections will
  appear after go-live.

### Compliance Impact

- Strengthens the audit trail. Each query and resubmission is a recorded, attributable event.
- Keeps segregation of duties. Nobody approves their own request, and only the assigned approver,
  or an administrator, can approve after resubmission.
- Removing the approver's right to refuse outright is a change to a financial control. The Finance
  business owner should sign it off explicitly (see Approval Requirements).

---

## Notification Rules

### Query raised

One email per query. All recipients are in BCC, and no separate email is sent to each recipient. A
person who qualifies twice receives it once.

| Stage raising the query | Email recipients (BCC) | Who can work on the request while queried |
|-------------------------|------------------------|-------------------------------------------|
| Level 1 | Invoice submitter(s), the Finance user who raised the request | Submitter, Finance, Admin |
| Level 2 | Invoice submitter(s), the Finance user who raised the request, Level 1 approver | Submitter, Finance, Admin |
| Level 3 | Invoice submitter(s), the Finance user who raised the request, Level 2 and Level 1 approvers | Submitter, Finance, Admin |
| Level 4 and above | Invoice submitter(s), the Finance user who raised the request, the approver who raised the query, all previous approvers | Submitter, Finance, Admin |

"Level" here means the stage's position in the request's approval chain: first stage = Level 1,
second = Level 2, and so on. Additional stages added for a particular request (such as the optional
L2-A countersignature or other additional approvers) count as stages in their position. The approver
who raised the query is deliberately not copied at Levels 1–3. They are copied from Level 4 upwards,
as requested.

### Query reminder

Every **two days** while a query is unanswered, one email (BCC) goes to the invoice submitter(s) and
the Finance user who raised the request. The approvers are not reminded, because they cannot act
until the request is resubmitted. Reminders stop as soon as the request is resubmitted. If a new
query is raised later, its reminders start again from that query.

### Resubmission

When the request is resubmitted, the approver who raised the query is notified that the request is
back with them, the same way an approver is notified today when a request reaches their stage.

---

## Decisions Recorded

Confirmed by stakeholders on 2026-10-14.

1. **Invoice submitter(s) receive the query email.** They are added to the BCC list at every level.
2. **The approver who raised the query is copied from Level 4 upwards only.** The rule is kept as
   originally written.
3. **"Finance" means the Finance user who raised the payment request**, not the whole Finance team.
4. **A resubmitted request goes back to the approver who raised the query.** Approvals already given
   are kept, because the correction rules stop the amount from rising.
5. **Who can resubmit:** the submitter of any invoice on the request, Finance and administrators. A
   written response is required.
6. **Stopping a request that should not be paid:** Finance releases its invoices with a recorded
   reason. Releasing the last invoice voids the request.
7. **Unanswered queries are reminded every two days**, by one email (BCC) to the invoice
   submitter(s) and the Finance user who raised the request, until the request is resubmitted.
8. **Naming:** the action is called **"Approval Query"** in the application and in the email
   subject.

---

## Implementation Overview

1. Replace Reject with Approval Query for approvers, in the application and on the approval page
   opened from the email link. A query needs a written question.
2. Introduce a "Queried" state for payment requests. The request keeps its invoices, its approvals
   and its place in the chain. While it is queried, no approver can approve it, and only the
   submitter, Finance and administrators can work on it.
3. Let the submitter, Finance and administrators answer the query, correct invoices within the
   existing rules, attach documents, and resubmit with a written response.
4. On resubmission, return the request to the approver who raised the query and notify them.
5. Send the query notification as a single email with all recipients in BCC, chosen by the stage
   rules above.
6. Add a reminder that runs every two days for unanswered queries, and pause the daily approval
   reminders for queried requests.
7. Show the query state and history on the request page, the approvals queue, the dashboard and the
   PAF. Keep historical rejected requests and their report entries exactly as they are.
8. Record every query, answer and resubmission in the audit trail.
9. Update the user guidance that tells Finance to "reject the request" before an invoice can be
   returned, since that route will no longer exist.

---

## Rollout Plan

1. **Development:** Build the Approval Query action, the Queried state, resubmission, the single BCC
   notification and the two-day reminder, as recorded under *Decisions Recorded*. Add automated
   tests for the approval transitions, the access restriction, the recipient list at each level and
   the reminder timing.
2. **Internal Validation:** Verify that no route allows an approver to reject. Verify that a queried
   request cannot be approved by anyone, including through the email link, until it is resubmitted.
   Verify that exactly one email is sent per query, with the correct BCC list at Levels 1, 2, 3, 4
   and 5, and that a person in two roles is listed once. Verify that the reminder goes out two days
   after the query, again two days later, and not after resubmission.
3. **QA Verification:** Test end to end with chains of different lengths, including additional
   stages. Confirm that requests already in approval at go-live keep working, and that historical
   rejected requests, their PAFs and the Rejected PAFs report are unchanged.
4. **User Acceptance Testing:** An approver at Level 3 raises a query. The submitter, the Finance
   user who raised the request and the Level 1 and Level 2 approvers each confirm they received one
   email. The query is left unanswered for two days, and the submitter and Finance user confirm the
   reminder. The submitter then answers and resubmits, and the Level 3 approver confirms that the
   request returned to them with the earlier approvals in place.
5. **Production Deployment:** Back up the database before release. Deploy in a scheduled window
   outside the month-end payment peak. Brief approvers ("Approval Query replaces Reject"),
   submitters (how to answer and resubmit) and Finance (how to stop a request that should not be
   paid).
6. **Post Deployment Monitoring:** Monitor for four weeks: number of queries, time to answer,
   reminders sent per query, requests resubmitted more than once, delivery of query emails, and any
   request left queried for more than five working days. Review with Finance at the end of the
   period.

---

## Backout Plan

1. **Suspend new functionality:** Restore the Reject button, stop new queries and stop the query
   reminders. Requests already queried are resubmitted by Finance or released, so none is left on
   hold with nobody able to act.
2. **Restore previous application state:** Roll back the release, returning approval to Approve or
   Reject with the current rejection notice.
3. **Restore backups if required:** Only if data integrity is affected. Queries, answers and
   approvals recorded after deployment exist only in the live database. They must be preserved
   before any restore is considered.
4. **Validate business operations:** Confirm requests can be approved, rejected and paid end to end,
   that the daily approval reminders resume, and that every existing PAF opens.
5. **Notify stakeholders:** Tell approvers, submitters and Finance that Reject is back and what
   happened to requests that were queried at the time.

---

## Approval Requirements

### Requestor

Name: ______________________  Signature: ______________________  Date: ____________

### Department Manager — Finance

Name: ______________________  Signature: ______________________  Date: ____________

### IT Manager

Name: ______________________  Signature: ______________________  Date: ____________

### Business Owner — Payment Approval Process

*Explicit sign-off needed: this change removes an approver's right to refuse a payment outright.*

Name: ______________________  Signature: ______________________  Date: ____________

### CAB Approval (if applicable)

Required: this changes a financial control and the approval workflow.

Name: ______________________  Signature: ______________________  Date: ____________

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-10-14

Decisions Recorded: 2026-10-14

Risk Rating: Medium

Emergency Change: No

Analysis Confidence: High (90%)

| Measure | Confidence |
|---------|------------|
| Analysis Confidence Score | 90% |
| Affected Features Confidence | 90% |
| Business Impact Confidence | 85% |
| Security Impact Confidence | 85% |
| Risk Assessment Confidence | 85% |

---

## Implementation Record

**Status:** Implemented 2026-10-14, as recorded under *Decisions Recorded*. Architecture decision:
`ai/decisions/ADR-004-approval-query-replaces-rejection.md`.

How it was built, where it differs from or adds to the text above:

- **Who can query.** The assigned approver for the current stage, or an administrator, exactly as
  for approval. The person who raised the request can never query it, just as they can never approve
  it.
- **Deactivated users** are left out of every recipient list (not only the Finance user), and each
  omission is logged.
- **Dashboard.** Anyone who can answer a query (Finance, administrators, and submitters for their own
  requests) sees a banner with the number of requests on hold for an approval query.
- **Status label.** Queried requests show as **"Approval Query"** in lists, filters and the request
  page. The PAF carries an "ON HOLD — QUERY" watermark and lists every query and answer.
- **Stopping a request.** Withdrawal still applies only to fully approved requests. A request in
  approval or under query is taken out by releasing its invoices, as decided.

**Verification:** automated tests cover the query hold, the recipient list at stages 1–5, the
single-email rule, de-duplication, deactivated users, the approval block on both routes, who may
resubmit, the return to the same stage, the emailed link, the two-day reminder schedule, and the
daily approval reminder skipping queried requests (`tests/Feature/ApprovalQueryTest.php`). Existing
tests that used rejection were moved onto the query or onto historical rejection records. The full
suite passes apart from four failures that predate this change and are recorded in
`ai/issues/technical-debt.md`. User acceptance testing per the Rollout Plan is still to be done.

---

# Technical Analysis Appendix

Included because the change touches two approval routes, adds a request state and adds a scheduled
job, which drive the effort and the risk.

### Affected Systems

- **Approval actions.** Rejection exists on two separate routes, the in-app approval and the
  token-gated email link. Both are replaced, and the "queried" restriction must be enforced on both.
- **Payment request states.** A new state, plus records of each query (stage, question, raised by,
  response, resubmitted by, timestamps, last reminder sent). This is an additive database change: no
  existing data is rewritten, and historical `rejected` requests stay as they are. A database backup
  is taken before the migration in line with the project's database safety rules.
- **Notifications.** All existing notifications send one email per recipient. The query notice and
  the query reminder are the first single emails with a BCC list, and they need a visible "To"
  address (the system's sender mailbox). Emails are queued, so one job carries all recipients.
  "Finance" resolves to the user who created the payment request.
- **Reminders.** The existing daily reminder job selects requests in approval, and queried requests
  must be excluded from it. The two-day query reminder is a new scheduled job, using the same
  "last reminder sent" approach as the existing approval reminders.
- **Invoice correction.** The existing "submitter corrects during approval" and "Finance corrects in
  place" rules are reused for answering a query. Their availability is tied to the request being in
  approval, so it has to extend to the queried state.
- **Guidance text.** Messages that tell Finance to "reject that request" before raising an invoice
  query, or before withdrawing, need rewording because rejection no longer exists.

### Related Change Requests

- `hold-invoices-on-a-rejected-payment-request.md` covers what happens to invoices on rejection. It
  is not yet reflected in the code. If this change is approved, new rejections stop, and that CR
  only matters for requests rejected before go-live. Its scope should be reconsidered.
- `report-and-notify-rejected-payment-requests.md` and
  `requester-corrections-during-approval-and-payment-request-attachments.md` both describe behaviour
  this change builds on (the rejection report is kept for history, and the correction rules are
  reused).

### Security Controls

- Server-side enforcement of who may act on a queried request (submitter of an invoice on it,
  Finance, admin). Approvers, including the one who raised the query, can view but not approve.
- The existing rule that nobody approves their own request is unchanged.
- BCC for every recipient, so no recipient's email address is disclosed to another.
