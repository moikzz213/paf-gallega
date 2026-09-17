# Change Request

## Subject

Add a period filter (From month → To month) to the Dashboard, and make every card and chart report on that one selected period.

---

## Affected Areas

### Features

- Dashboard — the landing screen every authenticated user sees after login
- The four KPI cards: Total Invoices, Awaiting Posting, In Approval, Paid This Month
- The four charts: Requests by Status (donut), Monthly Flow — Submitted vs Paid, Top Vendors by Spend, Spend by Business Unit
- The Recent Requests table on the Dashboard
- The approver call-to-action banner ("N requests are waiting for your approval")
- Not affected: the Reports page, the Excel export, the key-authenticated export API, the Invoice Log, Payment Requests, Approvals

### Modules

- Dashboard / reporting module (read-only analytics)

### Controllers

- `app/Http/Controllers/DashboardController.php` → `index()` — the whole of the change on the server. It currently accepts **no** request input at all; it will gain validated `from` / `to` month parameters and apply them to every metric it returns.

### Services

- None. The Dashboard bypasses the service layer and queries models directly; this change does not alter that.
- `App\Support\InvoiceReport` — not used by the Dashboard, and deliberately left alone. Its date filter anchors on `invoice_date`; the Dashboard needs per-metric event dates, so the two remain separate definitions (see Risk 4).
- `App\Services\AuditLogger` — not affected. Viewing a dashboard is a read and is not audited today.

### Frontend Components

- `resources/js/pages/DashboardPage.vue`
  - line 18 — the single `api.get('/dashboard')` call on mount, which will gain query parameters and must re-fire when the period changes
  - lines 22–32 — the `cards` computed, including the hard-coded captions `'all time'` and the title `'Paid This Month'`
  - the four chart computeds and the Recent Requests table, all of which consume whatever the API returns and need no structural change
- No new shared component is required; the filter is a page-local control built from existing Vuetify inputs.

### APIs

- Internal: `GET /api/dashboard` (registered at `routes/web.php:55`) — **additive** contract change. Three optional query parameters are accepted (`from` and `to` as `YYYY-MM`, plus `all=1` to select All Time); the response gains a `period` object echoing the resolved range. Sending no parameters still returns a valid payload.
- One key is renamed: the `paid_this_month` card becomes `paid`, because it is no longer a this-month figure. The endpoint has exactly one consumer, updated in the same change, so nothing external breaks — but it is a rename, not a pure addition.
- No external or third-party API impact. No authentication change.

### Database

- No schema change. No migration, no new column, index, constraint, view, or stored procedure.
- Read-path impact only. The added filtering is a date predicate on the invoice submission timestamp, on the invoice date, and on the payment date inside the existing payment-request sub-query.
- Recommended verification: confirm indexes exist on `invoices.submitted_at` and `payment_requests.paid_at`. Without them the new date predicates force full scans on the two largest tables — on current data volumes this is not expected to be material, but it is the one database-side thing worth checking before sign-off.
- Net query volume is unchanged: the same number of queries, each returning a narrower slice than the present all-time aggregates.

### Security

- No change to authentication, roles, or permissions.
- **Data scoping is preserved unchanged.** Every Dashboard query already runs through `Invoice::scopeVisibleTo($user)`; the period filter is applied *on top of* that scope, never in place of it. A user cannot widen what they can see by widening the date range — only see more or fewer of the records already visible to them.
- No new fields are exposed. The same aggregates, over a user-chosen window.
- The two new parameters are user-supplied and must be validated to a strict `YYYY-MM` format with a bounded range, so they cannot be used to inject into a query or to request an absurd span.

### Infrastructure

- No environment variables, queues, workers, cron jobs, storage, or cache changes.
- Deployment requires a frontend asset rebuild (`npm run build`), because `DashboardPage.vue` compiles into `public/build/`.

---

## Emergency Change Assessment

### Business Continuity

Assessment: No

Justification: No business process is interrupted. The Dashboard loads and every number it shows is arithmetically correct for the window it uses. Nothing in the invoice, approval, or payment workflow depends on it.

### Workaround Availability

Assessment: Yes — a partial workaround exists

Justification: The Reports page already offers date-range filtering with export, so anyone who needs period-specific figures can obtain them there. The workaround does not cover the Dashboard's at-a-glance role, but it does mean no information is unobtainable.

### Operational Impact

Assessment: No — but with a standing accuracy concern

Justification: Delay causes no financial or operational damage. It does prolong a genuine misreading risk: because three cards are all-time and one is this-month, a reader comparing them side by side is comparing different windows without being told. That is a reporting-quality issue, not a continuity one.

### Timeline Constraints

Assessment: No

Justification: No deadline, audit, or regulatory date forces a bypass of normal assessment. The change is self-contained and fits an ordinary release cycle.

Emergency Change Classification: **No**

Reason: A reporting-consistency and usability improvement with an available workaround on the Reports page, no continuity, security, or compliance driver, and no timeline pressure. It should follow the standard change process.

---

## Risk

### Risk Level

**Low–Medium.** Low in technical terms — one controller, one screen, no schema or permission change, and the change is confined to read-only analytics. The Medium component is entirely about *interpretation*: published numbers on the most-viewed screen in the application will change value on the day of release, and stakeholders who have anchored on the current figures need to be told why.

### Identified Risks

**Business / Interpretation**

1. **Headline numbers change on release day.** "Total Invoices" today is an all-time count. Under a default period it becomes a period count and will drop — potentially by a large multiple. Anyone who has been tracking that number, screenshotting it, or quoting it in a meeting will see an unexplained fall and may report it as data loss.
2. **Per-metric event dates are correct but need explaining.** Each card is filtered on the date that is meaningful for it: when the invoice entered the system, or when it was actually paid. This is the accurate reading, but it means the four cards no longer describe one identical set of invoices — a period can contain invoices that were paid in it but submitted before it. Card captions and a short help note must make this explicit, or "the numbers don't add up" reports will follow.
3. **"Paid This Month" changes name and meaning.** Its title becomes period-driven. A user with the period set to a past quarter sees a "Paid" figure that is not this month's, which is correct but contrary to years of habit.

**Technical**

4. **Two different definitions of "a period" in one application.** The Reports page filters on invoice date; the Dashboard will filter on event dates. The same From/To months will legitimately return different totals on the two screens. This was an explicit decision, not an oversight, but it must be documented or it will be raised as a defect.
5. **Invoices with no event date fall out of the window.** Any invoice whose submission timestamp is missing, or any paid invoice whose payment request has no payment date recorded, will not appear in a filtered period even though it appears in the all-time view today. Legacy or imported records are the likely candidates. This must be quantified before release.
6. **An unbounded or inverted range.** A user can ask for a To month earlier than the From month, or a span of decades. Both must be handled — the first by normalising or rejecting, the second by capping — so the screen cannot be made to hang.
7. **The trend chart's bar count follows the span.** The Monthly Flow chart currently draws exactly six bars. Driven by the selected period it could be asked to draw hundreds, which would be unreadable and slow to render.
8. **Deployment mismatch.** If the rebuilt frontend ships without the backend change, the filter control appears but the figures ignore it — silently wrong numbers, which is worse than an error. Both must be released together.

**Security**

9. **Parameter validation is the only new attack surface.** Two user-supplied values reach a database query. Strict format validation and a bounded range are the control; they must be server-side, not only enforced by the picker in the browser.
10. **No change to who sees what.** Confirmed: the existing visibility scoping stays in front of the new filter on every query. Worth an explicit regression test rather than an assumption.

**Operational**

11. **Support noise in the first days after release**, driven mainly by Risks 1 and 3, concentrated among Finance and management users who read the Dashboard daily.

### Mitigation Actions

1. **Communicate before deployment.** A short note to Finance, department heads, and regular Dashboard users explaining that the figures are now period-based, what the default period is, and how to get back to the old view (the All Time preset). This is the single most effective mitigation for the largest risk.
2. **Keep All Time available as a preset**, so the previous all-time reading of every card is one click away and nothing is taken from anyone.
3. **Label the period everywhere it matters.** The selected range appears on the page and in each card's caption, so no figure is ever shown without the window it describes. Add a brief note explaining that each card uses its own relevant date.
4. **Validate server-side.** Accept only `YYYY-MM`; reject anything else; swap From and To if inverted; cap the span at a defined maximum; treat both parameters as optional and fall back to the default period.
5. **Cap the trend chart** at a fixed maximum number of bars, showing the most recent months of the span when it is longer.
6. **Quantify the missing-date population** before release — count invoices with no submission timestamp and paid invoices with no payment date — and either accept the number as immaterial or correct the data first.
7. **Leave the approver banner outside the filter.** "Requests waiting for your approval" is a live to-do count, not a historical metric; filtering it by a past period would hide real work. It stays as-is, and the screen should make clear that it is not period-scoped.
8. **Regression-test the visibility scoping** at each role, with a period applied, to prove the filter cannot widen anyone's view.
9. **Deploy backend and rebuilt assets in a single atomic release.**
10. **Confirm indexes** on the submission and payment date columns before release.

---

## Description

The Dashboard is the first screen every user sees, and today its four KPI cards do not agree on what period they describe. Three of them — Total Invoices, Awaiting Posting and In Approval — count everything ever recorded. The fourth, Paid This Month, counts only the current calendar month. The charts are inconsistent in a third way again: the trend chart is fixed to the last six months, while the status donut, the top-vendor chart and the business-unit chart are all-time. Nothing on the screen tells the reader which window any given figure uses, so four numbers presented as a row of peers are in fact answering four different questions.

This change introduces a single period control at the top of the Dashboard — a From month and a To month, with quick presets for This Month, Last 3 Months, Last 6 Months, Year to Date, Last 12 Months and All Time. Every card, every chart and the recent-requests list then reports on that one selected period, and the selected range is displayed alongside the figures so it can never be misread again.

Each metric is filtered on the date that is meaningful for that metric rather than on a single shared date column. Counts of work that entered or is moving through the system are measured by when the request was submitted. The paid figure is measured by when payment actually happened. Spend analysis by vendor and business unit follows the invoice's own date. This gives the most accurate reading of each individual number, at the cost that the four cards no longer describe an identical set of invoices — a deliberate trade-off, made explicit on screen.

One element deliberately stays outside the filter: the banner telling an approver how many requests are waiting for them. That is a live work queue, not a historical statistic, and hiding items from it because they fall outside a chosen date range would cause real approvals to be missed.

The default period on first load is Year to Date. All Time remains available as a preset, so the current all-time view of every card is never more than one click away.

---

## Reason for Change

Two business problems, one root cause.

The first is credibility. A management dashboard whose numbers silently use different time windows invites wrong conclusions. Placing an all-time invoice count next to a this-month payment count implies a relationship between them that does not exist, and there is nothing on screen to warn the reader. Figures read off this screen make their way into conversations and updates, and at present they cannot be safely compared to one another.

The second is flexibility. The Dashboard can currently answer exactly one question — roughly, "everything, plus this month's payments." It cannot answer "how did last quarter look", "what did we do in the last financial year", or "compare this month to the same month last year." Users needing any of those must leave for the Reports page and assemble the answer manually, which is slower and produces inconsistent working.

A single, visible, user-controlled period solves both: every figure becomes comparable to every other figure, and the screen becomes able to answer any period question asked of it.

---

## Impact

### Business Impact

Positive and material for a low-cost change. Management gains a dashboard whose figures can be trusted against each other and can be pointed at any period of interest — month-end review, quarterly reporting, year-on-year comparison — without leaving the screen. No business rule, approval route, workflow, or financial calculation is touched; this change alters only how existing information is presented.

The cost is a one-off adjustment period. Figures that people have grown used to will change on release day, and that needs to be communicated in advance rather than discovered.

### Technical Impact

Two files change: one controller and one screen, plus a frontend asset rebuild. No migration, no new dependency, no new service, no change to any existing response field. The Dashboard endpoint becomes parameterised, which is the pattern the Reports, Invoice Log and Payment Request endpoints already follow, so the change moves the Dashboard toward the existing house convention rather than away from it.

### Security Impact

Minimal, and no change to who can see what. The existing per-user visibility scoping is applied to every Dashboard query today and remains in front of the new date filter, so the period control can only narrow or widen the time window within what a user is already entitled to see. The one genuinely new element is two user-supplied parameters reaching the database; strict server-side format validation and a capped range are the required control, and they are the subject of specific test coverage.

### User Impact

Every user sees a new control at the top of the Dashboard and different starting figures. Finance and management users, who read this screen most, feel it most. Users who never touch the filter still get a consistent, labelled set of figures rather than today's mixed windows — which is the point of the change.

Approvers are unaffected in their day-to-day work: their pending-approval banner behaves exactly as before.

### Reporting Impact

The Reports page, the Excel export and the key-authenticated export API are untouched and produce identical output before and after this change. Note for users: the Reports page measures a date range by invoice date, while the Dashboard measures by each metric's own event date, so the same From/To months can legitimately produce different totals on the two screens. This should be stated in the user-facing note accompanying the release.

### Compliance Impact

None. No audited action changes, no retention or data-handling change, no new personal or financial data exposure. Dashboard viewing is not an audited event today and does not become one.

---

## Rollout Plan

1. **Development** — add validated `from` / `to` month parameters to the Dashboard endpoint and apply them, per metric, on top of the existing visibility scoping; add the period control, presets and period labelling to the Dashboard screen; cap the trend chart's bar count; leave the approver banner unfiltered.
2. **Unit Testing** — feature tests covering: the default period when no parameters are sent; a specified range; an inverted range; a malformed value; an excessive span; the All Time preset; and a per-role test proving visibility scoping still applies with a period set.
3. **Code Review** — confirm every Dashboard query still passes through the visibility scope, confirm validation is server-side, confirm no existing response key changed name or shape.
4. **QA Testing** — exercise every preset and a range of custom spans at every role, including periods containing no data, and confirm each card's caption matches the selected period.
5. **UAT Testing** — Finance and management users confirm the figures match their own expectations for a known period, and confirm the All Time preset reproduces today's numbers.
6. **Staging Deployment** — deploy backend plus rebuilt assets; measure Dashboard load time on production-like volumes at both the default period and All Time; run the count of records with missing event dates.
7. **Production Deployment** — single atomic release of backend and compiled assets, preceded by the user communication described in Mitigation 1.
8. **Post Deployment Verification** — load the Dashboard, confirm the default period renders, switch to All Time and confirm the figures match the pre-release screen; check application logs for validation errors on the new parameters and for slow queries against the invoice and payment-request tables.

---

## Backout Plan

1. **Disable feature** — revert the Dashboard screen so the period control is not rendered and the endpoint is called with no parameters. Because the parameters are optional and the endpoint falls back to its default behaviour, this alone restores a working screen.
2. **Restore previous version** — redeploy the prior release artifact: the previous `DashboardController.php` and the previous compiled `public/build/` assets. This restores the exact pre-change figures.
3. **Rollback database changes** — not applicable; no migration is included in this change.
4. **Restore backup** — not applicable. This change performs no writes and transforms no data, so no restore can be required.
5. **Validate business operations** — load the Dashboard at each role and confirm the original figures are back.
6. **Notify stakeholders** — inform the users notified at release that the Dashboard has been reverted, and why.

---

## Approval Requirements

| Role | Name | Decision | Date |
|------|------|----------|------|
| Requestor | | | |
| Department Manager | | | |
| IT Manager | | | |
| Business Owner (Finance) | | | |
| CAB Approval | Not required — standard, low-risk, reversible change | | |

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-09-11

Risk Rating: **Low–Medium**

Emergency Change: **No**

Analysis Confidence: **High (90%)** — the entire server-side change is contained in one controller that was read in full, and the consuming screen is a single component that was also read in full.

Affected Features Confidence: **High (95%)** — the Dashboard endpoint has exactly one consumer, confirmed by search.

Business Impact Confidence: **Medium–High (80%)** — the mechanics are certain; how strongly individual stakeholders react to the headline figures changing is a judgement.

Security Impact Confidence: **High (95%)** — visibility scoping was verified in the source on every Dashboard query; the only new surface is two validated parameters.

Risk Assessment Confidence: **High (85%)** — the one open quantity is the number of legacy records with missing event dates, which is measured during staging (Mitigation 6).

---

## Technical Analysis Appendix

**Affected systems.** One internal endpoint (`GET /api/dashboard`, `routes/web.php:55`), its controller (`app/Http/Controllers/DashboardController.php`), and one screen (`resources/js/pages/DashboardPage.vue`). Nothing else consumes the endpoint.

**Current windows, for the record.** Total Invoices, Awaiting Posting, In Approval: all time. Paid This Month: current calendar month, by payment date. Requests by Status, Top Vendors, Spend by Business Unit: all time. Monthly Flow: a hard-coded loop over the last six months. Recent Requests: the latest eight, all time. Approver queue: live, all time — and correctly so.

**Proposed anchors.** Requests submitted in the period → submission timestamp. Payments made in the period → the payment date on the related payment request. Spend by vendor and business unit → invoice date. The approver queue is not filtered.

**Contract change.** Three optional query parameters in (`from`, `to`, `all`); one new `period` object in the response echoing the resolved range so the screen labels figures from the server's interpretation rather than its own. One key renamed, `cards.paid_this_month` → `cards.paid`; all others unchanged.

**Implementation note.** Period resolution, bounding and labelling live in `App\Support\DashboardPeriod`, alongside the existing `App\Support\InvoiceReport`, so the controller stays thin and the rules (inverted range swapped, span capped at 120 months, trend chart capped at 24 bars) are unit-testable in one place. Covered by `tests/Feature/DashboardPeriodFilterTest.php` — 16 cases.

**Integrations.** None. No external system consumes this endpoint, and the Excel and key-authenticated export paths read from a separate report definition that is not touched.

**Security controls.** Existing per-user visibility scoping (`Invoice::scopeVisibleTo` / `PaymentRequest::scopeVisibleTo`) remains the outermost filter on every query. New: strict server-side validation of the two date parameters, with a bounded maximum span.

**Architecture impact.** None structurally. The change brings the Dashboard endpoint in line with the parameterised read endpoints elsewhere in the application.
