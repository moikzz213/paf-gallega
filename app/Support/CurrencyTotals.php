<?php

namespace App\Support;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Totals that never add one currency to another.
 *
 * The dashboard and the report summary used to sum `total_amount` across every invoice and label the
 * result AED, so a USD 10,000 invoice counted as 10,000 towards an "AED" figure. A total is now
 * either kept apart per currency (`byCurrency`), or — for a chart, which needs one number — converted
 * to the base currency at the rates in Master Data → Currencies (`toBase`), with any currency that has
 * no rate left out and named rather than counted 1:1.
 *
 * CR: ai/change-requests/fix-wrong-currency-on-payment-requests-and-dashboard-totals.md
 */
class CurrencyTotals
{
    /**
     * One total per currency, the most-used currency first.
     *
     * @return array<int, array{currency: string, count: int, amount: float}>
     */
    public static function byCurrency(Builder $query, string $column = 'total_amount'): array
    {
        return (clone $query)
            ->reorder()
            ->selectRaw("currency, count(*) as aggregate_count, sum({$column}) as aggregate_amount")
            ->groupBy('currency')
            ->get()
            ->map(fn ($row) => [
                'currency' => (string) $row->currency,
                'count' => (int) $row->aggregate_count,
                'amount' => round((float) $row->aggregate_amount, 2),
            ])
            ->sortBy([['count', 'desc'], ['amount', 'desc']])
            ->values()
            ->all();
    }

    /**
     * Rows of {currency, amount, ...} grouped by `$key` and converted to the base currency.
     *
     * @param  Collection<int, object>  $rows  each with `currency`, `amount` and the `$key` column
     * @param  array<int, string>  $unrated  filled with the currencies left out for want of a rate
     * @return Collection<string, float> base-currency amount per key
     */
    public static function toBase(Collection $rows, string $key, array &$unrated): Collection
    {
        $rates = [];

        return $rows
            ->groupBy(fn ($row) => (string) $row->{$key})
            ->map(function (Collection $group) use (&$rates, &$unrated) {
                return round($group->sum(function ($row) use (&$rates, &$unrated) {
                    $code = strtoupper((string) $row->currency);
                    $rates[$code] ??= Currency::rateToBase($code);

                    if ($rates[$code] === null) {
                        $unrated[$code] = $code;

                        return 0;
                    }

                    return (float) $row->amount * $rates[$code];
                }), 2);
            });
    }
}
