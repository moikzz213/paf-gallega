# Change Request

## Subject

Route payment approvals past an approver who is on leave

---

## Executive Summary

Every payment request must be signed by a named approver at each required approval level, one
after another. Today the system has no way to deal with an approver who is away. If the approver
for a level is on leave, Finance cannot leave that level empty when raising the request. If the
request is already waiting on that person, it stays stuck until they return. Payments to vendors
are delayed for no reason other than someone's absence.

This change adds an **On leave** option in two places:

1. **When Finance builds the approval chain.** Next to each required level's approver there is an
   "On leave" option. When it is chosen, that level is recorded as skipped, and the request goes
   straight to the next approver.
2. **On a request that is already waiting.** Finance or an administrator can mark the approver
   currently holding the request as on leave. The request then moves to the next approver, who is
   emailed their approval link as usual.

In both places a **reason is required**. The skipped stage stays visible as "Skipped — on leave",
with the reason, the person who marked it and the date, on the request page, the emailed approval
page, the printed Payment Approval Form (PAF) and the audit trail. Nobody can mistake a skipped
stage for a signature.

The expected outcome is that an absence no longer stops payments, and each case leaves a clear,
reviewable record of who was skipped and why.

---

## Business Reason for Change

- **Payments stall during leave.** A request waiting on an approver who is away cannot move. The
  only way out today is to release the invoices and raise a new request with a different chain,
  which repeats Finance's work and restarts every approval already given.
- **Late payments cost money and goodwill.** Missed due dates can mean late fees, lost early-payment
  discounts and strained vendor relationships.
- **Workarounds leave no record.** When people work around an absence informally, there is no
  record of why a level was not signed. A formal "On leave" option with a required reason makes the
  exception visible and auditable.

---

## Affected Business Areas

- **Finance team:** builds approval chains and will use the new option on waiting requests.
- **Approvers (all levels, including nominated Finance approvers):** may be skipped while on leave.
  The next approver receives the request sooner.
- **Administrators:** can mark an approver on leave on any waiting request.
- **Requesters / invoice submitters:** see the skipped stage on the request and the PAF. Nothing
  changes in how they submit invoices.
- **Internal audit / auditors:** each skip appears in the audit trail and on the PAF.
- **Processes:** Payment Request creation and Payment Approval. Invoice submission, posting,
  payment and reports are not affected.

---

## Emergency Change Assessment

### Business Continuity

Assessment: No

Justification: Payments can still be approved when the approver is available, or by releasing
the invoices and raising a new request. The current situation causes delays, not an outage.

### Workaround Availability

Assessment: Yes

Justification: Finance can release the invoices and raise a new request with a different
approver. This works, but it repeats work and restarts approvals already given.

### Operational Impact

Assessment: No

Justification: Delays are inconvenient and can be costly, but they are not severe enough to skip
the normal change process.

### Timeline Constraints

Assessment: No

Justification: The change can follow the normal assessment and testing cycle.

Emergency Change Classification: **No**

Reason: This is an operational improvement. A workaround exists and there is no immediate risk to
continuity, security or compliance.

---

## Risk Assessment

### Risk Level

**Medium**

The change is small in scope, but it touches the payment approval control: it lets a required
approval level go unsigned.

### Risks Identified

- **Weaker approval control.** Skipping a level means one fewer signature on a payment. Misused, it
  could bypass a senior approver on a large payment.
- **Self-serving skips.** The Finance user who raised a request could skip an approver who might
  query it.
- **Over-skipping.** If every approver were skipped, nobody would sign the payment.
- **Confusion on the PAF.** A skipped stage must never look like an approval.

### Risk Mitigation Plan

- **A reason is always required**, and it is recorded with who marked the skip and when.
- **Every skip is in the audit trail**, and it is printed on the PAF in place of a signature, as
  "On leave", so management and auditors can review it.
- **At least one approver must sign.** A chain where every approver is marked on leave is refused.
- **There must be a next approver.** On a waiting request, the last approver on the chain cannot be
  skipped. The request has to go to someone.
- **Only Finance and administrators** can skip an approver on a waiting request. Approvers cannot
  skip each other, and nobody can approve their own request (the existing rule stays).
- **Skips are only possible while the request is waiting for approval**, not while it is on hold
  for a query, and not after it is approved, paid or withdrawn.
- **No change to the approval thresholds** or to who may be chosen at each level.

---

## Expected Business Impact

### Positive Impact

- Payments keep moving when an approver is on leave.
- No need to release invoices and raise a new request, and approvals already given are kept.
- Every exception has a written reason and a named person accountable for it.

### Potential Negative Impact

- Some payments will carry fewer signatures than their amount would normally need. Management
  should decide whether this is acceptable for every level (see Decision for Approvers below).

### User Impact

- Finance sees one extra option per level in the chain builder, and a new **Mark on leave** action
  on waiting requests. Both take a reason.
- The next approver is emailed as soon as a stage is skipped. Approvers whose stage was skipped can
  still open their link, but only to view the request.

### Reporting Impact

- No report columns change. The skip shows on the request page, the PAF and the audit trail.

### Compliance Impact

- The approval trail stays complete: skipped stages are recorded, not deleted.
- Internal audit can find every skip through the audit trail ("approver on leave").

---

## Decision for Approvers

- **Can every level be skipped, including the highest level a payment reaches?** **Decided
  2026-11-26: yes, any level**, provided at least one approver still signs. The stricter option
  (never skipping the highest required level) was considered and not chosen.

## Implementation Status

Implemented 2026-11-26 as described above, with these points settled during development:

- **The approver stays named.** When an approver is marked on leave while building the chain, they
  remain selected, so the record shows whose absence it was.
- **Additional approvers** (L2-A and ad-hoc stages) have no "On leave" option when building the
  chain, because Finance can simply leave them off. On a waiting request, any stage can be skipped
  except the last.
- **A skipped approver is not copied** on a later approval query, because they took no part.
- **Database link to the person who marked the skip.** The working database holds 34 leftover
  approval rows from deleted early requests. Because of them, the database refuses a new enforced
  link on that table. The person's id is therefore stored without an enforced link. No existing
  data was changed. The leftover rows are logged for a human to decide on (known issue #32).
- **Verification:** 13 new automated tests pass. Four existing tests fail with or without this
  change and are logged separately (known issue #33). The screens build cleanly. The in-browser
  check could not be completed, because the demo accounts on the working database no longer use the
  demo password. UAT (Rollout step 4) is still to be done.

---

## Implementation Overview

- Add an "On leave" option, with a required reason, to each required level when Finance builds an
  approval chain. The skipped level is saved as part of the chain, and the request starts with the
  first approver who is not on leave.
- Add a **Mark on leave** action, with a required reason, for Finance and administrators on a
  request that is waiting for approval. It skips the current approver and emails the next one.
- Show skipped stages as "Skipped — on leave", with the reason, on the request page, the emailed
  approval page and the PAF.
- Record each skip in the audit trail.
- Add automated tests for the new rules, and update the project documentation.

---

## Rollout Plan

1. **Development:** build the option in the chain builder and the action on waiting requests,
   with a small addition to the stored approval stages.
2. **Internal Validation:** automated tests cover: skipping a level when raising a request; the
   request starting with the next approver; skipping on a waiting request and the email to the next
   approver; the refusals (no reason, every approver skipped, last approver, request on hold or
   finished, wrong role); and the record on the PAF and audit trail.
3. **QA Verification:** raise test requests with one and two levels skipped; skip on a waiting
   request; check the request page, emailed approval page, PAF and audit trail.
4. **User Acceptance Testing:** Finance raises a request with an approver on leave, and marks an
   approver on leave on a waiting request. The next approver confirms they received the email.
5. **Production Deployment:** back up the database, apply the small addition to the stored
   approval stages, and deploy the application.
6. **Post Deployment Monitoring:** review the audit trail for "approver on leave" entries for the
   first two weeks, to confirm skips are used as intended.

---

## Backout Plan

1. **Suspend new functionality:** redeploy the previous version. The option and the action
   disappear.
2. **Restore previous application state:** stages already skipped stay recorded. The previous
   version shows them as not approved, and no request depends on them.
3. **Restore backups if required:** the database addition only adds new information, so a restore
   is not expected to be needed. The pre-deployment backup is kept.
4. **Validate business operations:** confirm requests can be raised and approved as before.
5. **Notify stakeholders:** tell Finance and approvers that skipping is unavailable, and that a
   request waiting on an absent approver must be released and raised again.

---

## Approval Requirements

### Requestor

Name / Signature / Date:

### Department Manager

Name / Signature / Date:

### IT Manager

Name / Signature / Date:

### Business Owner

Head of Finance — Name / Signature / Date:

### CAB Approval (if applicable)

Required: Yes, because the change affects a payment approval control.

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-11-26

Risk Rating: Medium

Emergency Change: No

Analysis Confidence: 85%

- Affected Features Confidence: 90%
- Business Impact Confidence: 85%
- Security Impact Confidence: 85%
- Risk Assessment Confidence: 80%

---

# Technical Analysis Appendix

- **Affected systems:** the payment request chain builder and request page (web app), the payment
  approval logic, the emailed approval page, the PAF document, and the approval notification email.
- **Data:** each approval stage gains a "skipped" status, the skip reason and who marked it. Only
  new columns are added. No existing data is changed, and a backup is taken before deployment.
- **Security controls:** skipping on a waiting request is limited to Finance and administrators,
  checked on the server and in the screen. A skipped approver's emailed link becomes view-only,
  as happens today once a stage is passed.
- **Integrations:** none. Email uses the existing notification path.
