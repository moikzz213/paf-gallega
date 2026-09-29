# Test Cases

## Related Change Request

| | |
|---|---|
| **Change Request Subject** | Fix the Vendor and Customer search on Payment Requests, and show each vendor's and customer's code |
| **Change Request Filename** | `ai/change-requests/fix-vendor-and-customer-search-on-payment-requests.md` |
| **Reference** | INC-176271 |
| **Risk Rating** | Low |
| **Emergency Change** | No |
| **Implementation Date** | 2026-09-29 |

---

## Objective

Confirm that:

1. The Vendor name and Customer Name searches on the New Payment Request screen, and the Vendor
   filter on the Payment Requests list, show each entry as "Name (Code)". Typing narrows the list to
   matching entries only, the same way the Department search does.
2. Selecting an entry returns only the invoices, or payment requests, of that exact vendor or
   customer record. It does not return those of another group company's record with the same name.
3. Every other search and filter on these screens behaves exactly as before.

---

## Scope

**In scope**

- New Payment Request dialog: the Vendor name and Customer Name searches.
- Payment Requests list: the Vendor filter.
- The invoice results each search returns.

**Out of scope**

- The invoice entry form's vendor and customer pickers, which already identify the record.
- Reports and the export feed, whose vendor filter is unchanged.
- Correcting vendor or customer master data, such as the vendor with no code.

---

## Test Scenarios

### Happy Path Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| HP-01 | Vendor list shows codes | Open New Payment Request and open the Vendor name list | Entries read "Name (Code)", e.g. "3PLOGISTICS (VEN0008 - GIL)" and "3PLOGISTICS (VEN0008 - GGL)" |
| HP-02 | Vendor search narrows like Department | Type "3PLOG" in Vendor name | Only the 3PLOGISTICS entries remain, one per company |
| HP-03 | Vendor search by code | Type "VEN0008" in Vendor name | Only entries whose code contains VEN0008 remain |
| HP-04 | Vendor selection is entity-specific | Select "3PLOGISTICS (VEN0008 - GIL)" | Only invoices of the GIL 3PLOGISTICS record are listed |
| HP-05 | Other entity's vendor | Select "3PLOGISTICS (VEN0008 - GGL)" | Only invoices of the GGL 3PLOGISTICS record are listed |
| HP-06 | Customer list shows codes and filters | Type part of a customer name in Customer Name | Only matching entries remain, each shown with its code |
| HP-07 | Customer selection is entity-specific | Select one company's customer entry | Only invoices with a line recorded against that customer record are listed |
| HP-08 | List-page Vendor filter | On the Payment Requests list, type then select a vendor entry | Only payment requests containing an invoice of that vendor record are listed |
| HP-09 | Combined filters | Select a vendor and a department together | Results satisfy both filters |

### Negative Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| NG-01 | No match | Type text that matches no vendor | The list shows "No data available"; nothing is selected |
| NG-02 | Clearing the search | Select a vendor, then clear it | The full eligible invoice list returns |
| NG-03 | Vendor with no code | Search for "Grand Spicy" | It appears by name alone, without empty brackets, and can be selected |
| NG-04 | Invalid record reference | Request the invoice search with a non-numeric vendor or customer reference | The request is refused as invalid; no error page |
| NG-05 | Vendor with no eligible invoices | Select a vendor that has no posted invoices awaiting payment | The invoice list is empty |

### Security Tests

| ID | Scenario | Steps | Expected Result |
|---|---|---|---|
| SC-01 | Eligible-invoice search stays Finance-only | As a requester or approver, call the eligible-invoice search with a vendor reference | Access is refused, as today |
| SC-02 | List visibility unchanged | As a requester, filter the Payment Requests list by a vendor | Only payment requests the user could already see are returned |

### Regression Tests

| ID | Scenario | Expected Result |
|---|---|---|
| RG-01 | Department, Currency, Invoice No and Job No searches | Behave exactly as before |
| RG-02 | Existing search by vendor name or customer name text | Still returns the same results as before |
| RG-03 | Creating a payment request from filtered invoices | Selection, credit-note marking, approval chain and submission work as before |
| RG-04 | Status filter and free-text search on the Payment Requests list | Behave exactly as before |
| RG-05 | Automated test suite | `php artisan test` passes |

### User Acceptance Tests

| ID | Scenario | Expected Result |
|---|---|---|
| UAT-01 | A Finance user searches for a vendor they use every day | The vendor is found by typing part of the name, and the right company's entry can be recognised by its code |
| UAT-02 | A Finance user builds a payment request for one company's vendor | Only that company's invoices are offered |

---

## Expected Results

- Every Vendor and Customer entry on the Payment Requests screens is unique and labelled
  "Name (Code)".
- Typing narrows the list to matching entries only, on both the name and the code.
- A selected entry returns the invoices, or payment requests, of that record only.
- All other searches, filters and the payment request creation flow are unchanged.

---

## Pass/Fail Criteria

**Pass:** every Happy Path, Negative, Security and Regression scenario gives its expected result,
the automated suite passes, and the UAT scenarios are signed off by Finance.

**Fail:** any of the following:

- a search list still shows non-matching entries while typing;
- a selected entry returns another company's invoices;
- any other filter changes behaviour;
- a user can reach invoices or payment requests they could not see before.

---

## Test Execution Checklist

- [x] HP-04, HP-05, HP-07, HP-08, HP-09: automated in `PaymentRequestVendorCustomerFilterTest`.
  HP-04/05 were also checked against live data: "AL RABIYA AUTO ACCESSORIES TR" returns 8 eligible
  invoices by name, 7 for its GIL record and 1 for its GGL record.
- [ ] HP-01, HP-02, HP-03, HP-06: labels and typing in the browser. These need a manual QA pass.
- [x] NG-04: automated, and checked live (`422`).
- [ ] NG-01, NG-02, NG-03, NG-05: in the browser. These need a manual QA pass.
- [x] SC-01, SC-02: automated.
- [x] RG-02: automated.
- [x] RG-05: `php artisan test` run on 2026-09-29. 405 of 409 passed. The 4 failures are
  pre-existing, fail identically without this change, and are listed in `ai/issues/technical-debt.md`.
- [ ] RG-01, RG-03, RG-04: in the browser. These need a manual QA pass.
- [ ] UAT-01 – UAT-02 Finance user acceptance
- [ ] Deployed to production with the normal build (`npm run build`)

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
