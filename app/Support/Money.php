<?php

namespace App\Support;

class Money
{
    /**
     * Accounting presentation of an amount, for the documents a person signs off.
     *
     * A vendor credit note is stored as a negative line, and on a dense PDF table or an email a
     * leading minus sign is easy to miss — parentheses are not. Mirrors `money()` in
     * resources/js/utils/format.js so the screen, the PDF, the public view and the emails all
     * render a credit the same way.
     */
    public static function format(float|string|null $value, ?string $currency = 'AED'): string
    {
        $amount = round((float) ($value ?? 0), 2);
        $figure = number_format(abs($amount), 2);
        $figure = $amount < 0 ? "({$figure})" : $figure;

        // A currency passed in blank is unknown, and stays blank. Substituting AED here is what
        // labelled a USD payment request as AED once its invoices had been returned.
        $code = trim((string) $currency);

        return $code === '' ? $figure : "{$code} {$figure}";
    }
}
