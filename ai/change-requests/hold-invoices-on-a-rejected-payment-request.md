# Change Request

## Subject

Keep invoices attached to a rejected payment request until Finance deliberately releases them,
instead of returning them to the pool automatically

---

## Executive Summary

When a payment request is rejected today, its invoices are detached from it immediately and
automatically. They return to the pool of invoices available for payment in the same instant the
rejection is recorded, and the rejected request is left holding nothing. Nobody chooses this; it
simply happens as part of the rejection.

This causes two practical problems. The rejected request becomes an empty shell — opening it shows
a rejection and a reason but not the invoices it was rejected over, so the one screen that should
explain what happened cannot show it. And the invoices become immediately available to be pulled
onto a new payment request before anyone has decided what the rejection means or what needs
correcting, which invites the same invoices being re-submitted unchanged, straight back into the
approval chain the approver just turned down.

This change makes the separation deliberate. A rejected request keeps its invoices. They are held
in a new payment state that marks them as sitting on a rejected request, which keeps them out of
any new payment request until someone acts. When Finance has decided what to do, they release each
invoice from the rejected request individually — a single, visible action, recorded in the audit
trail — and only then does the invoice return to the pool and become available again.

Releasing is per invoice rather than all at once, because a rejection is frequently caused by one
invoice among several. Finance can free the invoices that were never in question and leave the
disputed one attached while it is sorted out.

The rejected request itself does not change status. It stays rejected, with its reason and its
approval history intact, permanently. Releasing an invoice only detaches that invoice; it does not
reopen, withdraw or alter the request.

The expected outcome is that nothing leaves a rejected payment request by accident, the rejected
request remains a complete and readable record of what was rejected, and the decision to put an
invoice back into circulation is made by a person and recorded, rather than happening as an
invisible side effect.

---

## Business Reason for Change

**Business challenge.** Rejection is the point at which a payment request has been judged wrong in
some way. It is precisely the moment that warrants a pause and a decision. At present it is the
opposite: the system acts immediately and silently, dissolving the request and returning its
contents to general availability before anyone has looked at why it was rejected.

**Operational need.** Finance have asked for the invoices to stay where they are. The automatic
return gives them no opportunity to review the rejected request as a whole, and no record of a
decision to put each invoice back into play. A deliberate release gives them both.

**Control.** An invoice that returns to the pool automatically can be grouped onto a new payment
request by anyone with the right role, including before the rejection reason has been read. The
approver who rejected it may then see the same invoice again, unchanged, with no indication that
anything was considered. Holding the invoice until it is released closes that loop.

**Record quality.** A rejected request that holds nothing is a poor record. Today the only way to
see what was on it is through reporting, which had to be given a dedicated mechanism precisely
because the live record was emptied. Keeping the invoices attached means the request itself answers
the question.

---

## Affected Business Areas

**Departments and teams.** Finance and Accounts Payable, who act on rejections. Approvers, who will
continue to see a rejected request complete rather than emptied. Requesters, whose invoices now
remain visibly attached to the rejected request until released.

**Processes.** Payment request rejection, and the handling that follows it. Invoice submission,
posting, approval, payment and reporting are not changed.

**Reports.** No new report. One correction is needed to avoid a rejection being listed twice while
an invoice is still attached to the request that rejected it.

**Users.** Finance gain one new action on a rejected request. No other screen gains or loses
anything.

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No.

**Justification:** The platform is working. Rejection records correctly and invoices do become
available again; the objection is to it happening automatically rather than by decision.

### Workaround Availability

**Assessment:** Partial.

**Justification:** Finance can look up what was on a rejected request through reporting, and can
exercise discipline about not re-grouping invoices before reviewing the rejection. Neither is
enforced by the system, which is the point of the request.

### Operational Impact

**Assessment:** Low to moderate if delayed.

**Justification:** The present behaviour does not lose data, but it allows a rejected invoice to
re-enter the approval chain unexamined, and it leaves rejected requests unreadable on screen.

### Timeline Constraints

**Assessment:** No.

**Justification:** The change is self-contained and can follow the normal assessment and release
process.

**Emergency Change Classification:** No.

**Reason:** A workflow and control improvement with no outage, data loss or security exposure
behind it.

---

## Risk Assessment

### Risk Level

**Medium.**

The change is contained, but it alters what happens at a point every payment request can reach, and
it introduces a state an invoice can be left sitting in.

### Risks Identified

**Operational risk — invoices forgotten on a rejected request.** This is the main new risk. Today
an invoice always comes back by itself; afterwards it comes back only when somebody releases it. An
invoice nobody releases is an invoice nobody pays, and the vendor will chase it. This is the cost
of making the step deliberate, and it has to be made visible rather than assumed away.

**Operational risk — an extra step in a routine path.** Where a rejection is straightforward and
everything needs re-submitting, Finance now perform a release they did not previously have to.
Per-invoice release makes that several clicks rather than one where a request held several invoices.

**Business risk — invoices cannot be corrected while held.** An invoice attached to a rejected
request is not editable, exactly as it is not editable while on a live request. The order of work
becomes release, then correct, then re-submit. This is a change in sequence for Finance, not a loss
of capability.

**Visibility change — approvers keep seeing rejected invoices.** Approvers can see invoices on a
request routed to them. Because rejection currently detaches, a rejected invoice drops out of their
view immediately; afterwards it stays visible until released. This is a deliberate widening and the
reverse of a decision taken in an earlier change, so it is called out rather than left to be
discovered.

**Reporting risk — a rejection reported twice.** While an invoice is attached to the request that
rejected it, the rejection can be read both from the request and from the separate rejection
history, and would otherwise appear twice in the remarks. Corrected as part of this change.

**Security risk — none identified.** No change to authentication or to who may do what, beyond the
new release action being restricted to Finance and administrators, consistent with withdrawal.

### Risk Mitigation Plan

1. Restrict the release action to Finance and administrators, as withdrawal already is.
2. Record every release in the audit trail, naming the invoice, the request and who released it.
3. Mark held invoices with their own clearly labelled payment state so they are never mistaken for
   invoices awaiting a payment request, and can be filtered and counted.
4. Keep rejected requests listed and openable so held invoices remain discoverable rather than
   silently parked.
5. Correct the duplicate rejection remark in reporting in the same change.
6. Confirm during acceptance that an invoice held on a rejected request cannot be pulled onto a new
   payment request until released.

---

## Expected Business Impact

### Positive Impact

- Nothing leaves a rejected payment request by accident.
- A rejected request remains a complete record of what was rejected, readable on screen.
- Putting an invoice back into circulation becomes a recorded decision with a named actor.
- An invoice cannot re-enter the approval chain before someone has looked at the rejection.
- Finance can free the uncontroversial invoices and hold back only the one at issue.

### Potential Negative Impact

- An invoice can now be left held indefinitely if nobody releases it, which did not previously
  happen. This is the deliberate trade-off and is the item most worth watching after release.
- One additional action in the common case, repeated per invoice.
- Correcting a rejected invoice now requires releasing it first.

### User Impact

Finance see a release action on each invoice of a rejected request. Approvers and requesters see
rejected requests that still list their invoices, where previously they appeared empty. No existing
screen loses anything.

### Reporting Impact

No change to report contents or columns, other than removing a duplicated rejection remark that
would otherwise appear while an invoice is still attached.

### Compliance Impact

Positive. A step that previously happened automatically becomes an attributable, audited decision.

---

## Implementation Overview

Rejection stops detaching invoices. The rejected request keeps them, and they are marked with a new
payment state meaning "held on a rejected request", which keeps them out of the pool that feeds new
payment requests.

Finance and administrators gain an action to release an individual invoice from a rejected request.
Releasing returns that one invoice to the pool, making it available again and editable again, and
records the release in the audit trail. The rejected request keeps its status, its reason and its
approval history unchanged; it simply holds one fewer invoice.

The existing record of which invoices were on a rejected request is kept and continues to feed
reporting, so a rejection stays visible after the invoices have moved on to a later request.
Reporting is corrected so that a rejection is not listed twice while the invoice is still attached
to the request that rejected it.

Requests rejected before this change already have their invoices detached. They are not altered and
need no release; they stay as they are.

---

## Rollout Plan

1. **Development** — implement the held state, the per-invoice release, the audit entry and the
   reporting correction, with automated tests covering rejection, release, eligibility for a new
   request, and the unchanged behaviour of approval, withdrawal and payment.
2. **Internal Validation** — exercise a full reject-and-release cycle, confirming a held invoice
   cannot be grouped onto a new request and a released one can.
3. **QA Verification** — regression pass over the rejection, approval and payment journeys.
4. **User Acceptance Testing** — Finance confirm the held state reads clearly, releasing behaves as
   expected, releasing one invoice of several leaves the others held, and rejected requests now
   read as complete records.
5. **Production Deployment** — ordinary release; no data migration of existing records.
6. **Post Deployment Monitoring** — watch for invoices left held and unreleased over the first
   weeks. This is the risk the change introduces, and the one to measure.

---

## Backout Plan

1. **Suspend new functionality** — revert to detaching invoices at the point of rejection.
2. **Restore previous application state** — redeploy the previous version. The new payment state is
   a value in an existing column, so removing it is a code change, not a structural one.
3. **Release anything still held** — invoices sitting in the held state at the time of a backout
   must be returned to the pool, otherwise they would be left in a state the reverted code does not
   produce. This is a short, scripted correction and the only backout step needing care.
4. **Validate business operations** — confirm rejection, re-grouping, approval and payment behave
   as before.
5. **Notify stakeholders** — tell Finance that rejection has returned to releasing invoices
   automatically.

---

## Approval Requirements

### Requestor

Finance / Accounts Payable

### Department Manager

Finance Manager

### IT Manager

Required — changes workflow behaviour and invoice visibility.

### Business Owner

Head of Finance

### CAB Approval (if applicable)

Recommended. Low technical complexity, but it changes what happens at rejection for every payment
request and introduces a state an invoice can be left in.

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 2026-10-02

**Risk Rating:** Medium

**Emergency Change:** No

**Analysis Confidence:** High — the rejection, withdrawal, eligibility and reporting paths were read
directly.

**Affected Features Confidence:** High — rejection and the handling that follows it; approval,
payment and invoice submission are untouched.

**Business Impact Confidence:** Medium-High — the behavioural change is well understood; how often
invoices are left unreleased can only be measured after release.

**Security Impact Confidence:** High — no change to authentication or authorisation beyond
restricting the new action to Finance and administrators.

**Risk Assessment Confidence:** High — the risks are workflow risks, visible in the data, and none
involves data loss.

---

# Technical Analysis Appendix

**Affected systems.** Payment request rejection, the invoice pool that feeds new payment requests,
invoice visibility scoping, and the invoice report's remarks.

**Held state.** A new value in the invoice's existing payment-status column. It keeps held invoices
out of the eligibility query, which selects on that column, without a structural change. The value
already has a label and colour in the shared status metadata, so it renders without front-end work.

**Rejection history.** The separate record of which invoices were on a rejected request is retained;
it is what lets a rejection stay visible in reporting after the invoices have moved to a later
request. While an invoice is still attached to the rejecting request, the same rejection is readable
from two places, which is the duplicate remark corrected here.

**Visibility.** Approver visibility of an invoice follows the request it is attached to. Keeping the
attachment therefore keeps the invoice visible to the approvers of that request until it is
released — a deliberate reversal of an earlier decision to leave approver visibility unwidened.

**Existing data.** Requests rejected before this change have already had their invoices detached and
are unaffected. No backfill is performed or needed.

**Reversibility.** The behaviour is code-level. The only backout step requiring care is returning
any invoice still in the held state to the ordinary pool.
