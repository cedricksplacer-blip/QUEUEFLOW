<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\StaffOfficeScope;
use App\Support\SidebarBadges;
use Illuminate\Http\Request;

/**
 * Polling feed for the sidebar badges in the Blade consoles.
 *
 * The layouts render the counts server-side on first paint; this endpoint
 * keeps them fresh without a full page reload (polled on a slow interval,
 * skipped while the tab is hidden). Three cheap COUNT queries per call —
 * no joins, no row hydration.
 */
class SidebarBadgeController extends Controller
{
    use StaffOfficeScope;

    /**
     * System-wide badge counts. Route lives in the admin group
     * (`auth` + `role:admin`), so nothing office-private leaks out.
     */
    public function admin()
    {
        return response()->json(SidebarBadges::admin());
    }

    /**
     * Badge counts for the caller's own office. `officeOf()` 403s when the
     * staff account has no active operational office, exactly like every
     * other staff endpoint, so one office never sees another's numbers.
     */
    public function staff(Request $request)
    {
        return response()->json(
            SidebarBadges::staff($this->officeOf($request)->id)
        );
    }
}
