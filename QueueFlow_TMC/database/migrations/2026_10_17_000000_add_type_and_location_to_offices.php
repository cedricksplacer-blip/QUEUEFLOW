<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional descriptors for offices created through the admin
     * Offices screen. Nullable so every existing row keeps working
     * untouched; the Add/Edit forms write them when provided.
     */
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->string('office_type', 60)->nullable()->after('description');
            $table->string('location', 255)->nullable()->after('office_type');
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn(['office_type', 'location']);
        });
    }
};
