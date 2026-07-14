<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $visible = fn () => Invoice::query()->visibleTo($user);
        $paidThisMonth = fn () => $visible()
            ->where('payment_status', Invoice::PAY_PAID)
            ->whereHas('paymentRequest', fn ($p) => $p->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()]));

        $cards = [
            'total_invoices' => $visible()->count(),
            'awaiting_posting' => [
                'count' => $visible()->where('status', Invoice::STATUS_SUBMITTED)->count(),
                'amount' => (float) $visible()->where('status', Invoice::STATUS_SUBMITTED)->sum('total_amount'),
            ],
            'in_approval' => [
                'count' => $visible()->where('payment_status', Invoice::PAY_IN_APPROVAL)->count(),
                'amount' => (float) $visible()->where('payment_status', Invoice::PAY_IN_APPROVAL)->sum('total_amount'),
            ],
            'paid_this_month' => [
                'count' => $paidThisMonth()->count(),
                'amount' => (float) $paidThisMonth()->sum('total_amount'),
            ],
        ];

        // approvers: payment requests sitting on my desk right now
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

        $statusDistribution = $visible()
            ->select('status', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as amount'))
            ->groupBy('status')
            ->get();

        // last 6 months: submitted vs paid amounts
        $start = now()->startOfMonth()->subMonths(5);
        $monthly = [];
        for ($i = 0; $i < 6; $i++) {
            $from = $start->copy()->addMonths($i);
            $to = $from->copy()->endOfMonth();
            $monthly[] = [
                'month' => $from->format('M Y'),
                'submitted' => (float) $visible()->whereBetween('submitted_at', [$from, $to])->sum('total_amount'),
                'paid' => (float) $visible()
                    ->where('payment_status', Invoice::PAY_PAID)
                    ->whereHas('paymentRequest', fn ($p) => $p->whereBetween('paid_at', [$from, $to]))
                    ->sum('total_amount'),
            ];
        }

        $topVendors = $visible()
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->select('vendor_name', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as amount'))
            ->groupBy('vendor_name')
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        $byCategory = $visible()
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->select('category', DB::raw('sum(total_amount) as amount'))
            ->groupBy('category')
            ->orderByDesc('amount')
            ->get();

        $recent = $visible()
            ->with('submitter:id,name')
            ->latest()
            ->limit(8)
            ->get();

        return response()->json([
            'cards' => $cards,
            'my_queue' => $myQueue,
            'status_distribution' => $statusDistribution,
            'monthly' => $monthly,
            'top_vendors' => $topVendors,
            'by_category' => $byCategory,
            'recent' => $recent,
        ]);
    }
}
