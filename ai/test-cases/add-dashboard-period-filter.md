# Test Cases: Dashboard Period Filter

- **Change Request:** [add-dashboard-period-filter.md](../change-requests/add-dashboard-period-filter.md)
- **Risk Rating:** Low–Medium
- **Date:** 2026-09-11
- **Automated coverage:** `tests/Feature/DashboardPeriodFilterTest.php`

## Objective

Prove that the Dashboard reports every figure against one user-selected period, that the period is
resolved and bounded safely on the server, and that the existing per-user visibility scoping is
untouched by the new filter.

## Scope

**In scope** — `GET /api/dashboard` (`from`, `to`, `all` parameters, the new `period` response
object, and every metric it returns), the period control and period labelling on
`resources/js/pages/DashboardPage.vue`, and the per-metric date anchors.

**Out of scope** — the Reports page, the Excel export, the key-authenticated export API, the Invoice
Log, Payment Requests and Approvals. These must be regression-checked as *unchanged* (RG-1…RG-3) but
no new behaviour is expected of them.

The highest-risk area is not the filter arithmetic — it is that the same four cards now describe
four differently-dated populations, and that the headline figures change value on release. SC-1…SC-4
and UAT-1 exist for that.

## Environment and Preconditions

| Item | Value |
| --- | --- |
| Endpoint | `GET /api/dashboard` (auth required, all roles) |
| Parameters | `from` / `to` as `YYYY-MM`; `all=1` for All Time |
| Default period | Year to Date — January of the current year through the current month |
| Maximum span | 120 months |
| Trend chart cap | 24 bars, most recent months of the span |
| Not period-filtered | the approver "waiting for your approval" banner |
| Build | Frontend assets rebuilt (`npm run build`) and deployed with the backend change |

### Per-metric date anchors (the thing under test)

| Metric | Anchored on |
| --- | --- |
| Total Invoices, Awaiting Posting, In Approval | `invoices.submitted_at` |
| Paid | `payment_requests.paid_at` |
| Requests by Status (donut) | `invoices.submitted_at` |
| Monthly Flow — Submitted | `invoices.submitted_at` |
| Monthly Flow — Paid | `payment_requests.paid_at` |
| Top Vendors, Spend by Business Unit | `invoices.invoice_date` |
| Recent Requests | `invoices.submitted_at` |
| Approver queue banner | not filtered — live count |

### Test Data

- **TD-1** — an invoice submitted **2026-03-10**, invoice date **2026-02-25**, still `submitted`
  status. Exercises the gap between invoice date and submission date.
- **TD-2** — an invoice submitted **2025-11-02**, paid **2026-04-18**. The cross-period record: it
  must appear in the Paid figure for April 2026 but **not** in the Total Invoices figure for that
  same period. This is the single most important row in the set.
- **TD-3** — an invoice submitted **2026-04-05** and paid **2026-04-20**, entirely inside one month.
- **TD-4** — an invoice with `submitted_at` **NULL** (legacy/imported). Must appear under All Time
  and must be absent from every bounded period, with no error.
- **TD-5** — a paid invoice whose payment request has `paid_at` **NULL**. Must not appear in any
  bounded Paid figure, and must not crash the query.
- **TD-6** — an invoice submitted on **2026-01-01 00:00:00** and one on **2026-01-31 23:59:59**, for
  the month-boundary inclusivity cases.
- **TD-7** — a payment request sitting `in_approval` at the current stage of an approver user,
  submitted **2024-06-01** (far outside any recent period), for the unfiltered-banner case.
- **TD-8** — users at each role: `requester`, `approver`, `finance`, `admin`, plus a second requester
  with their own invoices, for the visibility-scoping cases.

---

## Test Scenarios

### Happy Path

| ID | Scenario | Steps | Expected Result |
| --- | --- | --- | --- |
| HP-1 | Default period | `GET /api/dashboard` with no parameters | `200`. `period.from` is January of the current year, `period.to` is the current month, `period.all_time` is `false`, `period.label` reads as a Year-to-Date range. Every card reflects that window. |
| HP-2 | Explicit month range | `GET /api/dashboard?from=2026-01&to=2026-04` | `200`. `period` echoes exactly that range. Figures cover 1 Jan 2026 00:00:00 through 30 Apr 2026 23:59:59. |
| HP-3 | Single month | `from=2026-04&to=2026-04` | `200`. Figures cover April 2026 only. TD-3 is counted in both Total Invoices and Paid. |
| HP-4 | All Time | `GET /api/dashboard?all=1` | `200`. `period.all_time` is `true`, `from`/`to` are `null`. **Every figure matches the pre-change Dashboard exactly**, except the Paid card, which was previously this-month-only and is now all-time. |
| HP-5 | Cross-period payment | `from=2026-04&to=2026-04` | TD-2 appears in the **Paid** count and amount, and does **not** appear in Total Invoices. This is correct-by-design, not a defect. |
| HP-6 | Trend chart follows the span | `from=2026-01&to=2026-06` | `monthly` has exactly 6 entries, Jan–Jun 2026, in chronological order. |
| HP-7 | Presets, UI | On the Dashboard, click each preset in turn | The From/To inputs update to match, the request re-fires once per selection, and the period label on the page and in each card caption updates to match. |
| HP-8 | Spend charts use invoice date | `from=2026-02&to=2026-02` | TD-1 (invoice date 2026-02-25, submitted March) contributes to Top Vendors and Spend by Business Unit, but **not** to Total Invoices. |
| HP-9 | Recent Requests respects the period | Set a period containing exactly 3 submissions | The Recent Requests table shows those 3 rows only, newest first. |

### Negative

| ID | Scenario | Steps | Expected Result |
| --- | --- | --- | --- |
| NG-1 | Malformed month | `from=2026-13`, `from=not-a-date`, `from=2026-04-15`, `from=<script>` | `422` with a validation error on `from`. No query executed. |
| NG-2 | Inverted range | `from=2026-06&to=2026-01` | Handled, not an error page: the range is normalised (swapped) and `period` echoes the corrected order. Figures are for Jan–Jun 2026. |
| NG-3 | Excessive span | `from=1900-01&to=2099-12` | The span is capped at 120 months; `period` echoes the capped range. Response returns within normal time; no timeout, no memory error. |
| NG-4 | Only one bound supplied | `from=2026-03` alone; then `to=2026-03` alone | `200`. The missing bound falls back sensibly (open end resolved to the current month / to the earliest data respectively) and `period` states what was resolved. |
| NG-5 | Empty period | A range with no matching records at all | `200`. All counts `0`, all amounts `0`, charts render empty states rather than breaking. No JavaScript console errors. |
| NG-6 | Missing event dates | With TD-4 and TD-5 present, request any bounded period | Both rows are excluded silently, no error. Under `all=1` TD-4 is included in Total Invoices. |
| NG-7 | Trend chart cap | `from=2015-01&to=2026-09` | `monthly` contains at most 24 entries, being the **most recent** 24 months of the span. Chart renders legibly. |

### Security

| ID | Scenario | Steps | Expected Result |
| --- | --- | --- | --- |
| SEC-1 | Unauthenticated | `GET /api/dashboard?from=2026-01&to=2026-04` with no session | `401`. The period parameters grant nothing. |
| SEC-2 | Scoping holds under a filter — requester | As a requester (TD-8), request a wide period and `all=1` | Only that user's own invoices are counted, at every period setting. Widening the range never surfaces another user's records. |
| SEC-3 | Scoping holds under a filter — approver | As an approver, same | Only own submissions plus invoices in payment requests routed to them. Identical set to the pre-change Dashboard under `all=1`. |
| SEC-4 | Scoping holds under a filter — finance/admin | As finance and as admin | Full visibility, unchanged from pre-change behaviour under `all=1`. |
| SEC-5 | Injection through the parameters | `from=2026-01' OR '1'='1`, `to=2026-01; DROP TABLE invoices` | `422` from validation. Verify in the query log that no malformed SQL was constructed. `invoices` table intact. |
| SEC-6 | No new fields exposed | Diff the response keys against the pre-change payload | Only the additive `period` object is new; the `paid_this_month` card key is renamed to `paid`. No invoice-level fields added beyond what Recent Requests already returned. |
| SEC-7 | Resource exhaustion | Repeated requests at the maximum span | No unbounded query; response times stay within the same order as an ordinary request. |

### Regression

| ID | Scenario | Steps | Expected Result |
| --- | --- | --- | --- |
| RG-1 | Reports page unchanged | Run a report with the same From/To months as a Dashboard period | Reports still filters on invoice date. **Totals may legitimately differ from the Dashboard** — record the difference and confirm it is explained by the differing anchors, not by a bug. |
| RG-2 | Excel / API export unchanged | Export via both paths | Byte-for-byte equivalent output to pre-change, for the same filters. |
| RG-3 | Other screens unchanged | Invoice Log, Payment Requests, Approvals, Audit Log | No change in behaviour, filters, or figures. |
| RG-4 | Approver banner is not period-filtered | As the TD-7 approver, set the period to a month containing none of their pending work | The banner still shows the correct live pending count and the "Review now" action still works. **A pending approval must never be hidden by the period filter.** |
| RG-5 | Endpoint smoke test | Run `tests/Feature/EndpointSmokeTest.php` | Still passes; `GET /api/dashboard` with no parameters returns `200`. |
| RG-6 | Existing response keys | Confirm `cards`, `my_queue`, `status_distribution`, `monthly`, `top_vendors`, `by_business_unit`, `recent` all still present with the same shapes | Present and unchanged. |

### User Acceptance

| ID | Scenario | Participants | Expected Result |
| --- | --- | --- | --- |
| UAT-1 | Old figures are recoverable | Finance, management | With All Time selected, the cards reproduce the numbers users saw before the release. Confirms nothing was lost. |
| UAT-2 | A known month reconciles | Finance | For a closed month, the Dashboard's Paid figure matches Finance's own record of payments made in that month. |
| UAT-3 | The mixed-window problem is gone | Department heads | Every figure on screen is labelled with the period it describes; no card silently uses a different window. |
| UAT-4 | The per-metric anchor is understandable | Finance, management | Shown HP-5 (an invoice paid in the period but submitted before it), users can explain why the cards differ from the on-screen note alone, without asking IT. |
| UAT-5 | The control is usable | All roles | Setting a custom range and using the presets is discoverable and takes no instruction. |

---

## Expected Results

1. One selected period governs every card, every chart and the recent-requests list.
2. The period in force is visible on screen and echoed by the API in `period`.
3. Each metric is anchored on its own event date, per the table above.
4. All Time reproduces the pre-change view.
5. The approver banner remains a live, unfiltered work queue.
6. Per-user visibility scoping is identical to pre-change at every role and every period.
7. Malformed, inverted and excessive ranges are handled server-side without error pages or timeouts.

## Pass/Fail Criteria

**Pass** requires all of:

- Every Happy Path, Negative and Security case passes as written.
- RG-4 passes — no pending approval is hidden by the filter. *Failure here blocks release outright.*
- SEC-2…SEC-4 pass — scoping is provably unchanged. *Failure here blocks release outright.*
- HP-4 passes — All Time reproduces the old figures.
- UAT-1 and UAT-3 are signed off by Finance.
- `php artisan test` is green; `./vendor/bin/pint` reports no changes.
- No JavaScript console errors at any preset or custom range.

**Fail** on any of: a pending approval hidden by the period; a user seeing a record outside their
scope at any period; a 500 or timeout from any parameter value; the All Time preset not matching the
pre-change figures.

## Test Execution Checklist

- [ ] TD-1 … TD-8 seeded
- [ ] Pre-change Dashboard figures captured per role, for the HP-4 / UAT-1 comparison
- [ ] Count of records with missing event dates recorded (CR Mitigation 6) and judged immaterial
- [ ] Indexes on `invoices.submitted_at` and `payment_requests.paid_at` confirmed (CR Mitigation 10)
- [ ] HP-1 … HP-9
- [ ] NG-1 … NG-7
- [ ] SEC-1 … SEC-7
- [ ] RG-1 … RG-6
- [ ] UAT-1 … UAT-5
- [ ] `php artisan test`
- [ ] `./vendor/bin/pint`
- [ ] Dashboard load time measured at the default period and at All Time on production-like volumes
- [ ] Pre-release user communication sent (CR Mitigation 1)
- [ ] `/ai` docs updated — `api-contracts.md`, `features/feature-overview.md`

## Sign-Off

| Role | Name | Result | Date |
| --- | --- | --- | --- |
| QA Lead | | Pass / Fail | |
| Business Owner (Finance) | | Accepted / Rejected | |
| UAT Sign-Off | | Accepted / Rejected | |
