<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived numeric codes for the student app's "forgot password" flow.
 *
 * The browser portal resets through Laravel's token broker and a mailed link
 * (routes/auth.php). The mobile app has no browser to redirect into, so it
 * asks for a 6-digit code instead and types it back into the app.
 *
 * A row is written per request and consumed on use. `expires_at` is the
 * authority, not `created_at`, so a code cannot be replayed after its window
 * even if the row is never cleaned up. The scheduled prune in
 * StudentAuthController keeps the table to one live row per address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->id();

            // Lower-cased so a code request is unique per account regardless of
            // how the student capitalised their address on the form.
            $table->string('email', 191)->index();

            // Only the hash is stored. A leaked table therefore cannot be
            // replayed against the endpoint, unlike a plaintext column.
            $table->string('code_hash', 191);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_codes');
    }
};
