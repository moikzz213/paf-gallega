<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }

        if (! $request->user()->is_active) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
        }

        $request->session()->regenerate();

        AuditLogger::log('login', "{$request->user()->name} signed in");

        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request)
    {
        AuditLogger::log('logout', ($request->user()?->name ?? 'User').' signed out');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }
}
