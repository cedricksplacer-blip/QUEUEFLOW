<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Students are identified by their Student ID, not an email address.
 *
 * Two schema changes are needed for that to hold:
 *
 *  1. users.email becomes nullable, so an admin can create a student from a
 *     Student ID alone instead of inventing a throwaway gmail address.
 *  2. users.student_id gets a unique index, because it is now a credential.
 *     Until now nothing stopped two accounts sharing one, and the sign-in
 *     lookup resolves Student ID before email, so a duplicate would make the
 *     winner unpredictable.
 *
 * MySQL permits any number of NULLs in a unique index, so the many staff and
 * admin rows that have no Student ID are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('users')
            ->select('student_id')
            ->whereNotNull('student_id')
            ->where('student_id', '!=', '')
            ->groupBy('student_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('student_id');

        if ($duplicates->isNotEmpty()) {
            // Refusing here is deliberate. Silently blanking a duplicate
            // Student ID would quietly lock that account out of its own
            // sign-in, and a hard failure points at the rows to fix first.
            throw new RuntimeException(
                'Cannot add the unique index on users.student_id; these values are shared by more than one account: '
                .$duplicates->implode(', ')
            );
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // Raw DDL avoids a doctrine/dbal dependency for ->change().
            DB::statement('ALTER TABLE `users` MODIFY `email` VARCHAR(255) NULL');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->nullable()->change();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('student_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['student_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // Every row needs a value again before the column can go back to
            // NOT NULL, so anything created without an email is filled in
            // with a non-deliverable placeholder derived from the Student ID.
            DB::statement(
                "UPDATE `users` SET `email` = CONCAT(COALESCE(`student_id`, `id`), '@students.queueflow.local') "
                .'WHERE `email` IS NULL OR `email` = \'\''
            );

            DB::statement('ALTER TABLE `users` MODIFY `email` VARCHAR(255) NOT NULL');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->nullable(false)->change();
            });
        }
    }
};
