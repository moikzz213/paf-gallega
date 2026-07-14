# Feature Overview

The PAF platform's capabilities, by area. See [../api-contracts.md](../api-contracts.md) for the
endpoints behind each, and [../architecture.md](../architecture.md) for how they fit together.

## 1. Authentication & session

- Email/password login (session cookie), throttled. Deactivated (`is_active=false`) users are
  blocked. Sign-in/out are audit-logged. No email verification / password reset yet.

## 2. Payment request submission (Requester)

- Fixed-format form: vendor details (name, email, TRN), invoice no/date/due date, currency,
  amount + tax (total computed server-side), category, department, cost center, payment method,
  priority, description.
- System reference `PAF-{year}-{00001}` auto-assigned.
- Save as **draft** or **submit** directly. Drafts and rejected requests are editable and
  resubmittable.
- **Supporting documents:** up to 10 files, ≤10 MB each (pdf/images/office/csv/txt), attached at
  create/update; downloadable; removable while the request is editable.

## 3. Approval workflow (Approver / Admin)

- **Amount-threshold routing:** on submit, one approval row is created per active approval level
  whose `min_amount ≤ total_amount`. Default chain: L1 Department Manager (≥0), L2 Finance
  Director (≥10k), L3 CFO (≥50k).
- **Sequential chain:** the request sits at `current_level`; only the approver pinned to that
  level (or an admin) can act. Approve → advances to the next level or finalizes to `approved`.
  Reject → chain ends, request returns to requester as `rejected` (with reason) and is editable.
- **Approval queue:** `/approvals` lists requests awaiting the current user's level (admins see
  all pending), ordered by priority then submission time.
- Resubmission after rejection starts a **fresh** approval cycle.

## 4. Payment processing (Finance / Admin)

- Queue tabbed by state: To Schedule (`approved`), Scheduled, Paid.
- **Schedule:** set a `scheduled_date` (today or later) → `scheduled`.
- **Mark paid:** record a `payment_reference` (+ optional paid date) → `paid`, capturing
  `paid_by`.

## 5. Dashboard

- KPI cards (total requests, pending count+amount, awaiting payment, paid this month), an
  approver "queue waiting" alert, and Chart.js visualizations: status distribution (doughnut),
  monthly submitted-vs-paid (bar), top vendors, spend by category, plus a recent-requests table.
  All figures are scoped to the viewer's visibility.

## 6. Reports & Excel export

- Filterable report (status, department, category, vendor, date range) with per-status summary,
  totals, and a paginated table.
- **Export to Excel** (`maatwebsite/excel`) — a 23-column XLSX of the filtered set; exports are
  audit-logged.

## 7. Audit trail (Admin)

- Every state-changing action writes an `audit_log` (actor, action, description, old/new JSON,
  IP, timestamp). Admin audit-log viewer with search + action/date filters; each entry links to
  its invoice. Invoice detail pages also render a per-request audit timeline.

## 8. Administration (Admin)

- **Users:** create/edit (no hard delete — deactivate via `is_active`); role assignment;
  `approval_level` required for approvers.
- **Approval levels:** full CRUD of the threshold-based chain (level, name, `min_amount`,
  active). Changes propagate to future submissions; historical approvals keep their snapshot.

## Role → feature matrix

| Feature | Requester | Approver | Finance | Admin |
|---------|:--------:|:--------:|:-------:|:-----:|
| Submit / edit own requests | ✓ | ✓ (own) | ✓ (own) | ✓ |
| Approve / reject at level | | ✓ (own level) | | ✓ |
| Schedule / mark paid | | | ✓ | ✓ |
| Reports & export | ✓ (scoped) | ✓ (scoped) | ✓ | ✓ |
| Audit log viewer | | | | ✓ |
| Manage users / levels | | | | ✓ |
| See all invoices | | own + level | ✓ | ✓ |

## Not yet implemented

- Real email/notifications (mail driver is `log`).
- ERP / payment-gateway integration.
- Password reset / email verification.
- The demo HTML's fixed **8-stage** chain (this system uses a configurable threshold chain
  instead) — see [../issues/technical-debt.md](../issues/technical-debt.md).
