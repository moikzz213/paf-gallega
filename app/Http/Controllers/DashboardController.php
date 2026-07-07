<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $visible = fn () => Invoice::query()->visibleTo($user);

        $cards = [
            'total_requests' => $visible()->count(),
            'pending' => [
                'count' => $visible()->where('status', Invoice::STATUS_PENDING)->count(),
                'amount' => (float) $visible()->where('status', Invoice::STATUS_PENDING)->sum('total_amount'),
            ],
            'awaiting_payment' => [
                'count' => $visible()->whereIn('status', [Invoice::STATUS_APPROVED, Invoice::STATUS_SCHEDULED])->count(),
                'amount' => (float) $visible()->whereIn('status', [Invoice::STATUS_APPROVED, Invoice::STATUS_SCHEDULED])->sum('total_amount'),
            ],
            'paid_this_month' => [
                'count' => $visible()->where('status', Invoice::STATUS_PAID)->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
                'amount' => (float) $visible()->where('status', Invoice::STATUS_PAID)->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('total_amount'),
            ],
        ];

        // approvers: how many are sitting on my desk right now
        $myQueue = 0;
        if ($user->isApprover()) {
            $myQueue = Invoice::where('status', Invoice::STATUS_PENDING)
                ->where('current_level', $user->approval_level)
                ->count();
        } elseif ($user->isAdmin()) {
            $myQueue = Invoice::where('status', Invoice::STATUS_PENDING)->count();
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
                'paid' => (float) $visible()->where('status', Invoice::STATUS_PAID)->whereBetween('paid_at', [$from, $to])->sum('total_amount'),
            ];
        }

        $topVendors = $visible()
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED, Invoice::STATUS_REJECTED])
            ->select('vendor_name', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as amount'))
            ->groupBy('vendor_name')
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        $byCategory = $visible()
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED, Invoice::STATUS_REJECTED])
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
