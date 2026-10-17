<?php

namespace App\Console\Commands;

use App\Services\QueueCounter;
use Illuminate\Console\Command;

/**
 * Report every overdue call still awaiting a staff decision.
 *
 * Runs every minute from the scheduler so expiry stays observable even when
 * no browser or phone is open. Overdue tickets are NOT auto-released: they
 * stay serving+expired until staff choose Call Again or No-Show, which is
 * what raises the popup on the staff console and the "time expired" state
 * on the student's phone. Arrived tickets are excluded (deadline cleared).
 */
class ExpireOverdueCalls extends Command
{
    protected $signature = 'queues:expire-overdue';

    protected $description = 'Count overdue serving tickets awaiting Call Again / No-Show.';

    public function handle(QueueCounter $counter): int
    {
        $overdue = $counter->expireAllOverdue();

        $this->info("Overdue ticket(s) awaiting staff action: {$overdue}.");

        return self::SUCCESS;
    }
}
