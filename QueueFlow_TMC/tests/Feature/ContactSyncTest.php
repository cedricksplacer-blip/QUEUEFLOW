<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * Email + phone stay per-account across Admin Dashboard, MySQL and the
 * Flutter profile: an admin edit lands in MySQL and the same account's own
 * API payload (never another account's), while accounts without contact
 * info still decode cleanly.
 */
class ContactSyncTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    private function makeStudent(array $attrs = []): User
    {
        return $this->makeUser(User::ROLE_STUDENT, array_merge([
            'student_id' => '24-019083',
            'course' => 'BSIT',
            'block' => '1',
            'year_level' => '2nd Year',
        ], $attrs));
    }

    private function tokenFor(User $user, string $identifier, string $device = 'phpunit'): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => $identifier,
            'password' => 'password',
            'device_name' => $device,
        ]);

        $response->assertOk();
        $this->app['auth']->forgetGuards();

        return $response->json('token');
    }

    public function test_admin_contact_edit_reaches_only_that_students_payload(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $studentA = $this->makeStudent([
            'student_id' => '24-000001',
            'email' => 'a@tmc.test',
            'phone' => '09170000001',
        ]);
        $studentB = $this->makeStudent([
            'student_id' => '24-000002',
            'email' => 'b@tmc.test',
            'phone' => '09170000002',
        ]);

        // Admin updates A's contact info through User Management.
        $this->actingAs($admin)
            ->put(route('admin.users.update', $studentA), [
                'name' => $studentA->name,
                'email' => 'new-a@tmc.test',
                'student_id' => '24-000001',
                'course' => 'BSIT',
                'block' => '1',
                'year_level' => '2nd Year',
                'phone' => '09998887777',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.users.index'));

        // MySQL: A changed, B untouched, no duplicate rows.
        $this->assertSame('new-a@tmc.test', $studentA->refresh()->email);
        $this->assertSame('09998887777', $studentA->refresh()->phone);
        $this->assertSame('b@tmc.test', $studentB->refresh()->email);
        $this->assertSame('09170000002', $studentB->refresh()->phone);
        $this->assertSame(1, User::where('student_id', '24-000001')->count());

        // Each account's own API payload carries its own contact info.
        $this->withToken($this->tokenFor($studentA, '24-000001', 'a'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'new-a@tmc.test')
            ->assertJsonPath('user.phone', '09998887777')
            ->assertJsonPath('user.student_id', '24-000001');

        $this->withToken($this->tokenFor($studentB, '24-000002', 'b'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'b@tmc.test')
            ->assertJsonPath('user.phone', '09170000002')
            ->assertJsonPath('user.student_id', '24-000002');
    }

    public function test_missing_contact_info_decodes_without_errors(): void
    {
        $student = $this->makeStudent([
            'student_id' => '24-000003',
            'email' => null,
            'phone' => null,
        ]);

        $this->withToken($this->tokenFor($student, '24-000003'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.student_id', '24-000003');

        $payload = $this->withToken($this->tokenFor($student, '24-000003', 'again'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('user');

        $this->assertNull($payload['email']);
        $this->assertNull($payload['phone']);
    }
}
