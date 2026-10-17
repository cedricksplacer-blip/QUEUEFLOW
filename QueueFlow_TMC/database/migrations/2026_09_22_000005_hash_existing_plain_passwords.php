<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Hash any legacy plain-text passwords so all stored passwords use
     * Laravel's bcrypt hashing (the value stored is the user's password).
     */
    public function up(): void
    {
        $users = DB::table('users')->get(['id', 'password']);

        foreach ($users as $user) {
            if (str_starts_with((string) $user->password, '$')) {
                continue;
            }

            $hashed = Hash::make($user->password);

            DB::table('users')->where('id', $user->id)->update([
                'password' => $hashed,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // One-way hardening; hashes are not reversible.
    }
};