<?php

namespace App\Mail;

use App\Models\PaymentRequest;
use App\Models\PaymentRequestQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An approver raised an Approval Query, so the request is on hold until it is answered.
 *
 * Sent once per query as a single BCC email (Notifier::sendBcc) — the recipients depend on the stage
 * that asked, see PaymentRequestService::queryRecipients. The same mailable is the two-day reminder
 * to the people who must answer it, with `$reminder` set.
 *
 * CR: ai/change-requests/replace-reject-with-query-in-payment-approval.md
 */
class PaymentRequestQueryRaised extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Re-fetched when the job runs; nothing to say if the request has since gone. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public PaymentRequest $paymentRequest,
        public PaymentRequestQuery $query,
        public bool $reminder = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->reminder ? 'Reminder — ' : '')."Approval Query: {$this->paymentRequest->subjectSummary()}",
        );
    }

    public function content(): Content
    {
        $this->paymentRequest->loadMissing(['invoices.submitter:id,name']);
        $this->query->loadMissing('raiser:id,name');

        return new Content(
            view: 'emails.payment-request-query-raised',
        );
    }
}
