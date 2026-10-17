<?php

namespace App\Listeners;

use App\Support\ApiContract;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps every student API response with the version that produced it.
 *
 * A phone in the field cannot be forced to upgrade, so when a build starts
 * receiving answers it does not recognise it needs to be able to tell that the
 * contract moved underneath it. X-Api-Version is that signal.
 *
 * This listens to RequestHandled rather than sitting in the route middleware
 * stack on purpose. Middleware only regains control when the pipeline returns
 * normally: the moment a route aborts - which is what an expired token does on
 * every single call - the exception unwinds past it and the header is lost.
 * RequestHandled fires after the kernel has rendered that error, so the header
 * survives exactly the responses an app most needs to identify.
 */
class StampApiVersion
{
    public function handle(RequestHandled $event): void
    {
        if (! $event->request->is('api/v1', 'api/v1/*')) {
            return;
        }

        $response = $event->response;

        if (! $response instanceof Response) {
            return;
        }

        $response->headers->set('X-Api-Version', ApiContract::VERSION);
    }
}
