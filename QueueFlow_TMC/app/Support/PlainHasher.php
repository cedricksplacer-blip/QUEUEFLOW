<?php

namespace App\Support;

use Illuminate\Contracts\Hashing\Hasher as HasherContract;

/**
 * The hasher QueueFlow TMC binds as its default driver.
 *
 * Registering this as the default driver means every framework code path that
 * reaches for the hasher — Auth::attempt(), the session guard's credential
 * validation used by the confirm-password screen, EloquentUserProvider — reads
 * and writes clear-text passwords, while still verifying any legacy bcrypt row
 * that has not signed in since the switch.
 */
class PlainHasher implements HasherContract
{
    /**
     * Information about the stored value. Clear text carries no algorithm.
     *
     * @return array<string, mixed>
     */
    public function info($hashedValue)
    {
        return ['algoName' => 'plain', 'algo' => null, 'options' => []];
    }

    /**
     * Verify a clear-text attempt against a clear-text or legacy hashed value.
     */
    public function check($value, $hashedValue, array $options = [])
    {
        return PlainPassword::check((string) $value, $hashedValue === null ? null : (string) $hashedValue);
    }

    /**
     * Store the password as clear text.
     */
    public function make($value, array $options = [])
    {
        return PlainPassword::make((string) $value);
    }

    /**
     * Never ask for a rehash: the stored value is already plain text, and
     * Laravel 11+ calls this on every sign-in.
     */
    public function needsRehash($hashedValue, array $options = [])
    {
        return false;
    }
}
