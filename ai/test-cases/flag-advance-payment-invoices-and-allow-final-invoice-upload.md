# Test Cases

## Related Change Request

| | |
|---|---|
| **Change Request Subject** | Flag Advance Payment Invoices, and Allow the Final Vendor Invoice to Be Attached After Approval |
| **Change Request Filename** | `ai/change-requests/flag-advance-payment-invoices-and-allow-final-invoice-upload.md` |
| **Risk Rating** | Low to Medium |
| **Emergency Change** | No |
| **Implementation Date** | 17 September 2026 |
| **Approved Scope** | **Upload-only.** Amounts, currency, vendor, dates and line items stay locked after approval. |

---

## Objective

Confirm that an invoice can be marked as an advance payment and that the marker is visible wherever
the payment is reviewed; that supporting documents can be attached to an advance-payment invoice
after its payment request has been approved or paid; and — most importantly — that this upload route
cannot change anything that was approved.

---

## Scope

**In scope**

1. The advance payment indicator on invoice create and edit
2. The indicator displayed on the invoice, the Invoice Log, the payment request and the PAF
3. Filtering the Invoice Log to advance payments
4. Uploading documents to an advance-payment invoice after approval or payment
5. The distinction between documents present at approval and documents added later

**Out of scope**

- Changing amounts, currency, vendor, dates or line items after approval (explicitly refused)
- Flagging historical advance payments retrospectively
- An outstanding-advances reconciliation report
- Any change to approval routing or thresholds

---

## Test Scenarios

### Happy Path Tests

| ID | Scenario | Steps | Expected Result |
|----|----------|-------|-----------------|
| HP-01 | Raise an advance-payment invoice | Create an invoice with the advance indicator set | Saves; the record shows as an advance payment |
| HP-02 | Raise an ordinary invoice | Create one without the indicator | Saves; no advance marking anywhere — unchanged behaviour |
| HP-03 | Set the indicator on an existing editable invoice | Edit a submitted invoice and set the indicator | Saves and the marker appears |
| HP-04 | Clear the indicator while still editable | Edit and unset it | Saves; marker removed |
| HP-05 | Indicator on the Invoice Log | Open the log | Advance invoices are visually distinguishable from ordinary ones |
| HP-06 | Filter to advances | Filter the log to advance payments | Only advance-payment invoices are listed |
| HP-07 | Indicator on the payment request | Group an advance invoice into a request and open it | The advance is identifiable on the request |
| HP-08 | Indicator on the PAF | Download the PAF for a request containing an advance | The advance is marked on the form the approvers sign |
| HP-09 | Upload after approval | Approve the payment request fully, then upload a document to the advance invoice as Finance | Upload succeeds; the document is listed |
| HP-10 | Upload after payment | Mark the request paid, then upload the vendor's final tax invoice | Upload succeeds — this is the core case the change exists for |
| HP-11 | Submitter uploads after payment | As the original submitter, upload to their own paid advance invoice | Upload succeeds |
| HP-12 | Late documents are distinguishable | View the document list on HP-10 | Documents added after approval are clearly separated from those present at approval, with uploader and date |
| HP-13 | Audit trail | Review the invoice's audit history after HP-10 | A distinct entry records the post-approval upload, its uploader and timestamp |
| HP-14 | Late document reaches the PAF | Re-download the PAF after HP-10 | The newly attached document is included |

### Negative Tests

| ID | Scenario | Steps | Expected Result |
|----|----------|-------|-----------------|
| NG-01 | Amounts still locked after approval | Attempt to change a line amount on an approved advance invoice through the normal edit route | Refused under the existing rules — this change grants no new right to alter figures |
| NG-02 | Amounts locked after payment | Attempt to edit any field of a **paid** advance invoice | Refused; only the upload route is open |
| NG-03 | Upload route cannot carry field changes | Send amount, currency or vendor changes alongside a document on the upload route | The document is stored and every other value is ignored — nothing on the record changes |
| NG-04 | Ordinary invoice, no late upload | Attempt to upload to a **paid non-advance** invoice | Refused — the relaxation applies to advance payments only |
| NG-05 | Unrelated user | As a user who is neither Finance, admin, nor the submitter, upload to a paid advance invoice | Refused |
| NG-06 | Cancelled invoice | Attempt to upload to a cancelled invoice | Refused |
| NG-07 | File type and size rules | Upload a disallowed type, and one over the size limit | Both refused with the existing messages — the limits are unchanged |
| NG-08 | Document count limit | Exceed the maximum number of documents | Refused with the existing message |
| NG-09 | Empty upload | Submit the upload route with no file | Refused with a clear message; nothing is written |
| NG-10 | Deleting an approved document | Attempt to delete a document that was present at approval | Refused, as today — approved evidence is not removable |

### Security Tests

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| SC-01 | Upload to an invoice the user cannot see | Refused; no information about the invoice is disclosed |
| SC-02 | Download a late-added document without rights | Refused under the existing visibility rules — unchanged |
| SC-03 | Approver visibility | An approver on the request can see the invoice and its documents, as today, but cannot upload unless they are Finance, admin or the submitter |
| SC-04 | Upload never re-opens approval | After HP-10, the payment request's status, approval history and current stage are unchanged |
| SC-05 | Upload never restores payability | After HP-10, the invoice is not offered as eligible for a new payment request |
| SC-06 | Every upload audited | Post-approval uploads appear in the audit log with actor and IP, to the same standard as any state change |
| SC-07 | Indicator cannot be flipped after approval | Attempt to set or clear the advance indicator on an approved or paid invoice | Refused — the marker is part of what was approved |

### Regression Tests

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| RG-01 | Submit, post, group, approve and pay an ordinary invoice | Unchanged end to end |
| RG-02 | Existing invoices | All show as non-advance; nothing about them changes |
| RG-03 | Existing documents | Remain listed, downloadable and merged into the PAF as before |
| RG-04 | In-place correction while in approval | Finance can still correct within the existing limits |
| RG-05 | In-place correction limits | Raising a total, changing currency, or leaving the request at or below zero are all still refused |
| RG-06 | Releasing an invoice from a request | Unchanged |
| RG-07 | Invoice Log filters and sorting | Unchanged; the new filter does not disturb the existing ones |
| RG-08 | Document deletion while editable | Still permitted for the uploader or an admin |
| RG-09 | PAF generation and attachment merging | Unchanged, including the trailing links page for non-renderable types |
| RG-10 | Approval emails and subjects | Unchanged |
| RG-11 | Credit notes and negative lines | Unchanged |
| RG-12 | Reports and dashboard figures | Unchanged — no total moves because of this change |

### User Acceptance Tests

| ID | Scenario | Acceptance Criterion |
|----|----------|----------------------|
| UA-01 | Finance identifies advances | Finance can list every advance payment without reading descriptions |
| UA-02 | Approver recognises an advance | An approver confirms they can tell from the request and the PAF that they are approving a prepayment |
| UA-03 | Finance completes an advance record | Finance attaches a vendor's final tax invoice to a paid advance and confirms it is where they expect it |
| UA-04 | The distinction is clear | A reviewer can tell which documents the approvers saw and which arrived later |
| UA-05 | Submitters understand the indicator | A submitter confirms when to set it and finds the wording unambiguous |

---

## Expected Results

1. An invoice can be marked as an advance payment on creation and while it remains editable.
2. The marker appears on the invoice, in the log, on the payment request and on the PAF.
3. The log can be filtered to advance payments.
4. Documents can be attached to an advance-payment invoice after approval and after payment, by Finance, an administrator, or the original submitter.
5. Late documents are visibly distinguished from those present at approval, and each upload is audited distinctly.
6. **No amount, currency, vendor, date, line item, status or approval state can be changed through the upload route, at any point.**
7. Ordinary invoices, and every existing behaviour, are unaffected.

---

## Pass/Fail Criteria

**Pass** — every Happy Path, Negative and Security case passes; every Regression case shows no change in behaviour; all UAT criteria are accepted.

**Fail** — any of the following, each on its own sufficient:

- Any amount, currency, vendor, date, line item or status changes through the upload route.
- A document can be attached to a paid **ordinary** invoice.
- An upload alters the payment request's status, approval history or payability.
- The advance indicator can be changed after approval.
- Any document or invoice becomes reachable by someone who could not see it before.
- Any regression case shows changed behaviour.

**Conditional pass** — cosmetic issues only (wording, placement of the marker on screen or on the form), logged and scheduled with business-owner agreement.

---

## Test Execution Checklist

- [ ] HP-01 … HP-14 executed and evidenced
- [ ] NG-01 … NG-10 executed and evidenced
- [ ] SC-01 … SC-07 executed and evidenced
- [ ] RG-01 … RG-12 executed and evidenced
- [ ] UA-01 … UA-05 accepted by the business
- [ ] Automated test suite green (`php artisan test`)
- [ ] Code formatted (`./vendor/bin/pint`)
- [ ] Migration applied and verified on a copy of production data
- [ ] PAF checked with and without the advance marker
- [ ] Invoice Log and detail screens checked on desktop and mobile
- [ ] `/ai` documentation updated for the affected areas
- [ ] Open points on the CR resolved and the CR updated to match what was built

---

## Sign-Off

### QA Lead

Name: ______________________  Signature: ______________________  Date: ____________

Result: Pass / Conditional Pass / Fail

### Business Owner — Payment Approval Process

Name: ______________________  Signature: ______________________  Date: ____________

### UAT Sign-Off — Finance

Name: ______________________  Signature: ______________________  Date: ____________
