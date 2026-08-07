<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    /**
     * Change the signed-in user's own password. Separate from UserController::update, which is
     * admin-only and can also change role, approval level and active state.
     */
    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            // 'current_password' checks the value against the authenticated user's stored hash, so
            // someone on a hijacked session cannot take the account over without knowing it.
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', 'string', 'min:8', 'different:current_password'],
        ], [
            'current_password.current_password' => 'That is not your current password.',
            'password.different' => 'Your new password must be different from your current one.',
        ]);

        $user = $request->user();

        $user->forceFill([
            // Hashed by the model's 'password' cast, as everywhere else in the app.
            'password' => $data['password'],
            // Retires "remember me" cookies issued before the change. The current session stays
            // valid on purpose: regenerating it would invalidate the CSRF token this page holds.
            'remember_token' => Str::random(60),
        ])->save();

        AuditLogger::log('password_changed', "{$user->name} changed their own password");

        return response()->json(['message' => 'Your password has been changed.']);
    }
}
