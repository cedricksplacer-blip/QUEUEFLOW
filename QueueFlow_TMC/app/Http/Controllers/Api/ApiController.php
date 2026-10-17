<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * Shared plumbing for every bearer-token endpoint.
 *
 * The five controllers beside this one exist only to describe WHAT an
 * endpoint does. Everything about HOW a refusal is shaped lives here, so the
 * Flutter client can rely on `message` being present on every non-2xx and
 * never has to special-case one route's error format.
 *
 * @see \App\Support\ApiPresenter for the payload shapes.
 */
abstract class ApiController extends Controller
{
    /**
     * A successful response.
     *
     * The payload is returned at the top level rather than wrapped in a
     * `data` envelope. The Dart side builds one model per screen from one
     * map per call, and an envelope would only add a line to unwrap in all
     * nineteen of them.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status);
    }

    /**
     * A refusal with a message the client can show verbatim.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function fail(string $message, int $status = 422, array $extra = []): JsonResponse
    {
        return response()->json(['message' => $message, ...$extra], $status);
    }

    protected function denied(string $message = 'You do not have permission to perform this action.'): JsonResponse
    {
        return $this->fail($message, 403);
    }

    /**
     * Stop here with a status code.
     *
     * Laravel's abort() renders an HTML error page unless the request
     * `expectsJson()`. The Flutter client does send `Accept:
     * application/json`, so abort() would already be JSON - but only because
     * of something the client sent. Returning the response from here makes
     * the shape guaranteed rather than incidental, so a curl without the
     * header gets the same JSON.
     *
     * Illuminate's own HttpResponseException is the class the framework
     * handler knows how to render as a finished response. Symfony's identically
     * named class is not on that list and would surface as a 500.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function refuse(string $message, int $status): never
    {
        throw new HttpResponseException($this->fail($message, $status));
    }

    /**
     * The signed-in account, or a 401.
     *
     * Every endpoint behind `auth:sanctum` already has a resolved user; this
     * exists so a controller never dereferences `$request->user()` without
     * saying what happens if it is null.
     */
    protected function account(\Illuminate\Http\Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        return $user;
    }

    /**
     * Confirm the account holds one of the given roles.
     */
    protected function guardRoles(User $user, string ...$roles): void
    {
        if (! in_array($user->role, $roles, true)) {
            $this->refuse('This action is not available for your role.', 403);
        }
    }

    /**
     * The office a staff member is allowed to work.
     *
     * Same rule as App\Http\Controllers\Traits\StaffOfficeScope::officeOf() -
     * an assigned, active, operational office - but it returns the account's
     * office rather than aborting, so the API can answer 403 with JSON that
     * explains the assignment problem instead of Laravel's generic refusal.
     */
    protected function staffOffice(?User $user): ?Office
    {
        if (! $user?->isStaff()) {
            return null;
        }

        return $user->office()
            ->whereIn('code', Office::OPERATIONAL_CODES)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Check that $user may act on $office.
     *
     * Admins pass for every operational office. Staff pass only for the one
     * office they are assigned to. This is the single authorisation gate for
     * every queue-mutating endpoint, so a new route cannot accidentally skip
     * it by forgetting an office_id check.
     */
    protected function guardOffice(User $user, Office $office): Office
    {
        if ($user->isAdmin()) {
            return $office;
        }

        if ($user->isStaff() && $this->staffOffice($user)?->id === $office->id) {
            return $office;
        }

        $this->refuse('You are not assigned to that office.', 403);
    }

    /**
     * Load an operational, active office by id.
     */
    protected function operationalOffice(int|string $id): Office
    {
        $office = Office::whereKey($id)
            ->whereIn('code', Office::OPERATIONAL_CODES)
            ->where('is_active', true)
            ->first();

        if (! $office) {
            $this->refuse('That office is not available.', 404);
        }

        return $office;
    }

    /**
     * The accounts an admin is allowed to manage: never the student.
     */
    protected function accountPayload(User $user): array
    {
        $user->loadMissing('office');

        return \App\Support\ApiPresenter::user($user);
    }
}