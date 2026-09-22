<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single definition of the invoice report: which rows it contains, and which columns each row
 * has. The interactive Reports page, the Excel export and the key-authenticated export API all read
 * it from here, so a spreadsheet wired to the API can never drift from the file people download.
 */
final class InvoiceReport
{
    /**
     * Column key => spreadsheet heading, in order. The keys are the JSON field names, so renaming a
     * heading is safe for API consumers while renaming a key is a breaking change.
     */
    private const COLUMNS = [
        'reference_no' => 'Reference No',
        'vendor' => 'Vendor',
        'invoice_no' => 'Invoice No',
        'job_no' => 'Job No',
        'invoice_date' => 'Invoice Date',
        'due_date' => 'Due Date',
        'currency' => 'Currency',
        'amount' => 'Amount',
        'tax' => 'Tax',
        'total' => 'Total',
        'business_unit' => 'Business Unit',
        'department' => 'Department',
        'location' => 'Location',
        'payment_method' => 'Payment Method',
        'priority' => 'Priority',
        'status' => 'Status',
        'payment_status' => 'Payment Status',
        'erp_doc_no' => 'ERP Doc No',
        'posting_date' => 'Posting Date',
        'requested_by' => 'Requested By',
        'submitted_at' => 'Submitted At',
        'posted_by' => 'Posted By',
        'payment_request' => 'Payment Request',
        'rejected_payment_requests' => 'Rejected Payment Requests',
        'paid_at' => 'Paid At',
        'payment_reference' => 'Payment Reference',
        'query_raised' => 'Query Raised',
        'remarks' => 'Remarks',
    ];

    /** Relations every row needs; without them each row would re-query. */
    public const RELATIONS = [
        'submitter:id,name',
        'poster:id,name',
        'paymentRequest:id,reference_no,status,paid_at,payment_reference,rejection_reason,withdrawal_reason',
        'items:id,invoice_id,job_no,sort_order',
        'queryLogs:id,invoice_id,action,description,created_at',
        'paymentRequest.approvals:id,payment_request_id,sequence,comments',
        // Requests this invoice was on that were rejected. Loaded separately from `paymentRequest`
        // because rejection detaches the invoice — see Invoice::rejections.
        'rejections:id,invoice_id,payment_request_id,rejected_at',
        'rejections.paymentRequest:id,reference_no,rejected_at,rejection_reason',
        'rejections.paymentRequest.approvals:id,payment_request_id,sequence,comments',
    ];

    /** @return array<string, string> */
    public static function columns(): array
    {
        return self::COLUMNS;
    }

    /** Spreadsheet heading row. */
    public static function headings(): array
    {
        return array_values(self::COLUMNS);
    }

    /**
     * Rows visible to $user, narrowed by the report filters. Always scoped through
     * `Invoice::scopeVisibleTo` — an API key carries its owner's visibility, nothing wider.
     *
     * @param  array<string, mixed>  $filters  status (array or comma-separated), department,
     *                                         business_unit, vendor (partial), date_from, date_to
     */
    public static function query(User $user, array $filters = []): Builder
    {
        $query = Invoice::query()->visibleTo($user);

        if ($status = $filters['status'] ?? null) {
            $selected = is_array($status) ? $status : explode(',', (string) $status);
            self::filterByStatus($query, array_filter(array_map('trim', $selected)));
        }

        if ($department = $filters['department'] ?? null) {
            $query->where('department', $department);
        }

        if ($businessUnit = $filters['business_unit'] ?? null) {
            $query->where('business_unit', $businessUnit);
        }

        if ($vendor = trim((string) ($filters['vendor'] ?? ''))) {
            $query->where('vendor_name', 'like', "%{$vendor}%");
        }

        if ($dateFrom = $filters['date_from'] ?? null) {
            $query->whereDate('invoice_date', '>=', $dateFrom);
        }

        if ($dateTo = $filters['date_to'] ?? null) {
            $query->whereDate('invoice_date', '<=', $dateTo);
        }

        return $query;
    }

    /**
     * Apply the report's status filter, sending each selected value to the field that actually
     * holds it.
     *
     * The filter offers everything in the client's `STATUS_META` — the invoice's own status, its
     * payment status, and the payment request's status — but only ever compared the selection
     * against `invoices.status`. That column holds four values, so selecting "Rejected", "Paid",
     * "Approved" or any other payment/PRF status silently returned nothing at all: the row set was
     * empty, not wrong, which is why it read as missing data rather than a broken filter.
     *
     * Selections are OR'd, as they were when they all hit one column. A value that belongs to more
     * than one vocabulary (`paid`, `in_approval`) is matched in each, which is harmless — an
     * invoice sitting at that payment status is on a request at that status anyway.
     *
     * @param  array<int, string>  $statuses
     */
    private static function filterByStatus(Builder $query, array $statuses): void
    {
        if (! $statuses) {
            return;
        }

        $invoiceStatuses = array_intersect($statuses, Invoice::STATUSES);
        $paymentStatuses = array_intersect($statuses, Invoice::PAYMENT_STATUSES);
        $requestStatuses = array_intersect($statuses, PaymentRequest::STATUSES);

        $query->where(function (Builder $scoped) use ($invoiceStatuses, $paymentStatuses, $requestStatuses) {
            if ($invoiceStatuses) {
                $scoped->orWhereIn('status', $invoiceStatuses);
            }

            if ($paymentStatuses) {
                $scoped->orWhereIn('payment_status', $paymentStatuses);
            }

            // "Rejected" cannot be read from the request the invoice is on: rejection detaches it,
            // and re-initiation gives it a different one. It means "was on a request that was
            // rejected", which is what `invoice_rejections` records.
            if (in_array(PaymentRequest::STATUS_REJECTED, $requestStatuses, true)) {
                $scoped->orWhereHas('rejections');
            }

            $onRequest = array_diff($requestStatuses, [PaymentRequest::STATUS_REJECTED]);

            if ($onRequest) {
                $scoped->orWhereHas('paymentRequest', fn (Builder $pr) => $pr->whereIn('status', $onRequest));
            }
        });
    }

    /**
     * One row, keyed by column. Numbers stay numeric and dates are ISO-ish strings so a spreadsheet
     * or Power Query gets usable types rather than pre-formatted text.
     *
     * @return array<string, mixed>
     */
    public static function row(Invoice $invoice): array
    {
        return [
            'reference_no' => $invoice->reference_no,
            'vendor' => $invoice->vendor_name,
            'invoice_no' => $invoice->invoice_no,
            'job_no' => self::jobNumbers($invoice),
            'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'currency' => $invoice->currency,
            'amount' => (float) $invoice->amount,
            'tax' => (float) $invoice->tax_amount,
            'total' => (float) $invoice->total_amount,
            'business_unit' => $invoice->business_unit,
            'department' => $invoice->department,
            'location' => $invoice->location,
            'payment_method' => config('paf.payment_methods')[$invoice->payment_method] ?? $invoice->payment_method,
            'priority' => ucfirst((string) $invoice->priority),
            'status' => str_replace('_', ' ', ucfirst((string) $invoice->status)),
            'payment_status' => str_replace('_', ' ', ucfirst((string) $invoice->payment_status)),
            'erp_doc_no' => $invoice->erp_doc_no,
            'posting_date' => $invoice->posting_date?->format('Y-m-d'),
            'requested_by' => $invoice->submitter?->name,
            'submitted_at' => $invoice->submitted_at?->format('Y-m-d H:i'),
            'posted_by' => $invoice->poster?->name,
            'payment_request' => $invoice->paymentRequest?->reference_no,
            'rejected_payment_requests' => self::rejectedPaymentRequests($invoice),
            'paid_at' => $invoice->paymentRequest?->paid_at?->format('Y-m-d H:i'),
            'payment_reference' => $invoice->paymentRequest?->payment_reference,
            'query_raised' => self::queriesRaised($invoice),
            'remarks' => self::approverRemarks($invoice),
        ];
    }

    /**
     * Every query raised on the invoice, oldest first and comma-separated — `finance_remarks` only
     * holds the latest one. The audit entry reads "Query raised on {reference}: {text}", so the
     * prefix is trimmed back off to leave the text Finance actually wrote.
     */
    private static function queriesRaised(Invoice $invoice): string
    {
        $prefix = "Query raised on {$invoice->reference_no}: ";

        return $invoice->queryLogs
            ->map(function ($log) use ($prefix) {
                $text = (string) $log->description;

                return trim(str_starts_with($text, $prefix) ? substr($text, strlen($prefix)) : $text);
            })
            ->filter(fn (string $text) => $text !== '')
            ->implode(', ');
    }

    /**
     * Every payment request this invoice was on that got rejected, oldest first, each with the date
     * it was rejected — e.g. "PAF-2026-0012 (rejected 2026-09-01)".
     *
     * Blank for the overwhelming majority of invoices, which have never been rejected.
     */
    private static function rejectedPaymentRequests(Invoice $invoice): string
    {
        return $invoice->rejections
            ->map(function ($rejection) {
                $reference = (string) $rejection->paymentRequest?->reference_no;

                return $reference === ''
                    ? ''
                    : "{$reference} (rejected {$rejection->rejected_at?->format('Y-m-d')})";
            })
            ->filter(fn (string $entry) => $entry !== '')
            ->implode(', ');
    }

    /**
     * Everything anyone wrote on the invoice's payment requests — the rejected ones first, then the
     * request it is on now: approvers' comments in approval order, then the request-level rejection
     * and withdrawal reasons. Those two are labelled, since on their own they read like just another
     * approver comment; a rejected request's remarks additionally carry its reference, so a reader
     * of a twice-rejected invoice can tell which round each remark came from.
     */
    private static function approverRemarks(Invoice $invoice): string
    {
        $remarks = $invoice->rejections->flatMap(function ($rejection) {
            $rejected = $rejection->paymentRequest;

            if (! $rejected) {
                return [];
            }

            return collect(self::requestRemarks($rejected))
                ->map(fn (string $remark) => "[{$rejected->reference_no}] {$remark}");
        })->values();

        return $remarks
            ->merge(self::requestRemarks($invoice->paymentRequest))
            ->implode(', ');
    }

    /**
     * One request's remarks in the order they were written: the approvers' comments by stage, then
     * the reason it was rejected or withdrawn.
     *
     * @return array<int, string>
     */
    private static function requestRemarks(?object $paymentRequest): array
    {
        $remarks = ($paymentRequest?->approvals ?? collect())
            ->sortBy('sequence')
            ->map(fn ($approval) => trim((string) $approval->comments))
            ->values();

        if ($reason = trim((string) $paymentRequest?->rejection_reason)) {
            $remarks->push("Rejected: {$reason}");
        }

        if ($reason = trim((string) $paymentRequest?->withdrawal_reason)) {
            $remarks->push("Withdrawn: {$reason}");
        }

        return $remarks->filter(fn (string $remark) => $remark !== '')->values()->all();
    }

    /** An invoice's job numbers live on its lines, and a line may carry none. */
    private static function jobNumbers(Invoice $invoice): string
    {
        return $invoice->items
            ->pluck('job_no')
            ->filter(fn (?string $jobNo) => trim((string) $jobNo) !== '')
            ->unique()
            ->implode(', ');
    }
}
