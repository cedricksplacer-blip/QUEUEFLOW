<?php

namespace App\Providers;

use App\Support\PlainHasher;
use App\Support\PlainUserProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // QueueFlow TMC keeps account passwords as clear text. The driver is
        // pinned here rather than through HASH_DRIVER so the behaviour cannot
        // be switched off by a stray .env value.
        config(['hashing.driver' => 'plain']);
    }

    public function boot(): void
    {
        // Registered in boot() because the 'hash' singleton is not bound yet
        // while providers are still registering.
        Hash::extend('plain', fn () => new PlainHasher());

        // Every guard-based sign-in funnels through the user provider, so the
        // legacy-hash upgrade is wired in once, here.
        Auth::provider('plain', function ($app, array $config) {
            return new PlainUserProvider(
                $app['hash'],
                $config['model'],
            );
        });

        config(['auth.providers.users.driver' => 'plain']);

        // Render pagination links with Bootstrap 5 markup to match the UI.
        Paginator::useBootstrapFive();
    }
}
