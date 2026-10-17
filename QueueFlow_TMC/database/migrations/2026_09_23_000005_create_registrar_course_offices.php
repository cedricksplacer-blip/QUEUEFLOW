<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COURSE_CODES = [
        'BSIT',
        'BSOA',
        'BAPOLSCI',
        'BACOMM',
        'BEED',
        'BSED',
        'BSCRIM',
    ];

    public function up(): void
    {
        // REG-BAPOLSCI is 12 chars; widen before inserting the new codes.
        DB::statement('ALTER TABLE offices MODIFY code VARCHAR(20) NOT NULL');

        // Revert migration 000004: each course now has its own office, so
        // deployments are partitioned per office again (no course_id needed).
        // NOTE: the (office_id, status) index from migration 000002 was never
        // dropped by 000004, so it is still present and must NOT be re-added.
        if (Schema::hasColumn('queue_deployments', 'course_id')) {
            Schema::table('queue_deployments', function (Blueprint $table) {
                $table->dropUnique(['office_id', 'course_id', 'batch_no']);
                $table->dropIndex(['office_id', 'course_id', 'status']);
                $table->dropForeign(['course_id']);
                $table->dropColumn('course_id');
            });
        }

        if (! Schema::hasIndex('queue_deployments', ['office_id', 'batch_no'])) {
            Schema::table('queue_deployments', function (Blueprint $table) {
                $table->unique(['office_id', 'batch_no']);
            });
        }

        $registrar = DB::table('offices')->where('code', 'REG')->first();

        if (! $registrar) {
            // Fresh/empty database (e.g. RefreshDatabase tests with no seeder).
            // Schema changes above still apply; the Registrar office rows do not
            // exist yet and will be created later by DatabaseSeeder.
            return;
        }

        $now = now();
        $registrarStation = DB::table('office_stations')
            ->where('office_id', $registrar->id)
            ->first();
        $registrarServices = DB::table('office_services')
            ->where('office_id', $registrar->id)
            ->get();

        foreach (self::COURSE_CODES as $code) {
            $officeId = DB::table('offices')->insertGetId([
                'name' => "Registrar - {$code}",
                'code' => "REG-{$code}",
                'description' => $registrar->description,
                'is_active' => true,
                'opening_time' => $registrar->opening_time ?? '08:00:00',
                'closing_time' => $registrar->closing_time ?? '15:00:00',
                'max_slots' => $registrar->max_slots ?? 100,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $stationId = DB::table('office_stations')->insertGetId([
                'office_id' => $officeId,
                'name' => "Registrar {$code} Station",
                'location' => $registrarStation->location ?? 'Main Administrative Block',
                'course_codes' => json_encode([$code]),
                'min_daily_students' => $registrarStation->min_daily_students ?? 40,
                'target_daily_students' => $registrarStation->target_daily_students ?? 60,
                'max_daily_slots' => $registrarStation->max_daily_slots ?? 100,
                'opening_time' => $registrar->opening_time ?? '08:00:00',
                'closing_time' => $registrar->closing_time ?? '15:00:00',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($registrarServices as $service) {
                DB::table('office_services')->insert([
                    'office_id' => $officeId,
                    'station_id' => $stationId,
                    'name' => $service->name,
                    'description' => $service->description,
                    'requires_course' => $service->requires_course,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('courses')
                ->where('code', $code)
                ->update(['office_id' => $officeId]);
        }

        // Retire the legacy single Registrar office and its staff/station/services.
        DB::table('offices')->where('id', $registrar->id)->update(['is_active' => false]);
        DB::table('office_stations')
            ->where('office_id', $registrar->id)
            ->update(['is_active' => false]);
        DB::table('office_services')
            ->where('office_id', $registrar->id)
            ->update(['is_active' => false]);
        DB::table('users')
            ->where('role', 'staff')
            ->where('office_id', $registrar->id)
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        foreach (self::COURSE_CODES as $code) {
            DB::table('offices')->where('code', "REG-{$code}")->delete();
        }

        DB::table('offices')->where('code', 'REG')->update(['is_active' => true]);
        DB::table('office_services')
            ->where('office_id', function ($query) {
                $query->select('id')->from('offices')->where('code', 'REG');
            })
            ->update(['is_active' => true]);
        DB::table('office_stations')
            ->where('office_id', function ($query) {
                $query->select('id')->from('offices')->where('code', 'REG');
            })
            ->update(['is_active' => true]);

        Schema::table('queue_deployments', function (Blueprint $table) {
            $table->dropUnique(['office_id', 'batch_no']);
            $table->foreignId('course_id')
                ->nullable()
                ->after('office_id')
                ->constrained('courses')
                ->nullOnDelete();
            $table->unique(['office_id', 'course_id', 'batch_no']);
            $table->index(['office_id', 'course_id', 'status']);
        });
    }
};
