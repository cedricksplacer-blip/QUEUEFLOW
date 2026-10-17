<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    protected function redirectTo($request): ?string
    {
        if ($request->expectsJson()) {
            return null;
        }

        if ($request->is('admin') || $request->is('admin/*') || $request->is('staff') || $request->is('staff/*')) {
            return route('admin.login');
        }

        return route('login');
    }

    /**
     * Refuse authenticated requests from accounts blocked mid-session.
     *
     * Blocking flips is_active and revokes API tokens, but a web session (or
     * a token minted before the block, in tests) can still arrive here. The
     * account row is re-read so a block lands immediately instead of at the
     * next login: API callers get a 401 carrying the reason, and browsers
     * are signed out back to their login form with the reason attached.
     */
    public function handle($request, Closure $next, ...$guards)
    {
        if (empty($guards)) {
            $guards = [null];
        }

        $this->authenticate($request, $guards);

        $user = $this->resolveUser($request, $guards);

        if ($user && ! $this->stillActive($user)) {
            $this->revokeAccess($request, $guards, $user);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'This account has been blocked.'], 401);
            }

            return redirect()
                ->to($this->redirectTo($request) ?? '/')
                ->withErrors(['email' => 'This account has been blocked.']);
        }

        return $next($request);
    }

    protected function resolveUser(Request $request, array $guards): mixed
    {
        foreach ($guards as $guard) {
            $candidate = $this->auth->guard($guard)->user();

            if ($candidate) {
                return $candidate;
            }
        }

        return $request->user();
    }

    protected function stillActive(mixed $user): bool
    {
        try {
            return (bool) ($user->fresh()?->is_active ?? false);
        } catch (\Throwable) {
            return true;
        }
    }

    protected function revokeAccess(Request $request, array $guards, mixed $user): void
    {
        try {
            if (method_exists($user, 'currentAccessToken') && $user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
            }
        } catch (\Throwable) {
            // Token cleanup must never break the refusal itself.
        }

        foreach ($guards as $guard) {
            try {
                $guardInstance = $this->auth->guard($guard);

                // Session guards sign out; stateless ones hold nothing, and
                // their token (if any) is revoked above.
                if (method_exists($guardInstance, 'logout')) {
                    $guardInstance->logout();
                }
            } catch (\Throwable) {
                // A stateless guard has nothing to log out of.
            }
        }

        try {
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        } catch (\Throwable) {
            // Stateless callers carry no session to clear.
        }
    }
}
