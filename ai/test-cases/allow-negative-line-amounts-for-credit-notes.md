# Test Cases

## Related Change Request

| Field | Value |
|-------|-------|
| **Change Request Subject** | Allow vendor credit notes to be recorded on an invoice and deducted from the payment request total |
| **Change Request Filename** | [ai/change-requests/allow-negative-line-amounts-for-credit-notes.md](../change-requests/allow-negative-line-amounts-for-credit-notes.md) |
| **Risk Rating** | Medium |
| **Emergency Change** | No |
| **CR Approved** | 2026-09-03 |
| **Implementation Date** | 2026-09-03 |
| **Amount Policy Confirmed** | Individual lines may be a deduction; a zero line is rejected; the invoice must net to a positive amount owed |

---

## Objective

Confirm that a vendor credit note can be recorded as a deduction line on an invoice, that the system
calculates the net amount owed correctly, and that the net amount is the figure carried into the
payment request, the approval routing and the payment.

Equally important: confirm that the controls protecting approval authority still hold once amounts
can be reduced, and that ordinary all-positive invoices behave exactly as they did before.

---

## Scope

**In scope**

- Entering a deduction line on a new invoice, and on an invoice being edited
- The invoice amount policy (deduction lines allowed, zero line rejected, net must be positive)
- Tax calculation on a deduction line
- Net amount flowing into the payment request total
- Approval routing measured on the net amount
- Correcting an invoice already held by a payment request, by adding a deduction
- Releasing an invoice that carries a net deduction from a payment request
- Presentation of deductions on screen, on the payment document, in the public view and in emails
- Dashboard and report figures with deductions present
- Audit trail entries for the net amount
- Regression: all-positive invoices unchanged

**Out of scope**

- Credit-only invoices (net zero or below) — deliberately excluded by the CR; a separate change request
- Approval threshold amounts themselves (unchanged)
- User access, roles and permissions (unchanged)
- Any external system or integration (none exist)
- Master data maintenance

---

## Test Scenarios

### Happy Path Tests

| ID | Scenario | Steps | Expected Result |
|----|----------|-------|-----------------|
| HP-01 | Record a credit note as a deduction line | Create an invoice with a line of 10,000 and a second line of −1,500 described as the credit note | Invoice saves. Net amount owed is 8,500. Both lines are visible separately |
| HP-02 | Net amount reaches the payment request | Raise a payment request from the HP-01 invoice | Payment request total is 8,500, not 10,000 |
| HP-03 | Tax on a deduction line | Line of 10,000 at 5% tax, plus a line of −1,000 at 5% tax | Tax on the deduction is −50. Invoice tax total 450, net total owed 9,450 |
| HP-04 | Deduction with no tax | Line of 10,000 at 5%, plus a line of −1,000 at 0% | Tax total 500, net total owed 9,500 |
| HP-05 | Several deduction lines | One line of 20,000 and three deductions of −1,000, −2,000, −500 | Net amount owed is 16,500 |
| HP-06 | Deduction added when editing | Save an invoice of 10,000, then edit it and add a −2,000 line | Invoice updates. Net amount owed becomes 8,000 |
| HP-07 | Deduction removed when editing | Take the HP-06 invoice and delete the deduction line | Net amount owed returns to 10,000 |
| HP-08 | Lower an invoice under approval with a deduction | Finance corrects an invoice held by an in-approval payment request by adding a deduction line | Correction is accepted (the total falls). Invoice and payment request totals both drop to the net figure |
| HP-09 | Full cycle with a credit note | Invoice with a deduction → payment request → approvals → mark paid | Cycle completes. The amount approved and paid is the net figure throughout |
| HP-10 | Deduction in a non-base currency | Invoice in a foreign currency with a deduction line, with a rate on record | Net amount is correct in the invoice currency, and the base-currency figure used for routing is correctly reduced |

### Negative Tests

| ID | Scenario | Steps | Expected Result |
|----|----------|-------|-----------------|
| NEG-01 | Zero-value line | Enter a line with an amount of 0 | Rejected with a clear message. A zero line carries no information |
| NEG-02 | Invoice nets to exactly zero | Line of 5,000 and a line of −5,000 | Rejected. The message explains the invoice must net to a positive amount owed and points to the credit-only limitation |
| NEG-03 | Invoice nets below zero | Line of 5,000 and a line of −6,000 | Rejected with the same clear message |
| NEG-04 | Deduction-only invoice | A single line of −5,000 | Rejected. This is the credit-only case that is out of scope |
| NEG-05 | Deduction exceeds the size limit | A line of −9,999,999,999,999 | Rejected by the amount size limit, exactly as an oversized positive amount is |
| NEG-06 | Non-numeric amount | Enter text in the amount field | Rejected as not a number, as before |
| NEG-07 | Raise an invoice-under-approval total with a deduction removed | Correct a held invoice by deleting its deduction line, raising the total | Rejected — the existing "a correction cannot raise the total" safeguard still applies |
| NEG-08 | Currency change on a held invoice | Attempt to change currency while correcting a held invoice containing a deduction | Rejected — the existing currency lock still applies |
| NEG-09 | Mixed currencies with a deduction | Positive line in one currency, deduction in another | Rejected — the existing single-currency rule still applies |
| NEG-10 | Deduction line with a negative tax rate | Line of −1,000 with a tax rate of −5 | Rejected. Tax rate remains 0–100 |

### Security Tests

| ID | Scenario | Steps | Expected Result |
|----|----------|-------|-----------------|
| SEC-01 | Approval routing follows the net amount, not the gross | Invoice of 500,000 reduced by a −450,000 deduction to a net of 50,000 | The approval chain generated matches the levels required for 50,000. This is correct behaviour and must be explicitly asserted, not assumed |
| SEC-02 | Routing never omits a required approver | Invoice of 500,000 reduced to a net of 200,000, where 200,000 still requires a senior level | Every level the net amount requires is present in the chain. No level is skipped |
| SEC-03 | No payment request without approvers | Attempt to reach a state where the net amount matches no approval level | Cannot occur — the positive-net rule blocks it at the invoice. Confirm the existing "add at least one approver" refusal remains as a second line of defence |
| SEC-04 | Approved payment cannot escape its approvals via a deduction | Add a deduction to an invoice held by an approved payment request, then attempt to release the invoice | Either the release is refused or the payment request is re-routed for approval. The recorded approvals must never cover less than the resulting total |
| SEC-05 | Release of a net-deducted invoice raises the remaining total | Payment request holding two invoices, one carrying a net deduction; release that invoice | The rise in the remaining total is handled explicitly, not silently accepted. Confirm the safeguard added by this change |
| SEC-06 | Ownership check unchanged | A submitter attempts to add a deduction to another user's invoice | Refused with the existing authorisation error. The widened amount range grants no new access |
| SEC-07 | Amount size limit still bounds deductions | Very large deduction values | Bounded by the same limit as positive amounts. No overflow or precision problem |
| SEC-08 | Audit trail records the net amount | Submit an invoice carrying a deduction | The audit entry records the net amount owed. Any invoice netting to zero or below is flagged for review |
| SEC-09 | No change to permissions | Review roles, permissions and visible data before and after | Identical. No new field exposed, no new access granted |

### Regression Tests

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| REG-01 | Existing automated test suites | Invoice submission, tax percentage, in-place correction, dynamic approval, currency-converted routing, payment request withdrawal, payment document, report export and endpoint smoke suites all pass |
| REG-02 | Ordinary all-positive invoice, single line | Behaves exactly as before. No change in totals, routing or presentation |
| REG-03 | Ordinary all-positive invoice, many lines | Behaves exactly as before |
| REG-04 | Existing invoices created before the change | Open, view, edit and re-save unaffected |
| REG-05 | Payment requests already in flight | Totals, approvals and status unchanged by the deployment |
| REG-06 | Dashboard spend indicators | Correct with deductions present. Figures reflect net amounts |
| REG-07 | Status breakdown and six-month trend | Render correctly with net figures |
| REG-08 | Top-vendor and business-unit rankings | Render correctly. Ranking order may change once credits apply — verify it is sensible, not broken |
| REG-09 | Charts with a reduced or negative value | Axis and any percentage-of-total calculation remain correct. No rendering failure |
| REG-10 | Report screen totals | Reflect net amounts |
| REG-11 | Excel/CSV export | Deduction lines and net totals export correctly |
| REG-12 | Payment document (PDF) | Deduction lines render unmistakably. Totals correct |
| REG-13 | Public payment request view | Deduction lines render unmistakably. Totals correct |
| REG-14 | All payment request emails (submitted, approved, reminder) | Deduction lines and net totals render unmistakably |
| REG-15 | Multi-invoice payment request | Total is the sum of net invoice amounts |
| REG-16 | Withdraw a payment request containing a credited invoice | Behaves as before |

### User Acceptance Tests

| ID | Scenario | Acceptance Criteria | Owner |
|----|----------|--------------------|-------|
| UAT-01 | Enter a real vendor credit note | Finance records an actual credit note as a deduction line and confirms the net amount owed matches their own calculation | Finance |
| UAT-02 | Net amount on the payment request | Finance confirms the payment request shows the net figure and routes to the approvers expected for that figure | Finance |
| UAT-03 | Deduction is unmistakable on screen | An approver confirms a deduction line cannot be mistaken for a charge when reviewing the invoice | Approver |
| UAT-04 | Deduction is unmistakable on the payment document | An approver confirms the same on the printed/PDF payment document | Approver |
| UAT-05 | Deduction is unmistakable in the email | An approver confirms the same in the notification email | Approver |
| UAT-06 | Error message on a credit-only invoice is clear | Finance attempts an invoice netting to zero or below and confirms the message explains why and what to do | Finance |
| UAT-07 | Accidental minus sign is caught | Finance confirms the confirmation step prevents a mistyped deduction going through unnoticed | Finance |
| UAT-08 | Audit trail is sufficient | Internal Audit or Finance confirms the credit note is traceable on the invoice — value, description and net effect | Finance / Audit |
| UAT-09 | Reporting on a net basis is understood and accepted | Report consumers confirm the shift to net spend figures is expected and correct | Management Reporting |
| UAT-10 | Tax treatment is correct | Finance confirms the tax calculated on a deduction line matches the required VAT/tax reporting treatment | Finance |

---

## Expected Results

1. A vendor credit note can be recorded as a deduction line on the invoice it applies to, with its own description and value.
2. The invoice's net amount owed is calculated by the system and equals the sum of its lines, including deductions.
3. Tax on a deduction line is calculated as a reduction, consistently with how tax is calculated on a charge.
4. The net amount owed is the figure that reaches the payment request, the approval routing and the payment.
5. Approval routing is measured on the net amount, and always includes every level that amount requires.
6. A zero-value line is rejected. An invoice that nets to zero or below is rejected with a clear, actionable message.
7. Deductions are visually unmistakable on screen, on the payment document, in the public view and in every notification email.
8. An accidental deduction is caught at the point of entry by a confirmation step.
9. The audit trail records the net amount owed on submission.
10. Releasing an invoice carrying a net deduction from a payment request is handled explicitly and can never leave a total exceeding the approvals recorded against it.
11. Reported spend figures reflect net amounts.
12. Ordinary all-positive invoices, and every invoice created before this change, behave exactly as they did before.

---

## Pass/Fail Criteria

**The change passes when all of the following hold:**

| Criterion | Requirement |
|-----------|-------------|
| Happy path | 100% of HP tests pass |
| Negative | 100% of NEG tests pass |
| Security | **100% of SEC tests pass — no exceptions.** These protect approval authority and are the reason for the Medium risk rating |
| Regression | 100% of REG tests pass. Any change in an all-positive invoice's behaviour is an automatic fail |
| User acceptance | All UAT tests signed off by their owner |
| Automated suites | The full existing test suite passes, plus new automated coverage for HP-01 to HP-05, NEG-01 to NEG-04, and SEC-01 to SEC-05 |
| Code standard | Formatter passes clean |

**Automatic fail conditions**

- Any approval chain generated with fewer levels than the net amount requires (SEC-01, SEC-02)
- Any route by which a payment can exceed the approvals recorded against it (SEC-04, SEC-05)
- An invoice netting to zero or below being accepted (NEG-02, NEG-03, NEG-04)
- Any behavioural change to an all-positive invoice (REG-02, REG-03)
- A deduction that is not visually distinguishable from a charge on any output (UAT-03 to UAT-05)

---

## Test Execution Checklist

**Pre-execution**

- [ ] Amount policy confirmed by Finance and reflected in the build
- [ ] Test environment deployed with the change
- [ ] Approval levels configured with at least three amount tiers, so routing changes are observable
- [ ] Currency rates on record for at least one foreign currency
- [ ] Test users available for each role: submitter, Finance, approvers at different levels, admin
- [ ] A real vendor credit note available for UAT

**Automated**

- [ ] New automated coverage written for the marked scenarios
- [ ] Full test suite run and green
- [ ] Formatter run clean

**Manual — functional**

- [ ] HP-01 to HP-10 executed
- [ ] NEG-01 to NEG-10 executed
- [ ] REG-01 to REG-16 executed

**Manual — security**

- [ ] SEC-01 to SEC-09 executed and individually signed off

**Manual — presentation**

- [ ] Invoice screen reviewed with a deduction line
- [ ] Payment document (PDF) reviewed
- [ ] Public payment request view reviewed
- [ ] All three notification emails reviewed
- [ ] Dashboard and charts reviewed
- [ ] Report screen and export reviewed

**User acceptance**

- [ ] UAT-01 to UAT-10 executed with the business
- [ ] Finance briefed
- [ ] Approvers briefed
- [ ] Report consumers notified of the shift to net spend figures

**Post-deployment**

- [ ] One real credit note entered on a low-value invoice in production
- [ ] Net amount, approvers routed to, payment document and dashboard figures all confirmed
- [ ] Existing invoices confirmed untouched
- [ ] Monitoring period completed

---

## Sign-Off

### QA Lead

Confirms all scenarios executed, the security scenarios individually verified, and no automatic fail condition triggered.

Name: ________________________  Result: Pass / Fail  Date: ____________

### Business Owner (Finance / Accounts Payable)

Confirms the business need is met, the process change is workable, and the reporting shift to a net basis is accepted.

Name: ________________________  Result: Pass / Fail  Date: ____________

### UAT Sign-Off

Confirms UAT-01 to UAT-10 completed and accepted by their owners.

Name: ________________________  Result: Pass / Fail  Date: ____________

### IT Manager (Deployment Readiness)

Confirms test results reviewed, regression clean, and the backout plan understood — including that any invoice saved with a deduction line requires manual correction if the change is backed out.

Name: ________________________  Result: Pass / Fail  Date: ____________

---

## Traceability

| Link | Reference |
|------|-----------|
| Change Request | Allow vendor credit notes to be recorded on an invoice and deducted from the payment request total |
| CR document | `ai/change-requests/allow-negative-line-amounts-for-credit-notes.md` |
| Risk rating | Medium |
| Emergency change | No |
| Implementation date | 2026-09-03 |
| Test case document | `ai/test-cases/allow-negative-line-amounts-for-credit-notes.md` |

Chain: Change Request → Development → Testing → Deployment
