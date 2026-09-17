<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A key/secret pair for machine callers of the export API. The secret is only ever readable at the
 * moment of issue; afterwards only its hash is kept, so it can be verified but never re-shown.
 */
class ApiKey extends Model
{
    protected $fillable = ['name', 'key', 'secret_hash', 'user_id', 'is_active', 'last_used_at', 'last_used_ip'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Issue a key for a user. Returns the model plus the plaintext secret, which the caller must
     * show once and never store.
     *
     * @return array{key: ApiKey, secret: string}
     */
    public static function issue(string $name, User $user): array
    {
        $secret = Str::random(40);

        $key = static::create([
            'name' => $name,
            'key' => Str::random(24),
            'secret_hash' => Hash::make($secret),
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        return ['key' => $key, 'secret' => $secret];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Constant-time comparison against the stored hash. */
    public function secretMatches(string $secret): bool
    {
        return Hash::check($secret, $this->secret_hash);
    }

    /** Usable only while both the key and the user behind it are active. */
    public function isUsable(): bool
    {
        return $this->is_active && (bool) $this->user?->is_active;
    }
}
