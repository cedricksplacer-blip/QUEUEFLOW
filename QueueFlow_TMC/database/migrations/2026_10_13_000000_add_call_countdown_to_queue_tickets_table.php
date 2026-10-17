<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->unsignedInteger('call_duration_minutes')->nullable()->after('called_at');
            $table->timestamp('expires_at')->nullable()->after('call_duration_minutes');

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['call_duration_minutes', 'expires_at']);
        });
    }
};
