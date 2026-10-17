<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\TrustProxies;
use Tests\TestCase;

/**
 * Public HTTPS endpoint configuration for off-campus access.
 *
 * The Flutter app can only reach the API from a different Wi-Fi network or
 * mobile data when Laravel is served behind a public HTTPS endpoint (tunnel
 * or production domain). These tests pin the two pieces of server
 * configuration that make that work without weakening development:
 *
 *   - CORS_ALLOWED_ORIGINS: public origins get an Access-Control-Allow-Origin
 *     response header, while unlisted origins still get none (never `*`).
 *   - TRUSTED_PROXIES: the tunnel / load balancer in front of Laravel is
 *     trusted for X-Forwarded-* headers, so the app sees https, not the
 *     plain-http hop behind the proxy. Direct `artisan serve` development
 *     keeps trusting none.
 */
class PublicEndpointConfigTest extends TestCase
{
    public function test_health_probe_answers_without_authentication(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'online');
    }

    public function test_configured_public_origin_receives_cors_headers(): void
    {
        config(['cors.allowed_origins' => ['https://public.example']]);

        $response = $this->get('/api/health', [
            'Origin' => 'https://public.example',
        ]);

        $response->assertOk();
        $response->assertHeader('Access-Control-Allow-Origin', 'https://public.example');
    }

    public function test_unlisted_origin_receives_no_cors_header(): void
    {
        config(['cors.allowed_origins' => ['https://public.example']]);

        $response = $this->get('/api/health', [
            'Origin' => 'https://untrusted.example.com',
        ]);

        $response->assertOk();
        $this->assertFalse(
            $response->headers->has('Access-Control-Allow-Origin'),
            'An unlisted origin must not be reflected in Access-Control-Allow-Origin.'
        );
    }

    public function test_trusted_proxies_default_to_trusting_none(): void
    {
        putenv('TRUSTED_PROXIES');

        $middleware = new TrustProxies();

        $this->assertNull(
            $this->proxiesOf($middleware),
            'Direct artisan serve development must trust no proxy by default.'
        );
    }

    public function test_trusted_proxies_star_is_honoured_from_env(): void
    {
        putenv('TRUSTED_PROXIES=*');

        try {
            $middleware = new TrustProxies();

            $this->assertSame('*', $this->proxiesOf($middleware));
        } finally {
            putenv('TRUSTED_PROXIES');
        }
    }

    public function test_trusted_proxies_list_is_honoured_from_env(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.5, 10.0.0.6');

        try {
            $middleware = new TrustProxies();

            $this->assertSame(['10.0.0.5', '10.0.0.6'], $this->proxiesOf($middleware));
        } finally {
            putenv('TRUSTED_PROXIES');
        }
    }

    /** Read the resolved proxy list off the middleware via reflection. */
    private function proxiesOf(TrustProxies $middleware): mixed
    {
        $property = new \ReflectionProperty(TrustProxies::class, 'proxies');
        $property->setAccessible(true);

        return $property->getValue($middleware);
    }
}
