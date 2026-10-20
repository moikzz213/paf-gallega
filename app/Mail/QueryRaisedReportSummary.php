<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * What the weekly Query Raised Report run sent, for Finance and admins: which departments were
 * reported to and who received each, any report that could not be sent, and any department with
 * open queries that nobody received. It does not repeat the invoice details.
 *
 * CR: ai/change-requests/schedule-weekly-query-raised-report-email-to-department-heads.md
 */
class QueryRaisedReportSummary extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{department: string, recipients: array<int, string>, count: int, sent: bool}>  $sent
     * @param  array<int, array{department: string, count: int}>  $withoutHead  in master data, no active Head
     * @param  array<int, array{department: string, count: int}>  $unmatched  on invoices, not in master data
     */
    public function __construct(
        public array $sent,
        public array $withoutHead,
        public array $unmatched,
        public string $reportDate,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Weekly Query Raised Report - summary of what was sent - {$this->reportDate}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.query-raised-report-summary');
    }
}
