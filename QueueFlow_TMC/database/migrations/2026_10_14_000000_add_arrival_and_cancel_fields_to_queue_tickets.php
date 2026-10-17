<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arrival + cancellation bookkeeping for the call-timeout workflow.
     *
     *  arrived_at    when staff confirmed the student reached the window
     *                (Stop Timer). Clears the deadline without changing the
     *                serving status, so the ticket stays workable.
     *  cancelled_at  when the ticket left the queue as cancelled.
     *  cancel_reason optional staff note (max 255) recorded with the cancel.
     *  staff_notifications.reason mirrors the reason into the activity log
     *                snapshot so history stays readable if the ticket row
     *                is later edited.
     */
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->timestamp('arrived_at')->nullable()->after('expires_at');
            $table->timestamp('cancelled_at')->nullable()->after('arrived_at');
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_at');

            $table->index('arrived_at');
            $table->index('cancelled_at');
        });

        Schema::table('staff_notifications', function (Blueprint $table) {
            $table->string('reason', 255)->nullable()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('staff_notifications', function (Blueprint $table) {
            $table->dropColumn(['reason']);
        });

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropIndex(['arrived_at']);
            $table->dropIndex(['cancelled_at']);
            $table->dropColumn(['arrived_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
