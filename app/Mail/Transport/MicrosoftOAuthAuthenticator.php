<?php

namespace App\Mail\Transport;

use App\Services\MicrosoftOAuthToken;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\Auth\AuthenticatorInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * SMTP XOAUTH2 sign-in with a Microsoft 365 token, used when MAIL_SMTP_AUTH=oauth.
 *
 * Symfony's own XOAuth2Authenticator reads the token from the transport's password, which is
 * fixed when the transport is built — and the queue worker keeps one transport for its whole
 * life, well past a token's hour. This one asks for the token on every connection instead.
 */
class MicrosoftOAuthAuthenticator implements AuthenticatorInterface
{
    public function __construct(private MicrosoftOAuthToken $token) {}

    public function getAuthKeyword(): string
    {
        return 'XOAUTH2';
    }

    public function authenticate(EsmtpTransport $client): void
    {
        $sasl = base64_encode('user='.$client->getUsername()."\1auth=Bearer ".$this->token->get()."\1\1");

        try {
            $client->executeCommand("AUTH XOAUTH2 {$sasl}\r\n", [235]);
        } catch (UnexpectedResponseException $e) {
            // A revoked or otherwise refused token must not be reused on the retry.
            $this->token->forget();

            throw $e;
        }
    }
}
