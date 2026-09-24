# Test Cases

## Related Change Request

| | |
|---|---|
| **Change Request Subject** | Prevent the same vendor invoice number from being recorded twice, by enforcing invoice number uniqueness for each vendor |
| **Change Request Filename** | `ai/change-requests/enforce-unique-invoice-number-per-vendor.md` |
| **Risk Rating** | Medium |
| **Emergency Change** | No |
| **Implementation Date** | 2026-09-24 |

---

## Objective

Confirm that:

1. The same vendor invoice number cannot be recorded twice against the same vendor, whether entered
   on a new submission or introduced by correcting an existing invoice.
2. The rule matches the way vendors actually write their numbers — capitalisation and surrounding
   spaces are ignored — so an obvious duplicate cannot slip through on a formatting difference.
3. The rule is scoped to one vendor. Two different vendors using the same number are unaffected.
4. A cancelled invoice releases its number, so a mis-entry that was cancelled never permanently
   blocks the genuine invoice.
5. The rule holds in the database itself, not only at the point of entry, so it cannot be bypassed
   by two simultaneous submissions.
6. Nothing in the existing invoice lifecycle regresses — submission, correction, in-place Finance
   correction, posting, payment requests, approval and payment must all behave as before.

---

## Scope

**In scope.** Invoice submission, invoice correction (both the ordinary edit and the Finance
in-place correction of an invoice held by a payment request), the database constraint, and the
message shown to the person entering the invoice.

**Out of scope.** Posting to the ERP, payment request creation, the approval chain, payment,
reporting and exports. None of these are modified. Historic invoice data is not altered, and
invoices with no vendor link are outside the rule by design.

---

## Test Scenarios

### Happy Path Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| HP-01 | A first invoice for a vendor is accepted | Submit an invoice for vendor A with number `INV-1001` | Accepted and recorded as normal |
| HP-02 | A different number for the same vendor is accepted | Submit a second invoice for vendor A with number `INV-1002` | Accepted |
| HP-03 | The same number for a different vendor is accepted | Submit an invoice for vendor B with number `INV-1001` | Accepted — the rule is per vendor |
| HP-04 | An invoice can be corrected without changing its number | Open an existing invoice, change the description or an amount, leave the number as it is, save | Saved successfully; the invoice must not block itself |
| HP-05 | An invoice's number can be changed to an unused one | Edit an invoice and change its number to one not used by that vendor | Accepted |
| HP-06 | A cancelled invoice releases its number | Submit `INV-2001` for vendor A, cancel it, then submit a new invoice for vendor A with `INV-2001` | The second submission is accepted |

### Negative Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| NG-01 | An exact duplicate is declined | Submit `INV-1001` for vendor A when vendor A already has `INV-1001` | Declined, with a message naming the existing invoice's reference |
| NG-02 | A duplicate differing only in capitalisation is declined | Submit `inv-1001` for vendor A when `INV-1001` exists | Declined |
| NG-03 | A duplicate differing only in surrounding spaces is declined | Submit `  INV-1001  ` for vendor A when `INV-1001` exists | Declined |
| NG-04 | A correction cannot introduce a duplicate | Edit vendor A's `INV-1002` and change its number to `INV-1001`, which vendor A already has | Declined |
| NG-05 | The message identifies the clashing invoice | Trigger any of the above | The message names the existing invoice's internal reference so the user can look it up, and does not merely say "already taken" |
| NG-06 | The duplicate is not partially saved | Trigger NG-01 on a submission carrying line items and an attachment | No invoice, line item, document or audit entry is created |

### Security Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| SC-01 | Permission rules are unchanged | Attempt to edit another user's invoice as a non-Finance, non-admin user | Still refused with the existing authorisation error, not a uniqueness error — the new rule must not run before or replace the permission check |
| SC-02 | The message does not leak another vendor's data | Trigger NG-01 | The message refers only to an invoice belonging to the same vendor the user is already submitting against |
| SC-03 | The rule cannot be bypassed from outside the form | Attempt to create a duplicate directly against the API endpoint | Declined by the same rule |
| SC-04 | Concurrent submissions cannot both succeed | Submit the same vendor and number twice simultaneously | At most one is recorded; the database constraint holds even if both pass validation |

### Regression Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| RG-01 | Ordinary submission is unaffected | Submit several invoices with distinct numbers | All accepted, with correct references, totals and currency |
| RG-02 | Finance in-place correction still works | Correct an invoice already held by a payment request, without changing the number | Saved, and the existing in-place correction restrictions still apply |
| RG-03 | Posting to the ERP is unaffected | Post an invoice and record its ERP document number | Unchanged behaviour |
| RG-04 | Payment request flow is unaffected | Group posted invoices into a payment request and take it through approval | Unchanged behaviour |
| RG-05 | Rejection returning invoices is unaffected | Reject a payment request and confirm its invoices return to the pool and can be re-submitted | Unchanged; the returned invoices keep their numbers and are not blocked by the rule |
| RG-06 | Historic invoices without a vendor link are untouched | Inspect pre-existing invoices with no vendor record | Present and unaltered; not blocked, not deleted |
| RG-07 | The full automated suite passes | Run `php artisan test` | No new failures against the pre-existing baseline |

### User Acceptance Tests

| ID | Scenario | Acceptance Criteria |
|---|---|---|
| UAT-01 | Finance attempt to record a vendor invoice already captured | The system declines it and states which invoice already holds the number, clearly enough to act on without help |
| UAT-02 | Finance record two genuinely different vendors' invoices sharing a number | Both are accepted without friction |
| UAT-03 | Finance cancel a mis-entered invoice and record it correctly | The corrected invoice is accepted using the same number |
| UAT-04 | Finance confirm day-to-day work is unchanged | Submission, correction, posting and payment request creation feel no different for non-duplicate invoices |
| UAT-05 | Pre-existing duplicates in live data | Before deployment, Finance receive the measured list of existing duplicates (if any) and confirm it is resolved |

---

## Expected Results

- A vendor invoice number may appear at most once per vendor across all invoices that are not
  cancelled.
- Comparison ignores capitalisation and surrounding spaces.
- A declined submission produces a clear message identifying the existing invoice and creates no
  record of any kind.
- Cancelled invoices do not reserve their numbers.
- Invoices with no vendor link are unaffected.
- All existing invoice, payment request, approval, payment and reporting behaviour is unchanged.

---

## Pass/Fail Criteria

**Pass** — every Happy Path, Negative, Security and Regression case behaves as stated; the automated
suite shows no new failures against the pre-existing baseline; and all User Acceptance cases are
confirmed by Finance.

**Fail** — any duplicate is accepted; any legitimate submission or correction is wrongly declined;
a cancelled invoice's number remains blocked; a declined submission leaves a partial record; the
permission rules change; or any existing lifecycle behaviour regresses.

---

## Test Execution Checklist

- [ ] HP-01 First invoice accepted
- [ ] HP-02 Different number, same vendor, accepted
- [ ] HP-03 Same number, different vendor, accepted
- [ ] HP-04 Correction without changing the number
- [ ] HP-05 Number changed to an unused one
- [ ] HP-06 Cancelled invoice releases its number
- [ ] NG-01 Exact duplicate declined
- [ ] NG-02 Capitalisation variant declined
- [ ] NG-03 Surrounding-space variant declined
- [ ] NG-04 Correction cannot introduce a duplicate
- [ ] NG-05 Message identifies the clashing invoice
- [ ] NG-06 Nothing partially saved on a declined submission
- [ ] SC-01 Permission rules unchanged
- [ ] SC-02 No cross-vendor data in the message
- [ ] SC-03 Rule enforced outside the form
- [ ] SC-04 Concurrent submissions cannot both succeed
- [ ] RG-01 Ordinary submission unaffected
- [ ] RG-02 Finance in-place correction unaffected
- [ ] RG-03 ERP posting unaffected
- [ ] RG-04 Payment request flow unaffected
- [ ] RG-05 Rejection and re-submission unaffected
- [ ] RG-06 Vendor-less historic invoices untouched
- [ ] RG-07 Full automated suite passes
- [ ] UAT-01 to UAT-05 confirmed by Finance
- [ ] Live database measured for pre-existing duplicates before deployment

---

## Sign-Off

### QA Lead

Name: ________________________  Date: ____________  Result: Pass / Fail

### Business Owner

Name: ________________________  Date: ____________  Result: Approved / Rejected

### UAT Sign-Off

Name: ________________________  Date: ____________  Result: Accepted / Not Accepted
