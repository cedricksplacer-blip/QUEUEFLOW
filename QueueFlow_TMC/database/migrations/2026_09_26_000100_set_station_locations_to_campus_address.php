<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Replace the placeholder station locations with the verified campus address.
     *
     * The previous values ("Finance / Cashier Counter", "Room M 104",
     * "Computer Lab CL 04", "Main Administrative Block", ...) were invented
     * placeholders that never matched the real campus. Trinidad Municipal
     * College is a single-campus institution and publishes no per-office room
     * directory, so the campus address is the only location that can be stated
     * as fact. Room-level detail is left blank for staff to fill in from the
     * admin Offices screen.
     */
    public function up(): void
    {
        $campus = 'Trinidad Municipal College, President Carlos P. Garcia St., Santo Nino, Trinidad, Bohol';

        $operationalCodes = [
            'REG-BSIT',
            'REG-BSOA',
            'REG-BAPOLSCI',
            'REG-BACOMM',
            'REG-BEED',
            'REG-BSED',
            'REG-BSCRIM',
            'CASH',
            'ADM',
            'SASO',
            'SCH',
            'CLINIC',
        ];

        DB::table('office_stations')
            ->whereIn('office_id', function ($query) use ($operationalCodes) {
                $query->select('id')->from('offices')->whereIn('code', $operationalCodes);
            })
            ->update(['location' => $campus, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // The previous values were placeholders with no value to restore.
    }
};
