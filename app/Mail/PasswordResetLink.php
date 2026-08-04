<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetLink extends Mailable
{
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
