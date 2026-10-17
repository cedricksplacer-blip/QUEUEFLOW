<?php

namespace App\Http\Middleware;

use App\Support\OfficePresence;
use Closure;
use Illuminate\Http\Request;

/**
 * Refreshes a signed-in staff member's presence key on every web request.
 *
 * The key lives as long as the session stays active (sliding window), so
 * the scheduled `offices:sync-presence` sweep can tell an actively working
 * staff member apart from an expired session whose key has lapsed.
 *
 * Deliberately writes no office rows: status flips happen on sign-in /
 * sign-out events and in the sweep, so rendering any page (including the
 * admin dashboard) never mutates what it displays.
 */
class TrackStaffPresence
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $user = $request->user();

            // Blocked (inactive) accounts accrue no presence: their key lapses
            // so the sweep takes their office offline like an expired session.
            if ($user && $user->isStaff() && $user->is_active) {
                OfficePresence::touch((int) $user->id);
            }
        } catch (\Throwable) {
            // Presence must never break the page being rendered.
        }

        return $next($request);
    }
}
