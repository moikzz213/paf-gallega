<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One department's open Finance queries, for its Department Head(s). Carries the rows as plain
 * values rather than models: it reports the state at the moment the report ran, and nothing about
 * it should change if an invoice is answered while the email waits in the queue. No attachments.
 *
 * CR: ai/change-requests/schedule-weekly-query-raised-report-email-to-department-heads.md
 */
class QueryRaisedWeeklyReport extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array<string, mixed>>  $rows  see QueryRaisedReportService::row()
     * @param  array<string, float>  $totals  currency => total
     */
    public function __construct(
        public string $department,
        public array $rows,
        public array $totals,
        public string $reportDate,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->rows);

        return new Envelope(
            subject: "Weekly Query Raised Report - {$this->department} - {$count} open ".($count === 1 ? 'query' : 'queries'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.query-raised-weekly-report');
    }
}
