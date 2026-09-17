<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceQueryRaised extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The mailable is queued, so the model is re-fetched when the job runs. If it has been deleted
     * by then there is nothing to notify anyone about — drop the job rather than fail it.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Invoice $invoice,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Query Raised on Invoice {$this->invoice->reference_no}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-query-raised',
        );
    }
}
