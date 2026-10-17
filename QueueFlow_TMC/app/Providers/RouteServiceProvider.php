<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/dashboard';

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Sign-in attempts are limited per identifier *and* per IP, so neither
        // a single student locking themselves out with a typo nor a spray
        // across many accounts from one machine gets a free pass.
        //
        // The bucket is keyed on whichever credential the client sent. The
        // student apps may still post `email` while the web form posts
        // `identifier`, and both must land in the same per-account bucket so
        // swapping one for the other cannot buy extra guesses.
        RateLimiter::for('student-login', fn (Request $request) => Limit::perMinute(5)
            ->by($this->credentialKey($request)));

        // Staff logins share the same limit so a public host cannot be
        // brute forced through either portal.
        RateLimiter::for('staff-login', fn (Request $request) => Limit::perMinute(5)
            ->by($this->credentialKey($request)));

        // Password recovery gets its own bucket rather than sharing
        // `student-login`. Sharing it would mean a student who had just been
        // throttled by five failed sign-ins could not ask for a reset code,
        // which is precisely the moment they most need one.
        //
        // The allowance is higher than for sign-in because the flow spans
        // several deliberate calls (request, then a few attempts at typing a
        // 6-digit code) and typos are normal. That is safe here because the
        // code itself is the credential: ten guesses a minute against a
        // million-value space, inside a ten-minute window, is not a viable
        // attack. The per-address cool-down in the controller is what stops
        // the mail channel from being used to flood an inbox.
        RateLimiter::for('student-reset', fn (Request $request) => Limit::perMinute(10)
            ->by($this->credentialKey($request)));

        // The student mobile app's own limit, applied to /api/v1.
        //
        // The blanket `api` allowance of 60/minute is a poor fit for a phone
        // that polls the now-serving board while a student waits in line, and
        // it is keyed on the user id only once authenticated. This bucket is
        // both higher and keyed on the token id, so one student's polling can
        // never spend another student's budget.
        //
        // A 429 always carries Retry-After, so the app can back off instead of
        // hammering a campus WiFi link that is already congested.
        RateLimiter::for('student-api', function (Request $request) {
            $key = $request->user()?->currentAccessToken()?->getKey()
                ?: $request->ip();

            return Limit::perMinute(120)->by('student-api|'.$key);
        });

        $this->routes(function () {
            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            // Password recovery is the one JSON surface that predates the
            // Flutter Web client. It runs against the stateless `api` group so
            // it is CSRF-free and callable from a native client, and keeps both
            // the versioned /api/v1/auth/* path and the legacy /api/student/*
            // one that the deployed Flutter build still calls.
            //
            // Registered before the main API file so its /api/student/* pair is
            // matched before anything in api.php could shadow it.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/password_recovery_api.php'));

            // The bearer-token API the Flutter Web client calls, in routes/api.php.
            //
            // `api-token`, not `api` and not `api-student`: both of those run
            // EnsureFrontendRequestsAreStateful, which grafts the web stack
            // (cookies, session, CSRF) onto a request whose host appears in
            // sanctum.stateful. That list is now empty, so the middleware is
            // inert today - but if anyone ever adds a localhost entry to it,
            // this group would start 419-ing the Flutter client, because it is
            // served from http://localhost:<port>. Using a group that cannot
            // include that middleware makes that regression impossible rather
            // than merely unlikely.
            Route::middleware('api-token')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        });
    }

    /**
     * A stable rate-limit key for one credential attempt.
     *
     * `identifier` is the current field name; `email` is still accepted so an
     * older build of the student app cannot sidestep the per-account limit by
     * sending the other name. `student_id` is what the Flutter app actually
     * posts, so it must fold in too: without it every app login behind one
     * address (one ngrok tunnel, one campus NAT) shares a single bucket and
     * students throttle each other. All three fold into the same string, so
     * the bucket is the account's, not the field's.
     */
    private function credentialKey(Request $request): string
    {
        $credential = $request->input('identifier')
            ?? $request->input('email')
            ?? $request->input('student_id');

        return Str::transliterate(Str::lower(trim((string) $credential)).'|'.$request->ip());
    }
}
