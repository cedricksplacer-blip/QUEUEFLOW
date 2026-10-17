<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Notifications\StudentResetCodeNotification;
use App\Support\PlainPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Password recovery, the last surviving part of the student mobile API.
 *
 * The sign-in, profile, ticket and office endpoints this controller used to
 * host were removed along with routes/student_api.php and routes/api_v1.php.
 * What is left is the pair of POST routes in routes/password_recovery_api.php,
 * mounted at both /api/v1/auth/* and /api/student/*.
 *
 * WHY IT IS STILL HERE
 * QueueFlow is now a browser-only application, and that leaves one hole: a
 * student signs in with their Student ID, so someone who forgets their
 * password cannot get back in on their own, and an admin can only reset it by
 * editing the account. These two routes are the self-service way out. They
 * are unauthenticated by necessity, which is exactly why both responses are
 * shaped so they cannot be used to enumerate accounts.
 */
class StudentAuthController extends Controller
{
    /**
     * POST /api/v1/auth/forgot-password
     * POST /api/student/forgot-password
     *
     * Emails a 6-digit code the student types back to set a new password.
     *
     * The response is deliberately identical whether or not the identifier is
     * a registered student, so this endpoint cannot be used to discover which
     * Student IDs hold accounts. The real reason is written to the log instead.
     */
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'identifier' => ['nullable', 'string', 'max:255', 'required_without:email'],
            'email' => ['nullable', 'string', 'email', 'required_without:identifier'],
        ]);

        $identifier = trim($validated['identifier'] ?? $validated['email']);

        $user = User::findByIdentifier($identifier);

        if (! $user || ! $user->is_active || ! $user->isStudent()) {
            Log::info('Student password reset requested for an unusable account.', [
                'identifier' => $identifier,
                'ip' => $request->ip(),
                'known' => (bool) $user,
            ]);

            return $this->resetRequestAccepted();
        }

        // The code is keyed on the account's real address, not on whatever the
        // student typed, so a code requested by Student ID is still redeemed
        // with it. An account with no address on file cannot be mailed, and
        // saying so would confirm the Student ID exists, so the same generic
        // response is returned and the real reason goes to the log.
        if (! $user->canReceiveMail()) {
            Log::info('Student password reset requested for an account with no email on file.', [
                'user_id' => $user->id,
                'student_id' => $user->student_id,
                'ip' => $request->ip(),
            ]);

            return $this->resetRequestAccepted();
        }

        $email = mb_strtolower((string) $user->email);

        // One code in flight per address: a second request voids the first, so
        // a student who resends can only ever redeem the newest code.
        $recent = PasswordResetCode::where('email', $email)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($recent && $recent->created_at->gt(
            now()->subSeconds(PasswordResetCode::RESEND_COOLDOWN_SECONDS)
        )) {
            $wait = (int) ceil(
                PasswordResetCode::RESEND_COOLDOWN_SECONDS
                - $recent->created_at->diffInSeconds(now())
            );

            return $this->resetRequestAccepted([
                'retry_after' => $wait,
            ]);
        }

        PasswordResetCode::where('email', $email)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        // A fresh code restarts the verification round: past wrong guesses
        // must not count against the new code.
        Cache::forget(self::attemptKey($email));

        $code = (string) random_int(0, 999999);
        $code = str_pad($code, 6, '0', STR_PAD_LEFT);

        $record = PasswordResetCode::create([
            'email' => $email,
            'code_hash' => 'pending',
            'expires_at' => now()->addMinutes(PasswordResetCode::TTL_MINUTES),
            'ip_address' => $request->ip(),
        ]);

        // The hash is bound to the row id, so it can only be written once the
        // id is known.
        $record->forceFill([
            'code_hash' => PasswordResetCode::hashCode($record->id, $code),
        ])->save();

        try {
            $user->notify(new StudentResetCodeNotification(
                $code,
                PasswordResetCode::TTL_MINUTES
            ));
        } catch (\Throwable $e) {
            // A mail failure must not leave a live code nobody was told about.
            $record->forceFill(['consumed_at' => now()])->save();

            Log::error('Student reset code could not be mailed.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'We could not send the reset code right now. Please try again shortly.',
            ], 503);
        }

        return $this->resetRequestAccepted();
    }

    /**
     * POST /api/v1/auth/verify-code
     * POST /api/student/verify-code
     *
     * Checks the emailed code on the server and, when it is correct and
     * fresh, issues a short-lived single-use reset token. The new-password
     * screen cannot open meaningfully without this token: it is the proof
     * the code step passed. The code itself is consumed here, so it can
     * never be verified twice and a verified-then-abandoned round cannot be
     * resumed — a new code must be requested.
     */
    public function verifyCode(Request $request)
    {
        $validated = $request->validate([
            'identifier' => ['nullable', 'string', 'max:255', 'required_without:email'],
            'email' => ['nullable', 'string', 'email', 'required_without:identifier'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $field = filled($validated['identifier'] ?? null) ? 'identifier' : 'email';
        $user = User::findByIdentifier(trim($validated['identifier'] ?? $validated['email']));

        if (! $user) {
            throw ValidationException::withMessages([
                $field => ['That account is no longer registered.'],
            ]);
        }

        // Codes are stored against the account's real address so a code
        // requested by Student ID is verified with the same Student ID.
        $email = mb_strtolower((string) $user->email);

        $record = PasswordResetCode::where('email', $email)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'code' => ['That reset code is not valid. Request a new one.'],
            ]);
        }

        if ($record->isExpired()) {
            throw ValidationException::withMessages([
                'code' => ['That reset code has expired. Request a new one.'],
            ]);
        }

        $attempts = (int) Cache::get(self::attemptKey($email), 0);

        if ($attempts >= PasswordResetCode::MAX_VERIFY_ATTEMPTS) {
            throw ValidationException::withMessages([
                'code' => ['Too many incorrect attempts. Request a new code.'],
            ]);
        }

        if (! $record->codeMatches($validated['code'])) {
            Cache::put(
                self::attemptKey($email),
                $attempts + 1,
                now()->addMinutes(PasswordResetCode::TTL_MINUTES)
            );

            throw ValidationException::withMessages([
                'code' => ['That reset code is not valid. Request a new one.'],
            ]);
        }

        $record->markConsumed();
        Cache::forget(self::attemptKey($email));

        $token = Str::random(48);

        Cache::put(
            self::tokenKey($email),
            hash('sha256', $token),
            now()->addMinutes(PasswordResetCode::VERIFY_TOKEN_TTL_MINUTES)
        );

        return response()->json([
            'message' => 'Code verified. Choose a new password.',
            'reset_token' => $token,
            'expires_in_minutes' => PasswordResetCode::VERIFY_TOKEN_TTL_MINUTES,
        ]);
    }

    /**
     * POST /api/v1/auth/reset-password
     * POST /api/student/reset-password
     *
     * Sets a new password, gated either by a freshly verified reset token
     * (the stepped app flow) or, for older clients, by the emailed code
     * directly. Every issued token is revoked, so a device that was signed
     * in with the old password is locked out.
     */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'identifier' => ['nullable', 'string', 'max:255', 'required_without:email'],
            'email' => ['nullable', 'string', 'email', 'required_without:identifier'],
            'code' => ['nullable', 'string', 'digits:6', 'required_without:reset_token'],
            'reset_token' => ['nullable', 'string', 'required_without:code'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $field = filled($validated['identifier'] ?? null) ? 'identifier' : 'email';
        $user = User::findByIdentifier(trim($validated['identifier'] ?? $validated['email']));

        if (! $user) {
            throw ValidationException::withMessages([
                $field => ['That account is no longer registered.'],
            ]);
        }

        // Codes are stored against the account's real address so a code
        // requested by Student ID is redeemed with the same Student ID.
        $email = mb_strtolower((string) $user->email);

        if (filled($validated['reset_token'] ?? null)) {
            $expected = Cache::get(self::tokenKey($email));

            if (! $expected || ! hash_equals($expected, hash('sha256', $validated['reset_token']))) {
                throw ValidationException::withMessages([
                    'code' => ['Verification has expired. Verify your code again.'],
                ]);
            }

            // Single use: a token that set a password can never set another.
            Cache::forget(self::tokenKey($email));
        } else {
            $record = PasswordResetCode::where('email', $email)
                ->whereNull('consumed_at')
                ->latest('id')
                ->first();

            if (! $record) {
                throw ValidationException::withMessages([
                    'code' => ['That reset code is not valid. Request a new one.'],
                ]);
            }

            if ($record->isExpired()) {
                throw ValidationException::withMessages([
                    'code' => ['That reset code has expired. Request a new one.'],
                ]);
            }

            if (! $record->codeMatches($validated['code'])) {
                throw ValidationException::withMessages([
                    'code' => ['That reset code is not valid. Request a new one.'],
                ]);
            }

            $record->markConsumed();
        }

        $user->forceFill([
            'password' => PlainPassword::make($validated['password']),
        ])->save();

        // A password change invalidates every device, this one included.
        $user->tokens()->delete();

        Log::info('Student password reset completed.', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Your password has been reset. Please sign in.',
        ]);
    }

    /**
     * Cache key for an address's wrong-code guesses this round.
     */
    private static function attemptKey(string $email): string
    {
        return 'pwd-reset-attempts:'.$email;
    }

    /**
     * Cache key for an address's verified reset token.
     */
    private static function tokenKey(string $email): string
    {
        return 'pwd-reset-token:'.$email;
    }

    /**
     * The same body for every outcome of a reset request.
     */
    private function resetRequestAccepted(array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'message' => 'If that address belongs to an active student, '
                .'a reset code is on its way.',
            'expires_in_minutes' => PasswordResetCode::TTL_MINUTES,
        ], $extra));
    }

    /**
     * Deletes codes that can no longer be redeemed. Wired to the scheduler.
     */
    public static function pruneResetCodes(): void
    {
        PasswordResetCode::where('expires_at', '<', now()->subDay())->delete();
    }
}
