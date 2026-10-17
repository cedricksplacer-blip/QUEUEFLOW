<?php

namespace App\Http;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class Kernel extends HttpKernel
{
    protected $middleware = [
        TrustProxies::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            \App\Http\Middleware\TrackStaffPresence::class,
        ],

        'api' => [
            EnsureFrontendRequestsAreStateful::class,
            ThrottleRequests::class.':api',
            SubstituteBindings::class,
        ],

        // Identical to `api` except for the limiter, so the versioned student
        // surface gets the allowance sized for a mobile app polling the live
        // board rather than the blanket 60/minute. Routes that need a tighter
        // limit still stack their own `throttle:` middleware on top, and the
        // stricter of the two always wins.
        'api-student' => [
            EnsureFrontendRequestsAreStateful::class,
            ThrottleRequests::class.':student-api',
            SubstituteBindings::class,
        ],

        // Bearer-token API for the Flutter Web client.
        //
        // Identical to `api-student` minus EnsureFrontendRequestsAreStateful.
        // That middleware is the whole point of the difference: it grafts the
        // web stack (cookies, session, CSRF) onto any request whose host is
        // listed in sanctum.stateful. Flutter Web's dev origin is
        // http://localhost:<port>, so leaving it in would make every bearer
        // call arrive without a CSRF token and be refused with a 419.
        //
        // The `student-api` limiter is kept rather than the blanket `api` one,
        // because the queue screens poll the now-serving board while a student
        // waits, and 60/minute would throttle that mid-queue.
        'api-token' => [
            ThrottleRequests::class.':student-api',
            SubstituteBindings::class,
        ],
    ];

    protected $middlewareAliases = [
        'auth' => Authenticate::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'cache.headers' => SetCacheHeaders::class,
        'can' => Authorize::class,
        'guest' => RedirectIfAuthenticated::class,
        'password.confirm' => RequirePassword::class,
        'signed' => ValidateSignature::class,
        'throttle' => ThrottleRequests::class,
        'verified' => EnsureEmailIsVerified::class,
        'role' => EnsureUserHasRole::class,
    ];
}
