<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Support\CurrencyTotals;
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

        // One total per currency: a sum across currencies is no amount at all (see CurrencyTotals).
        $cards = [
            'total_invoices' => $submitted()->count(),
            'awaiting_posting' => [
                'count' => $submitted()->where('status', Invoice::STATUS_SUBMITTED)->count(),
                'amounts' => CurrencyTotals::byCurrency($submitted()->where('status', Invoice::STATUS_SUBMITTED)),
            ],
            'in_approval' => [
                'count' => $submitted()->where('payment_status', Invoice::PAY_IN_APPROVAL)->count(),
                'amounts' => CurrencyTotals::byCurrency($submitted()->where('payment_status', Invoice::PAY_IN_APPROVAL)),
            ],
            'paid' => [
                'count' => $paid()->count(),
                'amounts' => CurrencyTotals::byCurrency($paid()),
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

        // Payment requests on hold for an approval query that this user can answer — Finance and admins
        // any, everyone else those holding an invoice they submitted. Live, like the queue above.
        $queriedQuery = PaymentRequest::where('status', PaymentRequest::STATUS_QUERIED);
        if (! $user->isAdmin() && ! $user->isFinance()) {
            $queriedQuery->whereHas('invoices', fn ($i) => $i->where('submitted_by', $user->id));
        }
        $awaitingAnswer = $queriedQuery->count();

        // Counts only — the chart is of how many, and an amount here would mix currencies.
        $statusDistribution = $submitted()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        // The charts need one number per bar, so their amounts are converted to the base currency at
        // the configured rates and labelled as such. A currency with no rate is left out and named.
        $unrated = [];
        // A closure, not an arrow function: it has to add to $unrated, which an arrow fn would copy.
        $toBase = function ($query, string $key) use (&$unrated) {
            return CurrencyTotals::toBase(
                $query->select(array_unique([$key, 'currency']))->addSelect(DB::raw('sum(total_amount) as amount'))
                    ->groupBy(array_unique([$key, 'currency']))->get(),
                $key,
                $unrated,
            );
        };

        // One base-currency figure for a whole set of invoices.
        $baseSum = fn ($query) => (float) $toBase($query, 'currency')->sum();

        // submitted vs paid amounts, one bar per month of the selected period
        $monthly = [];
        foreach ($period->trendMonths() as $from) {
            $to = $from->endOfMonth();
            $monthly[] = [
                'month' => $from->format('M Y'),
                'submitted' => $baseSum($visible()->whereBetween('submitted_at', [$from, $to])),
                'paid' => $baseSum($visible()
                    ->where('payment_status', Invoice::PAY_PAID)
                    ->whereHas('paymentRequest', fn ($p) => $p->whereBetween('paid_at', [$from, $to]))),
            ];
        }

        $topVendors = $toBase($dated()->where('status', '!=', Invoice::STATUS_CANCELLED), 'vendor_name')
            ->sortDesc()
            ->take(5)
            ->map(fn ($amount, $vendor) => ['vendor_name' => $vendor, 'amount' => $amount])
            ->values();

        $byBusinessUnit = $toBase($dated()->where('status', '!=', Invoice::STATUS_CANCELLED), 'business_unit')
            ->sortDesc()
            ->map(fn ($amount, $unit) => ['business_unit' => $unit, 'amount' => $amount])
            ->values();

        $recent = $submitted()
            ->with('submitter:id,name')
            ->latest()
            ->limit(8)
            ->get();

        return response()->json([
            'period' => $period->toArray(),
            'cards' => $cards,
            'my_queue' => $myQueue,
            'awaiting_query_answer' => $awaitingAnswer,
            'status_distribution' => $statusDistribution,
            'monthly' => $monthly,
            'top_vendors' => $topVendors,
            // The currency the chart amounts are expressed in, and any left out for want of a rate.
            'chart_currency' => Currency::base(),
            'unconverted_currencies' => array_values($unrated),
            'by_business_unit' => $byBusinessUnit,
            'recent' => $recent,
        ]);
    }
}
