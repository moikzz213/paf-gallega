<?php

namespace App\Mail;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the requestor(s) that a payment request was rejected, and why.
 *
 * No longer sent: approvers raise an Approval Query instead of rejecting (see
 * PaymentRequestQueryRaised). Kept only so a rejection notice still sitting in the queue at the
 * time of that release can be delivered; remove it once the queue has drained (technical debt).
 *
 * Unlike the approved mailable this cannot read the request's invoices: rejection returns them to
 * the Invoice Log by clearing `payment_request_id`, so by the time the queued job re-fetches the
 * model the relation is empty and `purpose` / `vendor_label` — both derived from it — have
 * degraded to their "nothing recorded" fallbacks. The caller snapshots what is needed before the
 * detach and passes it in; only the reason, which lives on the request itself, is read from the
 * model.
 */
class PaymentRequestRejected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  string  $summary  subject-line summary captured before the invoices were detached
     * @param  array<int, array{reference_no: string, vendor_name: string, invoice_no: string, total_amount: string|float, currency: string}>  $invoiceLines
     */
    public function __construct(
        public PaymentRequest $paymentRequest,
        public string $summary,
        public array $invoiceLines = [],
        public ?string $rejectedBy = null,
        public ?string $stageLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Rejected: {$this->summary}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-request-rejected',
            with: ['totalCurrency' => $this->totalCurrency()],
        );
    }

    /**
     * The currency of the total. The request's own where it is recorded; otherwise the one its
     * invoice lines were captured in — read from the request alone, a notice rendered after the
     * invoices had been returned found none and printed the total as AED (a USD 22,875 request
     * showed "AED 22,875.00" above invoice lines in USD). Blank, never AED, if neither says.
     */
    public function totalCurrency(): string
    {
        if ($this->paymentRequest->currency !== '') {
            return $this->paymentRequest->currency;
        }

        $codes = collect($this->invoiceLines)->pluck('currency')->filter()->unique();

        return match ($codes->count()) {
            0 => '',
            1 => (string) $codes->first(),
            default => 'MULTI-CURRENCY',
        };
    }
}
