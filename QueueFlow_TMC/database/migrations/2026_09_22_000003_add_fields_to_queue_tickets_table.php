<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->after('office_id')->constrained('courses')->nullOnDelete();
            $table->string('priority')->default('regular')->after('course_id'); // regular | pwd
            $table->string('priority_evidence')->nullable()->after('priority');
            $table->foreignId('deployment_id')->nullable()->after('priority_evidence')->constrained('queue_deployments')->nullOnDelete();

            $table->index('course_id');
            $table->index('priority');
            $table->index('deployment_id');
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
            $table->dropIndex(['priority']);
            $table->dropIndex(['deployment_id']);
            $table->dropForeign(['course_id']);
            $table->dropForeign(['deployment_id']);
            $table->dropColumn(['course_id', 'priority', 'priority_evidence', 'deployment_id']);
        });
    }
};