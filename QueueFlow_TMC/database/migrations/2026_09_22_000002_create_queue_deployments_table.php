<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->unsignedInteger('batch_no');
            $table->string('status')->default('open'); // open | full | closed | deployed | completed
            $table->unsignedSmallInteger('max_slots')->default(100);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'batch_no']);
            $table->index(['office_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_deployments');
    }
};