<?php

namespace App\Exports;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InvoicesExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    public function __construct(private Builder $query)
    {
    }

    public function query()
    {
        return $this->query->with(['submitter:id,name', 'payer:id,name']);
    }

    public function headings(): array
    {
        return [
            'Reference No', 'Vendor', 'Invoice No', 'Invoice Date', 'Due Date',
            'Currency', 'Amount', 'Tax', 'Total', 'Category', 'Department',
            'Cost Center', 'Payment Method', 'Priority', 'Status',
            'Requested By', 'Submitted At', 'Approved At', 'Scheduled Date',
            'Paid At', 'Payment Reference', 'Paid By', 'Rejection Reason',
        ];
    }

    /** @param Invoice $invoice */
    public function map($invoice): array
    {
        return [
            $invoice->reference_no,
            $invoice->vendor_name,
            $invoice->invoice_no,
            $invoice->invoice_date?->format('Y-m-d'),
            $invoice->due_date?->format('Y-m-d'),
            $invoice->currency,
            (float) $invoice->amount,
            (float) $invoice->tax_amount,
            (float) $invoice->total_amount,
            $invoice->category,
            $invoice->department,
            $invoice->cost_center,
            config('paf.payment_methods')[$invoice->payment_method] ?? $invoice->payment_method,
            ucfirst($invoice->priority),
            str_replace('_', ' ', ucfirst($invoice->status)),
            $invoice->submitter?->name,
            $invoice->submitted_at?->format('Y-m-d H:i'),
            $invoice->approved_at?->format('Y-m-d H:i'),
            $invoice->scheduled_date?->format('Y-m-d'),
            $invoice->paid_at?->format('Y-m-d H:i'),
            $invoice->payment_reference,
            $invoice->payer?->name,
            $invoice->rejection_reason,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
