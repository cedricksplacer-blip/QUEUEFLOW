<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('course', 20)->nullable()->after('student_id');
            $table->string('block', 10)->nullable()->after('course');
            $table->string('year_level', 20)->nullable()->after('block');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['course', 'block', 'year_level']);
        });
    }
};