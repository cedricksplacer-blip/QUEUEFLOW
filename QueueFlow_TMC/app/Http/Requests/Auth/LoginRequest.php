<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\PlainPassword;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Students sign in with their Student ID, staff and admins with
            // their email address, so the field is deliberately not validated
            // as an email: the "email" rule would reject 2026-0001 outright.
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * The name the credential is reported under in error and throttle output.
     */
    public function identifierField(): string
    {
        return 'identifier';
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = User::findByIdentifier($this->string('identifier'));

        if (! $user || ! PlainPassword::check($this->string('password'), $user->password)) {
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages([$this->identifierField() => trans('auth.failed')]);
        }

        // Correct password but blocked: say so plainly, next to the form.
        if (! $user->is_active) {
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages([$this->identifierField() => 'This account has been blocked.']);
        }

        // A legacy bcrypt row becomes clear text the first time it is used.
        PlainPassword::upgradeIfHashed($user, $this->string('password'));

        Auth::login($user, $this->boolean('remember'));
        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));
        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            $this->identifierField() => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('identifier')).'|'.$this->ip());
    }
}
