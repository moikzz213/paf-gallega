<?php

namespace App\Http\Controllers;

use App\Exports\InvoicesExport;
use App\Services\AuditLogger;
use App\Support\CurrencyTotals;
use App\Support\InvoiceReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->filteredQuery($request);

        // Amounts kept apart per currency — adding them together labelled one currency is no total
        // at all (see CurrencyTotals).
        $summary = (clone $query)
            ->reorder()
            ->select('status', 'currency', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as amount'))
            ->groupBy('status', 'currency')
            ->get()
            ->groupBy('status')
            ->map(fn ($rows, $status) => [
                'status' => $status,
                'count' => (int) $rows->sum('count'),
                'amounts' => $rows
                    ->map(fn ($row) => ['currency' => (string) $row->currency, 'count' => (int) $row->count, 'amount' => round((float) $row->amount, 2)])
                    ->sortBy([['count', 'desc'], ['amount', 'desc']])
                    ->values(),
            ])
            ->values();

        $totals = [
            'count' => (clone $query)->count(),
            'amounts' => CurrencyTotals::byCurrency($query),
        ];

        $rows = $query
            ->with(InvoiceReport::RELATIONS)
            ->orderByDesc('invoice_date')
            ->paginate((int) $request->input('per_page', 25));

        return response()->json([
            'summary' => $summary,
            'totals' => $totals,
            'rows' => $rows,
        ]);
    }

    public function export(Request $request)
    {
        $query = $this->filteredQuery($request)->orderByDesc('invoice_date');

        AuditLogger::log('report_exported', 'Invoice report exported to Excel');

        $filename = 'paf-invoices-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(new InvoicesExport($query), $filename);
    }

    private function filteredQuery(Request $request): Builder
    {
        return InvoiceReport::query($request->user(), $request->only([
            'status', 'department', 'business_unit', 'vendor', 'date_from', 'date_to',
        ]));
    }
}
