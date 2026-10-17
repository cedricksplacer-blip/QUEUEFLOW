<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AvatarStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep photos written by these tests out of the real storage directory.
        $this->isolateLocalStorage();
    }

    public function test_profile_page_renders_in_the_admin_layout(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, ['name' => 'Juan Dela Cruz']);

        $this->actingAs($admin)
            ->get(route('admin.profile.edit'))
            ->assertOk()
            ->assertSee('My Profile')
            // Admin chrome, not the generic layouts.app top nav.
            ->assertSee('admin-sidebar', false)
            ->assertSee('Juan Dela Cruz')
            // Without the multipart encoding the file input would be dropped
            // silently on submit, so guard it here.
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="avatar"', false);
    }

    public function test_profile_page_is_admin_only(): void
    {
        $this->actingAs($this->makeUser(User::ROLE_STAFF))
            ->get(route('admin.profile.edit'))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.profile.edit'))->assertRedirect(route('admin.login'));
    }

    public function test_settings_page_links_to_my_profile(): void
    {
        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('My Profile')
            ->assertSee(route('admin.profile.edit'), false);
    }

    public function test_admin_can_update_profile_and_stays_on_admin_page(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, [
            'name' => 'Old Name',
            'email' => 'old@tmc.edu.ph',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'New Name',
                'email' => 'new@tmc.edu.ph',
                'phone' => '09171234567',
                'redirect_to' => 'admin',
            ])
            ->assertRedirect(route('admin.profile.edit'))
            ->assertSessionHas('status', 'profile-updated');

        $admin->refresh();

        $this->assertSame('New Name', $admin->name);
        $this->assertSame('new@tmc.edu.ph', $admin->email);
        $this->assertSame('09171234567', $admin->phone);
    }

    public function test_shared_profile_update_still_uses_its_own_redirect(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        // No redirect_to flag: the shared route must keep its old behaviour so
        // staff/student profile pages are unaffected.
        $this->actingAs($admin)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Somebody',
                'email' => 'somebody@tmc.edu.ph',
            ])
            ->assertRedirect(route('profile.edit'));
    }

    public function test_phone_stays_untouched_when_the_field_is_omitted(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, ['phone' => '09170000000']);

        $this->actingAs($admin)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Somebody',
                'email' => 'somebody@tmc.edu.ph',
            ]);

        $this->assertSame('09170000000', $admin->refresh()->phone);
    }

    public function test_profile_update_rejects_a_duplicate_email(): void
    {
        $this->makeUser(User::ROLE_ADMIN, ['email' => 'taken@tmc.edu.ph']);
        $admin = $this->makeUser(User::ROLE_ADMIN, ['email' => 'mine@tmc.edu.ph']);

        $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Somebody',
                'email' => 'taken@tmc.edu.ph',
                'redirect_to' => 'admin',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame('mine@tmc.edu.ph', $admin->refresh()->email);
    }

    public function test_phone_must_be_a_short_string(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Somebody',
                'email' => 'somebody@tmc.edu.ph',
                'phone' => str_repeat('9', 31),
                'redirect_to' => 'admin',
            ])
            ->assertSessionHasErrors('phone');
    }

    public function test_admin_can_change_password_from_the_admin_page(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $admin->forceFill(['password' => 'secret123'])->save();

        $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->put(route('password.update'), [
                'current_password' => 'secret123',
                'password' => 'brand-new-456',
                'password_confirmation' => 'brand-new-456',
            ])
            // PasswordController redirects with back(), so the admin stays put.
            ->assertRedirect(route('admin.profile.edit'));

        $this->assertSame('brand-new-456', $admin->refresh()->password);
    }

    public function test_password_change_rejects_a_wrong_current_password(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $admin->forceFill(['password' => 'secret123'])->save();

        $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->put(route('password.update'), [
                'current_password' => 'not-the-password',
                'password' => 'brand-new-456',
                'password_confirmation' => 'brand-new-456',
            ])
            ->assertSessionHasErrors('current_password', null, 'updatePassword');

        $this->assertSame('secret123', $admin->refresh()->password);
    }

    /*
    |--------------------------------------------------------------------------
    | Profile photo
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_upload_a_profile_photo(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, ['name' => 'Juan Dela Cruz']);

        $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $admin->name,
                'email' => $admin->email,
                'avatar' => $this->avatarUpload(),
                'redirect_to' => 'admin',
            ])
            ->assertRedirect(route('admin.profile.edit'))
            ->assertSessionHasNoErrors();

        $path = $admin->refresh()->avatar;

        $this->assertNotNull($path);
        // Stored on the non-public disk, under a generated name.
        $this->assertStringStartsWith('avatars/avatar_', $path);
        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_profile_page_shows_the_uploaded_photo_instead_of_the_initial(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, ['name' => 'Juan Dela Cruz']);
        $admin->forceFill(['avatar' => (new AvatarStore())->store($this->avatarUpload())])->save();

        $this->actingAs($admin)
            ->get(route('admin.profile.edit'))
            ->assertOk()
            ->assertSee('avatar-img', false)
            ->assertSee(route('admin.profile.avatar', $admin), false);
    }

    public function test_profile_page_falls_back_to_the_initial_without_a_photo(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, ['name' => 'Juan Dela Cruz']);

        $this->actingAs($admin)
            ->get(route('admin.profile.edit'))
            ->assertOk()
            ->assertSee('J')
            ->assertDontSee('avatar-img', false);
    }

    public function test_a_photo_pointing_at_a_deleted_file_falls_back_to_the_initial(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, ['name' => 'Juan Dela Cruz']);
        // Column set, but nothing on disk: must not render a broken <img>.
        $admin->forceFill(['avatar' => 'avatars/avatar_missing.jpg'])->save();

        $this->assertFalse($admin->hasAvatar());
        $this->assertNull($admin->avatarUrl());

        $this->actingAs($admin)
            ->get(route('admin.profile.edit'))
            ->assertOk()
            ->assertDontSee('avatar-img', false);
    }

    public function test_photo_route_streams_the_image_to_an_admin(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $admin->forceFill(['avatar' => (new AvatarStore())->store($this->avatarUpload())])->save();

        $this->actingAs($admin)
            ->get(route('admin.profile.avatar', $admin))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_photo_route_is_admin_only(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $admin->forceFill(['avatar' => (new AvatarStore())->store($this->avatarUpload())])->save();

        $this->actingAs($this->makeUser(User::ROLE_STAFF))
            ->get(route('admin.profile.avatar', $admin))
            ->assertForbidden();
    }

    public function test_photo_route_404s_when_there_is_no_photo(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.profile.avatar', $admin))
            ->assertNotFound();
    }

    public function test_replacing_a_photo_deletes_the_previous_file(): void
    {
        $avatars = new AvatarStore();
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $original = $avatars->store($this->avatarUpload());
        $admin->forceFill(['avatar' => $original])->save();

        $this->actingAs($admin)
            ->patch(route('profile.update'), [
                'name' => $admin->name,
                'email' => $admin->email,
                'avatar' => $this->avatarUpload(),
                'redirect_to' => 'admin',
            ])
            ->assertSessionHasNoErrors();

        $replacement = $admin->refresh()->avatar;

        $this->assertNotSame($original, $replacement);
        Storage::disk('local')->assertMissing($original);
        Storage::disk('local')->assertExists($replacement);
    }

    public function test_a_photo_can_be_removed(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $path = (new AvatarStore())->store($this->avatarUpload());
        $admin->forceFill(['avatar' => $path])->save();

        $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $admin->name,
                'email' => $admin->email,
                'remove_avatar' => '1',
                'redirect_to' => 'admin',
            ])
            ->assertRedirect(route('admin.profile.edit'))
            ->assertSessionHas('status', 'avatar-removed');

        $this->assertNull($admin->refresh()->avatar);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_omitting_the_photo_keeps_the_existing_one(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $path = (new AvatarStore())->store($this->avatarUpload());
        $admin->forceFill(['avatar' => $path])->save();

        $this->actingAs($admin)
            ->patch(route('profile.update'), [
                'name' => 'Renamed',
                'email' => $admin->email,
                'redirect_to' => 'admin',
            ])
            ->assertSessionHasNoErrors();

        $admin->refresh();

        $this->assertSame('Renamed', $admin->name);
        $this->assertSame($path, $admin->avatar);
        Storage::disk('local')->assertExists($path);
    }

    public function test_a_rejected_photo_leaves_the_existing_one_intact(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $path = (new AvatarStore())->store($this->avatarUpload());
        $admin->forceFill(['avatar' => $path])->save();
        $originalName = $admin->name;

        // Allowed extension and declared MIME, but the payload is not an image,
        // so this exercises the getimagesize() content sniff.
        $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Renamed',
                'email' => $admin->email,
                'avatar' => $this->fakeUpload('payload.jpg', 'image/jpeg', 'this is not an image'),
                'redirect_to' => 'admin',
            ])
            ->assertSessionHasErrors('avatar');

        $admin->refresh();

        // The failed upload aborts before the save, so nothing is half-written
        // and the photo the user already had survives.
        $this->assertSame($originalName, $admin->name);
        $this->assertSame($path, $admin->avatar);
        Storage::disk('local')->assertExists($path);
    }

    public function test_a_non_image_file_cannot_be_uploaded_as_a_photo(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $admin->name,
                'email' => $admin->email,
                'avatar' => $this->fakeUpload('notes.txt', 'text/plain', 'just some text'),
                'redirect_to' => 'admin',
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($admin->refresh()->avatar);
    }

    public function test_a_photo_larger_than_two_megabytes_is_rejected(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $oversized = $this->fakeUpload(
            'huge.jpg',
            'image/jpeg',
            str_repeat('x', (AvatarStore::MAX_AVATAR_SIZE + 1) * 1024)
        );

        $this->actingAs($admin)
            ->from(route('admin.profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $admin->name,
                'email' => $admin->email,
                'avatar' => $oversized,
                'redirect_to' => 'admin',
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($admin->refresh()->avatar);
    }

    /**
     * Build a real JPEG upload (no GD extension required).
     */
    private function avatarUpload(string $name = 'me.jpg'): UploadedFile
    {
        return $this->fakeUpload($name, 'image/jpeg', base64_decode(self::PWD_EVIDENCE_BASE64));
    }

    /**
     * An upload with arbitrary contents, for the rejection paths.
     */
    private function fakeUpload(string $name, string $mime, string $contents): UploadedFile
    {
        $dir = storage_path('framework/testing/uploads');

        File::ensureDirectoryExists($dir);

        $path = $dir . '/' . $name;

        File::put($path, $contents);

        return new UploadedFile($path, $name, $mime, null, true);
    }
}
