<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to one or more account roles, e.g. role:admin or role:admin,staff
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || (! empty($roles) && ! in_array($user->role, $roles, true))) {
            abort(403, 'You do not have permission to access this area.');
        }

        return $next($request);
    }
}
