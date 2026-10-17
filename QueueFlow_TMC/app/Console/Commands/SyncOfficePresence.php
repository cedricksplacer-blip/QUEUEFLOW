<?php

namespace App\Console\Commands;

use App\Support\OfficePresence;
use Illuminate\Console\Command;

/**
 * Take offices with no signed-in staff offline.
 *
 * Run on a schedule as a backstop for sessions that expire without an
 * explicit logout (their presence keys lapse silently). The web middleware
 * already sweeps lazily on traffic; this covers quiet hours with no
 * requests at all.
 */
class SyncOfficePresence extends Command
{
    protected $signature = 'offices:sync-presence';

    protected $description = 'Mark offices with no signed-in staff as offline.';

    public function handle(): int
    {
        $changed = OfficePresence::sweepStaleOffline();

        $this->info("Office presence synced. Groups taken offline: {$changed}.");

        return self::SUCCESS;
    }
}
