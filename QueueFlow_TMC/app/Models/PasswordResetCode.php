<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A one-time 6-digit code for the student app's password reset.
 *
 * The code is never stored: only a hash of it, compared with hash_equals so a
 * timing side channel cannot be used to recover the code a digit at a time.
 * A row is marked consumed the moment it is redeemed, and any earlier
 * unconsumed code for the same address is voided at issue time, so a student
 * who requests a second code can only ever use the newest one.
 *
 * @property int $id
 * @property string $email
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $ip_address
 */
class PasswordResetCode extends Model
{
    protected $table = 'password_reset_codes';

    protected $fillable = [
        'email',
        'code_hash',
        'expires_at',
        'consumed_at',
        'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /**
     * A code stays usable for this many minutes.
     */
    public const TTL_MINUTES = 10;

    /**
     * Resend cool-down, in seconds, enforced per address.
     *
     * Long enough to stop a script burning the mail quota, short enough that a
     * student who mistyped their address is not locked out of the flow.
     */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * How long a verified reset token stays usable, in minutes.
     *
     * The token is issued only after the emailed code checks out, so this is
     * the window the new-password screen has to submit. Short on purpose: a
     * token that lives longer is a longer-lived way into the account.
     */
    public const VERIFY_TOKEN_TTL_MINUTES = 10;

    /**
     * Wrong-code guesses allowed per address before a fresh code is required.
     *
     * Ten guesses a minute against a million-value space is not viable either,
     * but a hard cap means a leaked code cannot be ground at all: five misses
     * void the round and the student requests a new code.
     */
    public const MAX_VERIFY_ATTEMPTS = 5;

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && ! $this->isExpired();
    }

    /**
     * Store a code as a hash scoped to this row's id.
     *
     * Binding the hash to the row id means two students who are issued the
     * same digits cannot produce the same stored value, so a code issued for
     * one address can never be replayed against another.
     */
    public static function hashCode(int $id, string $code): string
    {
        return hash_hmac('sha256', $code, 'qf-reset|'.$id);
    }

    public function codeMatches(string $code): bool
    {
        return hash_equals($this->code_hash, self::hashCode($this->id, $code));
    }

    public function markConsumed(): void
    {
        $this->forceFill(['consumed_at' => now()])->save();
    }
}
