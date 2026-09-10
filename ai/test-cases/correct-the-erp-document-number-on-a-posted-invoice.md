# Test Cases

## Related Change Request

| Field | Value |
|---|---|
| **Change Request Subject** | Allow the person who posted an invoice to correct a mistyped ERP document number, without re-posting the invoice |
| **Change Request Filename** | [ai/change-requests/correct-the-erp-document-number-on-a-posted-invoice.md](../change-requests/correct-the-erp-document-number-on-a-posted-invoice.md) |
| **Risk Rating** | Low to Medium |
| **Emergency Change** | No |
| **CR Approved** | 2026-09-10 |
| **Implementation Date** | 2026-09-10 |
| **Access Policy Confirmed** | Only the user recorded as having posted the invoice, or an administrator, may correct its posting details. Available while the invoice is **posted**, at any payment status including paid |
| **Scope Policy Confirmed** | The ERP document number and posting date may change. Who posted it, when, its posted status, all amounts, approvals and payment status may **not** |

---

## Objective

Confirm that a mistyped ERP document number on a posted invoice can be corrected by the person who posted it, that nobody else in Finance can, and that the correction changes **only** the document number and posting date — leaving the posting record, the amounts, the approvals and the payment untouched.

The number is what ties a payment made here to the accounting entry in the ERP, so the tests below give equal weight to two things: that it can be fixed, and that fixing it cannot be used to change anything else or to reassign who posted the invoice.

The secondary objective is regression: posting itself, and the existing refusal to re-post, must behave exactly as before.

---

## Scope

**In scope**

- The **Edit Posting** action: when it appears, who can use it, and what it changes
- Correction of the ERP document number and the posting date
- Refusal for a Finance user who did not post the invoice, and the guidance in that refusal
- The administrator override
- Refusal on an invoice that is not posted, including a queried invoice that still carries a document number
- Availability at every payment status, paid included, and proof that nothing financial moves
- Validation of the document number and posting date
- The audit entry, its previous values, and the survival of the original posting entry
- Server-side enforcement independent of the screen

**Out of scope**

- Any change to the ERP itself (this records a number the ERP issued; it does not write to it)
- Bulk correction of historical document numbers
- Changing who posted an invoice, or when — explicitly excluded by design
- Correcting posting details from the invoice list (the action lives on the invoice view)

---

## Test Scenarios

### Happy Path Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| HP-01 | The gap this closes | Post an invoice as `5112345678`, then correct it to `5187654321` | Accepted. The invoice now records `5187654321` |
| HP-02 | The posting date can be corrected too | Correct the date to an earlier one | Accepted. The recorded posting date changes |
| HP-03 | Omitting the date leaves it alone | Correct only the document number | The posting date is unchanged — not silently reset to today |
| HP-04 | The action appears for the poster | Open the invoice view as the person who posted it | **Edit Posting** is offered; **Post to ERP** is not (it is already posted) |
| HP-05 | The dialog opens prefilled | Open **Edit Posting** | The recorded document number and posting date are already filled in |
| HP-06 | The dialog says what will not change | Same | It states the invoice stays posted and names the person who remains recorded as having posted it |
| HP-07 | Nothing-changed is refused | Confirm without editing anything | Refused with a clear message; the dialog stays open; no correction is recorded |
| HP-08 | An administrator can correct any posting | As an admin, correct an invoice posted by someone else | Accepted — the route through when the original poster has left |
| HP-09 | Correcting is not re-posting | Have an admin correct an invoice posted by a Finance user | `posted_by` still names the Finance user, `posted_at` is unchanged, status is still posted. The admin does not become the poster |
| HP-10 | Correctable while in approval | Correct an invoice held by an in-approval payment request | Accepted. The request total, its approvals and its stage are unchanged |
| HP-11 | Correctable once approved | Same on a fully approved, unpaid request | Accepted. Approvals unchanged |
| HP-12 | **Correctable after payment** | Same on a paid request | Accepted — this is when reconciliation surfaces a wrong number. Payment status, payment reference and totals unchanged |
| HP-13 | The corrected number reaches the printed request | Download the payment request PDF after a correction | The PDF shows the corrected number |
| HP-14 | The invoice history shows the correction | Open the invoice history | A clearly labelled correction entry appears, showing the old and new values, alongside the original posting entry |

### Negative Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| NEG-01 | Another Finance user cannot correct it | As a different Finance user, attempt the correction | Refused. The document number is unchanged |
| NEG-02 | The refusal says who to ask | Same | The message names the person who posted the invoice and suggests them or an administrator |
| NEG-03 | The action is not offered to a non-poster | Open the invoice view as a different Finance user | **Edit Posting** is not shown. Screen and server agree |
| NEG-04 | A requester cannot reach the endpoint | Attempt the correction as a requester | Refused — the route carries the same restriction as posting |
| NEG-05 | An approver cannot reach the endpoint | Attempt as an approver | Refused |
| NEG-06 | Even the invoice's own submitter cannot | Attempt as the user who submitted the invoice | Refused. Submitting is not posting |
| NEG-07 | Nothing to correct before posting | Attempt on a submitted, queried, or cancelled invoice | Refused with a clear message. No document number is written |
| NEG-08 | A queried invoice carrying a document number | Attempt on an invoice returned to query after posting | Refused. Resolving the query is the route out of that state, not a posting correction |
| NEG-09 | The document number is required | Send an empty or missing number | Refused by validation. The recorded number is unchanged |
| NEG-10 | The document number is bounded | Send a number longer than the field allows | Refused, exactly as on posting |
| NEG-11 | The posting date must be a date | Send an invalid date | Refused by validation. Nothing changes |
| NEG-12 | Bypassing the screen changes nothing | Send the request directly as a non-poster, and on an unposted invoice | Every rule enforced server-side with the same outcome and a clear message |

### Security Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| SEC-01 | No amount can be changed | Correct a posting and inspect the invoice | Amount, tax and total are identical. The action reaches no financial field |
| SEC-02 | No approval is affected | Correct an invoice in approval and after approval | The approval chain, its stages and their recorded decisions are unchanged |
| SEC-03 | No payment is affected | Correct a paid invoice | Payment status, payment reference, paid-by and paid-at are unchanged; the request total is unchanged |
| SEC-04 | Responsibility for the posting cannot be reassigned | Attempt, by every available route, to change who posted the invoice or when | Impossible. Neither field is writable through this action |
| SEC-05 | The restriction cannot be bypassed | Attempt as every role, and as Finance users other than the poster, directly against the endpoint | Refused in every case. Only the poster and administrators succeed |
| SEC-06 | The correction is attributable | Correct a posting | The audit entry records who made the correction, when, and from which address, like every other state change |
| SEC-07 | The previous value is retained | Same | The audit entry holds the previous document number and posting date, so the original is recoverable |
| SEC-08 | The original posting entry survives | Same | The original posting entry still shows the number first recorded. History is added to, never rewritten |
| SEC-09 | An unchanged date is not reported as changed | Correct only the number, submitting the same date | The audit message mentions the document number only — it does not imply the date moved |
| SEC-10 | No change to permissions or exposed data | Compare roles, permissions and returned fields before and after | Identical. No new field exposed, no new access granted |
| SEC-11 | Visibility scoping unchanged | Check which invoices each role can see | Identical |

### Regression Tests

| ID | Scenario | Expected Result |
|---|---|---|
| REG-01 | Existing automated test suites | Invoice submission, in-place correction, credit-note, credit-only, credit-mark, dynamic approval, withdrawal, release, PDF, report export and endpoint smoke suites all pass |
| REG-02 | Posting an invoice | Works exactly as before: status becomes posted, the number and date are recorded, the poster and time are recorded, the posting date defaults to today |
| REG-03 | Re-posting a posted invoice | Still refused. Correction is the route, not a second post |
| REG-04 | Posting a queried invoice that already carries a document number | Still refused, with the existing message |
| REG-05 | The ordinary invoice edit | Still does not touch the posting fields |
| REG-06 | Raising a query on a posted invoice | Unaffected |
| REG-07 | Invoice reporting and exports | The ERP document number column behaves as before, showing the corrected value where one exists |
| REG-08 | Payment request PDF for an uncorrected invoice | Renders exactly as before |
| REG-09 | Invoices posted before this change | Correctable by their original poster; unaffected otherwise |
| REG-10 | Invoice history for an invoice never corrected | Renders exactly as before, with no extra entry |

### User Acceptance Tests

| ID | Scenario | Performed By | Expected Result |
|---|---|---|---|
| UAT-01 | Fix a real mistyped document number | Finance / Accounts Payable | The correction takes seconds, in the screen they were already in, and the invoice then reconciles to the right ERP document |
| UAT-02 | Confirm a colleague's posting cannot be corrected | Finance / Accounts Payable | The action is absent, and attempting it explains who to ask |
| UAT-03 | The administrator route works when someone has left | Administrator | An invoice posted by a former colleague can be corrected |
| UAT-04 | Correct a number discovered wrong during reconciliation | Finance / Accounts Payable | Works on a paid invoice, and nothing about the payment changes |
| UAT-05 | The history answers "who changed this and from what?" | Internal Audit | The entry names the person, the time, and the previous number, and the original posting entry is still there |
| UAT-06 | Ordinary posting is unchanged | Finance | Posting a new invoice feels exactly as before |

---

## Expected Results

1. A posted invoice's ERP document number and posting date can be corrected by the user recorded as having posted it, or by an administrator.
2. No other user — of any role, including other Finance users and the invoice's own submitter — can correct them.
3. The correction is available only while the invoice is posted, and at any payment status including paid.
4. Who posted the invoice, when, and its posted status are never changed by a correction.
5. No amount, approval, payment status or payment reference is changed.
6. Every rule is enforced server-side as well as on screen, with the same outcome.
7. Each correction is audited with the values it replaced and the person who made it, and the original posting entry survives unchanged.
8. Posting itself, and the existing refusal to re-post, behave exactly as before.

---

## Pass/Fail Criteria

**Pass requires all of the following:**

- Every Happy Path scenario produces its expected result.
- Every Negative scenario is refused with an actionable message and leaves the recorded values unchanged.
- Every Security scenario passes. **SEC-01 to SEC-04 and SEC-07 are mandatory and non-waivable** — they cover the boundary of what this action may touch and the recoverability of the previous value.
- Every Regression scenario matches the pre-change release.
- The full automated suite passes.
- UAT is signed off by Finance / Accounts Payable, an administrator, and Internal Audit.

**Fail on any of the following:**

- Any user other than the original poster or an administrator can correct posting details.
- A correction changes who posted the invoice, when, or its posted status.
- A correction changes any amount, approval, payment status or payment reference.
- A correction is not audited, or is audited without the previous values.
- The original posting entry is altered or lost.
- Any rule is enforceable only through the screen.
- Posting, or the refusal to re-post, behaves differently from before.

---

## Test Execution Checklist

| Step | Item | Owner | Status |
|---|---|---|---|
| 1 | Automated suite executed and passing | Developer | ☐ |
| 2 | Happy Path HP-01 to HP-14 executed | QA | ☐ |
| 3 | Negative NEG-01 to NEG-12 executed | QA | ☐ |
| 4 | Security SEC-01 to SEC-11 executed | QA | ☐ |
| 5 | SEC-01 to SEC-04 and SEC-07 reviewed and signed off explicitly | QA Lead | ☐ |
| 6 | Regression REG-01 to REG-10 executed | QA | ☐ |
| 7 | Server-side enforcement verified independently of the screen (NEG-12, SEC-05) | QA | ☐ |
| 8 | Correction after payment verified as changing nothing financial (HP-12, SEC-03) | Finance Manager | ☐ |
| 9 | Refusal wording reviewed for clarity | Business Owner | ☐ |
| 10 | UAT-01 to UAT-06 executed | Finance / Admin / Audit | ☐ |
| 11 | Audit trail reviewed over several corrections | Internal Audit | ☐ |
| 12 | `/ai` documentation confirmed updated | Developer | ☐ |
| 13 | Backout position confirmed, including reversing a correction | IT Manager | ☐ |
| 14 | Post-release review routine for corrections agreed | Finance Manager | ☐ |

---

## Sign-Off

### QA Lead

Confirms every scenario above was executed, that the mandatory security scenarios passed, and that no defect allowing this action to reach a financial field or the posting record remains open.

**Name:** ____________________  **Date:** ____________  **Signature:** ____________________

### Business Owner

Confirms that the person who posted an invoice is the right person to correct its ERP document number, that the administrator override is appropriate, and that correcting after payment is acceptable.

**Name:** ____________________  **Date:** ____________  **Signature:** ____________________

### UAT Sign-Off

Finance / Accounts Payable, an administrator, and Internal Audit confirm the change works as intended in day-to-day use, and that ordinary posting is unaffected.

**Finance:** ____________________  **Date:** ____________  **Signature:** ____________________

**Administrator:** ____________________  **Date:** ____________  **Signature:** ____________________

**Internal Audit:** ____________________  **Date:** ____________  **Signature:** ____________________
