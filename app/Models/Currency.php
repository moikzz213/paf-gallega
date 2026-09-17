<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Currency extends Model
{
    protected $fillable = ['name', 'exchange_rate', 'is_active'];

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    /** The currency approval thresholds are expressed in. */
    public static function base(): string
    {
        return strtoupper((string) config('paf.base_currency', 'AED'));
    }

    /**
     * Units of base currency per 1 unit of $code, or null when no rate is on record.
     *
     * The base currency is always 1 even if nobody has configured it, so a single-currency
     * installation needs no master-data upkeep at all.
     */
    public static function rateToBase(string $code): ?float
    {
        $code = strtoupper(trim($code));

        if ($code === '' || $code === self::base()) {
            return 1.0;
        }

        $rate = static::where('name', $code)->value('exchange_rate');

        return $rate === null ? null : (float) $rate;
    }

    /**
     * Convert an amount into the base currency, refusing rather than guessing.
     *
     * A missing rate throws: silently treating an unrated currency as 1:1 is exactly the bug this
     * exists to fix — it would send a USD invoice through the approval levels of an AED one.
     */
    public static function toBase(float $amount, string $code): float
    {
        $rate = static::rateToBase($code);

        if ($rate === null) {
            throw ValidationException::withMessages([
                'currency' => "No exchange rate is configured for {$code}. Ask an administrator to set its rate to ".self::base().' under Master Data → Currencies before sending this for approval.',
            ]);
        }

        return round($amount * $rate, 2);
    }
}
