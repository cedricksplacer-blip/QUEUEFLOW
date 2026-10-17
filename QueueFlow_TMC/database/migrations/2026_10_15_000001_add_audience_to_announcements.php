<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Who may view the announcement: 'user' (User Only),
            // 'staff' (Staff Only), or 'both' (User and Staff).
            // Defaults to 'both' so every existing notice stays visible
            // to everyone, matching the pre-audience behaviour.
            $table->string('audience')->default('both')->after('office_id');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
