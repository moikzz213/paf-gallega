<?php

namespace App\Mail;

use App\Models\PaymentRequest;
use App\Models\PaymentRequestApproval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells one approver that a payment request is waiting on their stage.
 *
 * The stage is passed in rather than looked up, and that is the whole point. This mailable is
 * queued, so it renders in a worker some time after it was dispatched, and it used to resolve both
 * the greeting and the approval link through `PaymentRequest::currentApproval()` — which reads
 * `current_stage` at render time. By then the request has often moved: a later stage approved, or
 * the request approved or rejected outright, each of which sets `current_stage` to null. The lookup
 * then found nothing, the link could not be generated, and the job died with an
 * UrlGenerationException. Nobody was told, and nothing surfaced to the person who had approved.
 *
 * It showed up on the Approvals screen first because that is where a chain moves fastest: an admin
 * may act on any stage, and several requests are driven through in the time the queue takes to send
 * the emails announcing their earlier stages.
 *
 * The stage a request is waiting on is settled the moment someone approves, so that is when it is
 * captured. The approval record is stable once chosen — its approver and its token do not change —
 * so serialising it is safe in a way that re-deriving it from mutable state never was.
 */
class PaymentRequestSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The mailable is queued, so the model is re-fetched when the job runs. If it has been deleted
     * by then there is nothing to notify anyone about — drop the job rather than fail it.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public PaymentRequest $paymentRequest,
        public ?PaymentRequestApproval $approval = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Approval Required: {$this->paymentRequest->subjectSummary()}",
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
