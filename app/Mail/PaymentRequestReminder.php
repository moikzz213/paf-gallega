<?php

namespace App\Mail;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentRequestReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PaymentRequest $paymentRequest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reminder: Payment Request {$this->paymentRequest->reference_no} Still Awaiting Your Approval",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-request-reminder',
        );
    }
}
