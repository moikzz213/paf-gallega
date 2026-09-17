<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Support\DashboardPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(DashboardPeriod::rules());

        $user = $request->user();
        $period = DashboardPeriod::fromRequest($request);

        $visible = fn () => Invoice::query()->visibleTo($user);

        // Each metric is anchored on the date it is actually about, not on one shared column:
        // work that entered the system by when it was submitted, payments by when they were made,
        // and spend analysis by the invoice's own date. The cards therefore describe overlapping
        // but not identical sets — an invoice can be paid in a period it was not submitted in.
        $submitted = fn () => $period->apply($visible(), 'submitted_at');
        $dated = fn () => $period->apply($visible(), 'invoice_date');
        $paid = fn () => $visible()
            ->where('payment_status', Invoice::PAY_PAID)
            ->whereHas('paymentRequest', fn ($p) => $period->apply($p, 'paid_at'));

        $cards = [
            'total_invoices' => $submitted()->count(),
            'awaiting_posting' => [
                'count' => $submitted()->where('status', Invoice::STATUS_SUBMITTED)->count(),
                'amount' => (float) $submitted()->where('status', Invoice::STATUS_SUBMITTED)->sum('total_amount'),
            ],
            'in_approval' => [
                'count' => $submitted()->where('payment_status', Invoice::PAY_IN_APPROVAL)->count(),
                'amount' => (float) $submitted()->where('payment_status', Invoice::PAY_IN_APPROVAL)->sum('total_amount'),
            ],
            'paid' => [
                'count' => $paid()->count(),
                'amount' => (float) $paid()->sum('total_amount'),
            ],
        ];

        // approvers: payment requests sitting on my desk right now. Deliberately NOT period-filtered
        // — this is a live work queue, and hiding a pending approval behind a date range would mean
        // real work going unseen.
        $myQueue = 0;
        if ($user->isApprover()) {
            $myQueue = PaymentRequest::where('status', PaymentRequest::STATUS_IN_APPROVAL)
                ->whereHas('approvals', fn ($a) => $a
                    ->whereColumn('sequence', 'payment_requests.current_stage')
                    ->where('approver_id', $user->id))
                ->count();
        } elseif ($user->isAdmin()) {
            $myQueue = PaymentRequest::where('status', PaymentRequest::STATUS_IN_APPROVAL)->count();
        }

        $statusDistribution = $submitted()
            ->select('status', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as amount'))
            ->groupBy('status')
            ->get();

        // submitted vs paid amounts, one bar per month of the selected period
        $monthly = [];
        foreach ($period->trendMonths() as $from) {
            $to = $from->endOfMonth();
            $monthly[] = [
                'month' => $from->format('M Y'),
                'submitted' => (float) $visible()->whereBetween('submitted_at', [$from, $to])->sum('total_amount'),
                'paid' => (float) $visible()
                    ->where('payment_status', Invoice::PAY_PAID)
                    ->whereHas('paymentRequest', fn ($p) => $p->whereBetween('paid_at', [$from, $to]))
                    ->sum('total_amount'),
            ];
        }

        $topVendors = $dated()
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->select('vendor_name', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as amount'))
            ->groupBy('vendor_name')
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        $byBusinessUnit = $dated()
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->select('business_unit', DB::raw('sum(total_amount) as amount'))
            ->groupBy('business_unit')
            ->orderByDesc('amount')
            ->get();

        $recent = $submitted()
            ->with('submitter:id,name')
            ->latest()
            ->limit(8)
            ->get();

        return response()->json([
            'period' => $period->toArray(),
            'cards' => $cards,
            'my_queue' => $myQueue,
            'status_distribution' => $statusDistribution,
            'monthly' => $monthly,
            'top_vendors' => $topVendors,
            'by_business_unit' => $byBusinessUnit,
            'recent' => $recent,
        ]);
    }
}
