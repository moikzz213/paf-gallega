<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\PasswordResetLink;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
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
     * Colleagues whose invoices and payment requests this user may view, read-only. Set per person
     * by an admin; never derived from role or department, and not passed on — a viewer does not
     * inherit the colleague's own grants.
     *
     * CR: ai/change-requests/grant-view-only-access-to-paf-records-of-nominated-colleagues.md
     */
    public function viewableColleagues(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'user_view_grants', 'viewer_id', 'colleague_id')->withTimestamps();
    }

    /**
     * The granted colleagues' ids as a subquery, for the visibleTo scopes. Visibility only — every
     * write check stays on `submitted_by === $user->id`, so a viewer can change nothing of theirs.
     */
    public function viewableColleagueIds(): QueryBuilder
    {
        return DB::table('user_view_grants')->select('colleague_id')->where('viewer_id', $this->id);
    }

    /**
     * Everyone in this user's department — themselves included, inactive members too, since what
     * they raised stays the department's — as a subquery for the visibleTo scopes. Null when the user
     * has no department (a blank department matches nobody, never everybody), and for every role but
     * requester: department visibility is a requester's view only, approvers keep what is routed to
     * them, and Finance/admin already see everything.
     *
     * Compared without regard to case, because the column is free text that has drifted from the
     * master list ("Freight forwarding" vs "Freight Forwarding"). Visibility only, like the grants.
     *
     * CR: ai/change-requests/show-paf-invoices-to-all-users-in-the-same-department.md
     */
    public function departmentColleagueIds(): ?QueryBuilder
    {
        $department = mb_strtolower(trim((string) $this->department));

        if (! $this->isRequester() || $department === '') {
            return null;
        }

        return DB::table('users')->select('id')->whereRaw('lower(trim(department)) = ?', [$department]);
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

    public function isRequester(): bool
    {
        return $this->role === self::ROLE_REQUESTER;
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

    /**
     * May be put on a payment request's approval chain.
     *
     * Approvers and admins always qualify. Finance qualifies **per person**, by an admin setting an
     * `approval_level` on them: Finance often raises the PRF itself, so the group members who sign
     * those off (rather than a requester's own manager) need to be selectable — but only the ones
     * deliberately nominated, not every Finance user.
     *
     * Keep this in step with `scopeEligibleApprovers()` and the client's `canApprove` getter.
     */
    public function canApprove(): bool
    {
        if (in_array($this->role, [self::ROLE_APPROVER, self::ROLE_ADMIN], true)) {
            return true;
        }

        return $this->isFinance() && $this->approval_level !== null;
    }

    /** The `canApprove()` rule as a query, for option lists and `exists` validation. */
    public function scopeEligibleApprovers(Builder $query): Builder
    {
        return $query->where('is_active', true)->where(fn (Builder $q) => $q
            ->whereIn('role', [self::ROLE_APPROVER, self::ROLE_ADMIN])
            ->orWhere(fn (Builder $finance) => $finance
                ->where('role', self::ROLE_FINANCE)
                ->whereNotNull('approval_level')));
    }
}
