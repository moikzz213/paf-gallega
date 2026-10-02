<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the Finance user who raised a payment request that one of its invoices was corrected by its
 * submitter while the request was in approval. Finance posted that invoice to the ERP from the old
 * figures, so the ERP entry may need adjusting to match.
 */
class InvoiceCorrectedDuringApproval extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Re-fetched when the job runs; nothing to say if the invoice has since gone. */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  array<int, string>  $changed  readable names of what the correction changed
     */
    public function __construct(
        public Invoice $invoice,
        public PaymentRequest $paymentRequest,
        public User $corrector,
        public float $previousTotal,
        public array $changed,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Invoice {$this->invoice->reference_no} corrected during approval of {$this->paymentRequest->reference_no}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-corrected-during-approval',
        );
    }
}
