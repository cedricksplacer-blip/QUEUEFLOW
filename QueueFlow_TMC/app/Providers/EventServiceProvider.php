<?php

namespace App\Providers;

use App\Listeners\StaffOfficePresenceListener;
use App\Listeners\StampApiVersion;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // Staff sign-in brings their assigned office online; sign-out
        // takes it offline when nobody assigned to it is still present.
        Login::class => [
            [StaffOfficePresenceListener::class, 'onLogin'],
        ],

        Logout::class => [
            [StaffOfficePresenceListener::class, 'onLogout'],
        ],

        // Advertised on every /api/v1 response, error responses included.
        RequestHandled::class => [
            StampApiVersion::class,
        ],
    ];

    public function boot(): void
    {
        //
    }
}
