<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\StudentAuthController;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Notifications\StudentResetCodeNotification;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use ReflectionProperty;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * Covers the mobile app's "forgot password" flow: a 6-digit code is mailed,
 * typed back into the app, and exchanged for a new password.
 */
class StudentPasswordResetTest extends TestCase
{
    use CreatesQueueFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        RateLimiter::clear('student-reset');
        RateLimiter::clear('student-login');
    }

    private function requestCode(User $user)
    {
        return $this->postJson('/api/student/forgot-password', ['email' => $user->email]);
    }

    private function redeem(User $user, string $code, string $password = 'brand-new-password')
    {
        return $this->postJson('/api/student/reset-password', [
            'email' => $user->email,
            'code' => $code,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    /**
     * The digits a student would have read out of their inbox.
     *
     * The code is only ever kept in clear text inside the notification, so
     * reaching for it is the one way a test can act as the student holding the
     * phone. Everything else asserts against the stored hash instead.
     */
    private function mailedCode(User $user): string
    {
        $notification = Notification::sent($user, StudentResetCodeNotification::class)->last();

        $this->assertNotNull($notification, 'No reset code was mailed.');

        $code = new ReflectionProperty(StudentResetCodeNotification::class, 'code');
        $code->setAccessible(true);

        return $code->getValue($notification);
    }

    // ==========================================================
    // Requesting a code
    // ==========================================================

    public function test_an_active_student_is_mailed_a_six_digit_code(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT);

        $response = $this->requestCode($student);

        $response->assertOk()->assertJson([
            'expires_in_minutes' => PasswordResetCode::TTL_MINUTES,
        ]);

        Notification::assertSentTo($student, StudentResetCodeNotification::class);

        $this->assertMatchesRegularExpression(
            '/^\d{6}$/',
            $this->mailedCode($student)
        );
    }

    /**
     * The response must not reveal whether the address holds an account, so an
     * unknown address has to look exactly like a known one.
     */
    public function test_an_unknown_address_gets_the_same_answer_and_no_code(): void
    {
        $response = $this->postJson('/api/student/forgot-password', [
            'email' => 'nobody@tmc.test',
        ]);

        $response->assertOk()->assertJson([
            'message' => 'If that address belongs to an active student, a reset code is on its way.',
            'expires_in_minutes' => PasswordResetCode::TTL_MINUTES,
        ]);

        Notification::assertNothingSent();
    }

    public function test_a_staff_account_is_never_mailed_a_student_code(): void
    {
        $staff = $this->makeUser(User::ROLE_STAFF);

        $this->requestCode($staff)->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_deactivated_student_is_not_mailed_a_code(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, ['is_active' => false]);

        $this->requestCode($student)->assertOk();

        Notification::assertNothingSent();
    }

    public function test_the_address_is_matched_regardless_of_capitalisation(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, ['email' => 'delia@tmc.test']);

        $this->postJson('/api/student/forgot-password', ['email' => 'DeLiA@TMC.Test'])
            ->assertOk();

        Notification::assertSentTo($student, StudentResetCodeNotification::class);

        // Stored lower-cased, so one account cannot hold two live codes.
        $this->assertDatabaseHas('password_reset_codes', [
            'email' => 'delia@tmc.test',
        ]);
    }

    /**
     * The Flutter form posts one `identifier` field holding either a Student
     * ID or an email address. Requesting by email and redeeming by Student ID
     * must reach the same account, so a student who retypes a different
     * address still completes recovery for their own account only.
     */
    public function test_identifier_accepts_an_email_then_a_student_id_for_the_same_account(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, [
            'student_id' => '24-019083',
            'email' => 'delia@tmc.test',
            'password' => 'password',
        ]);

        $this->postJson('/api/student/forgot-password', ['identifier' => 'delia@tmc.test'])
            ->assertOk()
            ->assertJson(['expires_in_minutes' => PasswordResetCode::TTL_MINUTES]);

        Notification::assertSentTo($student, StudentResetCodeNotification::class);

        $this->postJson('/api/student/reset-password', [
            'identifier' => '24-019083',
            'code' => $this->mailedCode($student),
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertSame('brand-new-password', $student->fresh()->password);

        // And the new password opens the real door while the old one is dead.
        $this->post('/login', [
            'identifier' => '24-019083',
            'password' => 'brand-new-password',
        ])->assertRedirect();
        $this->assertAuthenticated();

        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', [
            'identifier' => '24-019083',
            'password' => 'password',
        ]);
        $this->assertGuest();
    }

    public function test_only_a_hash_of_the_code_is_stored(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT);

        $this->requestCode($student);

        $record = PasswordResetCode::where('email', $student->email)->sole();
        $code = $this->mailedCode($student);

        $this->assertNotSame($code, $record->code_hash);
        $this->assertSame(
            PasswordResetCode::hashCode($record->id, $code),
            $record->code_hash
        );
    }

    // ==========================================================
    // Verifying a code (the stepped app flow)
    // ==========================================================

    private function verifyCode(User $user, string $code)
    {
        return $this->postJson('/api/student/verify-code', [
            'email' => $user->email,
            'code' => $code,
        ]);
    }

    public function test_a_correct_code_returns_a_single_use_reset_token(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT);

        $this->requestCode($student);

        $this->verifyCode($student, $this->mailedCode($student))
            ->assertOk()
            ->assertJsonStructure(['message', 'reset_token', 'expires_in_minutes']);

        // Consumed at verify time, so the same digits can never verify twice.
        $this->assertTrue(
            PasswordResetCode::where('email', $student->email)->sole()->isExpired()
            || PasswordResetCode::where('email', $student->email)->sole()->consumed_at !== null
        );
    }

    public function test_a_token_resets_the_password_and_cannot_be_reused(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, ['password' => 'password']);

        $this->requestCode($student);
        $token = $this->verifyCode($student, $this->mailedCode($student))->json('reset_token');

        $payload = [
            'email' => $student->email,
            'reset_token' => $token,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ];

        $this->postJson('/api/student/reset-password', $payload)->assertOk();
        $this->assertSame('brand-new-password', $student->fresh()->password);

        // Single use: replaying the token is refused.
        $this->postJson('/api/student/reset-password', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_a_wrong_code_is_rejected_at_verify_time(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT);

        $this->requestCode($student);

        $this->verifyCode($student, '000000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_repeated_wrong_codes_lock_the_round(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT);

        $this->requestCode($student);

        for ($i = 0; $i < PasswordResetCode::MAX_VERIFY_ATTEMPTS; $i++) {
            $this->verifyCode($student, '000000')->assertStatus(422);
        }

        // Even the right digits no longer work: a fresh code is required.
        $this->verifyCode($student, $this->mailedCode($student))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_an_expired_code_cannot_be_verified(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT);

        $this->requestCode($student);

        PasswordResetCode::where('email', $student->email)
            ->update(['expires_at' => now()->subMinute()]);

        $this->verifyCode($student, $this->mailedCode($student))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    // ==========================================================
    // Redeeming a code
    // ==========================================================

    public function test_a_valid_code_sets_a_new_usable_password(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, ['password' => 'password']);
        $oldPassword = $student->password;

        $this->requestCode($student);
        $this->redeem($student, $this->mailedCode($student))
            ->assertOk()
            ->assertJson(['message' => 'Your password has been reset. Please sign in.']);

        $this->assertSame('brand-new-password', $student->fresh()->password);
        $this->assertNotSame($oldPassword, $student->fresh()->password);

        // The whole point: the student can now actually sign in with it.
        // QueueFlow is a browser-only application, so the portal login is the
        // real door this password has to open.
        $this->post('/login', [
            'identifier' => $student->email,
            'password' => 'brand-new-password',
        ])->assertRedirect(route('dashboard'));

        $this->post('/logout');

        // ...and the old password is dead, not merely shadowed.
        $this->post('/login', [
            'identifier' => $student->email,
            'password' => 'password',
        ])->assertSessionHasErrors('identifier');
    }

    public function test_a_code_cannot_be_redeemed_twice(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, ['password' => 'password']);

        $this->requestCode($student);
        $code = $this->mailedCode($student);

        $this->redeem($student, $code)->assertOk();

        $this->redeem($student, $code)
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_a_wrong_code_is_rejected(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, ['password' => 'password']);

        $this->requestCode($student);
        $code = $this->mailedCode($student);

        $this->redeem($student, $code === '000000' ? '111111' : '000000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame('password', $student->fresh()->password);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, ['password' => 'password']);

        $this->requestCode($student);
        $code = $this->mailedCode($student);

        $this->travel(PasswordResetCode::TTL_MINUTES + 1)->minutes();

        $this->redeem($student, $code)
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame('password', $student->fresh()->password);
    }

    public function test_a_short_password_is_refused_even_with_a_valid_code(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, ['password' => 'password']);

        $this->requestCode($student);

        $this->postJson('/api/student/reset-password', [
            'email' => $student->email,
            'code' => $this->mailedCode($student),
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame('password', $student->fresh()->password);
    }

    public function test_resetting_revokes_every_issued_token(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, ['password' => 'password']);

        $phone = $student->createToken('phone')->plainTextToken;
        $tablet = $student->createToken('tablet')->plainTextToken;

        $this->assertSame(2, $student->tokens()->count());

        $this->requestCode($student);
        $this->redeem($student, $this->mailedCode($student))->assertOk();

        // Asserted against the token table rather than a live endpoint: the
        // routes that consumed these tokens were removed, but leaving a valid
        // bearer token behind after a password change would still be a
        // security hole, so the revocation itself is what matters.
        $this->assertSame(0, $student->fresh()->tokens()->count());

        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => $phone,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => $tablet,
        ]);
    }

    // ==========================================================
    // Re-sending and abuse limits
    // ==========================================================

    public function test_a_second_request_voids_the_first_code(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, ['password' => 'password']);

        $this->requestCode($student);
        $first = $this->mailedCode($student);

        $this->travel(PasswordResetCode::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->requestCode($student)->assertOk();
        $second = $this->mailedCode($student);

        // Only the newest code can be redeemed, so a code already in a
        // compromised inbox stops working the moment a new one is issued.
        $this->redeem($student, $first)->assertStatus(422);
        $this->redeem($student, $second)->assertOk();
    }

    public function test_requests_inside_the_cooldown_do_not_send_another_code(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT);

        $this->requestCode($student)->assertOk()->assertJsonMissing(['retry_after']);

        $this->travel(10)->seconds();

        $this->requestCode($student)
            ->assertOk()
            ->assertJson(['retry_after' => PasswordResetCode::RESEND_COOLDOWN_SECONDS - 10]);

        Notification::assertSentToTimes($student, StudentResetCodeNotification::class, 1);
    }

    public function test_reset_requests_are_throttled(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT);

        // Each request is answered the same way; only the limiter eventually
        // turns the flow into a 429, which is what stops a mail-bomb script.
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/student/forgot-password', ['email' => $student->email])
                ->assertOk();
        }

        $this->postJson('/api/student/forgot-password', ['email' => $student->email])
            ->assertStatus(429);
    }

    // ==========================================================
    // Housekeeping
    // ==========================================================

    public function test_the_scheduled_prune_only_drops_long_dead_codes(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT);

        $this->requestCode($student);

        $live = PasswordResetCode::where('email', $student->email)->sole();

        // Old enough to be worthless, but not yet beyond the grace day.
        $live->forceFill(['expires_at' => now()->subHours(2)])->save();

        StudentAuthController::pruneResetCodes();

        $this->assertDatabaseHas('password_reset_codes', ['id' => $live->id]);

        $live->forceFill(['expires_at' => now()->subDays(3)])->save();

        StudentAuthController::pruneResetCodes();

        $this->assertDatabaseMissing('password_reset_codes', ['id' => $live->id]);
    }

    /**
     * Nothing deletes these rows on its own, so if the scheduler entry is
     * dropped the table only ever grows.
     */
    public function test_the_prune_is_scheduled_to_run_overnight(): void
    {
        $schedule = app(Schedule::class);

        $expressions = array_map(
            fn (Event $event) => $event->expression,
            $schedule->events()
        );

        $this->assertContains('10 3 * * *', $expressions);
    }
}
