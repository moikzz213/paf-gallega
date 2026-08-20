<?php

namespace App\Mail;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentRequestReminder extends Mailable implements ShouldQueue
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
            subject: "Reminder: Payment Request {$this->paymentRequest->reference_no} Still Awaiting Your Approval",
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
            view: 'emails.payment-request-reminder',
        );
    }
}
