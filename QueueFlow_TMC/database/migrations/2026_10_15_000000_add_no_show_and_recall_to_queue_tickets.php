<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Terminal No-Show bookkeeping + Call-Again recall tracking.
     *
     *  no_show_at       when staff marked the serving ticket as No-Show.
     *  no_show_by       staff user who performed the No-Show action.
     *  recall_count     how many times the same serving ticket was called again.
     *  last_recalled_at when the ticket was last called again.
     *
     * All nullable / defaulted so existing rows migrate untouched. No data
     * is deleted and no existing column is altered.
     */
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('queue_tickets', 'no_show_at')) {
                $table->timestamp('no_show_at')->nullable()->after('cancel_reason');
            }
            if (! Schema::hasColumn('queue_tickets', 'no_show_by')) {
                $table->unsignedBigInteger('no_show_by')->nullable()->after('no_show_at');
            }
            if (! Schema::hasColumn('queue_tickets', 'recall_count')) {
                $table->unsignedInteger('recall_count')->default(0)->after('no_show_by');
            }
            if (! Schema::hasColumn('queue_tickets', 'last_recalled_at')) {
                $table->timestamp('last_recalled_at')->nullable()->after('recall_count');
            }
        });

        // FK + indexes where the driver allows it (SQLite in tests ignores FK).
        try {
            Schema::table('queue_tickets', function (Blueprint $table) {
                $table->index('no_show_at');
                $table->index('no_show_by');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('queue_tickets', function (Blueprint $table) {
                $table->foreign('no_show_by')->references('id')->on('users')->nullOnDelete();
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        try {
            Schema::table('queue_tickets', function (Blueprint $table) {
                $table->dropForeign(['no_show_by']);
            });
        } catch (\Throwable) {
        }

        Schema::table('queue_tickets', function (Blueprint $table) {
            try {
                $table->dropIndex(['no_show_at']);
            } catch (\Throwable) {
            }
            try {
                $table->dropIndex(['no_show_by']);
            } catch (\Throwable) {
            }
            $cols = [];
            foreach (['no_show_at', 'no_show_by', 'recall_count', 'last_recalled_at'] as $c) {
                if (Schema::hasColumn('queue_tickets', $c)) {
                    $cols[] = $c;
                }
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
