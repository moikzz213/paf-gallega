<?php

namespace App\Http\Controllers;

use App\Exports\InvoicesExport;
use App\Models\Invoice;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->filteredQuery($request);

        $summary = (clone $query)
            ->select('status', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as amount'))
            ->groupBy('status')
            ->get();

        $totals = [
            'count' => (clone $query)->count(),
            'amount' => (float) (clone $query)->sum('total_amount'),
        ];

        $rows = $query
            ->with(['submitter:id,name', 'poster:id,name', 'paymentRequest:id,reference_no,status'])
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
        $query = Invoice::query()->visibleTo($request->user());

        if ($status = $request->input('status')) {
            $query->whereIn('status', is_array($status) ? $status : explode(',', $status));
        }

        if ($department = $request->input('department')) {
            $query->where('department', $department);
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($vendor = trim((string) $request->input('vendor'))) {
            $query->where('vendor_name', 'like', "%{$vendor}%");
        }

        if ($request->filled('date_from')) {
            $query->whereDate('invoice_date', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('invoice_date', '<=', $request->date('date_to'));
        }

        return $query;
    }
}
