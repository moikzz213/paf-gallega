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
}
