<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells an invoice's submitter that Finance corrected it after posting it to the ERP. Before this
 * correction existed, the same change went back to the submitter as a query for them to make; now
 * Finance makes it directly, so the submitter is told what changed instead.
 *
 * CR: ai/change-requests/allow-finance-to-correct-posted-invoices-before-payment.md
 */
class InvoiceCorrectedAfterPosting extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Re-fetched when the job runs; nothing to say if the invoice has since gone. */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  array<int, string>  $changed  readable names of what the correction changed
     */
    public function __construct(
        public Invoice $invoice,
        public User $corrector,
        public string $previousCurrency,
        public float $previousTotal,
        public array $changed,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Invoice {$this->invoice->reference_no} corrected by Finance",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-corrected-after-posting',
        );
    }
}
