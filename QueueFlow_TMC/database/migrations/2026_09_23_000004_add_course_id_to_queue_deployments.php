<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_deployments', function (Blueprint $table) {
            $table->foreignId('course_id')
                ->nullable()
                ->after('office_id')
                ->constrained('courses')
                ->nullOnDelete();

            $table->dropUnique(['office_id', 'batch_no']);
            $table->unique(['office_id', 'course_id', 'batch_no']);
            $table->index(['office_id', 'course_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('queue_deployments', function (Blueprint $table) {
            $table->dropUnique(['office_id', 'course_id', 'batch_no']);
            $table->dropIndex(['office_id', 'course_id', 'status']);
            $table->unique(['office_id', 'batch_no']);
            $table->dropForeign(['course_id']);
            $table->dropColumn('course_id');
        });
    }
};