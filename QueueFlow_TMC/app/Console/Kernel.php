<?php

namespace App\Console;

use App\Http\Controllers\Api\StudentAuthController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Example: reset daily queue numbers every midnight.
        // $schedule->command('queues:reset-daily')->dailyAt('00:00');

        // Clear out reset codes that expired more than a day ago, so the
        // table stays small on a busy campus without needing a cron entry.
        $schedule->call(
            fn () => StudentAuthController::pruneResetCodes()
        )->dailyAt('03:10');

        // Release overdue calls every minute so an expired countdown frees
        // the window even when no browser or phone is open. Arrived tickets
        // are excluded inside the counter itself.
        $schedule->command('queues:expire-overdue')->everyMinute();

        // Take offices with no signed-in staff offline, covering sessions
        // that expire without an explicit logout. The web middleware also
        // sweeps lazily on traffic; this covers quiet hours with none.
        $schedule->command('offices:sync-presence')->everyFifteenMinutes();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
