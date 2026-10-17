<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * Students are identified by their Student ID rather than an email address.
 *
 * Two things have to hold together. An admin must be able to create a student
 * from a Student ID alone, with no invented gmail address, and that student
 * must then be able to sign in with it. The second half is the easy one to
 * miss: making the field optional without teaching the login form to read it
 * would quietly produce accounts nobody can get into.
 */
class StudentIdSignInTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Both limiters are keyed on the credential, so a leftover bucket from
        // an earlier test would look like a lockout rather than a real failure.
        RateLimiter::clear('student-login');
        RateLimiter::clear('staff-login');
    }

    public function test_the_login_screen_asks_for_a_student_id_or_an_email(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Student ID or Email')
            ->assertSee('name="identifier"', false);
    }

    public function test_a_student_created_from_a_student_id_alone_can_sign_in(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, [
            'email' => null,
            'student_id' => '2026-0001',
        ]);

        $response = $this->post('/login', [
            'identifier' => '2026-0001',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($student);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_a_student_can_still_sign_in_with_their_email_when_one_is_on_file(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT, [
            'email' => 'with.email@tmc.test',
            'student_id' => '2026-0002',
        ]);

        $this->post('/login', [
            'identifier' => 'with.email@tmc.test',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($student);
    }

    public function test_a_staff_account_signs_in_with_its_email(): void
    {
        $staff = $this->makeUser(User::ROLE_STAFF, [
            'email' => 'staff.signin@tmc.test',
            'office_id' => $this->office()->id,
        ]);

        $this->post('/login', [
            'identifier' => 'staff.signin@tmc.test',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($staff);
    }

    public function test_the_wrong_password_is_refused_for_a_student_id(): void
    {
        $this->makeUser(User::ROLE_STUDENT, [
            'email' => null,
            'student_id' => '2026-0003',
        ]);

        $this->post('/login', [
            'identifier' => '2026-0003',
            'password' => 'not-the-password',
        ]);

        $this->assertGuest();
    }

    public function test_a_deactivated_student_cannot_sign_in_with_a_student_id(): void
    {
        $this->makeUser(User::ROLE_STUDENT, [
            'email' => null,
            'student_id' => '2026-0004',
            'is_active' => false,
        ]);

        $this->post('/login', [
            'identifier' => '2026-0004',
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_an_admin_can_create_a_student_from_a_student_id_with_no_email(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Student With No Mail',
                'role' => User::ROLE_STUDENT,
                'student_id' => '2026-0005',
                'course' => 'BSIT',
                'block' => '1',
                'year_level' => '1st Year',
                'password' => 'password',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $created = User::where('student_id', '2026-0005')->firstOrFail();

        $this->assertNull($created->email);
        $this->assertTrue($created->is_active);
    }

    public function test_a_created_student_can_immediately_sign_in_with_their_student_id(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Round Trip',
            'role' => User::ROLE_STUDENT,
            'student_id' => '2026-0006',
            'course' => 'BSIT',
            'block' => '1',
            'year_level' => '1st Year',
            'password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->post('/logout');

        $this->post('/login', [
            'identifier' => '2026-0006',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_a_student_still_cannot_be_created_without_a_student_id(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'No Identifier',
                'role' => User::ROLE_STUDENT,
                'course' => 'BSIT',
                'block' => '1',
                'year_level' => '1st Year',
                'password' => 'password',
            ])
            ->assertSessionHasErrors('student_id');

        $this->assertDatabaseMissing('users', ['name' => 'No Identifier']);
    }

    public function test_a_staff_account_still_needs_an_email(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Staff Without Mail',
                'role' => User::ROLE_STAFF,
                'office_id' => $this->office()->id,
                'password' => 'password',
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['name' => 'Staff Without Mail']);
    }

    public function test_two_students_cannot_share_a_student_id(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $payload = [
            'role' => User::ROLE_STUDENT,
            'course' => 'BSIT',
            'block' => '1',
            'year_level' => '1st Year',
            'password' => 'password',
            'student_id' => '2026-0007',
        ];

        $this->actingAs($admin)->post(route('admin.users.store'), $payload + ['name' => 'First'])->assertSessionHasNoErrors();

        // The column carries a unique index, so the validator and the database
        // both have to agree. Catching it in the form gives a usable message
        // instead of a duplicate row that makes sign-in ambiguous.
        $this->actingAs($admin)
            ->post(route('admin.users.store'), $payload + ['name' => 'Second'])
            ->assertSessionHasErrors('student_id');
    }

    public function test_the_users_list_shows_a_dash_when_a_student_has_no_email(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->makeUser(User::ROLE_STUDENT, [
            'email' => null,
            'student_id' => '2026-0008',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('2026-0008')
            ->assertDontSee('<td></td>', false);
    }

    public function test_the_add_user_form_explains_that_student_id_is_the_sign_in(): void
    {
        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('Student ID')
            ->assertSee('what the student types to sign in');
    }

    public function test_a_student_with_no_email_gets_the_same_reset_answer_as_a_stranger(): void
    {
        $this->makeUser(User::ROLE_STUDENT, [
            'email' => null,
            'student_id' => '2026-0012',
        ]);

        $unknown = $this->postJson('/api/student/forgot-password', [
            'identifier' => '2026-0012',
        ]);

        $stranger = $this->postJson('/api/student/forgot-password', [
            'identifier' => '2099-9999',
        ]);

        // Identical apart from the throttle, or the endpoint turns into a
        // Student ID oracle.
        $unknown->assertOk();
        $this->assertSame(
            $stranger->json(),
            collect($unknown->json())->except('retry_after')->all()
        );
        $this->assertDatabaseCount('password_reset_codes', 0);
    }

    private function office(): \App\Models\Office
    {
        return $this->office ??= $this->makeOffice([
            'name' => 'Registrar BSIT',
            'code' => 'REG-BSIT',
        ]);
    }

    private ?\App\Models\Office $office = null;
}
