<?php

namespace App\Support;

use Illuminate\Auth\EloquentUserProvider;

/**
 * The user provider QueueFlow TMC registers for the `users` provider.
 *
 * `validateCredentials()` is the single point every guard-based sign-in passes
 * through — Auth::attempt(), Auth::login(), the session guard and the Sanctum
 * token guard alike — and it is the only place that holds both the account and
 * the clear-text password the visitor typed. Overriding it means a row left
 * behind by the previous bcrypt behaviour upgrades itself here, without each
 * login controller having to remember to do it.
 */
class PlainUserProvider extends EloquentUserProvider
{
    /**
     * Verify the credentials and, on success, flatten a legacy hash to text.
     */
    public function validateCredentials($user, array $credentials)
    {
        $plain = $credentials['password'] ?? null;

        if ($plain === null) {
            return false;
        }

        if (! PlainPassword::check((string) $plain, $user->getAuthPassword())) {
            return false;
        }

        PlainPassword::upgradeIfHashed($user, (string) $plain);

        return true;
    }
}
