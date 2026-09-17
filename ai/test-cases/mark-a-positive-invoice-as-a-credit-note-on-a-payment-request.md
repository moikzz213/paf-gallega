# Test Cases

## Related Change Request

| Field | Value |
|---|---|
| **Change Request Subject** | Let Finance mark an invoice as a credit note when raising a payment request, so a credit already recorded as a positive amount is deducted instead of added |
| **Change Request Filename** | [ai/change-requests/mark-a-positive-invoice-as-a-credit-note-on-a-payment-request.md](../change-requests/mark-a-positive-invoice-as-a-credit-note-on-a-payment-request.md) |
| **Risk Rating** | Medium |
| **Emergency Change** | No (priority change) |
| **CR Approved** | 2026-09-10 |
| **Implementation Date** | 2026-09-10 |
| **Amount Policy Confirmed** | Marking corrects the **invoice's own** recorded amount to a deduction; offered only on a positive invoice; an invoice already recorded as a credit note cannot be marked; a payment request must still come to more than zero |
| **Builds on** | [allow-negative-line-amounts-for-credit-notes.md](allow-negative-line-amounts-for-credit-notes.md) and [allow-credit-only-invoices-netted-in-payment-requests.md](allow-credit-only-invoices-netted-in-payment-requests.md) |

---

## Objective

Confirm that a credit note recorded as a positive amount is **deducted** from a payment request once Finance marks it, that marking corrects the invoice itself so every other figure in the system agrees, and that a payment request refused for any reason leaves every invoice exactly as it was.

The last point is the one that most needs proving. The correction has to be evaluated *before* validation — the amount, the currency and the approval chain must all be measured on the corrected figure — but written only *with* the payment request. A refused request that still rewrote an invoice would leave the record wrong and nothing to show why.

The secondary objective is regression: a payment request with nothing marked must behave exactly as it does today.

---

## Scope

**In scope**

- The **Credit note** tick-box on the payment request creation screen: when it appears, when it is enabled, and what it does
- Correction of the marked invoice's header amounts **and its line items**
- The running request total, the marked-count indicator, and the confirmation before sending
- Approval routing measured on the corrected total
- Refusal of a mark on an invoice already recorded as a credit note, and on one outside the selection
- Interaction with the existing rules: one currency per request, and a request must come to more than zero
- That a refused request leaves no invoice rewritten
- The audit trail over each correction
- Server-side enforcement independent of the screen

**Out of scope**

- Bulk correction of historical mis-signed credit notes (each is corrected as Finance handles it)
- Any change to approval thresholds, roles or permissions
- Marking an invoice already held by a payment request (the action exists only at creation)
- Identifying which invoices on file are mis-signed credit notes — a business judgement, not a system function

---

## Test Scenarios

### Happy Path Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| HP-01 | The defect is fixed | Select a 20,000 charge and a 5,000 credit note recorded as positive; mark the credit; send | Request total is **15,000**, not 25,000. The credit was deducted |
| HP-02 | The invoice itself is corrected | Take the HP-01 credit (5,000 + 250 tax) | Invoice amount −5,000, tax −250, total −5,250. Signs are consistent |
| HP-03 | The line items are corrected too | Same invoice | Every line's amount, tax and total change sign; the header remains the sum of its lines |
| HP-04 | The tax rate is untouched | Same invoice, entered at 5% | Tax rate stays 5%. The rate describes the line, not its direction |
| HP-05 | The request total equals the sum of its invoices | After HP-01, recompute the request total independently | 15,000 both ways. No later re-sync can move the figure |
| HP-06 | Several invoices marked at once | 30,000 charge plus credits of 5,000 and 4,000, both marked | Request total 21,000 |
| HP-07 | A mark alongside an existing credit note | 30,000 charge, a 5,000 positive credit marked, and a −4,000 invoice already recorded as a credit | Request total 21,000. Both deductions apply, by different routes |
| HP-08 | The tick-box appears only on a positive invoice | Open the selection list with both positive and negative invoices present | Positive rows show a tick-box; a negative row shows a **Credit note** label and no tick-box |
| HP-09 | The tick-box is enabled only on a selected invoice | Before selecting a row, then after | Disabled before selection, enabled after. A tick cannot be applied to an invoice that is not in the request |
| HP-10 | The total updates as it is ticked | Watch the running total while ticking | Total moves from added to deducted immediately (e.g. 25,250 → 14,750), and the row shows the amount as a deduction |
| HP-11 | A marked count is shown | Mark one or more | An indicator states how many invoices are marked as credit notes |
| HP-12 | The confirmation lists what will change | Send a request containing a mark | Confirmation shows each invoice, the amount recorded, the amount it will be corrected to, and the resulting request total |
| HP-13 | Going back from the confirmation changes nothing | Open the confirmation, choose to go back | No request is created and no invoice is changed. The selection and marks are still there |
| HP-14 | Deselecting an invoice drops its mark | Mark an invoice, then deselect the row | Its deduction no longer affects the total. A stray tick cannot influence a request the invoice is not in |
| HP-15 | Routing follows the corrected total | 500,000 charge with a 450,000 credit marked | Routes on 50,000, reaching the levels 50,000 requires and no further |
| HP-16 | Full cycle | Marked request → approvals → mark paid | Cycle completes on the net figure. Both invoices end marked paid |
| HP-17 | The printed request agrees | Download the PDF for a marked request | The corrected invoice appears as a deduction and the total is the net figure |

### Negative Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| NEG-01 | An invoice already a credit note cannot be marked | Send a mark for a −5,000 invoice | Refused. The message says it is already recorded as a credit note and deducted as it stands. The invoice is unchanged, and no request is created |
| NEG-02 | A mark outside the selection | Send a mark for an invoice that is not in `invoice_ids` | Refused. That invoice is unchanged |
| NEG-03 | Marking the whole selection | Mark every invoice in the request | Refused — nothing would be paid. The existing payable-total rule catches it |
| NEG-04 | Marks that net the request to exactly zero | 5,000 charge and a 5,000 credit, marked | Refused with the same rule |
| NEG-05 | **A refused request leaves the invoice untouched** | Send a request with a mark but **no approvers**, so creation fails after the mark is evaluated | 422. The marked invoice's amount, tax, total **and lines** are exactly as before; it is linked to no request; no request exists |
| NEG-06 | Refused for its approval chain | Same, but the creator assigns themselves as approver | 422 on the chain. The marked invoice is unchanged |
| NEG-07 | Refused for mixed currencies | Mark an invoice in a selection spanning two currencies | Refused as a currency problem. No invoice is changed |
| NEG-08 | An unknown invoice id in the marks | Send a mark for an id that does not exist | Refused by validation. Nothing is changed |
| NEG-09 | Marking without selecting anything | Send marks with an empty selection | Refused with the existing "select at least one invoice" message |
| NEG-10 | A mark on an invoice not eligible for payment | Mark an invoice already held by another request | Refused by the existing eligibility rule. The invoice is unchanged |

### Security Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| SEC-01 | Routing follows the corrected total | 500,000 charge, 450,000 credit marked | Chain matches the levels required for 50,000. Correct and intended, and asserted explicitly rather than assumed |
| SEC-02 | Routing never omits a required level | 500,000 charge, 300,000 credit marked, where 200,000 still requires a senior level | Every level 200,000 requires is present. No level is skipped |
| SEC-03 | Marking grants no new access | A requester, and any non-Finance role, attempts to create a request with marks | Refused with the existing authorisation error. The marked invoice is unchanged |
| SEC-04 | The screen is not the only defence | Send marks directly, bypassing the screen — including a mark on a negative invoice and one outside the selection | Every rule is enforced server-side with the same outcome and a clear message |
| SEC-05 | No partial state on any refusal | Across NEG-01 to NEG-10, inspect the invoices and the request afterwards | Every refusal leaves invoices, their lines, and the request table exactly as before. Nothing is half-applied |
| SEC-06 | Approvals always cover what is asked | After a marked request is created, attempt the existing correction and release paths | The recorded approvals never cover less than the request total; the existing guards behave identically, because they read the corrected amount |
| SEC-07 | The correction is audited with its original figures | Mark an invoice and create the request | An audit entry records the reclassification against both the invoice and the request, with the replaced amount, tax and total retained, so the original is recoverable |
| SEC-08 | The audit entry is attributable | Same | It records who performed it and when, like every other state change |
| SEC-09 | Segregation of duties intact | The creator of a marked request attempts to approve it | Refused, as before |
| SEC-10 | No change to permissions or exposed data | Compare roles, permissions and returned fields before and after | Identical. No new field exposed, no new access granted |

### Regression Tests

| ID | Scenario | Expected Result |
|---|---|---|
| REG-01 | Existing automated test suites | Credit-note, credit-only invoice, in-place correction, dynamic approval, currency-converted routing, withdrawal, release, PDF, report export and endpoint smoke suites all pass |
| REG-02 | A request with nothing marked | Behaves exactly as before. Same total, same routing, no new refusal and no confirmation |
| REG-03 | Invoices in an unmarked request | Amounts unchanged after creation. Nothing is rewritten unless it was ticked |
| REG-04 | An invoice recorded as a credit note, unmarked | Deducted as it stands, exactly as it was before this change |
| REG-05 | Requests already in flight | Totals, approvals and status unaffected by the deployment |
| REG-06 | Withdrawal and rejection of a marked request | Invoices return to the Invoice Log with payment status reset; the corrected invoice returns as the credit note it now is |
| REG-07 | Invoice edit after a correction | The corrected invoice opens, edits and re-saves like any credit-only invoice, and can be changed back to a charge if the mark was wrong |
| REG-08 | Approval and reminder emails | Show the net figure and the deducted invoice correctly |
| REG-09 | Dashboard and report figures | Correct with corrected invoices present; figures reflect deductions |
| REG-10 | PDF for an unmarked request | Renders exactly as before |

### User Acceptance Tests

| ID | Scenario | Performed By | Expected Result |
|---|---|---|---|
| UAT-01 | Mark a real mis-signed credit note | Finance / Accounts Payable | The credit is deducted, the request asks for the amount genuinely owed, and the invoice's own figures are put right |
| UAT-02 | The confirmation is clear enough to catch a mistake | Finance / Accounts Payable | The listed "recorded → corrected to" figures make an accidental tick on a genuine charge obvious before sending |
| UAT-03 | The tick-box appears only where it makes sense | Finance / Accounts Payable | Nothing to tick on an invoice already recorded as a credit note; no confusion about which rows can be marked |
| UAT-04 | The deduction is visible to an approver | Approver representative | The approver can see the deduction and that the amount presented is the net payable — on screen and on the printed request |
| UAT-05 | The audit trail answers the question "why did this invoice change?" | Internal Audit | The entry names the request, the original figures and the person who marked it |
| UAT-06 | Ordinary work is unchanged | Finance | Requests with no credit notes feel exactly as before |

---

## Expected Results

1. A marked invoice is **deducted** from the payment request instead of added.
2. Marking corrects that invoice's header amounts and its line items, so the header stays the sum of its lines and the request total stays the sum of its invoices.
3. The tick-box is offered only on an invoice recorded as a positive amount, and only on one actually selected. An invoice already recorded as a credit note cannot be marked.
4. Approval routing is measured on the corrected total and always resolves to every level that total requires.
5. Sending a request with any mark requires a confirmation that shows each invoice's recorded and corrected amounts and the resulting total.
6. A payment request refused for **any** reason leaves every invoice, and every invoice line, exactly as it was.
7. Every rule is enforced server-side as well as on screen, with the same outcome.
8. Every correction is audited against both the invoice and the request, with the replaced figures retained.
9. A request with nothing marked behaves exactly as before, and no role, permission or threshold changes.

---

## Pass/Fail Criteria

**Pass requires all of the following:**

- Every Happy Path scenario produces its expected result.
- Every Negative scenario is refused with an actionable message and leaves no partial state.
- Every Security scenario passes. **SEC-01, SEC-02, SEC-05 and SEC-07 are mandatory and non-waivable** — they cover the amount, the approvers who must sign for it, and the recoverability of the correction.
- **NEG-05 is mandatory and non-waivable**: a refused request must never leave a rewritten invoice.
- Every Regression scenario matches the pre-change release for requests with nothing marked.
- The full automated suite passes.
- UAT is signed off by Finance / Accounts Payable and by an approver representative.

**Fail on any of the following:**

- A marked invoice is added to a request rather than deducted, in any combination.
- A request total does not equal the sum of its invoices.
- Any refusal leaves an invoice, or one of its lines, rewritten.
- An invoice already recorded as a credit note can be marked.
- Any request reaches approval with fewer approvers than its corrected amount requires.
- A correction is not audited, or is audited without the figures it replaced.
- Any rule is enforceable only through the screen.
- A request with nothing marked behaves differently from before.

---

## Test Execution Checklist

| Step | Item | Owner | Status |
|---|---|---|---|
| 1 | Automated suite executed and passing | Developer | ☐ |
| 2 | Happy Path HP-01 to HP-17 executed | QA | ☐ |
| 3 | Negative NEG-01 to NEG-10 executed | QA | ☐ |
| 4 | **NEG-05 verified explicitly**, including invoice line items | QA Lead | ☐ |
| 5 | Security SEC-01 to SEC-10 executed | QA | ☐ |
| 6 | SEC-01, SEC-02, SEC-05, SEC-07 reviewed and signed off explicitly | QA Lead | ☐ |
| 7 | Regression REG-01 to REG-10 executed | QA | ☐ |
| 8 | Server-side enforcement verified independently of the screen (SEC-04) | QA | ☐ |
| 9 | Confirmation wording reviewed for clarity | Business Owner | ☐ |
| 10 | Routing on corrected totals reviewed against the threshold configuration | Finance Manager | ☐ |
| 11 | UAT-01 to UAT-06 executed | Finance / Approver / Audit | ☐ |
| 12 | Audit trail reviewed over several corrections | Internal Audit | ☐ |
| 13 | `/ai` documentation confirmed updated | Developer | ☐ |
| 14 | Backout position confirmed, including how to reverse a correction | IT Manager | ☐ |
| 15 | Interim guidance issued to Finance for the period before release | Finance Manager | ☐ |

---

## Sign-Off

### QA Lead

Confirms every scenario above was executed, that the mandatory security scenarios and NEG-05 passed, and that no defect affecting payment amounts or approval routing remains open.

**Name:** ____________________  **Date:** ____________  **Signature:** ____________________

### Business Owner

Confirms that Finance may correct an invoice's recorded amount from a charge to a deduction as part of raising a payment, that approving on the corrected amount is right, and that the audit trail is sufficient.

**Name:** ____________________  **Date:** ____________  **Signature:** ____________________

### UAT Sign-Off

Finance / Accounts Payable and an approver representative confirm the change works as intended in day-to-day use, and that ordinary work is unaffected.

**Finance:** ____________________  **Date:** ____________  **Signature:** ____________________

**Approver:** ____________________  **Date:** ____________  **Signature:** ____________________
