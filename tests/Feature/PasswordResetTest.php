<?php

namespace Tests\Feature;

use App\Mail\PasswordResetLink;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Cara Customer',
            'email' => 'cara@t.local',
            'password' => 'password',
            'role' => User::ROLE_REQUESTER,
            'is_active' => true,
        ], $overrides));
    }

    public function test_active_user_is_sent_a_reset_link(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonStructure(['message']);

        Mail::assertQueued(PasswordResetLink::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_unknown_address_is_answered_identically_and_sends_nothing(): void
    {
        Mail::fake();
        $known = $this->user();

        $forKnown = $this->postJson('/api/forgot-password', ['email' => $known->email])->assertOk();
        $forUnknown = $this->postJson('/api/forgot-password', ['email' => 'nobody@t.local'])->assertOk();

        // Identical status and body: the endpoint must not reveal who has an account.
        $this->assertSame($forKnown->json('message'), $forUnknown->json('message'));
        Mail::assertQueued(PasswordResetLink::class, 1);
    }

    public function test_deactivated_user_cannot_request_a_reset(): void
    {
        Mail::fake();
        $user = $this->user(['email' => 'gone@t.local', 'is_active' => false]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Mail::assertNothingQueued();
    }

    public function test_valid_token_resets_the_password_and_allows_sign_in(): void
    {
        Event::fake([PasswordReset::class]);
        $user = $this->user();
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-secret-1',
            'password_confirmation' => 'new-secret-1',
        ])->assertOk();

        Event::assertDispatched(PasswordReset::class);
        $this->assertTrue(Auth::validate(['email' => $user->email, 'password' => 'new-secret-1']));
        $this->assertFalse(Auth::validate(['email' => $user->email, 'password' => 'password']));
    }

    public function test_token_cannot_be_reused(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);
        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-secret-1',
            'password_confirmation' => 'new-secret-1',
        ];

        $this->postJson('/api/reset-password', $payload)->assertOk();
        $this->postJson('/api/reset-password', $payload)->assertStatus(422);
    }

    public function test_forged_token_is_rejected(): void
    {
        $user = $this->user();

        $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'new-secret-1',
            'password_confirmation' => 'new-secret-1',
        ])->assertStatus(422);

        $this->assertTrue(Auth::validate(['email' => $user->email, 'password' => 'password']));
    }

    public function test_deactivated_user_cannot_reset_even_with_a_valid_token(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);
        $user->update(['is_active' => false]);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-secret-1',
            'password_confirmation' => 'new-secret-1',
        ])->assertStatus(422);

        $this->assertTrue(Auth::validate(['email' => $user->email, 'password' => 'password']));
    }

    public function test_reset_requires_a_confirmed_password_of_at_least_eight_characters(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-secret-1',
            'password_confirmation' => 'different-1',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_reset_retires_the_previous_remember_token(): void
    {
        $user = $this->user();
        $user->forceFill(['remember_token' => 'stale-remember-token'])->save();
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-secret-1',
            'password_confirmation' => 'new-secret-1',
        ])->assertOk();

        $this->assertNotSame('stale-remember-token', $user->fresh()->remember_token);
    }

    public function test_the_emailed_link_points_at_the_spa_reset_route(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Mail::assertQueued(PasswordResetLink::class, function ($mail) use ($user) {
            $rendered = $mail->render();

            return str_contains($rendered, '/reset-password?')
                && str_contains($rendered, urlencode($user->email));
        });
    }

    public function test_requests_are_rate_limited(): void
    {
        Mail::fake();
        $user = $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(429);
    }
}
