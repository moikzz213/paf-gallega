<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\PasswordResetLink;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

#[Fillable(['name', 'email', 'password', 'role', 'approval_level', 'department', 'job_title', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_REQUESTER = 'requester';

    public const ROLE_APPROVER = 'approver';

    public const ROLE_FINANCE = 'finance';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_REQUESTER,
        self::ROLE_APPROVER,
        self::ROLE_FINANCE,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'approval_level' => 'integer',
        ];
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'submitted_by');
    }

    /**
     * Called by the password broker. Overridden so the reset mail is a Mailable with a branded
     * Blade view, matching how the rest of the app sends mail, instead of Laravel's default
     * notification (whose link would point at a web route this SPA does not have).
     */
    public function sendPasswordResetNotification($token): void
    {
        Mail::to($this->email)->send(new PasswordResetLink($this, $token));
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isApprover(): bool
    {
        return $this->role === self::ROLE_APPROVER;
    }

    public function isFinance(): bool
    {
        return $this->role === self::ROLE_FINANCE;
    }

    /** Admin & finance can see every invoice; approvers see those routed to them. */
    public function canViewAllInvoices(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_FINANCE], true);
    }
}
