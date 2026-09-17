<?php

namespace App\Exports;

use App\Models\Invoice;
use App\Support\InvoiceReport;
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
        return $this->query->with(InvoiceReport::RELATIONS);
    }

    public function headings(): array
    {
        return InvoiceReport::headings();
    }

    /**
     * Same row the export API serves, positional for the spreadsheet.
     *
     * @param  Invoice  $invoice
     */
    public function map($invoice): array
    {
        return array_values(InvoiceReport::row($invoice));
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
