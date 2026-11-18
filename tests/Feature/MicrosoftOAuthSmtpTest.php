<?php

namespace Tests\Feature;

use App\Mail\Transport\MicrosoftOAuthAuthenticator;
use App\Services\MicrosoftOAuthToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * MAIL_SMTP_AUTH switches SMTP sign-in between MAIL_PASSWORD and a Microsoft 365 OAuth token.
 * No network: the token endpoint is faked and the SMTP server is never contacted.
 */
class MicrosoftOAuthSmtpTest extends TestCase
{
    private const CONFIG = [
        'tenant_id' => 'tenant-123',
        'client_id' => 'client-456',
        'client_secret' => 'secret-789',
        'scope' => 'https://outlook.office365.com/.default',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function fakeTokenEndpoint(string ...$tokens): void
    {
        $sequence = Http::sequence();
        foreach ($tokens as $token) {
            $sequence->push(['access_token' => $token, 'expires_in' => 3599, 'token_type' => 'Bearer']);
        }

        Http::fake(['login.microsoftonline.com/*' => $sequence]);
    }

    /** Rebuild the mail manager so the provider re-reads MAIL_SMTP_AUTH. */
    private function smtpTransport(array $smtp): EsmtpTransport
    {
        config(['mail.mailers.smtp' => array_merge(config('mail.mailers.smtp'), $smtp)]);
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();

        return Mail::mailer('smtp')->getSymfonyTransport();
    }

    private function authenticators(EsmtpTransport $transport): array
    {
        return (new ReflectionProperty(EsmtpTransport::class, 'authenticators'))->getValue($transport);
    }

    public function test_token_is_requested_with_client_credentials_and_reused(): void
    {
        $this->fakeTokenEndpoint('token-a');
        $token = new MicrosoftOAuthToken(self::CONFIG);

        $this->assertSame('token-a', $token->get());
        $this->assertSame('token-a', $token->get());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://login.microsoftonline.com/tenant-123/oauth2/v2.0/token'
            && $r['grant_type'] === 'client_credentials'
            && $r['client_id'] === 'client-456'
            && $r['client_secret'] === 'secret-789'
            && $r['scope'] === 'https://outlook.office365.com/.default');
    }

    public function test_token_is_renewed_before_it_expires(): void
    {
        $this->fakeTokenEndpoint('token-a', 'token-b');
        $token = new MicrosoftOAuthToken(self::CONFIG);

        $this->assertSame('token-a', $token->get());
        $this->travel(50)->minutes();
        $this->assertSame('token-a', $token->get());
        $this->travel(6)->minutes(); // within five minutes of the 3599s expiry
        $this->assertSame('token-b', $token->get());
    }

    public function test_missing_credentials_fail_with_a_clear_message(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MICROSOFT_TENANT_ID, MICROSOFT_CLIENT_ID and MICROSOFT_CLIENT_SECRET');

        (new MicrosoftOAuthToken(['tenant_id' => 'tenant-123']))->get();
    }

    public function test_rejected_token_request_reports_microsofts_reason_without_the_secret(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'AADSTS7000215: Invalid client secret provided.',
        ], 401)]);

        try {
            (new MicrosoftOAuthToken(self::CONFIG))->get();
            $this->fail('Expected the token request to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AADSTS7000215', $e->getMessage());
            $this->assertStringNotContainsString('secret-789', $e->getMessage());
        }
    }

    public function test_authenticator_sends_xoauth2_with_the_mailbox_and_token(): void
    {
        $this->fakeTokenEndpoint('token-a');
        $client = Mockery::mock(EsmtpTransport::class);
        $client->shouldReceive('getUsername')->andReturn('paf@example.com');
        $client->shouldReceive('executeCommand')->once()->with(
            'AUTH XOAUTH2 '.base64_encode("user=paf@example.com\1auth=Bearer token-a\1\1")."\r\n",
            [235]
        )->andReturn('235 2.7.0 Authentication successful');

        (new MicrosoftOAuthAuthenticator(new MicrosoftOAuthToken(self::CONFIG)))->authenticate($client);
    }

    public function test_a_refused_token_is_not_reused(): void
    {
        $this->fakeTokenEndpoint('token-a', 'token-b');
        $token = new MicrosoftOAuthToken(self::CONFIG);
        $client = Mockery::mock(EsmtpTransport::class);
        $client->shouldReceive('getUsername')->andReturn('paf@example.com');
        $client->shouldReceive('executeCommand')->once()
            ->andThrow(new UnexpectedResponseException('535 5.7.3 Authentication unsuccessful', 535));

        try {
            (new MicrosoftOAuthAuthenticator($token))->authenticate($client);
            $this->fail('Expected the SMTP sign-in to be refused.');
        } catch (UnexpectedResponseException) {
        }

        $this->assertSame('token-b', $token->get());
    }

    public function test_oauth_mode_signs_smtp_in_with_the_microsoft_authenticator_only(): void
    {
        $transport = $this->smtpTransport([
            'auth' => 'oauth', 'host' => 'smtp.office365.com', 'port' => 587, 'username' => 'paf@example.com', 'password' => null,
        ]);

        $this->assertSame('paf@example.com', $transport->getUsername());
        $authenticators = $this->authenticators($transport);
        $this->assertCount(1, $authenticators);
        $this->assertInstanceOf(MicrosoftOAuthAuthenticator::class, $authenticators[0]);
    }

    public function test_oauth_mode_falls_back_to_the_sender_address_as_mailbox(): void
    {
        config(['mail.from.address' => 'noreply@example.com']);

        $transport = $this->smtpTransport([
            'auth' => 'oauth', 'host' => 'smtp.office365.com', 'port' => 587, 'username' => null,
        ]);

        $this->assertSame('noreply@example.com', $transport->getUsername());
    }

    public function test_password_mode_keeps_laravels_standard_smtp_sign_in(): void
    {
        $transport = $this->smtpTransport([
            'auth' => 'password', 'host' => 'smtp.example.com', 'port' => 587, 'username' => 'user', 'password' => 'pass',
        ]);

        $this->assertSame('pass', $transport->getPassword());
        foreach ($this->authenticators($transport) as $authenticator) {
            $this->assertNotInstanceOf(MicrosoftOAuthAuthenticator::class, $authenticator);
        }
    }
}
