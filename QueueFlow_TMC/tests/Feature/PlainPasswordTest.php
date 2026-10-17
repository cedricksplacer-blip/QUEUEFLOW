<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PlainPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QueueFlow TMC stores account passwords as clear text.
 *
 * These tests pin that contract and, just as importantly, pin that a row left
 * behind by the previous bcrypt behaviour keeps working and upgrades itself the
 * first time its owner signs in.
 */
class PlainPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_plaintext_password_is_stored_verbatim_on_create(): void
    {
        $user = User::factory()->create(['password' => 'supersecret']);

        $this->assertSame('supersecret', $user->password);
        $this->assertTrue(PlainPassword::check('supersecret', $user->password));
    }

    public function test_plaintext_password_is_stored_verbatim_on_update(): void
    {
        $user = User::factory()->create(['password' => 'oldpass']);

        $user->update(['password' => 'newpass']);

        $user->refresh();

        $this->assertSame('newpass', $user->password);
        $this->assertTrue(PlainPassword::check('newpass', $user->password));
        $this->assertFalse(PlainPassword::check('oldpass', $user->password));
    }

    public function test_a_legacy_bcrypt_row_is_still_accepted(): void
    {
        $hash = password_hash('alreadyhashed', PASSWORD_BCRYPT, ['cost' => 12]);

        $this->assertTrue(PlainPassword::isHashed($hash));
        $this->assertTrue(PlainPassword::check('alreadyhashed', $hash));
        $this->assertFalse(PlainPassword::check('wrong', $hash));
    }

    public function test_a_legacy_bcrypt_row_is_left_untouched_until_it_signs_in(): void
    {
        $hash = password_hash('alreadyhashed', PASSWORD_BCRYPT, ['cost' => 12]);

        $user = User::factory()->create(['password' => $hash]);

        $this->assertSame($hash, $user->password);
    }

    public function test_signing_in_upgrades_a_legacy_bcrypt_row_to_plaintext(): void
    {
        $hash = password_hash('supersecret', PASSWORD_BCRYPT, ['cost' => 12]);

        $user = User::factory()->create(['password' => $hash]);

        $this->assertTrue($this->app['auth']->attempt([
            'email' => $user->email,
            'password' => 'supersecret',
        ]));

        $this->assertSame('supersecret', $user->fresh()->password);
    }

    public function test_a_wrong_password_never_triggers_the_upgrade(): void
    {
        $hash = password_hash('supersecret', PASSWORD_BCRYPT, ['cost' => 12]);

        $user = User::factory()->create(['password' => $hash]);

        $this->assertFalse($this->app['auth']->attempt([
            'email' => $user->email,
            'password' => 'not-the-password',
        ]));

        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_registration_stores_a_password_usable_at_login(): void
    {
        $this->post('/register', [
            'name' => 'Test Student',
            'email' => 'login.usable@tmc.edu',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'login.usable@tmc.edu')->firstOrFail();

        $this->assertSame('secret123', $user->password);
        $this->assertTrue($this->app['auth']->attempt([
            'email' => 'login.usable@tmc.edu',
            'password' => 'secret123',
        ]));
    }

    public function test_user_created_with_plain_password_can_authenticate(): void
    {
        User::factory()->create([
            'email' => 'plain.login@tmc.edu',
            'password' => 'plainsecret',
        ]);

        $this->assertTrue($this->app['auth']->attempt([
            'email' => 'plain.login@tmc.edu',
            'password' => 'plainsecret',
        ]));

        $this->assertFalse($this->app['auth']->attempt([
            'email' => 'plain.login@tmc.edu',
            'password' => 'wrongsecret',
        ]));
    }

    public function test_the_hasher_never_asks_for_a_rehash(): void
    {
        $this->assertFalse($this->app['hash']->needsRehash('plaintext'));
        $this->assertSame('plaintext', $this->app['hash']->make('plaintext'));
    }
}
