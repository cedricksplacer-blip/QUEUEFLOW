<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_deployments', function (Blueprint $table) {
            // Batch numbers now reset to #1 whenever an office closes its
            // batch (old batches are archived as history first). Ticket
            // records reference deployment_id, so duplicate batch numbers
            // across history are safe.
            $table->dropUnique(['office_id', 'batch_no']);
        });
    }

    public function down(): void
    {
        Schema::table('queue_deployments', function (Blueprint $table) {
            $table->unique(['office_id', 'batch_no']);
        });
    }
};