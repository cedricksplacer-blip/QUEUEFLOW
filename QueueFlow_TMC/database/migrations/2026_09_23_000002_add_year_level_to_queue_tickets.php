<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('queue_tickets', 'year_level')) {
            Schema::table('queue_tickets', function (Blueprint $table) {
                $table->string('year_level', 30)->nullable()->after('course_id');
                $table->index('year_level');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('queue_tickets', 'year_level')) {
            Schema::table('queue_tickets', function (Blueprint $table) {
                $table->dropIndex(['year_level']);
                $table->dropColumn('year_level');
            });
        }
    }
};
