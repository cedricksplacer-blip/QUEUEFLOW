<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Everything under /api answers in JSON, whatever the client asked for.
     *
     * By default Laravel only renders an error as JSON when the request says
     * it expects JSON - usually via `Accept: application/json` - and falls back
     * to an HTML error page otherwise. That makes the error format depend on a
     * header the caller controls: a Flutter build that forgets the header, or a
     * `curl` someone uses to check the server, gets HTML back where every other
     * failure on the same route produced JSON. The mobile client then fails to
     * decode the body instead of showing the message.
     *
     * Scoped to the `api` prefix on purpose. The Blade admin and staff panels
     * keep the stock behaviour, so an admin still gets a readable error page
     * rather than a JSON body in the middle of the panel.
     *
     * Paired with APP_DEBUG=false in .env, which is what keeps the rendered
     * message free of an exception class, a file and line, and the query that
     * produced it.
     */
    protected function shouldReturnJson($request, Throwable $e): bool
    {
        if ($request->is('api/*')) {
            return true;
        }

        return parent::shouldReturnJson($request, $e);
    }
}
