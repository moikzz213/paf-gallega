<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The period the Dashboard reports on: a whole-month range, or All Time.
 *
 * The Dashboard used to mix windows — three cards all-time, one this-month, one chart pinned to the
 * last six months — with nothing on screen saying which was which. This is the single definition of
 * "the period" that every card and chart on that screen now shares. It stays deliberately separate
 * from `InvoiceReport`'s date filter: the report anchors everything on `invoice_date`, while the
 * Dashboard anchors each metric on the date that metric is actually about (submitted, paid, dated).
 */
final class DashboardPeriod
{
    /** A span long enough for any real question, short enough that no query can be made to crawl. */
    private const MAX_MONTHS = 120;

    /** The trend chart is unreadable past this many bars, so a longer span shows its most recent. */
    public const MAX_TREND_MONTHS = 24;

    private function __construct(
        public readonly ?CarbonImmutable $start,
        public readonly ?CarbonImmutable $end,
        public readonly bool $allTime,
    ) {}

    /** Validation rules for the request parameters this class reads. */
    public static function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'all' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Resolve the period from `from` / `to` (both `YYYY-MM`) and `all`. Everything is optional: with
     * no parameters the Dashboard opens on the year to date. An inverted range is swapped rather
     * than rejected — the user meant a span, and which box they typed it in first is not worth a
     * validation error.
     */
    public static function fromRequest(Request $request): self
    {
        if ($request->boolean('all')) {
            return new self(null, null, true);
        }

        $from = $request->filled('from') ? CarbonImmutable::createFromFormat('Y-m', $request->input('from')) : null;
        $to = $request->filled('to') ? CarbonImmutable::createFromFormat('Y-m', $request->input('to')) : null;

        // Default: year to date. One bound on its own anchors the other to today.
        $start = ($from ?? $to ?? CarbonImmutable::now()->startOfYear())->startOfMonth();
        $end = ($to ?? CarbonImmutable::now())->endOfMonth();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->startOfMonth(), $start->endOfMonth()];
        }

        if ($start->diffInMonths($end) >= self::MAX_MONTHS) {
            $start = $end->subMonths(self::MAX_MONTHS - 1)->startOfMonth();
        }

        return new self($start, $end, false);
    }

    /**
     * Narrow a query to the period on the given date column. All Time narrows nothing, which is what
     * makes every caller a plain one-liner regardless of which period is in force.
     */
    public function apply(Builder $query, string $column): Builder
    {
        return $this->allTime ? $query : $query->whereBetween($column, [$this->start, $this->end]);
    }

    /**
     * The months to plot on the trend chart, oldest first. All Time has no start to count from, so
     * it plots the most recent window — as does any span longer than the chart can legibly show.
     *
     * @return array<int, CarbonImmutable> each the first instant of a month
     */
    public function trendMonths(): array
    {
        $end = ($this->end ?? CarbonImmutable::now())->startOfMonth();
        $start = $this->allTime
            ? $end->subMonths(self::MAX_TREND_MONTHS - 1)
            : $this->start->startOfMonth();

        $count = min($start->diffInMonths($end) + 1, self::MAX_TREND_MONTHS);
        $first = $end->subMonths($count - 1);

        return array_map(fn (int $i) => $first->addMonths($i), range(0, $count - 1));
    }

    /** What the screen shows next to every figure, so no number is ever displayed without its window. */
    public function label(): string
    {
        if ($this->allTime) {
            return 'all time';
        }

        return $this->start->isSameMonth($this->end)
            ? $this->start->format('M Y')
            : $this->start->format('M Y').' – '.$this->end->format('M Y');
    }

    /** The resolved period, echoed to the client so it labels figures from what the server actually used. */
    public function toArray(): array
    {
        return [
            'from' => $this->start?->format('Y-m'),
            'to' => $this->end?->format('Y-m'),
            'all_time' => $this->allTime,
            'label' => $this->label(),
        ];
    }
}
