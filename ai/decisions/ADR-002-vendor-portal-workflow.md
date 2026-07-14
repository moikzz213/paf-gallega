# ADR-002: Vendor-Portal Workflow — Invoice Log + Payment-Request Grouping + Dynamic Chain

- **Status:** Accepted
- **Date:** 2026-07-14
- **Supersedes:** the invoice-centric approval model described in
  [ADR-001](ADR-001-project-initialization.md) and the per-invoice dynamic-approver feature that
  preceded this change.

## Context

The reference `vendor-portal-demo` prototype defines a four-step process that the original PAF
model did not match. The product owner chose to re-implement the app to that scenario, keeping
the PAF name and AED currency, and adopting three elements of the demo:

1. **Invoice Log + ERP posting/query** intake step.
2. **Payment Request (PRF)** grouping **multiple** invoices into one approval.
3. A **dynamic approval chain** — approvers added per request and each selected from all
   approver-role users — **not** the demo's fixed 8 stages. The chain pre-fills from
   `approval_levels` defaults (by amount) but every stage stays editable/removable.

Previously the **invoice** carried the approval chain and full payment lifecycle.

## Decision

Move the approval + payment lifecycle from the invoice to a new **`payment_requests` (PRF)**
entity.

- **Invoice** is now intake-only: `submitted → posted | query_raised` (+ `cancelled`), with a
  `payment_status` (`not_initiated → in_approval → approved_for_payment → paid`) and a
  `payment_request_id` linking it to its current PRF. Finance posts to ERP (`erp_doc_no`,
  `posting_date`) or raises a query (`finance_remarks`).
- **PaymentRequest** owns the chain (`payment_request_approvals`, sequence-ordered, each with an
  assigned `approver_id`, `is_adhoc` flag) and the payment fields (`status`, `current_stage`,
  `paid_at`, `payment_reference`, `paid_by`).
- **PaymentRequestService** builds the chain (from `ApprovalLevel::requiredFor(total)` defaults +
  ad-hoc stages), routes it sequentially, and handles approve/reject/mark-paid. Rejection
  returns the invoices to the eligible pool.
- Retired: `invoice_approvals` table, `InvoiceApproval` model, `ApprovalService`, and the
  per-invoice approval/payment columns; `ApprovalController`/`PaymentController` replaced by
  `PaymentRequestController`.

## Consequences

**Benefits**
- Matches the intended business process; one approval can cover many invoices (as Finance works
  in practice).
- Approver assignment is explicit and flexible per request; visibility/queue are assignment-based.

**Trade-offs / limitations**
- Larger surface area (new tables, service, controller, and four reworked Vue pages).
- **No concurrency guard**: two Finance users could select the same invoice into two PRFs before
  either sends (last writer wins on reservation). See
  [../issues/known-issues.md](../issues/known-issues.md).
- A **rejected PRF unlinks its invoices** (so they can be re-initiated), which means requesters
  lose the on-screen link back to the rejected PRF (the audit trail still records it).

## Notes

- Approval **levels remain** purely as defaults/suggestions for the chain builder — they no
  longer gate the invoice directly.
- Verified end-to-end (12 feature tests + browser walkthrough of submit → post → create PRF →
  approve).
