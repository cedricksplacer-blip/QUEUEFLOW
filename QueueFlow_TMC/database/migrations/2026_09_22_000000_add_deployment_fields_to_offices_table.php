<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->time('opening_time')->nullable()->after('description');
            $table->time('closing_time')->nullable()->after('opening_time');
            $table->unsignedSmallInteger('max_slots')->default(100)->after('closing_time');
        });

        // Set sensible defaults for every existing office.
        DB::table('offices')->update([
            'opening_time' => DB::raw("IFNULL(opening_time, '08:00:00')"),
            'closing_time' => DB::raw("IFNULL(closing_time, '17:00:00')"),
            'max_slots' => 100,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Guidance Office removal
        |--------------------------------------------------------------------------
        |
        | The Guidance Office is being completely retired from QueueFlow TMC.
        | Detach any staff/users first so no broken references remain, then
        | remove the office itself. Queue history for this office (if any)
        | follows the office cascade rule used by the rest of the system.
        |
        */

        $guidanceId = DB::table('offices')
            ->where('code', 'GDN')
            ->value('id');

        if ($guidanceId) {
            DB::table('users')->where('office_id', $guidanceId)->update(['office_id' => null]);
            DB::table('offices')->where('id', $guidanceId)->delete();
        }

        Schema::table('offices', function (Blueprint $table) {
            $table->index(['code', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropIndex(['code', 'is_active']);
            $table->dropColumn(['opening_time', 'closing_time', 'max_slots']);
        });
    }
};