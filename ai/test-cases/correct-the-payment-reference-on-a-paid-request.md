# Test Cases

## Related Change Request

| Field | Value |
|---|---|
| **Change Request Subject** | Allow the person who recorded a payment to correct a mistyped payment reference on a paid payment request |
| **Change Request Filename** | [ai/change-requests/correct-the-payment-reference-on-a-paid-request.md](../change-requests/correct-the-payment-reference-on-a-paid-request.md) |
| **Risk Rating** | Low to Medium |
| **Emergency Change** | No |
| **CR Approved** | 2026-09-10 |
| **Implementation Date** | 2026-09-10 |
| **Access Policy Confirmed** | Only the user recorded in `paid_by` — the person who recorded the payment — or an administrator. **Not** the user who raised the payment request, unless they also recorded the payment |
| **Scope Policy Confirmed** | The payment reference may change. Who recorded the payment, when, the paid status, the total, the invoices and all approvals may **not**. No uniqueness rule — one transfer may settle several requests |
| **Precondition Confirmed** | The action exists only where there is a reference to correct, i.e. the request is **paid** |

---

## Objective

Confirm that a mistyped payment reference on a paid payment request can be corrected by the person who recorded the payment, that nobody else can, and that the correction changes **only** that reference — leaving the payment record, the amount, the invoices and the approvals untouched.

The reference is what ties money that left the bank to a payment approved here, so the tests give equal weight to two things: that it can be fixed, and that fixing it cannot be used to change anything else, to reassign who took the payment, or to mark anything paid.

The secondary objective is regression: recording a payment, and the existing refusal to re-record one, must behave exactly as before.

---

## Scope

**In scope**

- The **Edit Payment Ref** action: when it appears, who can use it, and what it changes
- The precondition that a reference must already exist (the request is paid)
- Refusal for a Finance user who did not record the payment — including the request's own creator — and the guidance in that refusal
- The administrator override
- Refusal on every non-paid status
- Validation of the reference
- That two requests may share a reference
- The audit entry, its previous value, and the survival of the original payment entry
- Server-side enforcement independent of the screen

**Out of scope**

- Any interaction with a bank or payment system (the reference describes a payment already made)
- Bulk correction of historical references
- Changing who recorded a payment, or when — explicitly excluded by design
- Correcting a reference from the payment request list (the action lives on the request view)
- The `payment_reference` field on invoices, which is a separate legacy column

---

## Test Scenarios

### Happy Path Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| HP-01 | The gap this closes | Mark a request paid as `TT-9001`, then correct it to `TT-9002` | Accepted. The request now records `TT-9002` |
| HP-02 | The action appears for the payer | Open the request view as the person who recorded the payment | **Edit Payment Ref** is offered |
| HP-03 | The dialog opens prefilled | Open the action | The recorded reference is already filled in |
| HP-04 | The dialog says what will not change | Same | It states the request stays paid and names the person who remains recorded as having taken the payment, with the date |
| HP-05 | Nothing-changed is refused | Confirm without editing | Refused with a clear message; the dialog stays open; no correction is recorded |
| HP-06 | An administrator can correct any payment | As an admin, correct a payment recorded by someone else | Accepted — the route through when the original payer has left |
| HP-07 | Correcting is not re-recording | Have an admin correct a payment taken by a Finance user | `paid_by` still names the Finance user, `paid_at` is unchanged, status is still paid. The admin does not become the payer |
| HP-08 | Two requests may share a reference | Correct one request's reference to match another's | Accepted. One transfer settling several requests is normal, so no clash is reported |
| HP-09 | Correctable more than once | Correct the same request twice | Both accepted. Two separate correction entries are recorded |
| HP-10 | The corrected reference is shown | After correcting | The paid banner and the detail row both show the new reference |

### Negative Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| NEG-01 | Another Finance user cannot correct it | As a different Finance user, attempt the correction | Refused. The reference is unchanged |
| NEG-02 | **The request's creator cannot correct it** | As the Finance user who raised the request but did not record the payment, attempt the correction | Refused. Raising a request is not recording its payment |
| NEG-03 | The refusal says who to ask | Same as NEG-01 | The message names the person who recorded the payment and suggests them or an administrator |
| NEG-04 | The action is not offered to a non-payer | Open the request view as a different Finance user | **Edit Payment Ref** is not shown. Screen and server agree |
| NEG-05 | A requester cannot reach the endpoint | Attempt as a requester | Refused — the route carries the same restriction as recording a payment |
| NEG-06 | An approver cannot reach the endpoint | Attempt as an approver | Refused |
| NEG-07 | Nothing to correct before payment | Attempt on a request that is in approval, and on one approved but unpaid | Refused with a clear message. No reference is written |
| NEG-08 | Nothing to correct on a rejected request | Attempt on a rejected request | Refused. It was never paid |
| NEG-09 | Nothing to correct on a withdrawn request | Attempt on a request withdrawn after approval | Refused. It was never paid |
| NEG-10 | The action is not offered where there is no reference | Open an unpaid request's view | **Edit Payment Ref** is absent — the "when there is a value" condition |
| NEG-11 | The reference is required | Send an empty or missing reference | Refused by validation. The recorded reference is unchanged |
| NEG-12 | The reference is bounded | Send a reference longer than the field allows | Refused, exactly as when recording a payment |
| NEG-13 | Bypassing the screen changes nothing | Send the request directly as a non-payer, and on an unpaid request | Every rule enforced server-side with the same outcome and a clear message |

### Security Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| SEC-01 | No amount can be changed | Correct a reference and inspect the request | The request total is identical. The action reaches no financial field |
| SEC-02 | No invoice is affected | Same | Every invoice in the request keeps its paid status and its figures |
| SEC-03 | No approval is affected | Same | The approval chain, its stages, approvers, decisions and timestamps are byte-for-byte unchanged |
| SEC-04 | Responsibility for the payment cannot be reassigned | Attempt, by every available route, to change who recorded the payment or when | Impossible. Neither field is writable through this action |
| SEC-05 | It cannot be a back door to marking something paid | Attempt the correction on every non-paid status | Refused in every case. Nothing becomes paid, and no payment date or payer is written |
| SEC-06 | The restriction cannot be bypassed | Attempt as every role, and as Finance users other than the payer, directly against the endpoint | Refused in every case. Only the payer and administrators succeed |
| SEC-07 | The correction is attributable | Correct a reference | The audit entry records who made the correction, when, and from which address, like every other state change |
| SEC-08 | The previous value is retained | Same | The audit entry holds the previous reference, so the original is recoverable |
| SEC-09 | The original payment entry survives | Same | The original payment entry still shows the reference first recorded. History is added to, never rewritten |
| SEC-10 | The entry is tied to the request | Same | The audit entry is attached to the payment request, so it appears in that request's history |
| SEC-11 | No change to permissions or exposed data | Compare roles, permissions and returned fields before and after | Identical. No new field exposed, no new access granted |
| SEC-12 | Visibility scoping unchanged | Check which payment requests each role can see | Identical |

### Regression Tests

| ID | Scenario | Expected Result |
|---|---|---|
| REG-01 | Existing automated test suites | Payment request creation, credit-mark, credit-only, dynamic approval, withdrawal, release, in-place correction, posting correction, PDF, report export and endpoint smoke suites all pass |
| REG-02 | Marking a request paid | Works exactly as before: status becomes paid, the reference is recorded, the payer and time are recorded, and its invoices are marked paid |
| REG-03 | Re-marking a paid request as paid | Still refused. Correction is the route, not a second payment |
| REG-04 | Marking an unapproved request paid | Still refused |
| REG-05 | Withdrawing an approved request | Unaffected |
| REG-06 | Payment reporting and exports | The payment reference column behaves as before, showing the corrected value where one exists |
| REG-07 | Payment request PDF | Renders as before |
| REG-08 | Requests paid before this change | Correctable by their original payer; unaffected otherwise |
| REG-09 | A request never corrected | Its history renders exactly as before, with no extra entry |

### User Acceptance Tests

| ID | Scenario | Performed By | Expected Result |
|---|---|---|---|
| UAT-01 | Fix a real mistyped payment reference | Finance / Accounts Payable | The correction takes seconds, on the screen they were already on, and the payment then matches the bank statement |
| UAT-02 | Confirm a colleague's payment cannot be corrected | Finance / Accounts Payable | The action is absent, and attempting it explains who to ask |
| UAT-03 | The administrator route works when someone has left | Administrator | A payment recorded by a former colleague can be corrected |
| UAT-04 | Correct a reference found wrong at reconciliation | Finance / Accounts Payable | Works, and nothing about the payment amount, invoices or approvals changes |
| UAT-05 | The history answers "who changed this and from what?" | Internal Audit | The entry names the person, the time and the previous reference, and the original payment entry is still there |
| UAT-06 | Ordinary payment recording is unchanged | Finance | Marking a request paid feels exactly as before |

---

## Expected Results

1. A paid request's payment reference can be corrected by the user recorded in `paid_by`, or by an administrator.
2. No other user — of any role, including other Finance users and the request's own creator — can correct it.
3. The action exists only where a reference has been recorded, i.e. the request is paid; every other status is refused.
4. Who recorded the payment, when, and the paid status are never changed by a correction.
5. No amount, invoice, approval or approval decision is changed.
6. Two requests may share a payment reference.
7. Every rule is enforced server-side as well as on screen, with the same outcome.
8. Each correction is audited against the request with the value it replaced and the person who made it, and the original payment entry survives unchanged.
9. Recording a payment, and the existing refusal to re-record one, behave exactly as before.

---

## Pass/Fail Criteria

**Pass requires all of the following:**

- Every Happy Path scenario produces its expected result.
- Every Negative scenario is refused with an actionable message and leaves the recorded reference unchanged.
- Every Security scenario passes. **SEC-01 to SEC-05 and SEC-08 are mandatory and non-waivable** — they cover the boundary of what this action may touch, that it cannot mark anything paid, and the recoverability of the previous value.
- Every Regression scenario matches the pre-change release.
- The full automated suite passes.
- UAT is signed off by Finance / Accounts Payable, an administrator, and Internal Audit.

**Fail on any of the following:**

- Any user other than the original payer or an administrator can correct a payment reference.
- A correction changes who recorded the payment, when, or the paid status.
- A correction changes any amount, invoice, approval or approval decision.
- The action can be performed on a request that is not paid, or can cause one to become paid.
- A correction is not audited, or is audited without the previous value.
- The original payment entry is altered or lost.
- Any rule is enforceable only through the screen.
- Recording a payment, or the refusal to re-record one, behaves differently from before.

---

## Test Execution Checklist

| Step | Item | Owner | Status |
|---|---|---|---|
| 1 | Automated suite executed and passing | Developer | ☐ |
| 2 | Happy Path HP-01 to HP-10 executed | QA | ☐ |
| 3 | Negative NEG-01 to NEG-13 executed | QA | ☐ |
| 4 | **NEG-02 verified explicitly** (the request creator is refused) | QA Lead | ☐ |
| 5 | Security SEC-01 to SEC-12 executed | QA | ☐ |
| 6 | SEC-01 to SEC-05 and SEC-08 reviewed and signed off explicitly | QA Lead | ☐ |
| 7 | Regression REG-01 to REG-09 executed | QA | ☐ |
| 8 | Server-side enforcement verified independently of the screen (NEG-13, SEC-06) | QA | ☐ |
| 9 | Refusal wording reviewed for clarity | Business Owner | ☐ |
| 10 | Shared-reference behaviour confirmed as intended (HP-08) | Finance Manager | ☐ |
| 11 | UAT-01 to UAT-06 executed | Finance / Admin / Audit | ☐ |
| 12 | Audit trail reviewed over several corrections | Internal Audit | ☐ |
| 13 | `/ai` documentation confirmed updated | Developer | ☐ |
| 14 | Backout position confirmed, including reversing a correction | IT Manager | ☐ |
| 15 | Post-release review routine for corrections agreed | Finance Manager | ☐ |

---

## Sign-Off

### QA Lead

Confirms every scenario above was executed, that the mandatory security scenarios passed, and that no defect allowing this action to reach a financial field, the payment record, or an unpaid request remains open.

**Name:** ____________________  **Date:** ____________  **Signature:** ____________________

### Business Owner

Confirms that the person who recorded a payment is the right person to correct its reference, that the administrator override is appropriate, and that a paid record may be amended in this narrow way.

**Name:** ____________________  **Date:** ____________  **Signature:** ____________________

### UAT Sign-Off

Finance / Accounts Payable, an administrator, and Internal Audit confirm the change works as intended in day-to-day use, and that ordinary payment recording is unaffected.

**Finance:** ____________________  **Date:** ____________  **Signature:** ____________________

**Administrator:** ____________________  **Date:** ____________  **Signature:** ____________________

**Internal Audit:** ____________________  **Date:** ____________  **Signature:** ____________________
