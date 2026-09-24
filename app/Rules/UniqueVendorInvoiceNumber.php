<?php

namespace App\Rules;

use App\Models\Invoice;
use App\Models\Vendor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * A vendor's invoice number may be recorded only once for that vendor.
 *
 * The database carries the same rule as a unique index, which is what makes it hold under two
 * simultaneous submissions. This is the half that can explain itself: it names the invoice that
 * already holds the number, so the person entering it can go and look rather than guess what they
 * have collided with.
 *
 * The comparison is spelled out here rather than left to the database on purpose. Production
 * compares `invoice_no` under `utf8mb4_unicode_ci`, which ignores case; the test suite runs on
 * SQLite, which does not. A rule that leaned on the column's collation would pass its tests and
 * behave differently in production — so both sides are normalised explicitly and the two agree.
 */
class UniqueVendorInvoiceNumber implements ValidationRule
{
    /**
     * @param  int|null  $vendorId  the vendor being submitted against; no vendor, nothing to scope to
     * @param  int|null  $ignoreInvoiceId  the invoice being corrected, which must not block itself
     */
    public function __construct(
        private ?int $vendorId,
        private ?int $ignoreInvoiceId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The vendor rule reports a missing or invalid vendor itself; without one there is no
        // scope to check within, and a second complaint about the number would only mislead.
        if (! $this->vendorId || ! is_string($value) || trim($value) === '') {
            return;
        }

        // Petty cash floats, staff reimbursements and fuel claims issue no vendor invoice number.
        // Submitters type a word in the field instead — `petty cash`, `na`, `august` — so the same
        // text recurs legitimately, month after month, over genuinely different transactions.
        // Enforcing uniqueness there would have refused ordinary petty cash from day one.
        if (Vendor::whereKey($this->vendorId)->value('is_expense_account')) {
            return;
        }

        $existing = Invoice::query()
            ->where('vendor_id', $this->vendorId)
            // Grandfathered historic duplicates hold no number: they were already paid or in a
            // payment cycle when the rule arrived and could not be cancelled without rewriting
            // payment history. The earliest invoice of each group still holds it.
            ->where('invoice_no_exempt', false)
            // A cancelled invoice is a withdrawn record. Holding its number would let one
            // mis-entry lock out the real invoice permanently.
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->when($this->ignoreInvoiceId, fn ($query, $id) => $query->whereKeyNot($id))
            ->whereRaw('LOWER(TRIM(invoice_no)) = ?', [Str::lower(trim($value))])
            ->first();

        if (! $existing) {
            return;
        }

        $fail(
            "Invoice number {$existing->invoice_no} is already recorded for this vendor on "
            ."{$existing->reference_no}. Check that invoice before submitting this one — if they "
            .'are the same document, it has already been received.'
        );
    }
}
