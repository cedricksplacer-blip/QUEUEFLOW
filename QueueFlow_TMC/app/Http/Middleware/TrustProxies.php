<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * Trusted reverse proxies for X-Forwarded-* headers.
     *
     * Stays null (trust none) for direct `artisan serve` development. A public
     * HTTPS endpoint terminates TLS at a tunnel or reverse proxy in front of
     * Laravel, so without trusting it the app sees plain http://localhost:8000
     * and generates wrong absolute URLs / secure-cookie decisions. Set:
     *   TRUSTED_PROXIES=*            trust the front proxy (tunnel / single LB)
     *   TRUSTED_PROXIES=10.0.0.5,10.0.0.6   trust named hops only
     */
    protected $proxies;

    /**
     * Resolve the proxy list from TRUSTED_PROXIES at runtime.
     */
    public function __construct()
    {
        $configured = trim((string) env('TRUSTED_PROXIES', ''));

        if ($configured !== '') {
            $this->proxies = $configured === '*'
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', $configured))));
        }
    }

    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
