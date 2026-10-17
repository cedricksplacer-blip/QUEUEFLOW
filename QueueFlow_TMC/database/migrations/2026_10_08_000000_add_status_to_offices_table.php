<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the online/offline switch to the offices table.
 *
 * WHY A NEW COLUMN AND NOT `is_active`
 *
 * `offices.is_active` already exists, but it means "this counter is still part
 * of the campus" and is decided by an administrator when an office closes down
 * for good. The new online/offline switch is the daily "are you accepting
 * students right now?" flag that the assigned staff member flips at the start
 * and end of the day, so the two really are different state and sharing a
 * column would force a member of staff to decommission an office just to take
 * the window offline at 15:00.
 *
 * DEFAULT 'offline'
 *
 * A freshly provisioned campus must not accept tickets until somebody on the
 * staff has switched the office on, so the column defaults to offline and the
 * seeder does not need to guess. Existing rows keep whatever value they carry
 * after this migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offices')) {
            return;
        }

        if (Schema::hasColumn('offices', 'status')) {
            return;
        }

        Schema::table('offices', function (Blueprint $table) {
            $table->string('status', 10)->default('offline')->after('is_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('offices')) {
            return;
        }

        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};