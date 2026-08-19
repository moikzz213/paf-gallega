<?php

namespace App\Http\Controllers;

use App\Exports\InvoicesExport;
use App\Models\ApiKey;
use App\Services\AuditLogger;
use App\Support\InvoiceReport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Key-authenticated export of the invoice report — the same rows and columns as the Excel download
 * on the Reports page, in a shape a spreadsheet or BI tool can refresh on its own.
 *
 * The rows come from `InvoiceReport`, which the page and the .xlsx download also use, so a connected
 * workbook cannot drift from the file people download by hand. Data is scoped to the user the key
 * was issued for.
 */
class ReportApiController extends Controller
{
    private const MAX_ROWS = 50000;

    public function export(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable'],
            'department' => ['nullable', 'string', 'max:255'],
            'business_unit' => ['nullable', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'format' => ['nullable', 'in:json,xlsx'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_ROWS],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $apiKey = $request->attributes->get('api_key');
        $query = InvoiceReport::query($request->user(), $filters)->orderByDesc('invoice_date');

        $this->audit($apiKey, $request, $filters);

        if (($filters['format'] ?? 'json') === 'xlsx') {
            return Excel::download(
                new InvoicesExport($query),
                'paf-invoices-'.now()->format('Ymd-His').'.xlsx',
            );
        }

        $total = (clone $query)->count();
        $limit = (int) ($filters['limit'] ?? self::MAX_ROWS);
        $offset = (int) ($filters['offset'] ?? 0);

        $rows = $query->with(InvoiceReport::RELATIONS)
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn ($invoice) => InvoiceReport::row($invoice))
            ->all();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'columns' => InvoiceReport::columns(),
            'filters' => array_diff_key($filters, ['format' => null, 'limit' => null, 'offset' => null]),
            'total' => $total,
            'offset' => $offset,
            'count' => count($rows),
            // A client that sees this true should re-request with offset advanced by count.
            'has_more' => $offset + count($rows) < $total,
            'rows' => $rows,
        ]);
    }

    /**
     * Machine access to invoice data is auditable like any other export, naming the key so a leaked
     * credential can be traced and revoked.
     *
     * @param  array<string, mixed>  $filters
     */
    private function audit(?ApiKey $apiKey, Request $request, array $filters): void
    {
        $applied = collect($filters)
            ->except(['format', 'limit', 'offset'])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, $name) => $name.'='.(is_array($value) ? implode('|', $value) : $value))
            ->implode(', ');

        AuditLogger::log(
            'report_exported_via_api',
            "Invoice report exported through the API by key \"{$apiKey?->name}\" as {$request->user()?->name}"
                .($applied !== '' ? " (filters: {$applied})" : ' (no filters)'),
        );
    }
}
