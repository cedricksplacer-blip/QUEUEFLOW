<?php

use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\VerifyCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from these domains receive stateful API authentication
    | cookies, which is only needed by a cookie-based SPA client. QueueFlow
    | TMC has none: the browser portal (routes/web.php) uses the plain
    | `auth` middleware, and the two mobile portals always send a bearer
    | token.
    |
    | Do NOT add the deployed API domain here. Sanctum consults the session
    | guard in `guard` below BEFORE reading the bearer token, so listing a
    | domain here would let a live browser session outrank a mobile token.
    |
    | THIS LIST IS NOW EMPTY ON PURPOSE.
    |
    | It used to carry `localhost` and `127.0.0.1`, which was harmless while
    | the only clients were native apps on a phone. It stopped being harmless
    | the moment the Flutter Web client arrived, because that client is served
    | FROM `http://localhost:<port>` by the Flutter tool. A domain listed here
    | causes Sanctum to graft the whole web stack onto the request -
    | EncryptCookies, StartSession, VerifyCsrfToken - so a bearer-token call
    | from the browser would arrive with no CSRF token and be rejected with a
    | 419 before the controller ever ran.
    |
    | An empty list means the bearer token is the only credential the API
    | accepts, which is what routes/api.php and the Flutter client both
    | assume. Set SANCTUM_STATEFUL_DOMAINS in .env if a cookie-based SPA is
    | ever added; do not uncomment the defaults in this file.
    |
    */

    'stateful' => array_values(array_filter(
        explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'verify_csrf_token' => VerifyCsrfToken::class,
    ],

];
