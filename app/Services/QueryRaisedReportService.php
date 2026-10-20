<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Invoice;
use App\Support\InvoiceReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the weekly Query Raised Report contains: every invoice Finance has queried and is still
 * waiting on, grouped by department, with the Department Head(s) each group goes to.
 *
 * Deliberately not scoped through `Invoice::scopeVisibleTo`: nobody is logged in when it runs, and
 * who sees a department's list is decided by the admin's Head nomination instead. Each group holds
 * one department's invoices only. The row values come from `InvoiceReport`, so they read the same
 * as the Reports page.
 *
 * CR: ai/change-requests/schedule-weekly-query-raised-report-email-to-department-heads.md
 */
class QueryRaisedReportService
{
    /**
     * One entry per department that has open queries, oldest query first within each.
     *
     * @return Collection<int, array{name: string, department: ?Department, recipients: Collection, rows: array<int, array<string, mixed>>, totals: array<string, float>}>
     */
    public function groups(): Collection
    {
        $departments = Department::query()
            ->with(['heads' => fn ($q) => $q->where('users.is_active', true)->whereNotNull('users.email')])
            ->get()
            ->keyBy(fn (Department $d) => Department::normalizeName($d->name));

        return Invoice::query()
            ->where('status', Invoice::STATUS_QUERY)
            ->with(InvoiceReport::RELATIONS)
            ->get()
            ->groupBy(fn (Invoice $invoice) => Department::normalizeName($invoice->department))
            ->map(function (Collection $invoices, string $key) use ($departments) {
                $department = $departments->get($key);
                $rows = $invoices->map(fn (Invoice $invoice) => $this->row($invoice))
                    ->sortBy('raised_at')
                    ->values();

                return [
                    // The master-data spelling where there is one; otherwise what the invoice says.
                    'name' => $department?->name ?? (trim((string) $invoices->first()->department) ?: '(no department)'),
                    'department' => $department,
                    'recipients' => $department?->heads ?? collect(),
                    'rows' => $rows->all(),
                    'totals' => $rows->groupBy('currency')
                        ->map(fn (Collection $r) => round((float) $r->sum('amount'), 2))
                        ->sortKeys()
                        ->all(),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /** @return array<string, mixed> */
    private function row(Invoice $invoice): array
    {
        $report = InvoiceReport::row($invoice);

        // The latest query: a query answered and raised again is waiting since it was raised again.
        $raisedAt = $invoice->queryLogs->last()?->created_at ?? $invoice->updated_at;

        return [
            'id' => $invoice->id,
            'reference_no' => $report['reference_no'],
            'invoice_no' => $report['invoice_no'],
            // A queried invoice is outside the payment cycle, so this is usually a PAF it was on
            // before (a rejected one), and usually blank.
            'paf_no' => implode(', ', array_filter([$report['payment_request'], $report['rejected_payment_requests']])),
            'vendor' => $report['vendor'],
            'currency' => (string) $report['currency'],
            'amount' => (float) $report['total'],
            'submitter' => $report['requested_by'],
            'query' => $invoice->finance_remarks,
            'raised_at' => $raisedAt?->format('Y-m-d H:i'),
            'days_open' => $raisedAt ? (int) Carbon::parse($raisedAt)->startOfDay()->diffInDays(now()->startOfDay()) : null,
            'remarks' => $report['remarks'],
            'url' => url('/invoices/'.$invoice->id),
        ];
    }
}
