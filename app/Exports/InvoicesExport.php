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
    public function __construct(private Builder $query) {}

    public function query()
    {
        return $this->query->with(['submitter:id,name', 'poster:id,name', 'paymentRequest:id,reference_no,status,paid_at,payment_reference']);
    }

    public function headings(): array
    {
        return [
            'Reference No', 'Vendor', 'Invoice No', 'Invoice Date', 'Due Date',
            'Currency', 'Amount', 'Tax', 'Total', 'Business Unit', 'Department',
            'Location', 'Payment Method', 'Priority', 'Status', 'Payment Status',
            'ERP Doc No', 'Posting Date', 'Requested By', 'Submitted At', 'Posted By',
            'Payment Request', 'Paid At', 'Payment Reference',
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
            $invoice->business_unit,
            $invoice->department,
            $invoice->location,
            config('paf.payment_methods')[$invoice->payment_method] ?? $invoice->payment_method,
            ucfirst($invoice->priority),
            str_replace('_', ' ', ucfirst($invoice->status)),
            str_replace('_', ' ', ucfirst($invoice->payment_status)),
            $invoice->erp_doc_no,
            $invoice->posting_date?->format('Y-m-d'),
            $invoice->submitter?->name,
            $invoice->submitted_at?->format('Y-m-d H:i'),
            $invoice->poster?->name,
            $invoice->paymentRequest?->reference_no,
            $invoice->paymentRequest?->paid_at?->format('Y-m-d H:i'),
            $invoice->paymentRequest?->payment_reference,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
