<?php

namespace App\Mail;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentRequestSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PaymentRequest $paymentRequest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New Payment Request {$this->paymentRequest->reference_no} Awaiting Your Approval",
        );
    }

    public function content(): Content
    {
        $this->paymentRequest->loadMissing([
            'creator:id,name',
            'invoices.submitter:id,name',
            'invoices.items.customer:id,name',
            'approvals.approver:id,name',
        ]);

        return new Content(
            view: 'emails.payment-request-submitted',
        );
    }
}
