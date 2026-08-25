<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalLevel extends Model
{
    protected $fillable = ['level', 'name', 'min_amount', 'default_approver_id', 'is_active'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'min_amount' => 'decimal:2',
            'default_approver_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** The user pre-filled onto a request's chain for this level (editable per request). */
    public function defaultApprover()
    {
        return $this->belongsTo(User::class, 'default_approver_id');
    }

    /**
     * Levels a given total must pass through, in order.
     *
     * `$totalAmount` must already be in the base currency (see `Currency::toBase`) — `min_amount`
     * is an amount in that currency, so a raw foreign-currency figure compared against it routes on
     * the wrong tier. Callers converting an invoice set should use `requiredForInvoices`.
     */
    public static function requiredFor(float $totalAmount)
    {
        return static::where('is_active', true)
            ->where('min_amount', '<=', $totalAmount)
            ->orderBy('level')
            ->get();
    }

    /**
     * Levels an invoice set must pass through, measured on its base-currency worth.
     *
     * Converted invoice by invoice rather than on the summed total, so the figure stays right even
     * for the legacy requests that mix currencies (creation has refused mixed sets since
     * PaymentRequestService::create). Throws when any currency involved has no rate on record.
     */
    public static function requiredForInvoices($invoices)
    {
        $base = collect($invoices)->reduce(
            fn (float $carry, $invoice) => $carry + Currency::toBase((float) $invoice->total_amount, (string) $invoice->currency),
            0.0,
        );

        return static::requiredFor(round($base, 2));
    }
}
