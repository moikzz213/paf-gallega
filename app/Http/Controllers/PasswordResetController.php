<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Returned whether or not the address matches an account. Confirming which addresses have a
     * login would turn this endpoint into an account-enumeration tool, so the reply never varies.
     */
    private const GENERIC_REPLY = 'If that email address belongs to an active account, a reset link is on its way.';

    public function sendLink(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        // is_active is part of the lookup, so a deactivated account cannot use a reset to get
        // back in — the same rule AuthController::login enforces at sign-in.
        $status = Password::sendResetLink([
            'email' => $data['email'],
            'is_active' => true,
        ]);

        if ($status !== Password::RESET_LINK_SENT) {
            // Recorded for support, never surfaced: the caller always sees GENERIC_REPLY.
            Log::info('Password reset link not sent', [
                'email' => $data['email'],
                'status' => $status,
                'ip' => $request->ip(),
            ]);
        }

        return response()->json(['message' => self::GENERIC_REPLY]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            // min:8 matches the floor UserController applies when an admin sets a password.
            'password' => ['required', 'confirmed', 'string', 'min:8'],
        ]);

        $status = Password::reset([
            'email' => $data['email'],
            'password' => $data['password'],
            'password_confirmation' => $request->input('password_confirmation'),
            'token' => $data['token'],
            'is_active' => true,
        ], function (User $user, string $password) {
            $user->forceFill([
                // Hashed by the model's 'password' cast, as everywhere else in the app.
                'password' => $password,
                // Retires any "remember me" cookie issued before the reset.
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));

            AuditLogger::log('password_reset', "{$user->name} reset their password");
        });

        if ($status !== Password::PASSWORD_RESET) {
            // Covers an unknown address, a deactivated account, and a used/expired/forged token
            // with one message, so none of those states can be told apart from the outside.
            throw ValidationException::withMessages([
                'email' => 'This reset link is invalid or has expired. Please request a new one.',
            ]);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }
}
