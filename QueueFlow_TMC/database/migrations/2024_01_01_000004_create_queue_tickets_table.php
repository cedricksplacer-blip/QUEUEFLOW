<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('queue_number'); // e.g. REG-001
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('served_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose')->nullable();
            $table->string('status')->default('waiting'); // waiting | serving | served | cancelled
            $table->timestamp('called_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_tickets');
    }
};
