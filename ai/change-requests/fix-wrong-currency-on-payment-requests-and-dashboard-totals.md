# Change Request

## Subject

Fix the wrong currency shown on payment requests whose invoices have been returned, and show
dashboard totals per currency

---

## Executive Summary

Two related display faults make amounts appear to be in UAE dirhams when they are not.

**1. Payment requests.** A payment request raised in US dollars, or any currency other than AED, can
be displayed as if it were in AED. The amount stays the same, but the currency label becomes "AED".
It happens on the Payment Requests list, the request page, the emailed approval page and the
printed Payment Approval Form (PAF). The cause is that a request does not record its own currency:
it borrows it from the invoices it holds. When those invoices leave the request (because it was
rejected, withdrawn, or had every invoice released), there is nothing left to borrow from, and
every screen falls back to AED. On current data at least five requests are affected. The most
serious is an OMR 19,500 request shown as AED 19,500, which understates it by roughly AED 175,000.

**2. Dashboard.** The "Awaiting Posting", "In Approval" and "Paid" cards add up invoice amounts in
every currency into one figure and label it AED. A USD 10,000 invoice counts as 10,000 towards an
"AED" total. The dashboard charts (monthly submitted and paid, top vendors, business units) mix
currencies in the same way.

This change makes every payment request **record its currency when it is raised** and keep it
permanently. Existing requests are repaired from their history. Where the history cannot prove the
currency, it is **left blank**, never assumed, and Finance updates those requests manually. The
dashboard cards show a **separate total for each currency** instead of one mixed figure.

The expected outcome is that every amount on screen and on the PAF is labelled with its true
currency, and dashboard totals can be relied on.

---

## Business Reason for Change

**Business challenge.** Rejected and withdrawn payment requests are kept as a permanent record and
are reviewed afterwards. A record showing USD 7,400 as AED 7,400, or OMR 19,500 as AED 19,500,
misstates the payment by its exchange rate. On the dashboard, adding amounts in different currencies
and calling the result AED produces figures that are simply wrong. They overstate or understate the
real position depending on the mix, and management reads them as headline numbers.

**Control.** A request's currency is a fact fixed when it is raised. It should be stored with the
request, not re-derived from invoices that can later be taken off it. A total is only meaningful in
one currency.

**Trust.** Users have noticed ("raised in USD but showing AED"). Wrong currency labels on financial
figures undermine confidence in the system as a whole.

---

## Affected Business Areas

| Area | Impact |
|------|--------|
| **Finance** | Correct currency on rejected and withdrawn requests. Dashboard totals broken down by currency. Updates the requests whose currency could not be recovered. |
| **Management (dashboard users)** | Dashboard cards show one total per currency, so no figure mixes currencies. |
| **Approvers** | Correct currency on the emailed approval page and the PAF of a rejected or withdrawn request. |
| **Requesters** | Correct currency when tracking a request that holds their invoices, and on their own dashboard. |
| **Payment Approval Form (PAF)** | The total, the "amount in" heading and the amount in words show the request's own currency. |
| **Payments in progress** | No change in behaviour. Requests that hold their invoices already show the right currency. |

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** No payment is affected. The wrong request labels are all on requests that cannot
be paid, and the dashboard is an overview, not a payment control.

### Workaround Availability

**Assessment:** Yes

**Justification:** The true currency can be confirmed from the invoices in the Invoice Log, and
accurate totals can be taken from the Reports page export.

### Operational Impact

**Assessment:** No

**Justification:** Delaying the fix prolongs the risk of misreading, but causes no financial loss by
itself.

### Timeline Constraints

**Assessment:** No

**Justification:** No deadline applies. It should be scheduled soon: affected records grow with every
rejection or withdrawal of a foreign-currency request, and the dashboard misstates its totals every
day.

**Emergency Change Classification:** No

**Reason:** A display and record-keeping defect that does not affect payments. A priority fix, not
an emergency.

---

## Risk Assessment

### Risk Level

**Low**

The change adds a stored currency to each payment request, repairs existing requests from their
history, and changes how the dashboard groups its totals. It does not change amounts, approvals,
routing or who can see what. The repair touches existing records, so it is preceded by a backup and
produces a list of everything it changed.

### Risks Identified

**Business risks**

1. **Some historical currencies cannot be recovered.** Requests rejected before mid-September 2026
   (35 of the 40 rejected) kept no record of which invoices they held, so their currency cannot be
   proven from the system. *Mitigation:* their currency is left **blank**, never guessed. They are
   listed for Finance, and updated manually once confirmed.
2. **A wrongly repaired request.** *Mitigation:* the repair uses only firm evidence: the invoices
   still on the request, the rejection history, and the invoices named in the request's own audit
   trail. Every request it changes is listed with the evidence used, for Finance to review.
3. **Busier dashboard cards.** A card with several currencies shows several lines instead of one
   figure. *Mitigation:* each line is one currency, the most-used first, so the card stays readable.
   Most periods involve only a few currencies.

**Operational risks**

4. **Repairing existing records.** *Mitigation:* a full database backup is taken first, and the
   repair only sets the new currency field. Nothing else on the request is altered.

**Security risks**

5. None identified. No change to access, approval authority or audit. The dashboard keeps showing
   each user only the invoices they may see.

### Risk Mitigation Plan

- Back up the database before the change is applied.
- Repair existing requests only from firm evidence, and leave the rest blank.
- Produce and review the list of repaired requests and the list of blank ones.
- Make new requests record their currency at the moment they are raised.
- Group every dashboard total by currency so no figure mixes currencies.

---

## Expected Business Impact

### Positive Impact

- Every payment request shows the currency it was raised in, permanently.
- Rejected and withdrawn records can be read and reported on without cross-checking invoices.
- The PAF of a rejected or withdrawn request states its value correctly, including the amount in
  words.
- Dashboard totals are true totals, one per currency.

### Potential Negative Impact

- About 35 older rejected requests show their amount with no currency until Finance updates them.
- Dashboard cards are slightly longer when several currencies are present.

### User Impact

No change to how anyone works. Users see correct currencies on records that were showing AED, and
per-currency totals on the dashboard.

### Reporting Impact

- Historical payment requests carry a reliable currency, or none where it is unknown.
- Dashboard cards are broken down by currency.
- The Reports page summary has the same mixed-currency total as the dashboard. It is not in this
  request (see Decision 5).

### Compliance Impact

Improves the accuracy of the payment record and of management figures. A request's currency is fixed
when it is raised and cannot be changed by later movements of its invoices.

---

## Decisions Recorded

Confirmed by stakeholders on 2026-10-14.

1. **Unrecoverable currencies are left blank.** Nothing is assumed. The amount is shown without a
   currency.
2. **Blank currencies are updated manually.** Finance confirms the currency of each blank request,
   and it is then set by hand. No new screen is built for this. The list of blank requests is
   handed to Finance after the repair.
3. **Dashboard totals are shown per currency.** Each card lists one total per currency instead of a
   single mixed figure.

## Decisions Confirmed After Review

4. **Dashboard charts.** *Confirmed: AED equivalent.* The monthly "Submitted vs Paid" chart and the "Top vendors" and "By business
   unit" charts also add amounts across currencies. A chart cannot show a separate total per
   currency the way a card can. *Recommendation:* convert these charts to AED-equivalent at the
   exchange rates configured in Master Data → Currencies (every currency in use has a rate), and
   label them "AED equivalent". The alternatives are to show only AED invoices in the charts, or to
   add a currency selector.
5. **Reports page summary.** *Confirmed: included in this change.* The total on the Reports page
   mixes currencies in the same way.
   *Recommendation:* apply the same per-currency breakdown there in this change, because it is the
   same fault and the same fix.

---

## Implementation Overview

1. Record the currency on each payment request when it is raised, taken from its invoices (a request
   already covers one currency only).
2. Show that recorded currency everywhere a request's amount appears: the Payment Requests list, the
   request page, the approvals queue, emails, the emailed approval page and the PAF. Where it is
   blank, show the amount without a currency.
3. Repair existing requests:
   - from the invoices they still hold;
   - for rejected requests, from the rejection history;
   - for withdrawn requests, from the invoices named in their audit trail.
4. Leave the currency blank where none of these proves it. Produce the list of repaired requests and
   the list of blank ones for Finance.
5. Change the dashboard cards to show one total per currency.
6. Apply Decisions 4 and 5 as confirmed.

---

## Rollout Plan

1. **Development:** Store the currency on new requests, display it everywhere, build the repair and
   its two lists, and break the dashboard totals down by currency. Add automated tests for: a request
   keeping its currency after rejection, withdrawal and release of its last invoice; a blank currency
   showing no label; and dashboard totals for a mix of currencies.
2. **Internal Validation:** Run the repair on a copy of the production data. Confirm the known cases:
   PAF-2026-00239 (USD), PAF-2026-00056 (USD), PAF-2026-00103 and PAF-2026-00104 (EUR) and
   PAF-2026-00120 (OMR). Confirm that live requests are unchanged, and that dashboard per-currency
   totals match the Reports export.
3. **QA Verification:** Check the list, request page, emails, emailed approval page and PAF for a
   non-AED request before and after its invoices are returned. Check the dashboard with one currency
   and with several.
4. **User Acceptance Testing:** Finance reviews the repair list and the blank list, confirms the
   known cases, and compares the dashboard figures with their own records.
5. **Production Deployment:** Back up the database, deploy, run the repair, and keep its output as
   the record of what changed. Hand the blank list to Finance.
6. **Post Deployment Monitoring:** For two weeks, confirm that newly rejected or withdrawn
   foreign-currency requests keep their currency. Track Finance's manual updates of the blank list.

---

## Backout Plan

1. **Suspend new functionality:** Revert the display to the previous behaviour, which ignores the
   stored currency. Revert the dashboard to its single totals.
2. **Restore previous application state:** Roll back the release. The new currency field can remain,
   unused, without effect.
3. **Restore backups if required:** Only if the repair is found to have altered anything other than
   the new currency field. The pre-deployment backup is the restore point.
4. **Validate business operations:** Confirm requests can be raised, approved and paid, that live
   requests show the right currency, and that the dashboard loads.
5. **Notify stakeholders:** Tell Finance and dashboard users the fix was withdrawn, and that rejected
   or withdrawn records and dashboard totals may again show AED.

---

## Approval Requirements

### Requestor

Name: ______________________  Signature: ______________________  Date: ____________

### Department Manager — Finance

Name: ______________________  Signature: ______________________  Date: ____________

### IT Manager

Name: ______________________  Signature: ______________________  Date: ____________

### Business Owner — Finance (Accounts Payable)

Name: ______________________  Signature: ______________________  Date: ____________

### CAB Approval (if applicable)

Not expected to be required: a Low-risk correction to how existing records and totals are displayed,
with a backed-up data repair.

Name: ______________________  Signature: ______________________  Date: ____________

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-10-14

Decisions Recorded: 2026-10-14

Risk Rating: Low

Emergency Change: No

Analysis Confidence: High (90%)

| Measure | Confidence |
|---------|------------|
| Analysis Confidence Score | 90% |
| Affected Features Confidence | 90% |
| Business Impact Confidence | 85% |
| Security Impact Confidence | 95% |
| Risk Assessment Confidence | 90% |

---

## Implementation Record

**Status:** Implemented 2026-10-14, with Decisions 4 and 5 confirmed as recommended (charts in AED
equivalent; Reports page summary per currency).

- New requests record their currency when raised. Every screen, email, the emailed approval page and
  the PAF show it. A blank currency is shown blank, so the amount appears without a code.
- **The repair is a separate step run by IT**, not an automatic part of the release. It previews by
  default and writes only with an explicit instruction, after a backup. On the development database
  the preview found **217** requests whose currency it can prove, and **34** to leave blank. That is
  32 rejected requests with no record of their invoices, plus PAF-2026-00056 and PAF-2026-00104,
  where an invoice's currency was changed after it left the request, so its current currency is not
  proof.
- **Manual update:** for each blank request Finance confirms, IT records the currency with a single
  command naming who confirmed it. The change is kept in the request's history.
- **Dashboard:** each card lists one total per currency. The three charts are labelled
  "AED equivalent". If a currency has no exchange rate, it is left out of the charts and a warning
  names it; the cards still include it.
- **Reports page:** the summary totals are per currency.
- **Rejection notice (found in production after the first fix).** A rejection email for a USD request
  (PAF-2026-00460) showed its total as AED, because the email is assembled after the invoices have
  left. Rejection no longer exists, but any notice still waiting to be sent at deployment now takes
  the currency from the invoices it lists. Production requests such as PAF-2026-00460 get their
  currency back when the repair is run there.
- **Validated:** every email that states a request's amount (approval required, reminder, approval
  query, fully approved, rejection) and the emailed approval page show the request's own currency
  in automated tests and in a read-only check against real USD and OMR requests.

**Verification:** automated tests cover a request keeping its currency after withdrawal, a blank
currency never showing as AED, the repair (preview, evidence and refusal cases), the manual setting,
dashboard cards and charts with mixed currencies, an unrated currency, and the report totals
(`tests/Feature/PaymentRequestCurrencyTest.php`). The full suite passes apart from four failures that
predate this change. User acceptance testing per the Rollout Plan is still to be done.

---

# Technical Analysis Appendix

Included because the cause, and how far it reaches, determine the fix.

### Cause — payment requests

A payment request stores no currency. Everywhere a request's amount is shown — screens, emails, the
emailed approval page and the PAF — the currency is worked out from the invoices it currently holds.
Rejection (historically), withdrawal and releasing an invoice all detach invoices from the request.
When none are left, the derived currency is empty, and both the screen and the document formatting
fall back to AED. Invoices themselves are consistent: on current data, no invoice's currency
disagrees with its lines, and every request that still holds invoices displays correctly.

### Extent on current data (read-only analysis, 2026-10-14)

| Group | Requests | Non-AED and shown wrongly | Currency recoverable |
|-------|----------|----------------------------|----------------------|
| Rejected, with rejection history | 5 | PAF-2026-00239 (USD 7,400) | Yes |
| Rejected, before rejection history existed | 35 | Unknown | No: left blank, updated manually |
| Withdrawn | 16 | PAF-2026-00056 (USD), PAF-2026-00103 (EUR), PAF-2026-00104 (EUR), PAF-2026-00120 (OMR 19,500) | Yes, from the audit trail |

### Cause — dashboard

The dashboard totals add `total_amount` across invoices without grouping by currency, and the screen
labels each total AED by default. On current data about 10% of invoices are in a currency other than
AED (USD, EUR, SAR, OMR, JOD, QAR, GBP, AUD), so any period containing one has a wrong headline
total. Every currency in use has an exchange rate configured, which is what makes the
AED-equivalent option in Decision 4 practical.

### Affected Systems

- **Payment requests:** a new stored currency, set at creation. Additive, plus a one-off repair of
  existing rows, preceded by a backup under the project's database safety rules.
- **Display:** the Payment Requests list, request page, approvals queue, notification emails, emailed
  approval page and PAF read the stored currency. Formatting no longer substitutes AED for a missing
  currency on a payment request.
- **Dashboard:** card totals grouped by currency; charts as decided under Decision 4.
