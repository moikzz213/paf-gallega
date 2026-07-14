# Known Issues

Behavioral gaps and limitations after the vendor-portal rework
([ADR-002](../decisions/ADR-002-vendor-portal-workflow.md)). The codebase has no `TODO`/`FIXME`
markers. Severity is a triage judgment.

| # | Area | Issue | Severity |
|---|------|-------|----------|
| 1 | Notifications | No email/notifications. Approvers learn of pending PRFs only by visiting **Approvals**; departments see a query only by opening the invoice. `MAIL_MAILER=log`. | High (product) |
| 2 | ERP | "Post to ERP" records a doc number but calls **no external system** — there is no real ERP integration. | Medium (product) |
| 3 | Auth | No password reset or email verification. Passwords are set only by an admin. | Medium |
| 4 | Concurrency | **No guard against double-selecting an invoice into two PRFs.** Two Finance users could each pull the same eligible invoice before sending; whoever creates first reserves it, the other 422s at submit — but there is no lock, so a race on the reservation is possible. | Medium |
| 5 | Rejected PRF visibility | Rejecting a PRF **unlinks its invoices** (so they can be re-initiated), which means the invoice detail page loses its on-screen link back to the rejected PRF. The audit trail still records the rejection. | Low |
| 6 | Authorization drift risk | Two authorization vocabularies (server `role:` + inline/service checks vs. client router `meta.roles` + `AppLayout` getters). They agree today but are independent code paths. | Medium |
| 7 | Reports/dashboard have no role gate | They rely solely on the `scopeVisibleTo` scopes. Correct today, but any query added there without the scope would leak data. | Medium |
| 8 | Deleting an approval level | Admins can delete an `approval_level`; it only affects **future** PRF chains (existing PRF stages snapshot their label), but there is no warning. | Low |

## How to use this file

When you fix one of these, move it to [bugs-fixed.md](bugs-fixed.md) with the commit/date. Add
new behavioral gaps here, or structural ones to [technical-debt.md](technical-debt.md).
