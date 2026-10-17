<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| QueueFlow's JSON API is consumed by the Flutter Web client, which during
| development is served from a Flutter-chosen port on the loopback interface
| (http://localhost:<port>) and, on a shared network, from the machine's own
| LAN address (http://192.168.x.x:<port>).
|
| The port is assigned by the Flutter tool and changes between runs, so the
| origins are matched with a pattern rather than enumerated. That is narrower
| than `allowed_origins: ['*']`: the host must still be a loopback or private
| address, so a page served from anywhere else on the internet cannot read an
| authenticated response out of a browser on the campus network.
|
| Credentials are NOT supported. Every call authenticates with
| `Authorization: Bearer <token>` rather than a cookie, so there is nothing
| for the browser to attach automatically and nothing for an attacker on
| another origin to ride along on. This is why `sanctum.stateful` is empty:
| a stateful domain would put the session guard ahead of the bearer token.
|
| See App\Support\ApiContract for the endpoint list these headers apply to.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |----------------------------------------------------------------------
    |
    | Only the JSON API is CORS-enabled. The Blade portal under `web` is
    | same-origin and needs none of this.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    /*
    |----------------------------------------------------------------------
    | Allowed Methods
    |----------------------------------------------------------------------
    |
    | The methods the Flutter client actually issues. `PATCH` is separate
    | from `PUT` because the account and ticket endpoints are partial
    | updates and Laravel routes them separately.
    |
    */

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

/*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Written as patterns rather than a literal list, because the Flutter
    | tool picks the web-server port at run time.
    |
    |   localhost / 127.0.0.1  the Flutter Web dev server on this machine
    |   RFC1918 private ranges the same dev server reached over the LAN, from
    |                          this machine or any other device on that subnet
    |
    | The whole private range is allowed rather than one named address because
    | a campus machine is on DHCP: naming today's address means the app stops
    | working the next time the lease changes. What this deliberately does NOT
    | allow is any public host, so a page served from the internet still cannot
    | read an authenticated response out of a browser on this network. Only
    | http and https, only an optional port, only an address - never a hostname,
    | because a hostname would let anybody claim the origin with DNS.
    |
    */

    'allowed_origins_patterns' => [
        '#^https?://(localhost|127\.0\.0\.1|10(?:\.\d{1,3}){3}|192\.168(?:\.\d{1,3}){2}|172\.(?:1[6-9]|2\d|3[01])(?:\.\d{1,3}){2})(?::\d+)?$#',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins (public deployments)
    |--------------------------------------------------------------------------
    |
    | The pattern above covers loopback and RFC1918 development addresses. A
    | public deployment - an HTTPS tunnel URL or a production domain serving
    | the Flutter Web frontend or proxied API traffic - is NOT matched by it,
    | and must be listed explicitly via CORS_ALLOWED_ORIGINS instead of
    | widening the pattern or falling back to `*`.
    |
    | Comma-separated exact origins, e.g.:
    |   CORS_ALLOWED_ORIGINS=https://queueflow.example.edu,https://api.queueflow.example.edu
    |
    | Bearer-token auth means the browser attaches nothing automatically, so
    | listing an origin only lets that page READ its own responses; it grants
    | no access to ambient credentials. Never set this to `*` alongside
    | supports_credentials=true.
    |
    */

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),

    /*
    |----------------------------------------------------------------------
    | Allowed Headers
    |----------------------------------------------------------------------
    |
    | `Accept` makes Laravel return JSON for a 401/403/422 instead of an
    | HTML error page, which is what lets ApiClient read a status code off a
    | refusal at all. `Authorization` carries the Sanctum bearer token.
    |
    */

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        // Sent unconditionally by the Flutter ApiClient so the ngrok tunnel
        // proxies API calls instead of answering with its browser
        // interstitial page. Must be listed here or Flutter Web preflights
        // through the tunnel fail and the app reports Server Offline.
        'ngrok-skip-browser-warning',
    ],

    /*
    |----------------------------------------------------------------------
    | Exposed Headers
    |----------------------------------------------------------------------
    |
    | X-Api-Version is applied by App\Listeners\StampApiVersion to every
    | response under /api/v1, including error responses. A browser can only
    | read a non-simple response header if it is named here.
    |
    */

    'exposed_headers' => [
        'X-Api-Version',
        'Retry-After',
    ],

    /*
    |----------------------------------------------------------------------
    | Max Age
    |----------------------------------------------------------------------
    |
    | Ten minutes. Long enough to keep a hot-reloading Flutter session from
    | preflighting every call, short enough that editing this file and
    | reloading the browser actually takes effect.
    |
    */

    'max_age' => 600,

    /*
    |----------------------------------------------------------------------
    | Supports Credentials
    |----------------------------------------------------------------------
    |
    | Deliberately false. See the note at the top of this file.
    |
    */

    'supports_credentials' => false,

];