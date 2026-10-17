<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('status')->default('published')->after('is_active');
            $table->foreignId('submitted_by')->nullable()->after('posted_by')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->after('submitted_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropForeign(['submitted_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['status', 'submitted_by', 'reviewed_by', 'reviewed_at']);
        });
    }
};
