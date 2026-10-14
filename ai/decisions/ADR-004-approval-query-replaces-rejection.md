# ADR-004: An Approval Query Replaces Rejection in the Approval Chain

- **Status:** Accepted
- **Date:** 2026-10-14
- **CR:** [replace-reject-with-query-in-payment-approval.md](../change-requests/replace-reject-with-query-in-payment-approval.md)

## Context

An approver's only alternative to approving a payment request was **Reject**, which ended it: the
request became `rejected`, its invoices were detached and returned to Finance, and the corrected
invoices needed a new request and a fresh chain, so everyone who had already signed signed again.
Most rejections were really questions. The business asked for the question to pause the request
rather than end it.

## Decision

- Remove rejection from both approval routes (in-app and the emailed stage link). The approver's
  alternative is **query**: `PaymentRequestService::query` sets the request to a new status,
  `queried`, and records a `payment_request_queries` row. `current_stage`, the stage (still
  `pending`, same approver), the earlier approvals and the invoices are left exactly as they were.
- While `queried`, `assertActionable` refuses every approval and query, including an admin's, and the
  emailed link offers no action. Only Finance, admins and the submitter of an invoice on the request
  (`PaymentRequest::canBeResubmittedBy`) may **resubmit**, with a response. Resubmission returns the
  request to `in_approval` at the **same stage**. The approvals already given are **kept**, because
  the in-approval correction rules (currency fixed, total may not rise, the request must stay above
  zero) are what keep them valid, and they apply unchanged while the request is on hold.
- The query notice is a **single email with every recipient in BCC** (`Notifier::sendBcc`). Its
  audience is fixed by the stage's position: the submitters and the request's creator always, earlier
  approvers from stage 2, and the asking approver from stage 4. An unanswered query is re-sent to the
  submitters and the creator every two days (`prf:send-query-reminders`).
- `rejected` stays a valid status for the requests already in it, and `invoice_rejections` and the
  report keep reading them. Nothing new enters it.

## Consequences

- Approvers can no longer end a request in one step. A payment that should never be made is taken
  out by Finance releasing its invoices; releasing the last one withdraws the request. Withdrawal
  still applies only to `approved` requests.
- `queried` is a new status every status check has to consider. The in-approval document, release
  and correction rules accept it; approval, the approvals queue and the daily approval reminder
  exclude it.
- BCC is now an established notification pattern. `PaymentRequestRejected` is no longer sent; it is
  kept only so notices queued before the release can still deliver (see technical debt).
- Reversing this means restoring the reject routes and deciding what happens to requests that are
  `queried` at the time. Each one has to be resubmitted or released first, so none is left on hold
  with no action available.
