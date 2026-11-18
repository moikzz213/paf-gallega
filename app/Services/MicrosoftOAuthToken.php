<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The access token SMTP signs in with when MAIL_SMTP_AUTH=oauth: a client-credentials grant
 * against the Microsoft 365 tenant, for the Exchange Online SMTP scope.
 *
 * Held in memory rather than the cache store: it is a bearer credential, and the queue worker —
 * the process that actually sends — lives long enough to reuse it across many emails. It is
 * renewed shortly before Microsoft's stated expiry, so a long-running worker never presents a
 * stale one.
 */
class MicrosoftOAuthToken
{
    /** Renew this many seconds before the token's stated expiry. */
    private const EXPIRY_MARGIN = 300;

    private ?string $token = null;

    private int $expiresAt = 0;

    /**
     * @param  array{tenant_id?: ?string, client_id?: ?string, client_secret?: ?string, scope?: ?string}  $config
     */
    public function __construct(private array $config) {}

    public function get(): string
    {
        if ($this->token !== null && now()->getTimestamp() < $this->expiresAt) {
            return $this->token;
        }

        $tenant = $this->config['tenant_id'] ?? null;
        $clientId = $this->config['client_id'] ?? null;
        $secret = $this->config['client_secret'] ?? null;

        if (! $tenant || ! $clientId || ! $secret) {
            throw new RuntimeException(
                'MAIL_SMTP_AUTH=oauth needs MICROSOFT_TENANT_ID, MICROSOFT_CLIENT_ID and MICROSOFT_CLIENT_SECRET to be set.'
            );
        }

        $response = Http::asForm()->timeout(15)->post(
            'https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $secret,
                'scope' => $this->config['scope'] ?? 'https://outlook.office365.com/.default',
            ]
        );

        if ($response->failed() || ! $response->json('access_token')) {
            // Microsoft's own error text names the cause (bad secret, unknown tenant, no consent)
            // without echoing the secret back.
            $reason = $response->json('error_description') ?? $response->json('error') ?? "HTTP {$response->status()}";

            throw new RuntimeException("Microsoft OAuth token request failed: {$reason}");
        }

        $this->token = $response->json('access_token');
        $this->expiresAt = now()->getTimestamp() + max(0, (int) $response->json('expires_in', 3600) - self::EXPIRY_MARGIN);

        return $this->token;
    }

    /** Drop the held token so the next sign-in fetches a fresh one (e.g. after SMTP rejects it). */
    public function forget(): void
    {
        $this->token = null;
        $this->expiresAt = 0;
    }
}
