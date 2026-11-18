<?php

namespace App\Providers;

use App\Mail\Transport\MicrosoftOAuthAuthenticator;
use App\Services\MicrosoftOAuthToken;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MicrosoftOAuthToken::class, fn () => new MicrosoftOAuthToken(config('services.microsoft', [])));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // MAIL_SMTP_AUTH=oauth signs SMTP in with a Microsoft 365 token instead of MAIL_PASSWORD.
        // Password mode registers nothing, so it stays on Laravel's own SMTP transport.
        $this->callAfterResolving('mail.manager', function (MailManager $mail) {
            if (config('mail.mailers.smtp.auth') === 'oauth') {
                $mail->extend('smtp', fn (array $config) => $this->microsoftOAuthSmtpTransport($config));
            }
        });
    }

    /**
     * The same SMTP transport Laravel builds, signed in with XOAUTH2 only. The SMTP username is the
     * mailbox the app registration was granted; it falls back to the sender address.
     */
    private function microsoftOAuthSmtpTransport(array $config): EsmtpTransport
    {
        $port = $config['port'] ?? 587;

        /** @var EsmtpTransport $transport the SMTP factory only builds Esmtp transports */
        $transport = (new EsmtpTransportFactory)->create(new Dsn(
            $config['scheme'] ?? ($port == 465 ? 'smtps' : 'smtp'),
            $config['host'],
            ($config['username'] ?? null) ?: config('mail.from.address'),
            null,
            $port,
            $config
        ));

        $transport->setAuthenticators([new MicrosoftOAuthAuthenticator($this->app->make(MicrosoftOAuthToken::class))]);

        if (isset($config['timeout']) && $transport->getStream() instanceof SocketStream) {
            $transport->getStream()->setTimeout($config['timeout']);
        }

        return $transport;
    }
}
