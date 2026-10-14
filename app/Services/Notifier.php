<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a notification without letting it break the thing it is notifying about.
 *
 * Every caller here has already committed a state change — an invoice is queried, a request is
 * approved — so a mail problem must not surface as a failed action. Mailables are queued, so the
 * usual SMTP faults (a rotated password, a timeout) now happen inside a retryable job rather than
 * the request; what is caught here is the rarer case of the dispatch itself failing, or a queue
 * connection running synchronously.
 */
class Notifier
{
    /**
     * Hand a mailable to the queue. Returns false when it could not even be accepted, having
     * logged why — the caller reports that to the user instead of failing outright.
     *
     * @param  string  $context  what was being notified, for the log line
     */
    public static function send(?string $email, Mailable $mailable, string $context): bool
    {
        if (! $email) {
            Log::warning("Notification skipped ({$context}): no email address on the recipient.");

            return false;
        }

        try {
            Mail::to($email)->send($mailable);

            return true;
        } catch (Throwable $e) {
            Log::error("Notification failed ({$context}) to {$email}: {$e->getMessage()}", [
                'mailable' => $mailable::class,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Hand one mailable to the queue for several people at once: a single email, every recipient in
     * BCC, so nobody sees anyone else's address. Addressed "To" the system's own sender mailbox,
     * which is what the recipients see in that field. Duplicates and blanks are dropped first.
     *
     * @param  iterable<int, string|null>  $emails
     */
    public static function sendBcc(iterable $emails, Mailable $mailable, string $context): bool
    {
        $emails = collect($emails)->filter()->map(fn ($e) => strtolower(trim($e)))->unique()->values()->all();

        if (! $emails) {
            Log::warning("Notification skipped ({$context}): no recipients with an email address.");

            return false;
        }

        try {
            $from = config('mail.from.address');
            $pending = $from ? Mail::to($from)->bcc($emails) : Mail::bcc($emails);
            $pending->send($mailable);

            return true;
        } catch (Throwable $e) {
            Log::error("Notification failed ({$context}) to ".count($emails)." BCC recipient(s): {$e->getMessage()}", [
                'mailable' => $mailable::class,
                'exception' => $e,
            ]);

            return false;
        }
    }
}
