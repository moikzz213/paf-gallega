<?php

namespace App\Mail;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentRequestApproved extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The mailable is queued, so the model is re-fetched when the job runs. If it has been deleted
     * by then there is nothing to notify anyone about — drop the job rather than fail it.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public PaymentRequest $paymentRequest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment Request {$this->paymentRequest->reference_no} Fully Approved",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-request-approved',
        );
    }
}
