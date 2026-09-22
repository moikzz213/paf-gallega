# Test Cases

## Related Change Request

| | |
|---|---|
| **Change Request Subject** | Make rejected payment requests visible in the Reports output, and notify everyone involved when a payment request is rejected |
| **Change Request Filename** | `ai/change-requests/report-and-notify-rejected-payment-requests.md` |
| **Risk Rating** | Medium |
| **Emergency Change** | No (high-priority scheduled change) |
| **Implementation Date** | 2026-09-22 |

---

## Objective

Confirm that:

1. A rejected payment request, its rejection reason and the approver remarks recorded against it
   remain visible in the invoice report — on screen, in the Excel download and in the export API —
   both immediately after rejection and after the invoices have been re-submitted on a new payment
   request.
2. The rejection notice reaches the payment request creator, the invoice submitters, the Finance
   team and every approver on the rejected request's approval chain, once each.
3. Nothing about the existing rejection behaviour regresses — in particular, invoices returned to
   Finance must remain fully available for a new payment request, and report visibility rules must
   be unchanged.

---

## Scope

**In scope**

- Rejection of a payment request from inside the application.
- Rejection of a payment request through the public approval link.
- The Reports page, the Excel download and the key-authenticated export API.
- Rejection notification recipients and content.
- Re-initiation of invoices after a rejection.

**Out of scope**

- Withdrawal and cancellation of payment requests (these detach invoices the same way, but are not
  part of this change — recorded separately as a known issue).
- Approval, payment and posting flows other than as regression checks.
- Report filters, columns and formats not related to rejection.

---

## Test Scenarios

### Happy Path Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| HP-01 | Rejected request appears in the report | Create a payment request with two invoices, route it through a two-stage chain, approve stage 1, reject at stage 2 with a reason. Open the Reports page and locate the two invoices. | Both invoices show the rejected payment request's reference in the rejected-payment-requests column, and the rejection reason appears in the remarks column, labelled as a rejection. |
| HP-02 | Approver remarks survive the rejection | Using the request from HP-01, check the remarks column. | The stage 1 approver's comment and the stage 2 rejection comment both appear against each invoice. |
| HP-03 | History survives re-submission | Take the invoices from HP-01, put them on a new payment request, and approve it fully. Re-run the report. | The rejected payment request reference and its reason are still shown, alongside the new payment request in the payment request column. The report shows both the rejection history and the current request. |
| HP-04 | Multiple rejections accumulate | Reject the same invoice on two successive payment requests. Re-run the report. | Both rejected payment request references appear, oldest first, and both rejection reasons appear in the remarks. |
| HP-05 | Excel download matches the screen | Export the report to Excel for the same filters as HP-01. | The downloaded file contains the same rejection columns and values as the screen, with the new heading present. |
| HP-06 | Export API matches the screen | Call the key-authenticated export for the same filters, in both JSON and Excel formats. | The rejection fields are present and match the screen and the Excel download exactly. |
| HP-07 | Rejection notice reaches everyone | Reject a request whose creator, invoice submitters, Finance team members and chain approvers are all distinct people. | Each of those people receives exactly one rejection notice, containing the reason, the stage, the rejecting approver and the list of returned invoices. |
| HP-08 | Public-link rejection behaves identically | Reject a request through the emailed public approval link. | The report shows the rejection exactly as in HP-01, and the same recipient list is notified as in HP-07. |
| HP-10 | Filter the report by status Rejected | As Finance, reject a request, then filter the Reports page by status **Rejected**. | The invoices from the rejected request are listed. They remain listed after being re-submitted on a new request. |
| HP-11 | The other status options filter correctly | Filter by Paid, Approved, In Approval and Posted in turn. | Each returns the matching invoices rather than an empty page. |
| HP-12 | Several statuses select together | Select Posted and Rejected together. | The result is the union of both, not the intersection. |
| HP-09 | Notice wording suits the wider audience | Read the notice received by a Finance user and by a chain approver. | The wording does not address the recipient as the requestor; it reads correctly for someone informed of the rejection rather than responsible for it. |

### Negative Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| NG-01 | Rejection with no reason recorded | Attempt to reject without entering a comment. | Rejection is refused; a reason remains mandatory, as today. |
| NG-02 | Recipient with no email address | Reject a request where one chain approver has no email recorded. | The rejection completes normally, the other recipients are notified, and the missing address is logged. |
| NG-03 | Mail transport unavailable | Reject a request with the mail service failing. | The rejection is recorded, the invoices are returned to Finance, and the failure is logged. The user is not shown an error implying the rejection failed. |
| NG-04 | Duplicate recipient roles | Reject a request where one person is both the creator and a chain approver, and another is both an invoice submitter and a Finance user. | Each person receives exactly one notice, not two. |
| NG-05 | Invoice with no rejection history | Run the report over invoices that have never been on a rejected request. | The rejection columns are empty; no placeholder text, no errors, and the remarks column is unchanged from before this change. |
| NG-06 | Report over an empty result set | Apply filters that match no invoices, on screen and via export. | Empty report renders and exports cleanly with the new columns present in the heading row. |

### Security Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| SEC-01 | Report scoping unchanged — requester | Run the report as a requester whose own invoices were on a rejected request, and who also has no entitlement to other users' invoices. | Only their own invoices are returned. Rejection history is shown only for those rows. No invoice becomes visible that was not visible before this change. |
| SEC-07 | Rejected filter does not widen approver scope | As an approver, filter by status Rejected after a request they approved was rejected at a later stage. | The detached invoice is **not** returned — rejection correctly removes it from the approver's scope. Finance and admin do see it. This is existing behaviour and must not change. |
| SEC-02 | Report scoping unchanged — approver | Run the report as an approver with business-unit-limited visibility. | The row set is identical to the row set before this change; rejection history does not widen it. |
| SEC-03 | Export API carries its owner's scope | Call the export API with a key issued to a limited user, over data including rejected requests. | The response contains only rows that user could see on screen, with the same rejection values. |
| SEC-04 | Notification does not leak beyond the process | Inspect the recipient list generated for a rejection. | Recipients are limited to the request creator, invoice submitters, active Finance users and the approvers on that request's chain. No external address, and no user unrelated to the request. |
| SEC-05 | Rejection remains audited | Reject a request and inspect the audit trail. | The rejection is recorded with the reference, the stage and the acting user, as before. |
| SEC-06 | Public link rejection authorisation unchanged | Attempt a public-link rejection with an invalid or superseded token. | Rejection is refused exactly as before this change. |

### Regression Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| RG-01 | **Invoices remain available after rejection (critical)** | Reject a payment request. Open the payment request creation screen and look for its invoices. | All invoices from the rejected request appear in the available-invoice list and can be selected for a new request. This is the single most important check in this document. |
| RG-02 | Invoice payment status after rejection | Inspect the invoices from RG-01. | Payment status is "not initiated"; the invoices are editable and correctable exactly as before the change. |
| RG-03 | Re-initiation produces a clean new request | Build a new payment request from the rejected invoices and route it through approval. | The new request behaves normally; its approval chain, totals and documents are unaffected by the rejection history. |
| RG-04 | Approved and paid requests unaffected | Run the report over invoices on approved and paid requests. | Payment request reference, paid date, payment reference and remarks are unchanged from before this change. |
| RG-05 | Existing report columns unchanged | Compare a report export taken before and after deployment over the same filters. | All previously existing columns hold the same values; only the rejection information is added, and previously blank rejection-related remarks are now populated. |
| RG-06 | Approval notification unchanged | Fully approve a payment request. | The approval notice goes to the same recipients as before, with unchanged content. |
| RG-07 | Withdrawal and cancellation unchanged | Withdraw an approved request, and cancel an invoice. | Behaviour is exactly as before this change. (Their equivalent reporting gap is out of scope and recorded as a known issue.) |
| RG-08 | Summary totals on the Reports page | Compare the status summary cards and totals before and after. | Counts and amounts are unchanged; the change adds columns, not rows. |

### User Acceptance Tests

| ID | Scenario | Acceptance Criteria |
|---|---|---|
| UAT-01 | Finance can identify rejected payment requests from the report alone | A Finance user runs the report for a period and can list every rejected payment request, its reason and the stage at which it was rejected, without opening any individual request. |
| UAT-02 | Finance is told promptly | A Finance user confirms they receive the rejection notice at the time of rejection and that it contains enough detail to begin the correction. |
| UAT-03 | Approvers receive closure | An approver who approved an earlier stage confirms they are told when a later stage rejects the request, and that the message makes their role clear. |
| UAT-04 | Notification volume is acceptable | Finance and approver representatives confirm, over a trial period, that the number of rejection notices is manageable. If not, the recipient list is tuned before wider rollout. |
| UAT-05 | Management reporting is usable | A department head confirms the report can answer "how many payment requests were rejected this period, and why". |
| UAT-06 | No retraining required | Users confirm that submitting, approving, rejecting and paying are unchanged. |

---

## Expected Results

1. Rejection history is retained against each invoice independently of which payment request the
   invoice currently belongs to, so it is not lost when the invoice is re-submitted.
2. The report exposes the rejected payment request references and folds the rejection reasons and
   the approver remarks from those rejected requests into the remarks column, clearly labelled.
3. The Reports page, the Excel download and the export API produce identical rejection information,
   because they share one report definition.
4. The rejection notice is delivered once per person to the request creator, the invoice submitters,
   the active Finance team and the chain approvers.
5. A notification failure never prevents or reverses a rejection.
6. Report visibility scoping, invoice availability after rejection, and every other flow are
   unchanged.

---

## Pass/Fail Criteria

**Pass requires all of the following:**

- All Happy Path tests pass.
- All Security tests pass, with SEC-01, SEC-02 and SEC-03 confirming no widening of visibility.
- All Regression tests pass. **RG-01 is a blocking criterion** — if invoices cannot be re-submitted
  after a rejection, the change must not be deployed.
- Negative tests confirm rejection always completes even when notification does not.
- UAT-01 through UAT-06 are accepted by Finance and by an approver representative.

**Fail conditions:**

- Any invoice becomes unavailable for a new payment request after a rejection.
- Any user can see a row, remark or reason they could not see before the change.
- A rejection is blocked, reversed or left half-applied by a notification problem.
- Any previously existing report column changes value.
- Any recipient receives duplicate notices for a single rejection.

---

## Test Execution Checklist

| # | Item | Result | Tester | Date |
|---|---|---|---|---|
| 1 | Test data prepared: multi-stage chain, distinct creator / submitter / Finance / approvers | ☐ | | |
| 2 | HP-01 – HP-09 executed | ☐ | | |
| 3 | NG-01 – NG-06 executed | ☐ | | |
| 4 | SEC-01 – SEC-06 executed | ☐ | | |
| 5 | RG-01 executed and passed (blocking) | ☐ | | |
| 6 | RG-02 – RG-08 executed | ☐ | | |
| 7 | Automated test suite green (`php artisan test`) | ☐ | | |
| 8 | Code formatted (`./vendor/bin/pint`) | ☐ | | |
| 9 | Before/after report exports compared (RG-05) | ☐ | | |
| 10 | UAT-01 – UAT-06 accepted | ☐ | | |
| 11 | Report consumers notified of the added columns ahead of deployment | ☐ | | |
| 12 | `/ai` documentation updated (schema, API contracts, feature overview, bugs fixed) | ☐ | | |
| 13 | Post-deployment monitoring completed for one reporting cycle | ☐ | | |

---

## Sign-Off

### QA Lead

Name: ________________________  Signature: ________________  Date: __________

Comments: ______________________________________________________________

### Business Owner (Finance)

Name: ________________________  Signature: ________________  Date: __________

Comments: ______________________________________________________________

### UAT Sign-Off

Name: ________________________  Signature: ________________  Date: __________

Comments: ______________________________________________________________
