# Known Issues

Behavioral gaps and limitations after the vendor-portal rework
([ADR-002](../decisions/ADR-002-vendor-portal-workflow.md)). The codebase has no `TODO`/`FIXME`
markers. Severity is a triage judgment.

| # | Area | Issue | Severity |
|---|------|-------|----------|
| 1 | Notifications | Email is now wired (SMTP): approver-invite + public-link on each stage, daily reminders, **invoice-query → submitter**, and **final-approval → requestors**. Emails send **synchronously** (no queue) inside the request, so SMTP latency/failure can slow or break the triggering action; move to queued mail for production. | Medium |
| 2 | ERP | "Post to ERP" records a doc number but calls **no external system** — there is no real ERP integration. | Medium (product) |
| 3 | Auth | No password reset or email verification. Passwords are set only by an admin. | Medium |
| 4 | Concurrency | **No guard against double-selecting an invoice into two PRFs.** Two Finance users could each pull the same eligible invoice before sending; whoever creates first reserves it, the other 422s at submit — but there is no lock, so a race on the reservation is possible. | Medium |
| 5 | Rejected/withdrawn PRF visibility | Rejecting **or withdrawing** a PRF **unlinks its invoices** (so they can be re-initiated), which means the invoice detail page loses its on-screen link back to that PRF. The audit trail still records the rejection/withdrawal. | Low |
| 6 | Authorization drift risk | Two authorization vocabularies (server `role:` + inline/service checks vs. client router `meta.roles` + `AppLayout` getters). They agree today but are independent code paths. | Medium |
| 7 | Reports/dashboard have no role gate | They rely solely on the `scopeVisibleTo` scopes. Correct today, but any query added there without the scope would leak data. | Medium |
| 8 | Deleting an approval level | Admins can delete an `approval_level`; it only affects **future** PRF chains (existing PRF stages snapshot their label), but there is no warning. | Low |
| 9 | Reports status filter lists non-invoice statuses | `STATUS_META` intentionally covers invoice, payment and PRF statuses, and `ReportsPage` builds its status filter from **every** key — so PRF-only values (`draft`, `approved`, `rejected`, `withdrawn`, …) appear as selectable invoice statuses and match nothing. Pre-existing; `withdrawn` adds one more. | Low |
| 10 | In-place corrections don't notify the approvers | Finance correcting an invoice inside an approved PRF (lower total, changed job no./description) does not email the approvers who already signed it. The change is audit-logged against both the invoice and the PRF, and it can only ever reduce what is paid, but nobody is told. Consider a notification if Finance uses this often. | Low |
| 11 | Approval thresholds are currency-blind | `ApprovalLevel::requiredFor()` compares the raw `total_amount` against `min_amount` with no FX conversion, and the levels are AED figures. A USD 26,696 request is measured as 26,696 — about AED 98,000 — so a non-AED PRF can under-trigger its chain by the exchange rate. Surfaced while correcting AED→USD invoices. | **High** (control) |
| 12 | Legacy tax rates are approximations | Tax is now entered as a **percentage**; `invoice_items.tax_rate` was backfilled from each line's original cash figure, and an arbitrary figure is not always expressible as a 2-decimal rate (3,500 with 1,500 tax becomes 42.86%, which re-derives as 1,500.10). The migration never rewrites `tax_amount`, so recorded money is untouched, but **re-saving such a line recomputes the tax from the rate** and can move it by a cent or two. The edit form shows the computed figure and warns when it differs from what the invoice was saved with. Clean rates (5%, 0%) round-trip exactly. | Low |
| 13 | Export-API credentials in a URL | `GET /api/export-report` accepts `?key=&secret=` because Excel's web connector can only fetch a plain URL. Query strings land in web-server access logs, proxies and browser history, so such a URL is a shareable credential. Mitigations in place: the secret is hashed at rest, the key carries only its owner's visibility, every call is audit-logged with the key name, `throttle:60,1` applies, headers (`X-Api-Key`/`X-Api-Secret`) are supported for clients that can send them, and `api-key:revoke` is immediate. Serve it over HTTPS and rotate keys periodically. | Medium |
| 14 | No withdrawal once a PRF is paid | Deliberate — a paid request is final. A payment made in error has to be handled in the ERP and, if needed, a corrected invoice submitted afresh. Worth revisiting only with a proper reversal/credit-note concept. | Low (by design) |

## How to use this file

When you fix one of these, move it to [bugs-fixed.md](bugs-fixed.md) with the commit/date. Add
new behavioral gaps here, or structural ones to [technical-debt.md](technical-debt.md).
