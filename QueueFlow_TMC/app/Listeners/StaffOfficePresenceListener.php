<?php

namespace App\Listeners;

use App\Support\OfficePresence;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Drives office online status from staff web sessions.
 *
 * Fired by every session-guard sign-in/sign-out (unified portal, legacy
 * login, password-confirmed re-login), so no controller has to remember to
 * flip the office. API token sign-in/out never touches the session guard
 * and is handled explicitly in Api\AuthController instead.
 */
class StaffOfficePresenceListener
{
    public function onLogin(Login $event): void
    {
        $user = $event->user;

        if ($user instanceof \App\Models\User) {
            OfficePresence::markOnline($user);
        }
    }

    public function onLogout(Logout $event): void
    {
        $user = $event->user;

        if ($user instanceof \App\Models\User) {
            OfficePresence::markOffline($user);
        }
    }
}
