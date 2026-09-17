<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ProfilePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Ravi Requester',
            'email' => 'ravi@t.local',
            'password' => 'old-password',
            'role' => User::ROLE_REQUESTER,
            'is_active' => true,
        ], $overrides));
    }

    public function test_user_can_change_their_own_password(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->putJson('/api/profile/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk();

        $this->assertTrue(Auth::validate(['email' => $user->email, 'password' => 'brand-new-password']));
        $this->assertFalse(Auth::validate(['email' => $user->email, 'password' => 'old-password']));
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->putJson('/api/profile/password', [
                'current_password' => 'not-the-right-one',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Auth::validate(['email' => $user->email, 'password' => 'old-password']));
    }

    public function test_the_new_password_must_be_confirmed_and_long_enough(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->putJson('/api/profile/password', [
                'current_password' => 'old-password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->actingAs($user)
            ->putJson('/api/profile/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'something-else',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Auth::validate(['email' => $user->email, 'password' => 'old-password']));
    }

    public function test_the_new_password_must_differ_from_the_current_one(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->putJson('/api/profile/password', [
                'current_password' => 'old-password',
                'password' => 'old-password',
                'password_confirmation' => 'old-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_changing_the_password_retires_the_previous_remember_token(): void
    {
        $user = $this->user();
        $user->forceFill(['remember_token' => 'stale-remember-token'])->save();

        $this->actingAs($user)
            ->putJson('/api/profile/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk();

        $this->assertNotSame('stale-remember-token', $user->fresh()->remember_token);
    }

    public function test_a_guest_cannot_change_a_password(): void
    {
        $this->user();

        $this->putJson('/api/profile/password', [
            'current_password' => 'old-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(401);
    }

    public function test_the_change_is_recorded_in_the_audit_log(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->putJson('/api/profile/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'password_changed',
        ]);
    }

    public function test_the_response_carries_nothing_but_a_message(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'old-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        // No user payload back, so there is no route by which the hash could leak.
        $this->assertSame(['message'], array_keys($response->json()));
    }
}
