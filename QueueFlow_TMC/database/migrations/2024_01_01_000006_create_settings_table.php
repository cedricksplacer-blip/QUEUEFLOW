<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            // Setting name, for example:
            // site_name
            // college_name
            // primary_color
            // dark_mode
            $table->string('key')->unique();

            // Setting value
            // Can store text, numbers, colors, times, or 0/1 values
            $table->text('value')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};