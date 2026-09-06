# Test Cases: 1000-Row Page Option on the Master Data Page

- **Change Request:** [add-1000-items-per-page-option-master-data-page.md](../change-requests/add-1000-items-per-page-option-master-data-page.md)
- **Risk Rating:** Low
- **Date:** 2026-09-02
- **Automated coverage:** `tests/Feature/MasterDataAccessTest.php::test_per_page_accepts_a_thousand_but_no_more`

## Scope

The Master Data table now offers `1000` in its "Items per page" selector, and
`MasterDataController::index()` accepts `per_page` up to 1000. Two things must hold together: the new
option works end to end, and nothing outside this page changed. The second half is where the real
risk sits — the option is bound locally in `MasterDataPage.vue` precisely so the shared
`ITEMS_PER_PAGE_OPTIONS` default in `resources/js/plugins/vuetify.js` stays as it was for every other
table.

## Environment and Preconditions

| Item | Value |
| --- | --- |
| Roles with access | `finance`, `admin` (route group `role:finance,admin` in [routes/web.php:96](../../routes/web.php#L96)) |
| Endpoint | `GET /api/master-data/{entity}` |
| Entities | `vendors`, `customers`, `business-units`, `departments`, `locations`, `currencies` |
| Default page size | 15 |
| Page-size options | 10, 15, 25, 50, 100, 1000 |
| Build | Frontend assets rebuilt (`npm run build`) and deployed with the backend change |

### Test Data

- **TD-1** — `vendors` seeded with **1500** active records, names spread across the alphabet, so
  page 1 at 1000 rows is full and page 2 is partial. Include at least one name starting with a digit
  and one lowercase, to exercise `ORDER BY name`.
- **TD-2** — `currencies` seeded with ~20 records, **at least three with `exchange_rate` NULL**, to
  exercise the per-row rate rendering on a large page.
- **TD-3** — `departments` seeded with **exactly 1000** records, for the boundary where the row count
  equals the page size.
- **TD-4** — `locations` seeded with **7** records, so a 1000-row page returns fewer rows than requested.
- **TD-5** — a `viewer`/requester-role user (any role outside finance/admin) for the authorization cases.

---

## 1. Functional — New Option (UI)

### TC-01 — The 1000 option is present and selectable

**Priority:** High
**Precondition:** Logged in as `finance`, TD-1 loaded, Master Data page open on Vendors.

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | Open the "Items per page" selector at the foot of the table | The list shows exactly `10, 15, 25, 50, 100, 1000` in that order, and no "All" / `-1` entry |
| 2 | Select `1000` | The table reloads, the loading indicator appears for the duration of the request, and 1000 rows render |
| 3 | Read the pagination summary | It reads `1-1000 of 1500` |
| 4 | Open the browser network tab and inspect the request | One request to `/api/master-data/vendors` with `per_page=1000`; response `200`, `per_page: 1000`, `data` length 1000 |

### TC-02 — Default page size is unchanged

**Priority:** High

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | Load the Master Data page fresh (new tab / hard reload) | The selector reads `15`, the table shows 15 rows, and the request sends `per_page=15` |
| 2 | Confirm no 1000-row request fires on first load | Only the 15-row request is made; `1000` is opt-in, never the initial load |

### TC-03 — Paging at 1000 rows

**Priority:** High
**Precondition:** TD-1, page size set to `1000`.

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | Click next page | Request sends `page=2&per_page=1000`; 500 rows render; summary reads `1001-1500 of 1500` |
| 2 | Click previous page | Returns to rows `1-1000 of 1500`, no duplicated or skipped records |
| 3 | Compare the last row of page 1 with the first row of page 2 | The `ORDER BY name, id` sequence is continuous — no record appears twice and none is missing |

### TC-04 — 1000 on every entity tab

**Priority:** High
**Precondition:** TD-1 through TD-4 loaded.

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | For each of the six tabs, select `1000` | Each returns `200` and renders without a console error |
| 2 | Check `departments` (TD-3, exactly 1000 rows) | Summary reads `1-1000 of 1000`; the next-page control is disabled |
| 3 | Check `locations` (TD-4, 7 rows) | 7 rows render; summary reads `1-7 of 7`; no empty-row padding |
| 4 | Check `currencies` (TD-2) | Rows with a rate show `1 XXX = n AED`; rows without show the "Not set — cannot be routed" chip |

### TC-05 — Tab switching resets paging

**Priority:** Medium

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | On Vendors at `1000`, go to page 2, then switch to the Locations tab | Page resets to 1, the search box clears, and the request is valid for the new entity |
| 2 | Observe the row count | Locations returns its 7 rows without an out-of-range page error or an empty table |

### TC-06 — Search combined with 1000

**Priority:** High

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | On Vendors at `1000`, type a term matching ~30 records | Request sends `q=<term>&per_page=1000`; only matching rows render; the summary total reflects the filtered count, not 1500 |
| 2 | Clear the search | The full 1500-record set returns at the same page size |
| 3 | Search a term matching nothing | The table shows its empty state, not an error |

### TC-07 — Switching down from 1000

**Priority:** Medium

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | From `1000`, select `15` | The table reloads at 15 rows, page resets appropriately, and the summary is consistent |
| 2 | Reload the page | The selector returns to the default `15` — no page size is persisted (per the CR, no stored preference exists) |

---

## 2. API — Boundary and Validation

Covered by the automated test; retain as an API-level checklist for QA and for regression after any
validation change.

### TC-08 — `per_page` boundary values

**Priority:** Critical

| # | Request | Expected Result |
| --- | --- | --- |
| 1 | `?per_page=1000` | `200`, `per_page: 1000`, at most 1000 records |
| 2 | `?per_page=999` | `200`, `per_page: 999` |
| 3 | `?per_page=1001` | `422` with a validation error on `per_page` |
| 4 | `?per_page=1` | `200`, one record |
| 5 | `?per_page=0` | `422` on `per_page` (`min:1`) |
| 6 | `per_page` omitted | `200`, `per_page: 15` |

### TC-09 — Malformed and hostile `per_page`

**Priority:** Critical

| # | Request | Expected Result |
| --- | --- | --- |
| 1 | `?per_page=999999` | `422`, not a 1000-row response and not an unbounded query |
| 2 | `?per_page=-1` | `422` — the "All" semantics must not be reachable by hand-crafting the parameter |
| 3 | `?per_page=abc` | `422` (`integer`) |
| 4 | `?per_page=15.5` | `422` (`integer`) |
| 5 | `?per_page=` (empty) | `200` at the default 15 (`nullable`) |
| 6 | `?per_page[]=1000` (array) | `422`, no 500 |

### TC-10 — Response contract unchanged

**Priority:** High

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | Compare the response body at `per_page=15` and `per_page=1000` | Identical shape — Laravel paginator keys (`data`, `total`, `per_page`, `current_page`, …), same fields per record |
| 2 | Inspect a record at `per_page=1000` | No additional or newly exposed columns compared with the 15-row response |

---

## 3. Regression — Nothing Else Changed

This is the primary regression objective of the change. See CR risk #2.

### TC-11 — Other tables keep the old options list

**Priority:** Critical

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | Open the "Items per page" selector on **Payment Requests** | Options are `10, 15, 25, 50, 100` — **no 1000** |
| 2 | Repeat on **Invoices** | No 1000 |
| 3 | Repeat on **Users** | No 1000 |
| 4 | Repeat on **Audit Log** | No 1000 |
| 5 | Repeat on **Approvals** | No 1000 |
| 6 | Repeat on **Reports** | No 1000 |
| 7 | Page through each of those tables at its largest available size | Behavior and row counts are unchanged from before the release |

### TC-12 — Existing Master Data page sizes still work

**Priority:** High

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | Cycle through `10, 15, 25, 50, 100` on Vendors | Each returns `200` with the requested row count and a correct summary |
| 2 | Confirm the previously available sizes are all still offered | None was removed by the change |

### TC-13 — Master Data CRUD, import and export unaffected

**Priority:** High

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | With the page size at `1000`, create a new vendor | Created successfully; the list refreshes and the new record appears in `ORDER BY name` position |
| 2 | Edit, then delete that record | Both succeed; the table refreshes at the same page size |
| 3 | Download the import template | Downloads unchanged |
| 4 | Run an import, then review the result at `1000` rows | Imported records are all visible in a single page (the workflow the change exists for) |

---

## 4. Security

### TC-14 — Authorization is unchanged regardless of page size

**Priority:** Critical
**Precondition:** TD-5.

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | As a non-finance/non-admin user, `GET /api/master-data/vendors?per_page=1000` | `403` — the larger page size grants no access |
| 2 | As a guest (unauthenticated), same request | `401` |
| 3 | As `admin`, same request | `200` — admins retain full access |

### TC-15 — No unbounded query path

**Priority:** Critical

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | Inspect the rendered selector markup / DOM for the Master Data table | No `-1` or "All" entry is present |
| 2 | Attempt `?per_page=-1` and `?per_page=1001` directly | Both `422`; check the query log confirms no query ran without a `LIMIT` |
| 3 | Review the SQL issued at `per_page=1000` | A single `SELECT … ORDER BY name, id LIMIT 1000 OFFSET n` plus the paginator's count query — no N+1 and no full-table fetch into memory |

---

## 5. Performance

### TC-16 — 1000-row page on the largest entity

**Priority:** High
**Precondition:** TD-1 (1500 vendors), production-like environment.

| # | Step | Expected Result / Record |
| --- | --- | --- |
| 1 | Select `1000` on Vendors and measure the API response time | Record the value; investigate before sign-off if it exceeds ~3s |
| 2 | Record the response payload size | Record the value; note it against the 15-row baseline |
| 3 | Measure time to interactive after the rows render | The page remains scrollable and responsive; no browser "page unresponsive" warning |
| 4 | Observe the loading indicator for the whole request | It stays visible until rows appear — the request must never look like a hang (CR risk #8) |
| 5 | Check the slow-query log for the master-data tables | No new slow-query entries; confirm an index supports `ORDER BY name` on `vendors` and `customers` (CR mitigation #6) |

### TC-17 — Repeated large requests

**Priority:** Medium

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | Switch rapidly between tabs with the page size at `1000` | No stale rows from the previous entity render; the last request's results win; no unhandled promise rejection in the console |
| 2 | Type quickly in the search box at `1000` rows | Requests do not pile up into an unresponsive page; the final result matches the final search term |

---

## 6. Deployment Verification

### TC-18 — Frontend and backend shipped together

**Priority:** Critical

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | After deployment, hard-reload the Master Data page and select `1000` | Rows render — **not** a `422` error notification. A 422 here means the compiled assets shipped without the controller change (CR risk #7) |
| 2 | Check the served asset hash for `MasterDataPage` | Matches the build produced from this release, not a cached previous bundle |
| 3 | Review API logs for the first hours after release | No `per_page` validation errors originating from the Master Data page |

---

## 7. User Acceptance

### TC-19 — Whole-list review is achievable

**Priority:** High
**Tester:** Finance/admin user who maintains master data.

| # | Step | Expected Result |
| --- | --- | --- |
| 1 | Select `1000` and use the browser's find-in-page to locate a known vendor | The record is found without paging — the intended workflow |
| 2 | Scan the full vendor list for duplicate names | Achievable in one page |
| 3 | On Currencies, identify every record missing an exchange rate | All are visible at once, each showing the "Not set" chip |
| 4 | Immediately after a bulk import, verify the imported set at `1000` | The full import is reviewable in a single page; the user confirms the load time is acceptable |

---

## Traceability

| Test Case | CR Risk / Requirement Covered |
| --- | --- |
| TC-01, TC-02, TC-04 | New option present; default unchanged (CR mitigation #3) |
| TC-08, TC-09, TC-18 | Backend validation ceiling raised, not removed (CR risk #1, #5, #7) |
| TC-11 | Shared `ITEMS_PER_PAGE_OPTIONS` untouched — page-scoped binding (CR risk #2) |
| TC-14, TC-15 | Authorization unchanged; no unbounded page (CR Security Impact) |
| TC-16, TC-17 | Query and payload weight (CR risk #3, #8, mitigation #5, #6) |
| TC-07 | No persisted page-size state (CR risk #4) |
| TC-12, TC-13 | Regression on existing Master Data behavior |
| TC-19 | Reason for Change satisfied |

### Automated vs Manual

| Coverage | Cases |
| --- | --- |
| Automated (`MasterDataAccessTest::test_per_page_accepts_a_thousand_but_no_more`) | TC-08 rows 1, 3, 5, 6 |
| Recommended for automation | TC-08 remaining rows, TC-09, TC-10, TC-14 |
| Manual / QA only | TC-01 – TC-07, TC-11 – TC-13, TC-15 – TC-19 |

## Exit Criteria

- Every Critical and High case passes.
- TC-11 passes on all six other tables — no 1000 option leaked into the shared default.
- TC-16 response time and payload size are recorded and accepted by the change owner.
- No new slow-query entries against the master-data tables.
