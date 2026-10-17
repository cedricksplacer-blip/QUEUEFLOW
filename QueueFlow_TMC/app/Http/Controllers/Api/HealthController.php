<?php

namespace App\Http\Controllers\Api;

use App\Support\ApiContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /api/health  (also published at /api/v1/health)
 *
 * The liveness probe the QueueFlow client polls.
 *
 * WHY THIS EXISTS, because "just check the internet" is not the same question.
 * A phone on campus WiFi with `artisan serve` stopped has a perfectly good
 * network connection and no server to talk to. The client has to be able to
 * tell those two failures apart, or it will tell a student who is standing in
 * the registrar's line to turn their WiFi back on when the actual fix is for
 * somebody to restart a process on a machine they cannot see.
 *
 * So the app asks two separate questions and this endpoint answers the second
 * one: the radio has a link (connectivity_plus), and Laravel is actually
 * serving HTTP (this). Only a real response from this handler moves the client
 * to ONLINE, which is why the payload is produced by the framework and not
 * cached, hard-coded in the client, or inferred from a successful login.
 *
 * HTTP 200 MEANS LARAVEL IS UP, FULL STOP. The status code is decided by the
 * fact that this controller ran, never by the database. A `database` field is
 * reported beside it so a human can tell "Laravel reachable, MySQL down" from
 * "Laravel unreachable" - but a dead database must NOT turn the health check
 * into a 5xx, because that is exactly what would make the Flutter client cry
 * "server offline" when the server is in fact running and the fault is one
 * layer deeper. The database probe is wrapped so it can never do more than
 * change that one field.
 *
 * Still deliberately cheap: the only dependency is `SELECT 1`-style connection
 * resolution via getPdo(), which is a no-op once the connection is already
 * open. No session, no cache, no auth.
 *
 * Unauthenticated and unthrottled on purpose: it carries no user data, and a
 * probe that needed a token could not report on the requests that fail BEFORE
 * a token exists. `api-token` still applies, which keeps it CSRF-free and safe
 * to call from a native client.
 */
class HealthController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        $now = now()->toIso8601String();

        return $this->json([
            'status' => 'online',
            'message' => 'QueueFlow TMC API is running',
            'server' => 'Laravel',
            'api' => 'queueflow-tmc',
            'version' => ApiContract::VERSION,
            // 'connected' or 'unavailable'. Informational only - it never
            // changes the HTTP status, so a database outage is never reported
            // to a client as a network outage.
            'database' => $this->databaseStatus(),
            'timestamp' => $now,
            // Retained for clients built against the original payload.
            'time' => $now,
        ]);
    }

    /**
     * Whether the configured database answers, without ever throwing.
     *
     * getPdo() resolves the connection lazily; on an already-open connection
     * it just hands back the handle. When MySQL is down the driver throws a
     * connection exception, which is caught here and reported as a string
     * rather than allowed to become a 500.
     */
    private function databaseStatus(): string
    {
        try {
            DB::connection()->getPdo();

            return 'connected';
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
