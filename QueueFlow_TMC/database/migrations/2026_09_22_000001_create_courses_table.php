<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();      // e.g. BSIT
            $table->string('name');                    // e.g. Bachelor of Science in Information Technology
            $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed the standard Registrar course list.
        $registrarId = DB::table('offices')->where('code', 'REG')->value('id');

        if ($registrarId) {
            $courses = [
                'BSIT' => 'Bachelor of Science in Information Technology',
                'BSOA' => 'Bachelor of Science in Office Administration',
                'BSCRIM' => 'Bachelor of Science in Criminology',
                'BEED' => 'Bachelor of Elementary Education',
                'BSED' => 'Bachelor of Secondary Education',
                'BACOMM' => 'Bachelor of Arts in Communication',
                'BAPOLSCI' => 'Bachelor of Arts in Political Science',
            ];

            foreach ($courses as $code => $name) {
                DB::table('courses')->updateOrInsert(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'office_id' => $registrarId,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};