<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('office_id')->constrained('office_services')->nullOnDelete();
            $table->foreignId('station_id')->nullable()->after('service_id')->constrained('office_stations')->nullOnDelete();
            $table->index('service_id');
            $table->index('station_id');
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropIndex(['service_id']);
            $table->dropIndex(['station_id']);
            $table->dropForeign(['service_id']);
            $table->dropForeign(['station_id']);
            $table->dropColumn(['service_id', 'station_id']);
        });
    }
};
