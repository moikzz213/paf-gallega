# Change Request

## Subject

Add a `1000` option to the "Items per page" selector on the Master Data page.

---

## Affected Areas

### Features

- Master Data management screen (Admin) — tabs: Vendors, Customers, Business Units, Departments, Locations, Currencies
- Server-side table pagination for master data listings
- Indirectly: every other paginated table in the app, **if** the option is added to the shared Vuetify default instead of being scoped to this page (see Description)

### Modules

- Admin / Master Data module
- Shared UI configuration (Vuetify global defaults)

### Controllers

- `app/Http/Controllers/MasterDataController.php` → `index()` — validates `per_page` with `max:100`; **this rule blocks the change and must be raised.**

### Services

- `App\Support\MasterDataDefinition` — read-only in this path (supplies searchable/code columns); no change required
- `App\Services\MasterDataImportService` — not affected
- `App\Services\AuditLogger` — not affected (listing is not audited)

### Frontend Components

- `resources/js/pages/admin/MasterDataPage.vue`
  - line 16 — `const options = reactive({ page: 1, itemsPerPage: 15 })`
  - line 78 — `per_page: options.itemsPerPage` request parameter
  - line 268 — `<v-data-table-server v-model:items-per-page="options.itemsPerPage">`
- `resources/js/plugins/vuetify.js`
  - line 5 — `export const ITEMS_PER_PAGE_OPTIONS = [10, 15, 25, 50, 100]`
  - lines 36–37 — applied as defaults to `VDataTable` and `VDataTableServer`

### APIs

- Internal: `GET /api/master-data/{entity}` — no contract change; only the accepted range of the existing `per_page` query parameter widens from `1..100` to `1..1000`
- No external / third-party API impact
- No authentication or response-shape change; response remains a Laravel paginator payload (`data`, `total`, …)

### Database

- No schema change. No migration, no new column, index, constraint, view, or stored procedure.
- Read-path impact only: `SELECT … ORDER BY name, id LIMIT 1000` against `vendors`, `customers`, `business_units`, `departments`, `locations`, `currencies`.
- Recommended verification: confirm an index exists supporting `ORDER BY name` on the higher-volume tables (`vendors`, `customers`); without one, a 1000-row page forces a larger filesort.

### Security

- No change to authentication, roles, or permissions. The Master Data endpoints remain behind their existing authorization (covered by `tests/Feature/MasterDataAccessTest.php`).
- No new fields exposed. The same records already reachable by paging become reachable in fewer requests.
- Mild data-exfiltration surface change: an authorized user can extract a full master-data list in a single call. Master data is already fully exportable through the existing template/export path, so this is not a new capability.
- Denial-of-service consideration: `per_page` is user-supplied, so the upper bound is the real control. It must stay a hard validated ceiling — never unbounded and never `-1` / "All".

### Infrastructure

- No environment variables, queues, workers, cron jobs, storage, or cache changes.
- Deployment requires a frontend asset rebuild (`npm run build`) because both touched files are compiled Vue/JS bundles under `public/build/`.

---

## Emergency Change Assessment

### Business Continuity

Assessment: No

Justification: No business process is interrupted. The Master Data page is fully functional today; users can reach every record via existing page sizes up to 100.

### Workaround Availability

Assessment: Yes — a workaround exists

Justification: Users can page through results at 100 per page, use the search field to narrow results, or use the existing master-data export to obtain a full list.

### Operational Impact

Assessment: No

Justification: Delaying causes only minor inconvenience (extra clicks when reviewing large vendor/customer lists). No financial, operational, or reputational damage.

### Timeline Constraints

Assessment: No

Justification: There is no deadline forcing a bypass of normal assessment. The change is small and fits an ordinary release cycle.

Emergency Change Classification: **No**

Reason: A usability enhancement with an available workaround, no continuity or compliance driver, and no timeline pressure. It should follow the standard change process.

---

## Risk

### Risk Level

**Low** — with one caveat that raises it to **Medium** if the shared global option list is modified rather than a page-scoped override (see Mitigation Actions).

### Identified Risks

**Technical**

1. **Backend validation rejects the new value.** `MasterDataController::index()` validates `per_page` as `max:100`. If only the frontend option list is changed, selecting 1000 produces a `422 Unprocessable Entity` and the table shows an error instead of data. The controller and the UI must ship together.
2. **Unintended scope via shared defaults.** `ITEMS_PER_PAGE_OPTIONS` in `resources/js/plugins/vuetify.js` is applied to *every* `VDataTable` and `VDataTableServer` in the app. Editing it adds `1000` to Payment Requests, Invoices, Users, Audit Log, Approvals, and Reports as well — several of which query far larger tables than master data, and whose controllers still default `per_page` well below 1000 (Audit Log and Reports to 25, Invoices/Users to 15) with no `max` rule on most of them.
3. **Query and payload weight.** A 1000-row page increases SQL result size, JSON serialization, response payload, and client-side DOM/render cost. Noticeable on slower connections and on the Currencies tab, which renders a computed rate expression per row.
4. **Persisted page-size state.** No stored `itemsPerPage` preference was found for this page, so no stale-value migration is needed; confirm during development that no user preference store holds a page size.

**Security**

5. **Unbounded page size if the validation ceiling is removed rather than raised.** Replacing `max:100` with no rule would let a caller request an arbitrarily large page and pressure memory on both API and database.
6. **Faster bulk read of master data** by an already-authorized user, as noted under Security above. Low materiality; consider whether large-page reads warrant audit logging under existing compliance expectations.

**Operational**

7. **Deployment mismatch.** If compiled assets are deployed without the backend change (or vice versa), the option appears but fails. Both must be released atomically.
8. **Support noise** if the 1000 option is slow enough on the largest tab that users report it as a hang; the table's loading state must remain visible for the whole request.

### Mitigation Actions

1. Raise the backend rule to `'per_page' => ['nullable','integer','min:1','max:1000']` in `MasterDataController::index()` in the same change — keep it a hard, explicit ceiling; do not remove the `max` rule and do not accept `-1` / "All".
2. **Scope the option to the Master Data page** rather than editing the shared list: pass `:items-per-page-options` explicitly on the `v-data-table-server` in `MasterDataPage.vue` (e.g. `[...ITEMS_PER_PAGE_OPTIONS, 1000]`, or a locally defined list). This confines the blast radius to one screen and leaves the documented rationale in `vuetify.js` intact. If a global rollout is genuinely wanted, treat it as a separate CR covering every affected controller's `per_page` ceiling.
3. Leave the default page size at `15`; `1000` must be opt-in per session, never the initial load.
4. Keep the `:loading` binding on the table so long requests show progress.
5. Measure the worst-case tab (largest of vendors/customers) at 1000 rows in a production-like dataset before sign-off; record response time and payload size.
6. Verify (or add) an index supporting `ORDER BY name` on `vendors` and `customers`.
7. Deploy backend and rebuilt frontend assets together in a single release.

---

## Description

The Master Data admin page uses a server-paginated Vuetify table (`v-data-table-server`) whose "Items per page" selector currently offers `10, 15, 25, 50, 100`. That list comes from the shared `ITEMS_PER_PAGE_OPTIONS` constant in `resources/js/plugins/vuetify.js`, applied as a global default to all data tables. The selected value is sent to the API as the `per_page` query parameter.

This change adds `1000` as a selectable page size for the Master Data page, so administrators can review or visually scan a complete entity list (vendors, customers, business units, departments, locations, currencies) without paging.

Two coordinated edits are required:

1. **Frontend** — make `1000` available in the selector for this table. The recommended approach is a page-scoped `:items-per-page-options` binding on the `v-data-table-server` in `resources/js/pages/admin/MasterDataPage.vue`, rather than appending to the global constant, because the global constant governs every paginated table in the application including much larger datasets.
2. **Backend** — raise the `per_page` validation ceiling in `MasterDataController::index()` from `max:100` to `max:1000`. Without this the new option returns a `422` validation error.

The default page size remains `15`. No database, API contract, permission, or infrastructure change is involved.

---

## Reason for Change

Administrators maintaining master data frequently need to review an entity list in full — to spot duplicates, confirm a bulk import landed correctly, check which currencies still lack an exchange rate, or verify vendor/customer codes across the whole set. At a maximum of 100 rows per page this requires repeated paging, and cross-row comparison is impractical. A 1000-row page size makes whole-list review a single action and pairs naturally with the browser's own find-in-page.

---

## Impact

### Business Impact

Positive and contained. Master-data review and verification become faster, particularly after bulk imports. No business rule, approval route, or financial calculation is touched.

### Technical Impact

Two files change (one Vue component, one controller), plus a frontend asset rebuild. No migration, no new dependency, no API contract change. Larger responses on the read path when the new option is chosen; the existing pagination mechanism is otherwise unchanged.

### Security Impact

Minimal. No authentication or authorization change and no new data exposure — the same records, retrieved in fewer requests. The material control is that `per_page` remains bounded by explicit server-side validation; raising the ceiling to a defined 1000 preserves that protection, whereas removing the rule would not.

### User Impact

Admin users gain an extra choice in an existing dropdown. Behavior is unchanged for anyone who does not select it. Users who do select `1000` on a large tab will see a longer load, mitigated by the table's loading indicator. If the change is scoped to the Master Data page as recommended, users of other tables see nothing new.

### Reporting Impact

None. Reports, dashboards, exports, and scheduled jobs do not consume this endpoint's page size. The separate Reports and Audit Log tables keep their own page-size defaults.

---

## Rollout Plan

1. **Development** — add the page-scoped `items-per-page-options` binding in `resources/js/pages/admin/MasterDataPage.vue`; raise `per_page` to `max:1000` in `MasterDataController::index()`; keep the default at 15.
2. **Unit Testing** — feature test asserting `GET /api/master-data/{entity}?per_page=1000` returns `200` with at most 1000 records, and that `per_page=1001` still returns `422`.
3. **Code Review** — confirm the shared `ITEMS_PER_PAGE_OPTIONS` constant and its "no unbounded page" comment are untouched; confirm frontend and backend limits agree.
4. **QA Testing** — exercise all six tabs at every page-size option, with and without a search term, and across page navigation and tab switching.
5. **UAT Testing** — admin users confirm the 1000 option on realistic data volumes and confirm perceived performance is acceptable.
6. **Staging Deployment** — deploy backend plus rebuilt assets (`npm run build`); measure response time and payload size on the largest entity.
7. **Production Deployment** — single atomic release of backend and compiled assets.
8. **Post Deployment Verification** — load the Master Data page, select 1000 on the largest tab, confirm rows render and no `422` or timeout occurs; check API logs for validation errors on `per_page` and for slow-query entries on the master-data tables.

---

## Backout Plan

1. **Disable feature** — revert the `items-per-page-options` binding in `MasterDataPage.vue` so the selector returns to `10/15/25/50/100`; the option disappears immediately for all users.
2. **Restore previous version** — redeploy the prior release artifact (previous `MasterDataController.php` with `max:100`, plus the previous compiled `public/build/` assets).
3. **Rollback database changes** — not applicable; no migration is included in this change.
4. **Restore backup** — not applicable; no data is written or transformed. No restore is needed.
5. **Validate application health** — confirm the Master Data page loads on every tab at the default page size, search and paging behave normally, and the API returns `200` for `per_page` values up to 100.
6. **Notify stakeholders** — inform the requesting admin group that the 1000 option has been withdrawn, with the reason (e.g. performance) and the follow-up plan.

Note: because no state is persisted, rollback is a pure code revert with no residual effect on users.

---

## Testing Requirements

### Unit Testing

- `MasterDataController::index()` accepts `per_page=1000` and returns at most 1000 records.
- `per_page=1001` and `per_page=0` are rejected with `422`.
- Omitting `per_page` still yields the default page size of 15.
- Extend the existing `tests/Feature/MasterDataAccessTest.php` / `CurrencyMasterDataTest.php` coverage rather than adding a parallel suite.

### Integration Testing

- Each of the six entities returns a correct paginator payload (`data`, `total`) at `per_page=1000`.
- `per_page=1000` combined with a `q` search term returns correctly filtered and correctly counted results.
- Switching tabs resets to page 1 and does not carry a stale page size into an incompatible state.

### Regression Testing

- All existing page sizes (10/15/25/50/100) behave as before on Master Data.
- Other paginated tables — Payment Requests, Invoices, Users, Audit Log, Approvals, Reports — show an unchanged "Items per page" list and unchanged behavior (this is the primary regression check for mitigation #2).
- Master data create/edit/delete, import, and template export continue to work.

### Security Testing

- `per_page` cannot exceed 1000: verify with `per_page=999999`, `per_page=-1`, and non-numeric values.
- Unauthorized and insufficiently privileged users still receive the expected denial on `GET /api/master-data/{entity}` regardless of `per_page`.
- No additional fields appear in the response at the larger page size.
- Confirm no unbounded query path was introduced (no `-1` / "All" option reachable).

### User Acceptance Testing

- An admin selects 1000 on the largest entity and confirms the full list renders within an acceptable time.
- An admin confirms whole-list review (duplicate spotting, post-import verification, currencies missing an exchange rate) is achievable without paging.

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-09-02

Risk Rating: Low (Medium if the shared global options list is modified instead of a page-scoped override)

Emergency Change: No

Analysis Confidence: 93%

- Affected Features Confidence: 95%
- Affected APIs Confidence: 96%
- Affected Security Impact Confidence: 90%
- Overall Risk Assessment Confidence: 92%

Confidence is reduced from full certainty on two points not verifiable from code alone: actual production row counts per master-data entity (which determine whether 1000 rows is a performance concern), and whether the business wants the option on this page only or on every paginated table.
