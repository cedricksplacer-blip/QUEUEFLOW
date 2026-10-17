<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent per-office cancellation alerts.
     *
     * One row is written only after a ticket has actually moved to
     * `cancelled`. The snapshot columns (student name/ID, queue number,
     * office name, purpose, priority, cancelled_at) are copied at write
     * time so the history stays readable even if the student or ticket
     * row is later edited or removed. The foreign keys keep the live
     * scoping (office dashboard, admin overview, ticket link).
     */
    public function up(): void
    {
        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->default('ticket_cancelled');
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('queue_tickets')->nullOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120)->default('Student Cancelled Ticket');
            $table->text('message');
            $table->string('student_name', 120)->nullable();
            $table->string('student_id_no', 60)->nullable();
            $table->string('queue_number', 40)->nullable();
            $table->string('office_name', 120)->nullable();
            $table->string('office_code', 20)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->string('priority', 20)->default('regular');
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->foreignId('read_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One cancellation alert per ticket: re-POSTing the same cancel
            // must not stack a second row.
            $table->unique(['ticket_id', 'type']);
            $table->index(['office_id', 'is_read']);
            $table->index(['office_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notifications');
    }
};
