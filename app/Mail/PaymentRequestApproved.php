<?php

namespace App\Mail;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentRequestApproved extends Mailable
{
    use Queueable, SerializesModels;

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
