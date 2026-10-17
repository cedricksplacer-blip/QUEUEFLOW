<?php

namespace Tests\Feature\Api;

use App\Models\Office;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\QueueDeploymentManager;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * The bearer-token API consumed by the Flutter Web client.
 *
 * These run against a real MySQL database with RefreshDatabase, so a failure
 * here means the request genuinely would not work in the browser - not that a
 * mock was misconfigured.
 *
 * The assertions concentrate on the things that are easy to get wrong and
 * expensive to discover late:
 *
 *   - a token actually authenticating, and a revoked one actually not;
 *   - the two ways an account is identified (Student ID for students, email
 *     for staff/admin), since the sign-in form has to accept both;
 *   - `role:` narrowing which handler runs, and guardOffice() narrowing which
 *     office a staff token may touch. Both, because either alone is a hole:
 *     `role:admin,staff` on its own lets any member of staff reach every
 *     office in the campus;
 *   - a student's ticket staying private to that student.
 */
class ApiIntegrationTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateLocalStorage();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** A student with the identifying fields a real one carries. */
    private function makeStudent(array $attrs = []): User
    {
        return $this->makeUser(User::ROLE_STUDENT, array_merge([
            'student_id' => '24-019083',
            'course' => 'BSIT',
            'block' => 'A1',
            'year_level' => '3rd Year',
        ], $attrs));
    }

    private function makeStaffedOffice(array $officeAttrs = []): array
    {
        $office = $this->makeOffice(array_merge(['code' => 'REG-BSIT'], $officeAttrs));
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);

        return [$office, $staff];
    }

    /**
     * Sign in over HTTP and return the token, asserting it worked.
     *
     * The device name matters: signing in replaces the token that carries the
     * same device name, so a test that needs two live sessions at once has to
     * name them differently.
     */
    private function tokenFor(User $user, string $identifier, string $device = 'phpunit'): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => $identifier,
            'password' => 'password',
            'device_name' => $device,
        ]);

        $response->assertOk();

        $this->forgetGuards();

        return $response->json('token');
    }

    /**
     * Drop the cached guard so the next request re-reads the token table.
     *
     * Laravel keeps one resolved auth guard for the whole test method, so a
     * request made after a token was revoked would still be answered from
     * that cache. Real requests do not share a guard; this makes the test
     * behave like one request per process, which is what the client does.
     */
    private function forgetGuards(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /** Open a batch and mark it ready for service. */
    private function deployBatch(Office $office): void
    {
        $manager = new QueueDeploymentManager();

        if (! QueueDeployment::activeFor($office)) {
            $manager->start($office);
        }

        $manager->deploy($office);
    }

    /*
    |--------------------------------------------------------------------------
    | Discovery & refusal shape
    |--------------------------------------------------------------------------
    */

    public function test_discovery_is_public_and_describes_a_bearer_api(): void
    {
        $response = $this->getJson('/api/v1');

        $response->assertOk()
            ->assertJsonPath('api', 'queueflow-tmc')
            ->assertJsonPath('version', 'v1')
            ->assertJsonPath('auth', 'bearer')
            ->assertJsonStructure(['endpoints' => ['login', 'logout', 'me', 'offices', 'queues', 'accounts']]);
    }

    public function test_discovery_is_stamped_with_the_api_version(): void
    {
        // The header is what a deployed client uses to notice the contract
        // moved underneath it, so it has to be on the 200 as well as on errors.
        $this->getJson('/api/v1')->assertHeader('X-Api-Version', 'v1');
    }

    public function test_protected_endpoints_refuse_a_missing_token_with_json(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_protected_endpoints_refuse_a_bogus_token(): void
    {
        $this->withToken('not-a-real-token')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Sign-in
    |--------------------------------------------------------------------------
    */

    public function test_a_student_signs_in_with_their_student_id(): void
    {
        $student = $this->makeStudent();

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => '24-019083',
            'password' => 'password',
            'device_name' => 'flutter-web',
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.role', User::ROLE_STUDENT)
            ->assertJsonPath('user.student_id', '24-019083')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'course']]);

        // The issued token has to work, not merely exist.
        $this->withToken($response->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $student->id);
    }

    public function test_staff_and_admin_sign_in_with_an_email(): void
    {
        [$office] = $this->makeStaffedOffice();
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $staff->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.role', User::ROLE_STAFF);
    }

    public function test_sign_in_never_returns_the_stored_password(): void
    {
        // Passwords are stored as clear text in this schema, so a plain
        // $user->toArray() would hand the credential straight back.
        $this->makeStudent();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => '24-019083',
            'password' => 'password',
        ])->assertOk()->assertJsonMissingPath('user.password');
    }

    public function test_a_wrong_password_is_refused_without_saying_which_part_was_wrong(): void
    {
        $this->makeStudent();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => '24-019083',
            'password' => 'not-the-password',
        ])->assertUnauthorized()->assertJsonPath('message', 'Those credentials do not match our records.');

        // Same message for an account that does not exist, so the endpoint
        // cannot be used to discover which Student IDs are registered.
        $this->postJson('/api/v1/auth/login', [
            'identifier' => '99-999999',
            'password' => 'not-the-password',
        ])->assertUnauthorized()->assertJsonPath('message', 'Those credentials do not match our records.');
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $this->makeStudent(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => '24-019083',
            'password' => 'password',
        ])->assertForbidden();
    }

    public function test_signing_in_twice_from_one_device_replaces_the_first_token(): void
    {
        $this->makeStudent();

        $first = $this->postJson('/api/v1/auth/login', [
            'identifier' => '24-019083',
            'password' => 'password',
            'device_name' => 'shared-lab-pc',
        ])->json('token');

        $this->assertSame(1, User::where('role', User::ROLE_STUDENT)->first()->tokens()->count());

        $this->postJson('/api/v1/auth/login', [
            'identifier' => '24-019083',
            'password' => 'password',
            'device_name' => 'shared-lab-pc',
        ])->assertOk();

        // Still one token, and the one that replaced it is the live one.
        $this->assertSame(1, User::where('role', User::ROLE_STUDENT)->first()->tokens()->count());

        $this->withToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_sign_in_is_rate_limited_per_account(): void
    {
        $this->makeStudent();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'identifier' => '24-019083',
                'password' => 'wrong',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'identifier' => '24-019083',
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    /*
    |--------------------------------------------------------------------------
    | Sign-out
    |--------------------------------------------------------------------------
    */

    public function test_signing_out_revokes_only_the_current_device(): void
    {
        $student = $this->makeStudent();

        $phone = $this->tokenFor($student, '24-019083', 'phpunit-phone');
        $laptop = $this->tokenFor($student, '24-019083', 'phpunit-laptop');

        $this->withToken($laptop)->postJson('/api/v1/auth/logout')->assertOk();

        $this->forgetGuards()->withToken($laptop)->getJson('/api/v1/auth/me')->assertUnauthorized();

        // Signing out on the laptop must not sign the same person out of the
        // phone they are standing in the queue with.
        $this->withToken($phone)->getJson('/api/v1/auth/me')->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Bootstrap dispatch
    |--------------------------------------------------------------------------
    */

    public function test_bootstrap_dispatches_on_the_accounts_role(): void
    {
        [$office] = $this->makeStaffedOffice();

        $student = $this->makeStudent();
        $this->withToken($this->tokenFor($student, '24-019083'))
            ->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonStructure(['student', 'settings', 'offices', 'courses', 'announcements']);

        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);
        $this->withToken($this->tokenFor($staff, $staff->email))
            ->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonStructure(['staff', 'offices'])
            ->assertJsonMissingPath('student');

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->withToken($this->tokenFor($admin, $admin->email))
            ->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonStructure(['counts', 'offices', 'station_count', 'student_count', 'staff_count']);
    }

    public function test_a_student_bootstrap_carries_what_the_dart_models_decode(): void
    {
        [$office] = $this->makeStaffedOffice();
        $this->makeCourse(['office_id' => $office->id]);
        $this->makeService($office);

        $student = $this->makeStudent();

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonStructure([
                'student' => ['id', 'name', 'email', 'role', 'student_id', 'course', 'block', 'year_level'],
                'settings' => ['site_name', 'default_waiting_time', 'max_active_tickets_per_student'],
                'offices' => [['id', 'name', 'code', 'window_open', 'accepting', 'waiting_count',
                    'serving_count', 'services', 'stations']],
            ]);
    }

    /**
     * The office row for one code, as the phone receives it.
     *
     * Fails the test rather than returning null if the office is missing, so a
     * filter that accidentally hides it reads as a clear assertion failure
     * instead of a null dereference.
     *
     * @return array<string, mixed>
     */
    private function officePayloadFor(string $code): array
    {
        $offices = $this->withToken($this->tokenFor($this->makeStudent(), '24-019083'))
            ->getJson('/api/v1/offices')
            ->assertOk()
            ->json('offices');

        $match = collect($offices)->firstWhere('code', $code);

        $this->assertNotNull($match, "Office {$code} was not returned to a student.");

        return $match;
    }

    public function test_a_student_sees_their_own_college_but_not_the_others(): void
    {
        $bsit = $this->makeOffice(['name' => 'Registrar - BSIT', 'code' => 'REG-BSIT']);
        $this->makeCourse(['code' => 'BSIT', 'office_id' => $bsit->id]);

        // Another college, and a shared office every student may use.
        $bsoa = $this->makeOffice(['name' => 'Registrar - BSOA', 'code' => 'REG-BSOA']);
        $this->makeCourse(['code' => 'BSOA', 'office_id' => $bsoa->id]);
        $cashier = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);

        $student = $this->makeStudent(['course' => 'BSIT']);

        $codes = $this->withToken($this->tokenFor($student, '24-019083'))
            ->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->json('offices.*.code');

        $this->assertContains('REG-BSIT', $codes);
        $this->assertContains('CASH', $codes, 'Shared offices must remain reachable.');
        $this->assertNotContains('REG-BSOA', $codes, 'Another college must not be listed.');
    }

    public function test_offices_report_their_deployed_slot_counters(): void
    {
        $office = $this->makeOffice(['code' => 'CASH']);
        QueueDeployment::startFor($office);

        $payload = $this->officePayloadFor('CASH');

        $this->assertSame(100, $payload['total_slots']);
        $this->assertSame(100, $payload['available_slots']);
        $this->assertSame(0, $payload['used_slots']);
        $this->assertTrue($payload['slots_deployed']);
        $this->assertSame('open', $payload['queue_status']);
        $this->assertTrue($payload['accepting']);
    }

    public function test_an_office_with_no_deployed_batch_reports_zero_available_and_is_closed(): void
    {
        // Nothing deployed yet: the office must not read as "100/100 open",
        // because no number can actually be issued there.
        $this->makeOffice(['code' => 'CASH']);

        $payload = $this->officePayloadFor('CASH');

        $this->assertFalse($payload['slots_deployed']);
        $this->assertSame(100, $payload['total_slots']);
        $this->assertSame(0, $payload['available_slots']);
        $this->assertSame('closed', $payload['queue_status']);
        $this->assertFalse($payload['accepting']);
    }

    public function test_the_available_slot_count_falls_as_numbers_are_taken(): void
    {
        $office = $this->makeOffice(['code' => 'CASH']);
        QueueDeployment::startFor($office);
        $service = $this->makeService($office);

        // Two numbers issued from the same deployed batch.
        foreach (range(1, 2) as $ignored) {
            (new QueueTicketCreator())->create([
                'office_id' => $office->id,
                'service_id' => $service->id,
                'priority' => QueueTicket::PRIORITY_REGULAR,
            ]);
        }

        $payload = $this->officePayloadFor('CASH');

        $this->assertSame(100, $payload['total_slots']);
        $this->assertSame(2, $payload['used_slots']);
        $this->assertSame(98, $payload['available_slots']);
        $this->assertSame('open', $payload['queue_status']);
    }

    public function test_the_registrar_course_endpoint_lists_every_college_with_its_own_slots(): void
    {
        $bsit = $this->makeOffice(['name' => 'Registrar - BSIT', 'code' => 'REG-BSIT']);
        $bsoa = $this->makeOffice(['name' => 'Registrar - BSOA', 'code' => 'REG-BSOA']);
        $this->makeCourse(['code' => 'BSIT', 'office_id' => $bsit->id]);
        $this->makeCourse(['code' => 'BSOA', 'office_id' => $bsoa->id]);

        QueueDeployment::startFor($bsit);

        // BSOA is left with no batch on purpose: the two must report independently.
        $response = $this->withToken($this->tokenFor($this->makeStudent(), '24-019083'))
            ->getJson('/api/v1/registrar/courses')
            ->assertOk();

        $byCode = collect($response->json('registrar_courses'))->keyBy('course_code');

        $this->assertSame(['BSIT', 'BSOA'], $byCode->keys()->sort()->values()->all());

        $this->assertSame(100, $byCode['BSIT']['total_slots']);
        $this->assertSame(100, $byCode['BSIT']['available_slots']);
        $this->assertSame('open', $byCode['BSIT']['queue_status']);

        $this->assertSame(100, $byCode['BSOA']['total_slots']);
        $this->assertSame(0, $byCode['BSOA']['available_slots']);
        $this->assertSame('closed', $byCode['BSOA']['queue_status']);

        // The student is a BSIT, so only that row is flagged as theirs.
        $this->assertTrue($byCode['BSIT']['is_enrolled_course']);
        $this->assertFalse($byCode['BSOA']['is_enrolled_course']);
    }

    public function test_the_registrar_totals_are_the_sum_across_every_course(): void
    {
        $bsit = $this->makeOffice(['name' => 'Registrar - BSIT', 'code' => 'REG-BSIT']);
        $bsoa = $this->makeOffice(['name' => 'Registrar - BSOA', 'code' => 'REG-BSOA']);
        $this->makeCourse(['code' => 'BSIT', 'office_id' => $bsit->id]);
        $this->makeCourse(['code' => 'BSOA', 'office_id' => $bsoa->id]);

        QueueDeployment::startFor($bsit);
        QueueDeployment::startFor($bsoa);

        $totals = $this->withToken($this->tokenFor($this->makeStudent(), '24-019083'))
            ->getJson('/api/v1/registrar/courses')
            ->assertOk()
            ->json('totals');

        $this->assertSame(2, $totals['course_count']);
        $this->assertSame(200, $totals['total_slots']);
        $this->assertSame(200, $totals['available_slots']);
    }

    public function test_the_registrar_course_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/registrar/courses')->assertUnauthorized();
    }

    public function test_the_registrar_course_endpoint_reports_the_batch_that_is_currently_issuing(): void
    {
        $bsit = $this->makeOffice(['name' => 'Registrar - BSIT', 'code' => 'REG-BSIT']);
        $this->makeCourse(['code' => 'BSIT', 'office_id' => $bsit->id]);

        // A finished morning batch and an afternoon one that is open. Reporting
        // the first one found would say CLOSED while numbers are being issued.
        $morning = QueueDeployment::startFor($bsit);
        $morning->update(['status' => QueueDeployment::STATUS_COMPLETED]);

        $afternoon = QueueDeployment::startFor($bsit);
        $afternoon->update(['max_slots' => 80]);

        $row = $this->withToken($this->tokenFor($this->makeStudent(), '24-019083'))
            ->getJson('/api/v1/registrar/courses')
            ->assertOk()
            ->json('registrar_courses.0');

        // Batch numbering counts within the day, so the afternoon batch is #2.
        $this->assertSame(2, $row['batch_no']);
        $this->assertSame(80, $row['total_slots']);
        $this->assertSame(80, $row['available_slots']);
        $this->assertSame('open', $row['queue_status']);
        $this->assertSame('open', $row['batch_status']);
    }

    public function test_the_registrar_course_endpoint_honours_the_office_opening_window(): void
    {
        // Closed right now: a batch exists and has numbers left, but the counter
        // is shut, so this must not read as OPEN the way the office payload
        // would have it.
        $bsit = $this->makeOffice([
            'name' => 'Registrar - BSIT',
            'code' => 'REG-BSIT',
            'opening_time' => '03:00',
            'closing_time' => '03:01',
        ]);
        $this->makeCourse(['code' => 'BSIT', 'office_id' => $bsit->id]);
        QueueDeployment::startFor($bsit);

        $this->travelTo(now()->startOfDay()->addHours(12));

        try {
            $row = $this->withToken($this->tokenFor($this->makeStudent(), '24-019083'))
                ->getJson('/api/v1/registrar/courses')
                ->assertOk()
                ->json('registrar_courses.0');

            // The numbers are still counted honestly - only the "can I take one
            // now" answer changes, because that is what the counter's hours
            // decide.
            $this->assertSame(100, $row['available_slots']);
            $this->assertSame('closed', $row['queue_status']);
            $this->assertFalse($row['accepting']);
        } finally {
            // Left travelled, this would silently shut every other test's
            // office window too.
            $this->travelBack();
        }
    }

    public function test_the_registrar_course_endpoint_carries_the_services_for_each_course_queue(): void
    {
        // The catalogue a student signs in with only holds their own college's
        // office, so without the service list here the phone could show seven
        // course queues and only complete a request for one of them.
        $bsoa = $this->makeOffice(['name' => 'Registrar - BSOA', 'code' => 'REG-BSOA']);
        $this->makeCourse(['code' => 'BSOA', 'office_id' => $bsoa->id]);

        $service = $this->makeService($bsoa, ['name' => 'Certificate of Registration']);

        $row = $this->withToken($this->tokenFor($this->makeStudent(), '24-019083'))
            ->getJson('/api/v1/registrar/courses')
            ->assertOk()
            ->json('registrar_courses.0');

        $this->assertSame($service->id, $row['services'][0]['id']);
        $this->assertSame('Certificate of Registration', $row['services'][0]['name']);
        $this->assertSame($bsoa->id, $row['services'][0]['office_id']);
    }

    public function test_a_student_still_cannot_queue_at_another_colleges_registrar_office(): void
    {
        // The picker shows every course so the student can see the choices, but
        // seeing one must not make it bookable: the registered-course guard
        // refuses it at the office, before any service/station check runs.
        $bsoa = $this->makeOffice(['name' => 'Registrar - BSOA', 'code' => 'REG-BSOA']);
        $this->makeCourse(['code' => 'BSOA', 'office_id' => $bsoa->id]);

        $bsoaStation = \App\Models\OfficeStation::create([
            'office_id' => $bsoa->id,
            'name' => 'BSOA Window',
            'course_codes' => ['BSOA'],
            'is_active' => true,
        ]);

        $bsoaService = $this->makeService($bsoa, ['station_id' => $bsoaStation->id]);
        QueueDeployment::startFor($bsoa);

        // A BSIT student, who is shown this office in the picker.
        $student = $this->makeStudent(['course' => 'BSIT']);

        $codes = $this->withToken($this->tokenFor($student, '24-019083'))
            ->getJson('/api/v1/registrar/courses')
            ->assertOk()
            ->json('registrar_courses.*.course_code');

        $this->assertContains('BSOA', $codes, 'The picker must show the course.');

        // ...but taking a number there is still refused.
        $this->withToken($this->tokenFor($student, '24-019083', 'phone'))
            ->postJson('/api/v1/tickets', [
                'office_id' => $bsoa->id,
                'service_id' => $bsoaService->id,
                'purpose' => 'Transcript',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['office_id']]);
    }

    public function test_staff_bootstrap_reports_an_unassigned_account_rather_than_an_empty_shell(): void
    {
        // A reachable staff account with no office is an administrative
        // problem. The API has to say so, or the counter screen shows an
        // office list with nothing in it and no explanation.
        $staff = $this->makeUser(User::ROLE_STAFF);

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->getJson('/api/v1/staff/bootstrap')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Your staff account is not assigned to an active operational office. Please contact the registrar.'
            );

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->getJson('/api/v1/bootstrap')
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Role boundaries
    |--------------------------------------------------------------------------
    */

    public function test_a_student_cannot_reach_staff_or_admin_payloads(): void
    {
        $student = $this->makeStudent();
        $token = $this->tokenFor($student, '24-019083');

        $this->withToken($token)->getJson('/api/v1/staff/bootstrap')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/overview')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/accounts')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/queues')->assertForbidden();
    }

    public function test_staff_cannot_reach_admin_payloads(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $token = $this->tokenFor($staff, $staff->email);

        $this->withToken($token)->getJson('/api/v1/admin/overview')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/accounts')->assertForbidden();

        // The role-dispatching bootstrap is not an admin surface: it hands a
        // staff member the staff payload and nothing more.
        $this->withToken($token)->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonStructure(['staff'])
            ->assertJsonMissingPath('counts');

        // ...and the counter console is theirs.
        $this->withToken($token)->getJson('/api/v1/staff/bootstrap')->assertOk();
        $this->withToken($token)->getJson('/api/v1/queues/'.$office->id)->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Office scope
    |--------------------------------------------------------------------------
    |
    | `role:admin,staff` decides which HANDLER runs. These four tests cover the
    | other half: guardOffice() deciding which OFFICE that handler may act on.
    | Without it, any member of staff could operate any queue on the campus.
    |
    */

    public function test_staff_cannot_read_an_office_they_are_not_assigned_to(): void
    {
        [$office] = $this->makeStaffedOffice();
        $otherOffice = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->getJson('/api/v1/queues/'.$otherOffice->id)
            ->assertForbidden();
    }

    public function test_staff_cannot_call_next_at_an_office_they_are_not_assigned_to(): void
    {
        [$office] = $this->makeStaffedOffice();
        $otherOffice = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);

        $service = $this->makeService($otherOffice);

        (new QueueTicketCreator())->create([
            'office_id' => $otherOffice->id,
            'service_id' => $service->id,
        ]);

        // Ticket first, then deploy: a deployed batch is closed to submissions,
        // which is the real order the registrar works in.
        $this->deployBatch($otherOffice);

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->postJson("/api/v1/queues/{$otherOffice->id}/call-next")
            ->assertForbidden();

        $this->assertFalse(
            QueueTicket::where('office_id', $otherOffice->id)->where('status', QueueTicket::STATUS_SERVING)->exists(),
            'A refused call-next must not have promoted anything.'
        );
    }

    public function test_an_admin_may_act_on_any_office(): void
    {
        $office = $this->makeOffice(['code' => 'CASH']);
        $service = $this->makeService($office);

        (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
        ]);

        $this->deployBatch($office);

        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->withToken($this->tokenFor($admin, $admin->email))
            ->getJson('/api/v1/queues/'.$office->id)
            ->assertOk()
            ->assertJsonPath('office.id', $office->id);

        $this->withToken($this->tokenFor($admin, $admin->email))
            ->postJson("/api/v1/queues/{$office->id}/call-next")
            ->assertOk()
            ->assertJsonStructure(['ticket' => ['queue_number'], 'board']);
    }

    public function test_a_retired_office_is_not_addressable(): void
    {
        $staff = $this->makeUser(User::ROLE_STAFF);
        $retired = $this->makeOffice(['code' => 'CASH', 'is_active' => false]);

        // 404, not 403: a retired office is not addressable at all, and answering
        // "forbidden" would confirm that the id exists.
        $this->withToken($this->tokenFor($staff, $staff->email))
            ->getJson('/api/v1/queues/'.$retired->id)
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Ticket creation
    |--------------------------------------------------------------------------
    */

    public function test_a_student_takes_a_ticket_and_gets_its_position(): void
    {
        [$office] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $student = $this->makeStudent();

        $response = $this->withToken($this->tokenFor($student, '24-019083'))
            ->post('/api/v1/tickets', [
                'office_id' => $office->id,
                'service_id' => $service->id,
                'purpose' => 'Certificate of evaluation',
            ]);

        $response->assertCreated()->assertJsonStructure([
            'ticket' => ['id', 'queue_number', 'status', 'office' => ['id'], 'service' => ['id']],
            'progress' => ['position', 'people_ahead', 'estimated_wait_minutes', 'minutes_per_ticket'],
        ]);

        $this->assertSame(QueueTicket::STATUS_WAITING, $response->json('ticket.status'));

        // The receipt prints the counter's state, so the create response must
        // carry it rather than making the phone guess.
        $response->assertJsonStructure([
            'ticket' => ['office' => ['status', 'window_open', 'accepting']],
        ]);

        $this->assertSame(Office::STATUS_ONLINE, $response->json('ticket.office.status'));
        $this->assertTrue($response->json('ticket.office.window_open'));
        $this->assertTrue($response->json('ticket.office.accepting'));
    }

    public function test_a_student_cannot_choose_a_service_a_station_will_not_serve_their_course_for(): void
    {
        $office = $this->makeOffice(['code' => 'REG-BSIT']);

        $bsitStation = \App\Models\OfficeStation::create([
            'office_id' => $office->id,
            'name' => 'BSIT Window',
            'course_codes' => ['BSIT'],
            'is_active' => true,
        ]);

        $bsitService = $this->makeService($office, ['station_id' => $bsitStation->id]);

        // The student is in BSOA, so the BSIT-only window must refuse them.
        // Refused at the office first: the account's registered course decides
        // the Registrar queue, before any service/station check runs.
        $student = $this->makeStudent(['course' => 'BSOA']);

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->postJson('/api/v1/tickets', [
                'office_id' => $office->id,
                'service_id' => $bsitService->id,
                'purpose' => 'Transcript',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['office_id']]);
    }

    public function test_a_pwd_ticket_is_refused_without_evidence(): void
    {
        [$office] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $student = $this->makeStudent();

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->postJson('/api/v1/tickets', [
                'office_id' => $office->id,
                'service_id' => $service->id,
                'purpose' => 'Transcript',
                'priority' => QueueTicket::PRIORITY_PWD,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['priority_evidence']]);

        $this->assertSame(0, QueueTicket::count());
    }

    public function test_a_pwd_ticket_is_accepted_with_evidence(): void
    {
        [$office] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $student = $this->makeStudent();

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->post('/api/v1/tickets', [
                'office_id' => $office->id,
                'service_id' => $service->id,
                'purpose' => 'Transcript',
                'priority' => QueueTicket::PRIORITY_PWD,
                'priority_evidence' => $this->evidenceUpload(),
            ])
            ->assertCreated()
            ->assertJsonPath('ticket.priority', QueueTicket::PRIORITY_PWD)
            // Only a boolean reaches the client; the file stays outside the web
            // root and is streamed through an authenticated route.
            ->assertJsonPath('ticket.has_evidence', true)
            ->assertJsonMissingPath('ticket.priority_evidence');
    }

    public function test_a_ticket_is_refused_outside_the_office_opening_window(): void
    {
        $office = $this->makeOffice(['code' => 'CASH', 'opening_time' => '08:00', 'closing_time' => '08:01']);
        $service = $this->makeService($office);

        $student = $this->makeStudent();

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->postJson('/api/v1/tickets', [
                'office_id' => $office->id,
                'service_id' => $service->id,
                'purpose' => 'Payment',
            ])
            ->assertStatus(422);

        $this->assertSame(0, QueueTicket::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Ticket privacy
    |--------------------------------------------------------------------------
    */

    public function test_a_student_sees_only_their_own_tickets(): void
    {
        [$office] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $mine = $this->makeStudent(['student_id' => '24-000001']);
        $theirs = $this->makeStudent(['student_id' => '24-000002']);

        (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
            'user_id' => $mine->id, 'purpose' => 'Mine',
        ]);
        (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
            'user_id' => $theirs->id, 'purpose' => 'Theirs',
        ]);

        $purposes = $this->withToken($this->tokenFor($mine, '24-000001'))
            ->getJson('/api/v1/tickets')
            ->assertOk()
            ->json('tickets.*.purpose');

        $this->assertSame(['Mine'], $purposes);
    }

    public function test_another_students_ticket_id_is_a_404_not_a_403(): void
    {
        [$office] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $owner = $this->makeStudent(['student_id' => '24-000001']);
        $curious = $this->makeStudent(['student_id' => '24-000002']);

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
            'user_id' => $owner->id, 'purpose' => 'Not yours',
        ])['ticket'];

        // 404, not 403: telling them it exists would let them enumerate the
        // campus's queue numbers and purposes one id at a time.
        $this->withToken($this->tokenFor($curious, '24-000002'))
            ->getJson('/api/v1/tickets/'.$ticket->id)
            ->assertNotFound();
    }

    public function test_pwd_evidence_is_streamed_only_to_the_owner_or_the_counter(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $owner = $this->makeStudent(['student_id' => '24-000001']);
        $stranger = $this->makeStudent(['student_id' => '24-000002']);

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
            'user_id' => $owner->id, 'purpose' => 'Transcript',
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ])['ticket'];

        $this->withToken($this->tokenFor($owner, '24-000001'))
            ->getJson("/api/v1/tickets/{$ticket->id}/evidence")
            ->assertOk();

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->get("/api/v1/tickets/{$ticket->id}/evidence")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->withToken($this->tokenFor($stranger, '24-000002'))
            ->getJson("/api/v1/tickets/{$ticket->id}/evidence")
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Cancellation
    |--------------------------------------------------------------------------
    */

    public function test_a_student_cancels_their_own_waiting_ticket(): void
    {
        [$office] = $this->makeStaffedOffice();
        $service = $this->makeService($office);
        $student = $this->makeStudent();

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
            'user_id' => $student->id, 'purpose' => 'Transcript',
        ])['ticket'];

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->postJson("/api/v1/tickets/{$ticket->id}/cancel")
            ->assertOk()
            ->assertJsonPath('ticket.status', QueueTicket::STATUS_CANCELLED);

        $this->assertSame(QueueTicket::STATUS_CANCELLED, $ticket->refresh()->status);
    }

    public function test_cancelling_twice_is_refused(): void
    {
        [$office] = $this->makeStaffedOffice();
        $service = $this->makeService($office);
        $student = $this->makeStudent();

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
            'user_id' => $student->id, 'purpose' => 'Transcript',
        ])['ticket'];

        $token = $this->tokenFor($student, '24-019083');

        $this->withToken($token)->postJson("/api/v1/tickets/{$ticket->id}/cancel")->assertOk();
        $this->withToken($token)->postJson("/api/v1/tickets/{$ticket->id}/cancel")->assertStatus(422);
    }

    public function test_a_student_cannot_cancel_a_ticket_that_is_being_served(): void
    {
        // Once a number has been called the student walks away from the
        // window, so the counter has to be the one to clear it.
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);
        $student = $this->makeStudent();

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
            'user_id' => $student->id, 'purpose' => 'Transcript',
        ])['ticket'];

        $this->deployBatch($office);
        $this->withToken($this->tokenFor($staff, $staff->email))
            ->postJson("/api/v1/queues/{$office->id}/call-next")
            ->assertOk();

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->postJson("/api/v1/tickets/{$ticket->id}/cancel")
            ->assertStatus(422);
    }

    public function test_cancelling_completes_a_batch_whose_last_ticket_went_away(): void
    {
        [$office] = $this->makeStaffedOffice();
        $service = $this->makeService($office);
        $student = $this->makeStudent();

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
            'user_id' => $student->id, 'purpose' => 'Transcript',
        ])['ticket'];

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->postJson("/api/v1/tickets/{$ticket->id}/cancel")
            ->assertOk();

        // Otherwise the office keeps a finished batch open and refuses new
        // tickets for a batch nobody is left in.
        $this->assertSame(
            QueueDeployment::STATUS_COMPLETED,
            $ticket->deployment->refresh()->status
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Counter operations
    |--------------------------------------------------------------------------
    */

    public function test_call_next_serves_the_oldest_waiting_ticket(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $first = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id, 'user_id' => $this->makeStudent()->id,
        ])['ticket'];

        $this->deployBatch($office);

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->postJson("/api/v1/queues/{$office->id}/call-next")
            ->assertOk()
            ->assertJsonPath('ticket.id', $first->id);

        $this->assertSame(QueueTicket::STATUS_SERVING, $first->refresh()->status);
        $this->assertSame($staff->id, $first->refresh()->served_by);
    }

    public function test_call_next_prefers_a_pwd_ticket_over_an_older_regular_one(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $regular = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
        ])['ticket'];

        $pwd = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ])['ticket'];

        $this->deployBatch($office);

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->postJson("/api/v1/queues/{$office->id}/call-next")
            ->assertOk()
            ->assertJsonPath('ticket.id', $pwd->id);

        $this->assertSame(QueueTicket::STATUS_WAITING, $regular->refresh()->status);
    }

    public function test_the_api_and_the_blade_console_call_numbers_in_the_same_order(): void
    {
        // The two front doors must not be able to disagree about who is up
        // next. Both go through App\Services\QueueCounter; this asserts it.
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        (new QueueTicketCreator())->create(['office_id' => $office->id, 'service_id' => $service->id]);
        (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ]);

        $this->deployBatch($office);

        $this->actingAs($staff)
            ->post(route('staff.queues.call-next'))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'PWD priority'));

        $serving = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVING)
            ->firstOrFail();

        $this->assertTrue($serving->isPwd(), 'The Blade console must call PWD first too.');
    }

    public function test_call_next_refuses_when_a_ticket_is_already_being_served(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        (new QueueTicketCreator())->create(['office_id' => $office->id, 'service_id' => $service->id]);
        (new QueueTicketCreator())->create(['office_id' => $office->id, 'service_id' => $service->id]);

        $this->deployBatch($office);

        $token = $this->tokenFor($staff, $staff->email);

        $this->withToken($token)->postJson("/api/v1/queues/{$office->id}/call-next")->assertOk();

        // A distinct message, because the operator's next action differs: the
        // button already worked and the answer did not arrive.
        $this->withToken($token)
            ->postJson("/api/v1/queues/{$office->id}/call-next")
            ->assertStatus(422)
            ->assertJsonPath('message', "{$office->name} already has a ticket being served.");
    }

    public function test_call_next_can_name_the_ticket_to_promote(): void
    {
        // The counter screen's row action: an operator looking at the waiting
        // list hands the window to somebody specific rather than to whoever
        // the ordering rule puts first.
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $first = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
        ])['ticket'];

        $second = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
        ])['ticket'];

        $this->deployBatch($office);

        $token = $this->tokenFor($staff, $staff->email);

        $this->withToken($token)
            ->postJson("/api/v1/queues/{$office->id}/call-next", ['ticket_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('ticket.id', $second->id);

        $this->assertSame(QueueTicket::STATUS_SERVING, $second->refresh()->status);
        $this->assertSame(
            QueueTicket::STATUS_WAITING,
            $first->refresh()->status,
            'Naming a ticket must not disturb the rest of the line.'
        );
    }

    public function test_call_next_refuses_a_named_ticket_that_is_no_longer_waiting(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
        ])['ticket'];

        $this->deployBatch($office);

        $token = $this->tokenFor($staff, $staff->email);
        $this->withToken($token)->postJson("/api/v1/queues/{$office->id}/call-next")->assertOk();

        // The named case chooses WHICH ticket moves, never WHETHER one may:
        // a window that already has a number refuses it exactly as an unnamed
        // call would - and says so in the operator's terms, naming the
        // number rather than the office.
        $this->withToken($token)
            ->postJson("/api/v1/queues/{$office->id}/call-next", ['ticket_id' => $ticket->id])
            ->assertStatus(422)
            ->assertJsonPath('message', "Ticket {$ticket->queue_number} is not waiting any more.");

        $this->withToken($token)
            ->postJson("/api/v1/queues/{$office->id}/tickets/{$ticket->id}/serve")
            ->assertOk();

        // And once it has been served, the same request keeps being refused on its
        // own terms rather than silently falling through to "call whatever is
        // next".
        $this->withToken($token)
            ->postJson("/api/v1/queues/{$office->id}/call-next", ['ticket_id' => $ticket->id])
            ->assertStatus(422)
            ->assertJsonPath('message', "Ticket {$ticket->queue_number} is not waiting any more.");
    }

    public function test_call_next_refuses_a_named_ticket_from_another_office(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $otherOffice = $this->makeOffice(['code' => 'CASH']);
        $foreign = (new QueueTicketCreator())->create([
            'office_id' => $otherOffice->id,
            'service_id' => $this->makeService($otherOffice)->id,
        ])['ticket'];

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->postJson("/api/v1/queues/{$office->id}/call-next", ['ticket_id' => $foreign->id])
            ->assertForbidden();

        $this->assertSame(QueueTicket::STATUS_WAITING, $foreign->refresh()->status);
    }

    public function test_serve_finishes_the_ticket_at_the_window(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
        ])['ticket'];

        $this->deployBatch($office);

        $token = $this->tokenFor($staff, $staff->email);
        $this->withToken($token)->postJson("/api/v1/queues/{$office->id}/call-next")->assertOk();

        $this->withToken($token)
            ->postJson("/api/v1/queues/{$office->id}/tickets/{$ticket->id}/serve")
            ->assertOk()
            ->assertJsonPath('ticket.status', QueueTicket::STATUS_SERVED);

        $this->assertSame(QueueDeployment::STATUS_COMPLETED, $ticket->deployment->refresh()->status);
    }

    public function test_a_staff_member_cannot_serve_a_ticket_from_another_office(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $otherOffice = $this->makeOffice(['code' => 'CASH']);
        $otherService = $this->makeService($otherOffice);

        $foreign = (new QueueTicketCreator())->create([
            'office_id' => $otherOffice->id, 'service_id' => $otherService->id,
        ])['ticket'];

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->postJson("/api/v1/queues/{$otherOffice->id}/tickets/{$foreign->id}/serve")
            ->assertForbidden();

        $this->assertSame(QueueTicket::STATUS_WAITING, $foreign->refresh()->status);
    }

    public function test_no_show_is_terminal_and_preserves_audit(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
        ])['ticket'];

        $this->deployBatch($office);

        $token = $this->tokenFor($staff, $staff->email);
        $this->withToken($token)->postJson("/api/v1/queues/{$office->id}/call-next")->assertOk();

        $this->withToken($token)
            ->postJson("/api/v1/queues/{$office->id}/tickets/{$ticket->id}/no-show")
            ->assertOk()
            ->assertJsonPath('ticket.status', QueueTicket::STATUS_NO_SHOW);

        $ticket->refresh();
        $this->assertSame(QueueTicket::STATUS_NO_SHOW, $ticket->status);
        $this->assertNotNull($ticket->called_at, 'A terminal No-Show must preserve the original call time for history.');
        $this->assertNotNull($ticket->no_show_at);
        $this->assertNotNull($ticket->no_show_by);
    }

    public function test_a_plain_status_write_cannot_force_a_ticket_to_serving(): void
    {
        // callNext() is the only way in, because it checks that nothing is
        // already at the window and stamps called_at.
        [$office] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
        ])['ticket'];

        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->withToken($this->tokenFor($admin, $admin->email))
            ->putJson("/api/v1/queues/{$office->id}/tickets/{$ticket->id}", ['status' => QueueTicket::STATUS_SERVING])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Use "Call next" to move a ticket to serving.');

        $this->assertSame(QueueTicket::STATUS_WAITING, $ticket->refresh()->status);
    }

    public function test_the_queue_payload_is_what_the_dart_office_queue_model_decodes(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        (new QueueTicketCreator())->create(['office_id' => $office->id, 'service_id' => $service->id]);
        $this->deployBatch($office);

        $token = $this->tokenFor($staff, $staff->email);

        $this->withToken($token)
            ->getJson('/api/v1/queues/'.$office->id)
            ->assertOk()
            ->assertJsonStructure([
                'office' => ['id', 'name', 'code'],
                'now_serving',
                'waiting' => [['id', 'queue_number', 'status', 'office', 'service']],
                'recently_served',
                'batch',
                'window_open',
                'waiting_count',
                'serving_count',
                'served_count',
            ])
            ->assertJsonPath('waiting_count', 1)
            ->assertJsonPath('now_serving', null);

        // With somebody at the window, now_serving is the full ticket: the
        // counter screen shows whose number it is and what they are there for.
        $this->withToken($token)
            ->postJson('/api/v1/queues/'.$office->id.'/call-next')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/queues/'.$office->id)
            ->assertOk()
            ->assertJsonStructure(['now_serving' => ['queue_number', 'called_at']]);
    }

    public function test_the_waiting_list_leads_with_pwd(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        (new QueueTicketCreator())->create(['office_id' => $office->id, 'service_id' => $service->id]);
        (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ]);

        $priorities = $this->withToken($this->tokenFor($staff, $staff->email))
            ->getJson('/api/v1/queues/'.$office->id)
            ->assertOk()
            ->json('waiting.*.priority');

        $this->assertSame([QueueTicket::PRIORITY_PWD, QueueTicket::PRIORITY_REGULAR], $priorities);
    }

    /*
    |--------------------------------------------------------------------------
    | Live board
    |--------------------------------------------------------------------------
    */

    public function test_the_live_board_is_readable_by_a_student_and_reports_the_current_number(): void
    {
        [$office] = $this->makeStaffedOffice();
        $service = $this->makeService($office);
        $student = $this->makeStudent();

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id, 'service_id' => $service->id, 'user_id' => $student->id,
        ])['ticket'];

        $this->deployBatch($office);

        // Promote it the way the counter would.
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);
        $this->withToken($this->tokenFor($staff, $staff->email))
            ->postJson("/api/v1/queues/{$office->id}/call-next")
            ->assertOk();

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->getJson("/api/v1/offices/{$office->id}/live")
            ->assertOk()
            ->assertJsonPath('now_serving.queue_number', $ticket->queue_number)
            ->assertJsonPath('serving_count', 1)
            ->assertJsonStructure(['office' => ['id', 'name', 'code'], 'recent_served', 'window_open']);
    }

    public function test_the_live_board_for_a_missing_office_is_a_404(): void
    {
        $student = $this->makeStudent();

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->getJson('/api/v1/offices/999999/live')
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Accounts
    |--------------------------------------------------------------------------
    */

    public function test_an_admin_creates_a_student_account(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->withToken($this->tokenFor($admin, $admin->email))
            ->postJson('/api/v1/accounts', [
                'name' => 'Juan Dela Cruz',
                'email' => 'juan@example.test',
                'password' => 'secret123',
                'role' => User::ROLE_STUDENT,
                'student_id' => '25-000001',
                'course' => 'BSOA',
                'block' => 'B2',
                'year_level' => '1st Year',
            ])
            ->assertCreated()
            ->assertJsonPath('account.student_id', '25-000001')
            ->assertJsonPath('account.role', User::ROLE_STUDENT)
            ->assertJsonMissingPath('account.password');
    }

    public function test_a_duplicate_student_id_is_refused(): void
    {
        $this->makeStudent(['student_id' => '25-000001']);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        // Otherwise the same identifier would match two accounts on sign-in.
        $this->withToken($this->tokenFor($admin, $admin->email))
            ->postJson('/api/v1/accounts', [
                'name' => 'Someone Else',
                'email' => 'else@example.test',
                'password' => 'secret123',
                'role' => User::ROLE_STUDENT,
                'student_id' => '25-000001',
                'course' => 'BSIT',
                'block' => 'A1',
                'year_level' => '1st Year',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');
    }

    public function test_student_only_fields_are_dropped_for_a_non_student_account(): void
    {
        // "student_id is null unless the role is student" is a constraint on a
        // combination of two fields, which no single validation rule can
        // express, so it is applied in AccountRules::apply().
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->withToken($this->tokenFor($admin, $admin->email))
            ->postJson('/api/v1/accounts', [
                'name' => 'Staff Account',
                'email' => 'newstaff@example.test',
                'password' => 'secret123',
                'role' => User::ROLE_STAFF,
                'student_id' => '25-999999',
                'course' => 'BSIT',
                'block' => 'A1',
                'year_level' => '1st Year',
            ])
            ->assertStatus(422);
    }

    public function test_a_staff_account_without_an_operational_office_is_refused(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->withToken($this->tokenFor($admin, $admin->email))
            ->postJson('/api/v1/accounts', [
                'name' => 'Unassigned Staff',
                'email' => 'unassigned@example.test',
                'password' => 'secret123',
                'role' => User::ROLE_STAFF,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('office_id');
    }

    public function test_the_last_admin_cannot_be_deactivated(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->withToken($this->tokenFor($admin, $admin->email))
            ->deleteJson('/api/v1/accounts/'.$admin->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'The last admin account cannot be deleted.');

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_an_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $other = $this->makeUser(User::ROLE_ADMIN);

        // One of two admins, so the self-delete rule is what refuses this -
        // not the last-admin rule, which the previous test covers.
        $this->withToken($this->tokenFor($admin, $admin->email))
            ->deleteJson('/api/v1/accounts/'.$admin->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot delete your own account.');

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_deactivating_an_account_stops_sign_in_and_revokes_its_tokens(): void
    {
        $staff = $this->makeUser(User::ROLE_STAFF);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $staffToken = $this->tokenFor($staff, $staff->email);

        $this->withToken($this->tokenFor($admin, $admin->email))
            ->deleteJson('/api/v1/accounts/'.$staff->id)
            ->assertOk()
            ->assertJsonPath('account.is_active', false);

        $this->forgetGuards()->withToken($staffToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $staff->email, 'password' => 'password',
        ])->assertForbidden();
    }

    public function test_account_listing_can_be_filtered_and_searched(): void
    {
        $this->makeStudent(['name' => 'Ana Ramos', 'student_id' => '25-000010']);
        $this->makeStudent(['name' => 'Ben Cruz', 'student_id' => '25-000011']);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $token = $this->tokenFor($admin, $admin->email);

        $this->withToken($token)->getJson('/api/v1/accounts?role=student')
            ->assertOk()
            ->assertJsonCount(2, 'accounts');

        $this->withToken($token)->getJson('/api/v1/accounts?search=Ana')
            ->assertOk()
            ->assertJsonCount(1, 'accounts')
            ->assertJsonPath('accounts.0.name', 'Ana Ramos');

        // A literal % must not behave as a wildcard and match everything.
        $this->withToken($token)->getJson('/api/v1/accounts?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'accounts');
    }

    /*
    |--------------------------------------------------------------------------
    | Profile & password
    |--------------------------------------------------------------------------
    */

    public function test_a_user_updates_their_own_profile(): void
    {
        $student = $this->makeStudent();

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->patchJson('/api/v1/auth/me', [
                'phone' => '09171234567',
                // Deliberately ignored: a profile edit must not promote.
                'role' => User::ROLE_ADMIN,
                // Name and Student ID belong to the administrator: sent
                // values are ignored, not applied.
                'name' => 'Somebody Else',
                'student_id' => '99-999999',
            ])
            ->assertOk()
            ->assertJsonPath('user.phone', '09171234567')
            ->assertJsonPath('user.role', User::ROLE_STUDENT)
            ->assertJsonPath('user.name', $student->name)
            ->assertJsonPath('user.student_id', '24-019083');

        $this->assertSame($student->name, $student->refresh()->name);
        $this->assertSame('24-019083', $student->refresh()->student_id);
    }

    public function test_changing_a_password_requires_the_current_one(): void
    {
        $student = $this->makeStudent();

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'wrong',
                'password' => 'newsecret',
                'password_confirmation' => 'newsecret',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(\App\Support\PlainPassword::check('password', $student->refresh()->password));
    }

    public function test_changing_a_password_revokes_every_token(): void
    {
        // A password change is the usual way someone says "assume the old
        // session was stolen", so leaving a token behind would defeat it.
        $student = $this->makeStudent();

        $phone = $this->tokenFor($student, '24-019083', 'phpunit-phone');
        $laptop = $this->tokenFor($student, '24-019083', 'phpunit-laptop');

        $this->withToken($laptop)
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'newsecret',
                'password_confirmation' => 'newsecret',
            ])
            ->assertOk()
            ->assertJsonPath('reauthenticate', true);

        $this->forgetGuards()->withToken($laptop)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->forgetGuards()->withToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => '24-019083', 'password' => 'newsecret',
        ])->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Unversioned aliases
    |--------------------------------------------------------------------------
    |
    | The short paths some clients are written against. They must reach the
    | same logic under the same authorisation - which is what these assert,
    | because an alias with looser middleware is the easiest way to
    | accidentally publish a hole.
    |
    */

    public function test_the_unversioned_aliases_reach_the_same_handlers(): void
    {
        $student = $this->makeStudent();

        $token = $this->postJson('/api/login', [
            'identifier' => '24-019083', 'password' => 'password',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.id', $student->id);

        $this->withToken($token)->getJson('/api/offices')->assertOk()->assertJsonStructure(['offices']);

        $this->withToken($token)->postJson('/api/password', [
            'current_password' => 'password',
            'password' => 'newsecret',
            'password_confirmation' => 'newsecret',
        ])->assertOk();

        $this->forgetGuards()->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_the_student_cannot_reach_account_aliases(): void
    {
        $token = $this->tokenFor($this->makeStudent(), '24-019083');

        $this->withToken($token)->getJson('/api/users')->assertForbidden();
        $this->withToken($token)->postJson('/api/users', [])->assertForbidden();
    }

    public function test_an_alias_cannot_reach_an_endpoint_the_versioned_route_also_refuses(): void
    {
        [$office] = $this->makeStaffedOffice();
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);
        $otherOffice = $this->makeOffice(['code' => 'CASH']);

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->getJson('/api/queues/'.$otherOffice->id)
            ->assertForbidden();
    }

    public function test_the_flat_queue_aliases_take_and_cancel_a_ticket(): void
    {
        [$office] = $this->makeStaffedOffice();
        $service = $this->makeService($office);
        $student = $this->makeStudent();

        $token = $this->tokenFor($student, '24-019083');

        $created = $this->withToken($token)->postJson('/api/queues', [
            'office_id' => $office->id,
            'service_id' => $service->id,
            'purpose' => 'Certificate of good moral character',
        ])->assertCreated();

        $ticketId = $created->json('ticket.id');

        $this->withToken($token)
            ->deleteJson('/api/queues/'.$ticketId)
            ->assertOk()
            ->assertJsonPath('ticket.status', QueueTicket::STATUS_CANCELLED);
    }
}