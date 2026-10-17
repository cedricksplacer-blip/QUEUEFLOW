<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->string('name');
            $table->string('location')->nullable();
            $table->json('course_codes')->nullable();
            $table->unsignedSmallInteger('min_daily_students')->default(40);
            $table->unsignedSmallInteger('target_daily_students')->default(60);
            $table->unsignedSmallInteger('max_daily_slots')->default(100);
            $table->time('opening_time')->default('08:00:00');
            $table->time('closing_time')->default('15:00:00');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['office_id', 'is_active']);
        });

        Schema::create('office_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('office_stations')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('requires_course')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['office_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_services');
        Schema::dropIfExists('office_stations');
    }
};
