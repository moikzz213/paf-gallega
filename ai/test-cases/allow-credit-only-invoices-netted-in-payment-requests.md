# Test Cases

## Related Change Request

| Field | Value |
|---|---|
| **Change Request Subject** | Allow a credit-only invoice to be recorded, and require a payment request to net above zero — a credit must be grouped with the invoice it offsets |
| **Change Request Filename** | [ai/change-requests/allow-credit-only-invoices-netted-in-payment-requests.md](../change-requests/allow-credit-only-invoices-netted-in-payment-requests.md) |
| **Risk Rating** | Medium |
| **Emergency Change** | No |
| **CR Approved** | 2026-09-10 |
| **Implementation Date** | 2026-09-10 |
| **Amount Policy Confirmed** | An invoice may net below zero (a credit-only document); an invoice netting to exactly zero is rejected; a **payment request** must net to more than zero |
| **Supersedes** | Scenarios NEG-02, NEG-03, NEG-04, SEC-03, SEC-04 and SEC-05 of [allow-negative-line-amounts-for-credit-notes.md](allow-negative-line-amounts-for-credit-notes.md), where a net-negative invoice was out of scope and refused at entry |

---

## Objective

Confirm that a vendor credit note can be recorded as an invoice in its own right, that it can only be turned into money by being grouped with the charge it offsets, and that the "must come to more than zero" floor now enforced on the payment request is as effective as the invoice-level floor it replaces.

The floor exists for a structural reason, not a stylistic one: approval routing matches an amount against thresholds whose lowest value is zero, so an amount of zero or less matches no level and can be routed to nobody. Every test below that touches routing exists to prove that a request can never reach approval, or payment, without an amount that resolves a chain.

The secondary objective is regression: an ordinary all-positive invoice, and an invoice carrying a credit line that still leaves something owed, must behave exactly as they do today.

---

## Scope

**In scope**

- Invoice entry and editing where the lines net below zero (credit-only) and exactly zero
- Payment request creation from a selection that includes a credit-only invoice
- Refusal of a payment request selection that nets to zero or below, and the guidance given
- Correction of an invoice held by an in-approval or approved payment request, where the correction would take that request to zero or below
- Release of an invoice from a payment request where a credit is involved
- Approval routing measured on the netted payment request total, including currency conversion
- Both the on-screen enforcement and the server-side enforcement of each rule
- The audit trail over a credit-only invoice and a netted payment request

**Out of scope**

- Changing any approval threshold amount
- Any change to roles, permissions or visibility scoping
- Settling a credit note by any route other than grouping it into a payment request (for example, a refund received from the vendor in cash)
- Reporting redesign; existing reports are covered as regression only

---

## Test Scenarios

### Happy Path Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| HP-01 | Record a credit-only invoice | Create an invoice with a single line of −5,000 | Invoice saves. Net amount is −5,000. The screen states it must be settled against a charge in a payment request |
| HP-02 | Credit-only invoice with several lines | Lines of −3,000 and −2,000 | Invoice saves with a net of −5,000 |
| HP-03 | Invoice that nets below zero from mixed lines | Lines of 1,000 and −6,000 | Invoice saves with a net of −5,000 |
| HP-04 | Tax on a credit-only invoice | Single line of −1,000 at 5% tax | Tax is −50 and the net is −1,050. Signs are consistent throughout |
| HP-05 | Credit-only invoice appears as eligible for a payment request | Post the HP-01 invoice, then open the payment request creation screen | The credit-only invoice is listed and selectable alongside ordinary invoices |
| HP-06 | Group a credit with the charge it offsets | Select an invoice of 20,000 and the −5,000 credit-only invoice | Selection is accepted. The running total shows 15,000 |
| HP-07 | The netted request routes and is approved | Send the HP-06 selection for approval | Payment request total is 15,000. The chain matches the levels required for 15,000. Approvals complete normally |
| HP-08 | Full cycle with a credit-only invoice | Credit-only invoice → grouped with a charge → payment request → approvals → mark paid | Cycle completes. The amount approved and paid is the net figure throughout. Both invoices are marked paid |
| HP-09 | One credit offsetting several charges | Select invoices of 8,000 and 9,000 plus a credit of −5,000 | Total is 12,000. Request routes on 12,000 |
| HP-10 | Several credits in one request | Select a charge of 30,000 and credits of −5,000 and −4,000 | Total is 21,000. Request routes on 21,000 |
| HP-11 | Credit-only invoice edited before grouping | Save the HP-01 invoice, then edit the line to −6,000 | Update is accepted. Net becomes −6,000 |
| HP-12 | Credit-only invoice turned back into a charge | Edit the HP-01 invoice, changing the line to 5,000 | Update is accepted. Net becomes 5,000. Nothing blocks a return to an ordinary invoice |
| HP-13 | Credit-only invoice in a non-base currency | Credit-only invoice in a foreign currency with a rate on record, grouped with a larger charge in the same currency | Net is correct in the request currency, and the base-currency figure used for routing is correctly reduced |
| HP-14 | Correction that lowers a netted request but keeps it positive | Finance corrects a charge inside an in-approval request from 20,000 to 10,000, where a −5,000 credit is also held | Correction is accepted. Request total falls from 15,000 to 5,000 |
| HP-15 | Release the charge, leaving a still-positive request | Request holding charges of 20,000 and 9,000 and a credit of −5,000; release the 9,000 charge | Release is accepted. Remaining total is 15,000, still positive, and approvals still cover it |
| HP-16 | Printed payment request shows the credit | Download the PDF for the HP-08 request | The credit-only invoice appears as its own document line, and the net figure is the request total |

### Negative Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| NEG-01 | Invoice nets to exactly zero | Lines of 5,000 and −5,000 | Rejected with a clear message. A document that is neither a charge nor a credit carries no information |
| NEG-02 | Single zero line | Enter one line with an amount of 0 | Rejected, as before. The per-line zero rule is unchanged |
| NEG-03 | Payment request from a credit-only invoice alone | Select only the −5,000 credit-only invoice and attempt to send | Rejected. The message names the amount and tells Finance to add the invoice the credit offsets |
| NEG-04 | Payment request selection nets to exactly zero | Select a charge of 5,000 and a credit of −5,000 | Rejected with the same guidance. Nothing would be paid |
| NEG-05 | Payment request selection nets below zero | Select a charge of 5,000 and a credit of −6,000 | Rejected with the same guidance |
| NEG-06 | Several credits with no charge | Select two credit-only invoices and nothing else | Rejected with the same guidance |
| NEG-07 | Server-side enforcement of the request floor | Post a payment request creation request directly, bypassing the screen, with a selection netting to −5,000 | Rejected by the server with a validation error. The screen is not the only line of defence |
| NEG-08 | Correction takes a held request to zero | Finance corrects a charge inside an in-approval request so the request nets to exactly 0 | Rejected. The message explains the request must still ask for more than zero and offers withdrawal as the route |
| NEG-09 | Correction takes a held request below zero | Same as NEG-08 but the request would net to −2,000 | Rejected with the same message |
| NEG-10 | Correction takes an **approved** request to zero or below | Same as NEG-09 against a fully approved, unpaid request | Rejected. Approvals already granted are never left covering less than the request asks |
| NEG-11 | Release the only charge, leaving credits behind | Request holding a charge of 20,000 and a credit of −5,000; release the charge | Refused by the existing safeguard — releasing would raise what the request still asks for above the figure its approvals cover. Withdrawal is offered instead |
| NEG-12 | Raise a held invoice's total | Correct a held invoice upward, in a request containing a credit | Rejected — the existing "a correction cannot raise the total" safeguard still applies |
| NEG-13 | Currency change on a held invoice | Attempt to change currency while correcting a held credit-only invoice | Rejected — the existing currency lock still applies |
| NEG-14 | Mixed currencies including a credit | Select a charge in one currency and a credit in another | Rejected — the existing single-currency rule still applies, and is reported as a currency problem, not as a netting problem |
| NEG-15 | Credit exceeds the size limit | Credit-only invoice with a line of −9,999,999,999,999 | Rejected by the amount size limit, exactly as an oversized positive amount is |
| NEG-16 | Non-numeric amount | Enter text in the amount field | Rejected as not a number, as before |
| NEG-17 | Negative tax rate on a credit line | Line of −1,000 with a tax rate of −5 | Rejected. Tax rate remains 0–100 |
| NEG-18 | Empty selection | Attempt to create a payment request with nothing selected | Rejected with the existing "select at least one invoice" message, not the netting message |

### Security Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| SEC-01 | Approval routing follows the netted request total | Charge of 500,000 grouped with a credit of −450,000 | The chain generated matches the levels required for 50,000. This is correct and intended behaviour and must be explicitly asserted, not assumed |
| SEC-02 | Routing never omits a required approver | Charge of 500,000 netted to 200,000, where 200,000 still requires a senior level | Every level the net amount requires is present in the chain. No level is skipped |
| SEC-03 | No payment request can exist without approvers | Attempt, by every route available, to create a request whose total matches no approval level | Cannot occur. The positive-total rule blocks it at creation, and the existing "add at least one approver" refusal remains as a second line of defence |
| SEC-04 | No payment can be created out of a credit alone | Attempt to route, approve, or mark paid a request built from credits only | Impossible at creation, so no such request exists to approve or pay. Confirm no route reaches an approved request with a non-positive total |
| SEC-05 | Approvals always cover what the request asks | Across NEG-08 to NEG-11, inspect the request total and its recorded approvals after each refusal | The recorded approvals never cover less than the request total. No refusal leaves partial state behind |
| SEC-06 | Bypassing the screen grants nothing | Submit invoice and payment request payloads directly, with values the screens refuse | Every rule is enforced server-side with the same outcome and a clear validation message |
| SEC-07 | Ownership and role checks unchanged | A submitter attempts to record a credit against another user's invoice; a non-Finance user attempts to create a payment request | Refused with the existing authorisation errors. The widened amount range grants no new access |
| SEC-08 | Visibility scoping unchanged | Review which invoices and payment requests each role can see, before and after | Identical. A credit-only invoice is scoped exactly as any other invoice |
| SEC-09 | Amount size limit still bounds credits | Very large credit values on an invoice and across a selection | Bounded by the same limit as positive amounts. No overflow or precision problem |
| SEC-10 | Audit trail records the credit and the netting | Submit a credit-only invoice, then group it into a payment request | The invoice audit entry records the negative net and identifies it as a credit; the payment request entry records the netted total and the documents included |
| SEC-11 | No change to permissions or exposed data | Review roles, permissions and fields returned, before and after | Identical. No new field exposed, no new access granted |
| SEC-12 | Segregation of duties intact | The creator of a netted payment request attempts to approve it | Refused, as before |

### Regression Tests

| ID | Scenario | Expected Result |
|---|---|---|
| REG-01 | Existing automated test suites | Invoice submission, tax percentage, in-place correction, dynamic approval, currency-converted routing, payment request withdrawal, invoice release, payment document, report export and endpoint smoke suites all pass |
| REG-02 | Ordinary all-positive invoice, single line | Behaves exactly as before. No change in totals, routing or presentation |
| REG-03 | Ordinary all-positive invoice, many lines | Behaves exactly as before |
| REG-04 | Invoice with a credit line that still leaves something owed | Behaves exactly as it did after the previous change. Net figure carried through unchanged |
| REG-05 | Existing invoices created before the change | Open, view, edit and re-save unaffected |
| REG-06 | Payment requests already in flight | Totals, approvals and status unchanged by the deployment |
| REG-07 | Ordinary payment request creation, all-positive selection | Unchanged. No new refusal, no new message |
| REG-08 | Withdrawal and rejection of a netted request | Invoices return to the Invoice Log with their payment status reset, including the credit-only invoice |
| REG-09 | Dashboard spend indicators | Correct with credit-only invoices present. Figures reflect net amounts |
| REG-10 | Status breakdown and six-month trend | Render correctly with credit documents included |
| REG-11 | Top-vendor and business-unit rankings | Render correctly. Ranking order may shift once credits are recorded — verify it is sensible, not broken |
| REG-12 | Charts including a negative value | Axis and any percentage-of-total calculation remain correct. No rendering failure |
| REG-13 | Report screen totals and exports | Reflect net amounts. Excel/CSV export completes |
| REG-14 | Payment request PDF for an all-positive request | Renders exactly as before |

### User Acceptance Tests

| ID | Scenario | Performed By | Expected Result |
|---|---|---|---|
| UAT-01 | Record a real credit note received from a vendor | Finance / Accounts Payable | The credit note can be entered on the day it arrives, with its own reference and supporting document attached |
| UAT-02 | Settle that credit against a real charge | Finance / Accounts Payable | The credit is selected alongside the charge, the net is the figure sent for approval, and the process feels natural rather than a workaround |
| UAT-03 | The refusal is understood | Finance / Accounts Payable | Selecting the credit on its own produces a message the user can act on without asking for help |
| UAT-04 | The netting is visible to an approver | Approver representative | The approver can see that a credit is included, what it reduced, and that the amount presented is the net payable — on screen and on the printed request |
| UAT-05 | Month-end view of unsettled credits | Finance Manager | Credits recorded but not yet grouped into a payment request can be identified, so they are claimed rather than accumulated |
| UAT-06 | Ordinary work is unchanged | Finance and invoice submitters | Day-to-day entry and payment request creation feel exactly as before for documents with no credit involved |

---

## Expected Results

1. An invoice may be recorded with a net below zero. An invoice netting to exactly zero is refused.
2. A payment request must come to more than zero. Any selection that nets to zero or below is refused, with a message naming the amount and telling Finance to add the invoice the credit offsets.
3. A credit-only invoice is visible, selectable and groupable exactly as any other invoice, and is scoped by the same visibility rules.
4. Approval routing on a netted request is measured on the net figure, in the base currency, and always resolves to at least one approval level.
5. A correction to an invoice held by a payment request may not take that request to zero or below, and may not raise its total or change its currency.
6. Releasing an invoice never leaves a payment request asking for more than its recorded approvals cover.
7. Every rule is enforced on the server as well as on screen, with the same outcome.
8. The audit trail records the credit, the net amount, and the documents behind each netted request.
9. No role, permission, visible field or approval threshold changes.

---

## Pass/Fail Criteria

**Pass requires all of the following:**

- Every Happy Path scenario produces its expected result.
- Every Negative scenario is refused, with a message a Finance user can act on, and leaves no partial state behind.
- Every Security scenario passes. **SEC-01 through SEC-05 are mandatory and non-waivable** — they cover the relationship between the amount and the approvers who must sign for it.
- Every Regression scenario shows behaviour identical to the pre-change release for documents with no credit involved.
- The full automated suite passes.
- UAT is signed off by Finance / Accounts Payable and by an approver representative.

**Fail on any of the following:**

- A payment request can be created, routed, approved or paid with a total of zero or less, by any route.
- Any request reaches approval with fewer approvers than its net amount requires.
- A correction or release leaves recorded approvals covering less than the request asks for.
- Any rule is enforced only on screen and can be bypassed by a direct request.
- An ordinary all-positive invoice or payment request behaves differently from before.
- A refusal message does not tell the user what to do next.

---

## Test Execution Checklist

| Step | Item | Owner | Status |
|---|---|---|---|
| 1 | Automated suite executed and passing | Developer | ☐ |
| 2 | Happy Path HP-01 to HP-16 executed | QA | ☐ |
| 3 | Negative NEG-01 to NEG-18 executed | QA | ☐ |
| 4 | Security SEC-01 to SEC-12 executed | QA | ☐ |
| 5 | SEC-01 to SEC-05 reviewed and signed off explicitly | QA Lead | ☐ |
| 6 | Regression REG-01 to REG-14 executed | QA | ☐ |
| 7 | Server-side enforcement verified independently of the screens (NEG-07, SEC-06) | QA | ☐ |
| 8 | Refusal messages reviewed for clarity and actionability | Business Owner | ☐ |
| 9 | Approval routing on netted requests reviewed against the threshold configuration | Finance Manager | ☐ |
| 10 | UAT-01 to UAT-06 executed | Finance / Approver | ☐ |
| 11 | Audit trail reviewed over a credit-only invoice and a netted request | Internal Audit | ☐ |
| 12 | `/ai` documentation confirmed updated | Developer | ☐ |
| 13 | Backout position confirmed | IT Manager | ☐ |
| 14 | Month-end review routine for unsettled credits agreed | Finance Manager | ☐ |

---

## Sign-Off

### QA Lead

Confirms every scenario above was executed, that SEC-01 to SEC-05 passed, and that no defect affecting approval routing remains open.

**Name:** ____________________  **Date:** ____________  **Signature:** ____________________

### Business Owner

Confirms that a credit note may exist as a document in its own right, that settling it inside a payment request matches the intended accounting treatment, and that approving on the net figure is correct.

**Name:** ____________________  **Date:** ____________  **Signature:** ____________________

### UAT Sign-Off

Finance / Accounts Payable and an approver representative confirm the change works as intended in day-to-day use, and that ordinary work is unaffected.

**Finance:** ____________________  **Date:** ____________  **Signature:** ____________________

**Approver:** ____________________  **Date:** ____________  **Signature:** ____________________
