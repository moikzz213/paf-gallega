# Known Issues

Behavioral gaps and limitations observed during onboarding analysis. None are code-marked
(`TODO`/`FIXME`) — the codebase has no such markers. Severity is a judgment call for triage.

| # | Area | Issue | Severity |
|---|------|-------|----------|
| 1 | Notifications | No email/notifications. Approvers only learn of pending items by visiting `/approvals`. The demo's "email to approver" step is not real — `MAIL_MAILER=log`. | High (product) |
| 2 | Auth | No password reset or email verification flow. Passwords can only be set/changed by an admin. | Medium |
| 3 | Testing | No domain test coverage — only Laravel's default example tests. Approval-chain, authorization scoping, and payment lifecycle are untested. | High |
| 4 | Approval model mismatch | The `vendor-portal-demo` HTML specifies a fixed **8-stage** chain; the app implements a **configurable amount-threshold** chain (3 seeded levels). Reconciliation is a pending product decision. | Medium (product) |
| 5 | Authorization drift risk | Two independent authorization vocabularies (server `role:` middleware + inline checks vs. client router `meta.roles` + `AppLayout` getters). They agree today but can drift when routes are added. | Medium |
| 6 | Inconsistent enum strings | Invoice pending status serializes as `pending_approval` while an approval row's pending is `pending`. Easy to confuse in client code / queries. | Low |
| 7 | No concurrency guard on approvals | Two approvers at the same level (or admin + approver) acting simultaneously could race; the current-level check is not lock-protected. | Low |
| 8 | Reports/dashboard have no role gate | They rely solely on `Invoice::scopeVisibleTo` data-scoping. Correct today, but any query added there without the scope would leak data. | Medium |
| 9 | Deletion of approval levels | Admins can delete an `approval_level`; historical approvals snapshot the name so history survives, but deleting a level mid-flight changes routing for future submissions with no warning. | Low |

## How to use this file

When you fix one of these, move it to [bugs-fixed.md](bugs-fixed.md) with the commit/date. When
you discover a new limitation, add a row here (or a debt item in
[technical-debt.md](technical-debt.md) if it's structural).
