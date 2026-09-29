# Change Request

## Subject

Fix the Vendor and Customer search on Payment Requests, and show each vendor's and customer's code

---

## Executive Summary

When Finance creates a new payment request (PAF), they narrow the list of invoices ready for payment
using a set of search boxes: Department, Vendor name, Customer Name, Invoice No, Job No and
Currency. The Department search works as expected: typing "Finance" leaves only "Finance" in the
list of choices. The Vendor name and Customer Name searches do not. As the user types, the list of
choices fills with entries that do not match what was typed, or keeps showing entries from earlier
keystrokes, so the right vendor or customer is hard to find and easy to pick wrongly. The Vendor
filter on the main Payment Requests list has the same defect.

The cause is in how the vendor and customer master data is organised. The same vendor or customer
name is registered once for each group company. For example, "3PLOGISTICS" exists as VEN0008 - GIL
and as VEN0008 - GGL. About 1,440 vendor names and 1,400 customer names appear two or more times.
The search list shows only the name, so these entries look identical. That confuses users, and it
also confuses the search box, which expects every choice to be unique.

This change shows each entry with its code in brackets, for example "3PLOGISTICS (VEN0008 - GIL)"
and "3PLOGISTICS (VEN0008 - GGL)". Every choice in the list is then unique and recognisable. Picking
an entry brings back the invoices of that exact vendor or customer record only, not invoices of
every record with the same name.

The expected outcome is that the Vendor and Customer searches behave exactly like the Department
search, and that Finance can tell apart, and select, the right company's vendor or customer.

---

## Business Reason for Change

**Business challenge.** Choosing the right vendor is the first step in grouping invoices into a
payment request. Today the list shows repeated, identical-looking names that the user cannot tell
apart. The list also does not filter properly as the user types. Both problems slow Finance down on
every request and increase the chance of selecting the wrong entity's invoices for payment.

**Operational need.** With more than 3,700 active vendors and 3,500 active customers, Finance cannot
reasonably scroll to find a name. The search must filter correctly, and each choice must identify
which company's record it is.

**Control improvement.** Today, choosing a vendor name brings back invoices for that name under
every group company at once. Filtering by the exact vendor record makes it easier to build a
payment request for one entity without mixing another entity's invoices into view.

---

## Affected Business Areas

- **Departments:** Finance (the users who create payment requests).
- **Users:** Finance and Admin users who create payment requests, and anyone who searches the
  Payment Requests list by vendor.
- **Processes:**
  - Creating a payment request: finding the posted invoices to include in it (Vendor name and
    Customer Name searches).
  - Searching existing payment requests by vendor (Vendor filter on the Payment Requests list).
- **Reports:** None.

Approvers, invoice submitters, the approval journey, notifications and reporting are not affected.

---

## Emergency Change Assessment

### Business Continuity

Assessment: No

Justification: Payment requests can still be created. The defect makes finding a vendor or customer
slower and more error-prone, but it does not stop the process.

### Workaround Availability

Assessment: Yes, a workaround exists

Justification: Users can search by Invoice No or Job No instead. They can also type the full vendor
name and check the invoice list carefully before selecting. Both workarounds are slower and depend
on the user's care.

### Operational Impact

Assessment: No

Justification: Delay causes lost time and a higher risk of selection mistakes, but no direct
financial loss. Every request still passes through the full approval chain before payment.

### Timeline Constraints

Assessment: No

Justification: The change is small and fits the normal release cycle.

Emergency Change Classification: **No**

Reason: This is a usability defect with a workaround and no continuity, security or compliance
driver. It should follow the standard change process, with priority given to its daily impact on
Finance.

---

## Risk Assessment

### Risk Level

**Low**

### Risks Identified

**Business risks**

- **Narrower results by design.** Choosing "3PLOGISTICS (VEN0008 - GIL)" will no longer also show
  the GGL company's 3PLOGISTICS invoices. This is the intended improvement. Users who want both
  companies' invoices would clear the vendor search, or select one entity at a time.
- **Customer search follows the invoice line.** An invoice is found by customer only when one of its
  lines was recorded against that customer. This is how the customer search already works today.

**Operational risks**

- Users will see a slightly longer label in the list (name plus code). No training is needed.
- One active vendor ("Grand Spicy") has no code on record. It will show by name alone, and Master
  Data may wish to add its code.

**Security risks**

- None. Nothing changes in who can see which invoices or payment requests. The codes shown are
  already visible to these users elsewhere in the application.

### Risk Mitigation Plan

- Test the searches on the full production-size vendor and customer lists, where the repeated names
  exist, not on a small demo list.
- Confirm that selecting one company's vendor returns only that company's invoices, and that
  selecting the other company's entry returns the other's.
- Confirm the Department, Currency, Invoice No and Job No searches behave exactly as before.
- Brief Finance that the vendor and customer lists now show the code, and that each entry is one
  company's record.

---

## Expected Business Impact

### Positive Impact

- Finance can find a vendor or customer by typing part of the name, or part of the code, just as
  they do for Department.
- Each group company's vendor or customer can be told apart and selected on its own.
- Lower risk of bringing the wrong entity's invoices into a payment request.

### Potential Negative Impact

- Users who relied on one vendor name returning every entity's invoices at once will need to select
  each entity, or leave the vendor search empty.

### User Impact

Finance and Admin users see correctly filtered Vendor and Customer choices, each labelled with its
code. The screen is otherwise used exactly as before.

### Reporting Impact

None.

### Compliance Impact

None. The audit trail, approval chain and visibility rules are unchanged.

---

## Implementation Overview

- Label each Vendor and Customer choice with its code in brackets, on the New Payment Request screen
  and on the Vendor filter of the Payment Requests list.
- When a choice is selected, search for invoices belonging to that exact vendor or customer record
  rather than to every record with a matching name.
- Leave the existing name-based search in place for any other use, so nothing else in the
  application is affected.

No data is changed, no new data is stored, and no permission or approval rule changes.

---

## Rollout Plan

1. **Development:** label the choices with their codes and filter by the chosen record.
2. **Internal Validation:** check the searches against the full vendor and customer lists, including
   vendors registered under more than one company.
3. **QA Verification:** run the matching test case document.
4. **User Acceptance Testing:** a Finance user confirms that the Vendor and Customer searches behave
   like Department, and that choosing one company's vendor shows only that company's invoices.
5. **Production Deployment:** release with the normal application build.
6. **Post Deployment Monitoring:** confirm with Finance on the first working day that the searches
   behave correctly.

---

## Backout Plan

1. Suspend the change by redeploying the previous version of the application.
2. Restore the previous application state. No other step is needed, because no data is changed.
3. Restoring backups is not required, because no data is written or changed.
4. Validate that the New Payment Request screen and the Payment Requests list load and can be
   searched.
5. Notify Finance that the previous search behaviour has been restored, and explain the follow-up
   plan.

---

## Approval Requirements

### Requestor

Name: ________________________  Signature: ________________  Date: __________

### Department Manager

Name: ________________________  Signature: ________________  Date: __________

### IT Manager

Name: ________________________  Signature: ________________  Date: __________

### Business Owner (Finance)

Name: ________________________  Signature: ________________  Date: __________

### CAB Approval

Required: No. This is a low-risk defect fix and search refinement on one area of the application.

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 2026-09-29

**Reference:** INC-176271

**Risk Rating:** Low

**Emergency Change:** No

**Analysis Confidence:** High

| Confidence Area | Rating |
|---|---|
| Analysis Confidence | High. The cause was confirmed against the live master data and the search component's behaviour |
| Affected Features Confidence | High. The affected searches are all in the Payment Requests area |
| Business Impact Confidence | High. The narrower vendor results are intended and are named as such above |
| Security Impact Confidence | High. No change to visibility or permissions |
| Risk Assessment Confidence | High |

---

# Technical Analysis Appendix

*Included only to explain the cause, the effort and why the risk is low.*

**Cause.** The Vendor and Customer boxes are given a plain list of names. The search component uses
each choice's own text to track it while filtering. When a name is repeated, two choices share the
same identifier, and the on-screen list goes out of step with the filtered results. The live data
has 3,777 active vendors, of which 1,442 names are repeated, and 3,534 active customers, of which
1,405 names are repeated. Every name-and-code pair is unique.

**Approach.** Each choice will be identified by its vendor or customer record and labelled
"Name (Code)". The invoice searches gain a filter by the exact vendor record, and by the exact
customer record on the invoice's lines. The existing filters by name stay available, so nothing
else in the application is affected.

**Data readiness.** Every invoice is linked to its vendor record, so the vendor filter is complete.
Invoice lines are linked to a customer only where a customer was entered. This is the same basis
the customer search uses today.

**Scope.** Limited to the Payment Requests area: the New Payment Request dialog's Vendor name and
Customer Name boxes, the list page's Vendor filter, and the two invoice searches behind them. The
invoice entry form already identifies vendors and customers by record and is not affected.
