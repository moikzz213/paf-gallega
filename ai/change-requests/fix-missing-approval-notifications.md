# Change Request

## Subject

Correct the approval notification so it is not lost when a payment request moves on before the
email is sent

---

## Executive Summary

Approvers have reported not receiving the email that tells them a payment request is waiting for
their decision, particularly when decisions are taken from the Approvals screen. The reports are
accurate, and there are two separate causes. One is a defect in the application. The other is an
operational prerequisite that may not be running on the live server.

The defect concerns timing. When someone approves a stage, the platform prepares an email for the
next approver and hands it to a background queue, which sends it a moment later. That email was
written to work out who it is addressed to, and which secure link to include, **at the moment it is
sent** rather than at the moment it is prepared. In between, the payment request can move on — the
next approver may already have acted, or the request may have been fully approved or rejected. When
that happens, the information the email was going to look up no longer exists, the email fails
outright, and nobody is told anything. The failure is silent: it is recorded in a background error
log that nobody reads, and the person who approved sees a normal success message.

This is most likely to happen exactly where it has been reported. On the Approvals screen a user
works through several requests in quick succession, and an administrator can act on any stage, so
requests move through their approval chain faster than the queue sends the emails chasing them.

The fix is to decide the recipient and the secure link when the email is prepared, and carry them
with it, so that what the approver receives reflects the moment the decision was taken and cannot
be invalidated by anything that happens afterwards.

The second cause is that queued email requires a background worker process to be running on the
server. If that process is not running, no email of any kind is delivered — approvals, rejections,
queries or password resets — and the messages simply accumulate unsent. Evidence from the live data
suggests this needs checking, and it is an operational action rather than a code change.

---

## Business Reason for Change

**Business challenge.** The approval chain depends on each approver being told it is their turn. If
that email is lost, the request sits waiting on someone who does not know, and is only discovered
when somebody chases it or happens to open the Approvals screen. The daily reminder eventually
covers it, but the delay is real and the process looks unreliable to the people using it.

**Why it has gone unnoticed.** The failure leaves no trace in front of any user. The approval itself
is recorded correctly, the screen confirms success, and only a background error log records that
the email was abandoned. There is no indication to the approver, to Finance, or to the person who
acted.

**Operational need.** Approvers have complained. The credibility of the workflow depends on the
notification being dependable, and at present it is dependable only when nothing else happens to the
request in the seconds after a decision.

---

## Affected Business Areas

**Departments and teams.** All approvers, across every department that approves payment requests.
Finance, who field the chasing when a request stalls.

**Processes.** Payment request approval routing. Nothing about the approval decision itself, the
amounts, the chain or the records is affected — only the message that announces it.

**Users.** Approvers waiting on a request. Administrators acting on behalf of approvers, whose rapid
sequential use of the Approvals screen makes the problem most likely.

**Reports.** None affected.

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No, but with qualification.

**Justification:** The platform records approvals correctly and no data is lost or wrong. What fails
is the announcement. Payments are delayed rather than mis-made.

### Workaround Availability

**Assessment:** Partial.

**Justification:** Approvers can open the Approvals screen and see what is waiting without being
emailed, and a daily reminder goes out for requests still pending. Both mean the work is eventually
picked up, but neither is a substitute for being told at the time.

### Operational Impact

**Assessment:** Moderate.

**Justification:** Each lost notification is a payment request idling until someone notices.
Compounded across a chain of several stages, a request can lose days.

### Timeline Constraints

**Assessment:** No.

**Justification:** The code change is small and contained. The operational check costs minutes.

**Emergency Change Classification:** No.

**Reason:** A contained defect with a partial workaround already in place. It warrants prompt
scheduling rather than an emergency release, though the server-side check should be done
immediately since it costs nothing.

---

## Risk Assessment

### Risk Level

**Low.**

The change affects only how one email determines its recipient and its link. It alters no decision,
no record, no amount and no permission.

### Risks Identified

**Business risk — none material.** The change can only increase the number of notifications that
arrive correctly. The failure being fixed is an email that was not sent at all.

**Technical risk — emails already queued.** Any notifications sitting unsent on the live server were
prepared in the old form. After the change they cannot be sent as they are and should be discarded
rather than retried; they are, in any case, already failing. These are stale announcements about
requests that have since moved on, so nothing of value is lost.

**Operational risk — the second cause may mask the first.** If the background worker is not running,
fixing the defect will appear to change nothing, because no email is being delivered at all. The
server-side check must be done first or the fix cannot be judged.

**Security risk — none.** The secure link included in the email is the same link, for the same
approver, chosen a moment earlier. Nothing about who may view or approve a request changes.

### Risk Mitigation Plan

1. Confirm the background worker is running on the live server before anything else, since nothing
   can be verified until it is.
2. Discard the stale unsent messages rather than retrying them.
3. Cover the corrected behaviour with an automated test that reproduces the original failure — an
   email prepared and then sent after the request has moved on.
4. Ensure that where a notification genuinely cannot be addressed, it is skipped and recorded
   deliberately rather than failing partway through.

---

## Expected Business Impact

### Positive Impact

- Approvers are told when a request reaches them, reliably, regardless of what happens next.
- Notifications stop failing silently.
- The Approvals screen, where decisions are taken in quick succession, becomes dependable.
- Fewer stalled requests and less chasing.

### Potential Negative Impact

- None to the business process. Stale unsent messages from before the change are discarded, which
  means a small number of historic announcements are never delivered — they concern requests that
  have long since moved on.

### User Impact

Approvers receive the email they expect. No screen, field or action changes.

### Reporting Impact

None.

### Compliance Impact

Neutral to positive. The audit trail is unaffected; notification delivery becomes more reliable.

---

## Implementation Overview

The notification is changed to carry its recipient and its secure link with it from the moment it is
prepared, rather than looking them up when it is finally sent. Since the stage a request is waiting
on is settled at the moment of approval, that is the correct moment to capture it.

Where a notification genuinely has no recipient — a stage with no approver assigned — it is skipped
and recorded, rather than being prepared and then failing.

Separately, and as an operational matter, the background worker that delivers queued email must be
confirmed running on the live server, together with the scheduled task that drives the daily
reminder. Without the worker, no email of any kind leaves the system.

---

## Rollout Plan

1. **Development** — change the notification to carry its recipient and link, with a test that
   reproduces the original failure.
2. **Internal Validation** — confirm a notification prepared before a request moves on still sends
   correctly, addressed to the right approver with the right link.
3. **QA Verification** — regression pass over approval, rejection and the daily reminder.
4. **User Acceptance Testing** — approvers confirm they receive notifications, including when
   several requests are actioned in succession from the Approvals screen.
5. **Production Deployment** — confirm the background worker first, clear the stale unsent messages,
   then deploy.
6. **Post Deployment Monitoring** — watch the failed-message log over the first week; it should stay
   empty.

---

## Backout Plan

1. **Suspend new functionality** — redeploy the previous version. The change is confined to the
   application code.
2. **Restore previous application state** — no database change is involved, so nothing to restore.
3. **Restore backups if required** — not applicable.
4. **Validate business operations** — confirm approvals, rejections and reminders behave as before.
5. **Notify stakeholders** — advise approvers that the intermittent missing notification may recur.

---

## Approval Requirements

### Requestor

Finance / Accounts Payable, following approver complaints

### Department Manager

Finance Manager

### IT Manager

Required — includes a server-side operational check.

### Business Owner

Head of Finance

### CAB Approval (if applicable)

Not required. A contained defect fix with no data, schema or permission impact.

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 2026-10-02

**Risk Rating:** Low

**Emergency Change:** No

**Analysis Confidence:** High — the failure was reproduced against live data, and the recorded
background failure names the exact notification and cause.

**Affected Features Confidence:** High — one notification; approval, rejection and payment logic are
untouched.

**Business Impact Confidence:** High.

**Security Impact Confidence:** High — no change to access or to the link's secrecy.

**Risk Assessment Confidence:** Medium-High — the code defect is certain; how much of the reported
problem is the worker not running can only be settled on the server.

---

# Technical Analysis Appendix

**The defect.** `PaymentRequestSubmitted` resolves both the greeting and the approval link through
`PaymentRequest::currentApproval()`, which looks up the approval matching the request's
`current_stage`. The mailable implements `ShouldQueue` and re-fetches its model when the worker
runs, so the lookup happens at render time, not dispatch time. Once `current_stage` has changed — a
later stage approved, or the request approved or rejected outright, both of which set it to `null` —
the lookup returns nothing, the link cannot be generated, and `UrlGenerationException` aborts the
job. Confirmed against live data: a request with `current_stage` null returns no current approval,
and generating the link throws.

**Why the Approvals screen.** An administrator may act on any stage, and the screen presents many
requests at once, so a chain can be driven to completion in seconds while the queue is still working
through the emails announcing its earlier stages.

**The fix.** The approval is passed to the mailable explicitly at dispatch, when the routing decision
was taken. Serialising a specific approval record is stable; its approver and its token do not
change. The same class of problem was corrected in the rejection notice, which captures what it
needs before the data it depends on is altered.

**The operational prerequisite.** Every mailable is `ShouldQueue` and the queue connection is
`database`, so delivery depends on `queue:work` running under a supervisor, as the deployment notes
state. Unsent messages accumulate in the `jobs` table and failures land in `failed_jobs`; the live
database shows entries in both, including the failure described above.

**Stale messages.** Jobs queued before this change carry the old serialised form and should be
cleared rather than retried.
