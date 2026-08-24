<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetLink extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Queued, so the user is re-fetched when the job runs; a deleted account needs no reset mail. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public User $user, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password');
    }

    public function content(): Content
    {
        $broker = config('auth.defaults.passwords');

        return new Content(view: 'emails.password-reset', with: [
            // Points at the SPA route, not a Laravel web route: the reset form is a Vue page.
            'resetUrl' => rtrim(config('app.url'), '/').'/reset-password?'.http_build_query([
                'token' => $this->token,
                'email' => $this->user->email,
            ]),
            'expiresInMinutes' => (int) config("auth.passwords.{$broker}.expire", 60),
        ]);
    }
}
