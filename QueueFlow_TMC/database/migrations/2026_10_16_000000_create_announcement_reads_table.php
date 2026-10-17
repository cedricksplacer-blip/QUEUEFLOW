<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-staff read state for admin announcements.
     *
     * The bell polls published staff-visible announcements and derives
     * unread status from this table: no fan-out rows are written when the
     * admin publishes, so a new announcement is instantly unread for every
     * staff member and audience filtering stays a query concern.
     */
    public function up(): void
    {
        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'user_id']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
    }
};
