<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $official = ['REG', 'CASH', 'ADM', 'SASO', 'SCH', 'CLINIC'];

        DB::table('offices')
            ->whereNotIn('code', $official)
            ->update(['is_active' => false]);

        DB::table('office_services')
            ->whereNotIn('office_id', function ($query) use ($official) {
                $query->select('id')->from('offices')->whereIn('code', $official);
            })
            ->update(['is_active' => false]);

        DB::table('users')
            ->where('role', 'staff')
            ->whereNotIn('office_id', function ($query) use ($official) {
                $query->select('id')->from('offices')->whereIn('code', $official);
            })
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        // Legacy office records are intentionally not reactivated automatically.
    }
};
