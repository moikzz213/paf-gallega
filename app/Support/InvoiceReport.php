<?php

namespace App\Support;

use App\Models\Invoice;
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
        'paid_at' => 'Paid At',
        'payment_reference' => 'Payment Reference',
    ];

    /** Relations every row needs; without them each row would re-query. */
    public const RELATIONS = [
        'submitter:id,name',
        'poster:id,name',
        'paymentRequest:id,reference_no,status,paid_at,payment_reference',
        'items:id,invoice_id,job_no,sort_order',
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
            $query->whereIn('status', is_array($status) ? $status : explode(',', (string) $status));
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
            'paid_at' => $invoice->paymentRequest?->paid_at?->format('Y-m-d H:i'),
            'payment_reference' => $invoice->paymentRequest?->payment_reference,
        ];
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
