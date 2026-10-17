<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Office;
use App\Models\OfficeStation;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\QueueDeploymentManager;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * Every Registrar course office (BSIT, BSOA, BSCRIM, BEED, BSED, BACOMM,
 * BAPOLSCI) works as its own independent queue for the Flutter app.
 *
 * Each course gets its own REG-<CODE> office, its own 100-slot batch with
 * office-prefixed numbers, its own assigned staff member, and its own row
 * on GET /api/v1/registrar/courses carrying the live online/offline
 * switch. Staff stay scoped to their own course queue by guardOffice().
 */
class RegistrarCourseOfficesTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateLocalStorage();
    }

    /**
     * @return array<string, array{office: Office, course: Course}>
     */
    private function makeRegistrarCampuses(): array
    {
        $out = [];

        foreach (User::COURSES as $code) {
            $office = $this->makeOffice([
                'name' => "Registrar - {$code}",
                'code' => "REG-{$code}",
            ]);

            $station = OfficeStation::create([
                'office_id' => $office->id,
                'name' => "Registrar {$code} Station",
                'location' => 'Main campus',
                'course_codes' => [$code],
                'min_daily_students' => 40,
                'target_daily_students' => 60,
                'max_daily_slots' => 100,
                'opening_time' => '00:00',
                'closing_time' => '23:59',
                'is_active' => true,
            ]);

            $this->makeService($office, [
                'name' => 'Enrollment for Incoming Students',
                'requires_course' => false,
                'station_id' => $station->id,
            ]);

            $course = $this->makeCourse([
                'code' => $code,
                'name' => "Course {$code}",
                'office_id' => $office->id,
            ]);

            $out[$code] = ['office' => $office, 'course' => $course];
        }

        return $out;
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

    public function test_every_course_issues_its_own_prefixed_numbers(): void
    {
        $campuses = $this->makeRegistrarCampuses();
        $creator = new QueueTicketCreator();

        foreach ($campuses as $code => $parts) {
            $student = $this->makeUser(User::ROLE_STUDENT, [
                'student_id' => '24-' . $code,
                'course' => $code,
            ]);
            $service = $parts['office']->services()->firstOrFail();

            $result = $creator->create([
                'office_id' => $parts['office']->id,
                'service_id' => $service->id,
                'course_id' => $parts['course']->id,
                'user_id' => $student->id,
                'purpose' => 'Enrollment',
                'student_course' => $code,
            ]);

            $this->assertSame("REG-{$code}-001", $result['ticket']->queue_number);
            $this->assertSame($parts['office']->id, $result['ticket']->office_id);
            $this->assertSame($parts['course']->id, $result['ticket']->course_id);
        }

        // A second BSIT ticket advances only the BSIT batch, leaving the
        // other six course queues untouched.
        $bsit = $campuses['BSIT'];
        $otherStudent = $this->makeUser(User::ROLE_STUDENT, [
            'student_id' => '24-BSIT-2',
            'course' => 'BSIT',
        ]);

        $second = (new QueueTicketCreator())->create([
            'office_id' => $bsit['office']->id,
            'service_id' => $bsit['office']->services()->firstOrFail()->id,
            'course_id' => $bsit['course']->id,
            'user_id' => $otherStudent->id,
            'purpose' => 'Enrollment',
            'student_course' => 'BSIT',
        ]);

        $this->assertSame('REG-BSIT-002', $second['ticket']->queue_number);
        $this->assertSame(
            1,
            QueueTicket::where('office_id', $campuses['BSOA']['office']->id)->count()
        );
    }

    public function test_staff_cannot_call_another_course_queue(): void
    {
        $campuses = $this->makeRegistrarCampuses();

        $bsitStaff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $campuses['BSIT']['office']->id]);
        $bsoaStaff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $campuses['BSOA']['office']->id]);

        $student = $this->makeUser(User::ROLE_STUDENT, [
            'student_id' => '24-BSOA-9',
            'course' => 'BSOA',
        ]);

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $campuses['BSOA']['office']->id,
            'service_id' => $campuses['BSOA']['office']->services()->firstOrFail()->id,
            'course_id' => $campuses['BSOA']['course']->id,
            'user_id' => $student->id,
            'purpose' => 'Enrollment',
            'student_course' => 'BSOA',
        ])['ticket'];

        // BSIT staff acting on a BSOA ticket is refused.
        $this->withToken($this->tokenFor($bsitStaff, $bsitStaff->email))
            ->postJson("/api/v1/queues/{$campuses['BSOA']['office']->id}/tickets/{$ticket->id}/serve")
            ->assertForbidden();

        // The assigned BSOA staff can call within their own queue.
        $this->withToken($this->tokenFor($bsoaStaff, $bsoaStaff->email, 'bsoa'))
            ->postJson("/api/v1/queues/{$campuses['BSOA']['office']->id}/call-next")
            ->assertOk()
            ->assertJsonPath('ticket.status', QueueTicket::STATUS_SERVING);
    }

    public function test_registrar_courses_endpoint_lists_all_seven_with_status(): void
    {
        $this->makeRegistrarCampuses();

        $student = $this->makeUser(User::ROLE_STUDENT, [
            'student_id' => '24-070001',
            'course' => 'BSIT',
        ]);

        $payload = $this->withToken($this->tokenFor($student, '24-070001'))
            ->getJson('/api/v1/registrar/courses')
            ->assertOk()
            ->json();

        $rows = $payload['registrar_courses'] ?? [];
        $this->assertCount(7, $rows);

        $codes = collect($rows)->pluck('course_code')->sort()->values()->all();
        $this->assertSame(
            ['BACOMM', 'BAPOLSCI', 'BEED', 'BSCRIM', 'BSED', 'BSIT', 'BSOA'],
            $codes
        );

        foreach ($rows as $row) {
            // The Flutter Offline pill reads exactly this field.
            $this->assertArrayHasKey('status', $row);
            $this->assertContains($row['status'], [Office::STATUS_ONLINE, Office::STATUS_OFFLINE]);
            $this->assertArrayHasKey('available_slots', $row);
            $this->assertArrayHasKey('total_slots', $row);
            $this->assertArrayHasKey('accepting', $row);
            // The Queue Statistics screen reads these per-course counters.
            $this->assertArrayHasKey('waiting_count', $row);
            $this->assertArrayHasKey('serving_count', $row);
            $this->assertArrayHasKey('served_count', $row);
        }
    }

    public function test_registrar_course_counts_are_independent_per_course(): void
    {
        $campuses = $this->makeRegistrarCampuses();
        $creator = new QueueTicketCreator();

        $bsitStudent = $this->makeUser(User::ROLE_STUDENT, [
            'student_id' => '24-090001',
            'course' => 'BSIT',
        ]);
        $bsoaStudent = $this->makeUser(User::ROLE_STUDENT, [
            'student_id' => '24-090002',
            'course' => 'BSOA',
        ]);

        // Two waiting tickets in BSIT, one waiting in BSOA.
        foreach (['24-090001', '24-090001'] as $i => $sid) {
            $s = $i === 0 ? $bsitStudent : $bsitStudent;
            $creator->create([
                'office_id' => $campuses['BSIT']['office']->id,
                'service_id' => $campuses['BSIT']['office']->services()->firstOrFail()->id,
                'course_id' => $campuses['BSIT']['course']->id,
                'user_id' => $s->id,
                'purpose' => 'Enrollment',
                'student_course' => 'BSIT',
            ]);
        }
        $creator->create([
            'office_id' => $campuses['BSOA']['office']->id,
            'service_id' => $campuses['BSOA']['office']->services()->firstOrFail()->id,
            'course_id' => $campuses['BSOA']['course']->id,
            'user_id' => $bsoaStudent->id,
            'purpose' => 'Enrollment',
            'student_course' => 'BSOA',
        ]);

        // One BSIT ticket served, one BSIT ticket cancelled (excluded).
        $bsitWaiting = QueueTicket::where('office_id', $campuses['BSIT']['office']->id)
            ->where('status', QueueTicket::STATUS_WAITING)
            ->orderBy('id')
            ->get();
        $bsitWaiting[0]->update(['status' => QueueTicket::STATUS_SERVED, 'served_at' => now()]);
        $cancelled = $bsitWaiting[1];
        $cancelled->update(['status' => QueueTicket::STATUS_CANCELLED, 'cancelled_at' => now()]);

        // A fresh BSIT waiting ticket so the course still has one waiting.
        $creator->create([
            'office_id' => $campuses['BSIT']['office']->id,
            'service_id' => $campuses['BSIT']['office']->services()->firstOrFail()->id,
            'course_id' => $campuses['BSIT']['course']->id,
            'user_id' => $bsitStudent->id,
            'purpose' => 'Enrollment',
            'student_course' => 'BSIT',
        ]);

        $payload = $this->withToken($this->tokenFor($bsitStudent, '24-090001'))
            ->getJson('/api/v1/registrar/courses')
            ->assertOk()
            ->json();

        $byCode = collect($payload['registrar_courses'])->keyBy('course_code');

        // BSIT: 1 waiting, 0 serving, 1 served; the cancelled row is in none.
        $this->assertSame(1, $byCode->get('BSIT')['waiting_count']);
        $this->assertSame(0, $byCode->get('BSIT')['serving_count']);
        $this->assertSame(1, $byCode->get('BSIT')['served_count']);

        // BSOA untouched by BSIT activity: 1 waiting, 0 served.
        $this->assertSame(1, $byCode->get('BSOA')['waiting_count']);
        $this->assertSame(0, $byCode->get('BSOA')['served_count']);

        // Every other course is empty.
        foreach (['BSCRIM', 'BEED', 'BSED', 'BACOMM', 'BAPOLSCI'] as $code) {
            $this->assertSame(0, $byCode->get($code)['waiting_count']);
            $this->assertSame(0, $byCode->get($code)['serving_count']);
            $this->assertSame(0, $byCode->get($code)['served_count']);
        }

        // Summing the seven independent course counts counts every ticket
        // once: 2 waiting + 1 served across the campus Registrar queues.
        $this->assertSame(2, collect($payload['registrar_courses'])->sum('waiting_count'));
        $this->assertSame(1, collect($payload['registrar_courses'])->sum('served_count'));
    }

    public function test_registrar_queue_is_assigned_from_the_account_not_the_request(): void
    {
        $campuses = $this->makeRegistrarCampuses();

        $bsit = $this->makeUser(User::ROLE_STUDENT, [
            'student_id' => '24-091001',
            'course' => 'BSIT',
        ]);

        $bsitOffice = $campuses['BSIT']['office'];
        $bsoaOffice = $campuses['BSOA']['office'];
        $bsitService = $bsitOffice->services()->firstOrFail()->id;
        $bsoaService = $bsoaOffice->services()->firstOrFail()->id;
        $bsoaCourse = $campuses['BSOA']['course']->id;

        $token = $this->tokenFor($bsit, '24-091001');

        // Another course's queue is refused at the office, naming the
        // registered course, even with that queue's own service and course.
        $this->withToken($token)
            ->postJson('/api/v1/tickets', [
                'office_id' => $bsoaOffice->id,
                'service_id' => $bsoaService,
                'course_id' => $bsoaCourse,
                'purpose' => 'Enrollment',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('office_id');

        // The right office with the wrong course id still lands in the
        // registered queue: the course comes from the account, not the body.
        $response = $this->withToken($token)
            ->postJson('/api/v1/tickets', [
                'office_id' => $bsitOffice->id,
                'service_id' => $bsitService,
                'course_id' => $bsoaCourse,
                'purpose' => 'Enrollment',
            ])
            ->assertCreated();

        $ticket = QueueTicket::findOrFail($response->json('ticket.id'));
        $this->assertSame($bsitOffice->id, (int) $ticket->office_id);
        $this->assertSame('BSIT', $ticket->course->code);

        // A student with no course on file gets the administrator message,
        // not a ticket in someone else's line.
        $nocourse = $this->makeUser(User::ROLE_STUDENT, [
            'student_id' => '24-091002',
            'course' => null,
        ]);

        $this->withToken($this->tokenFor($nocourse, '24-091002'))
            ->postJson('/api/v1/tickets', [
                'office_id' => $bsitOffice->id,
                'service_id' => $bsitService,
                'purpose' => 'Enrollment',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('course_id');
    }

    public function test_offline_course_office_refuses_new_tickets(): void
    {
        $campuses = $this->makeRegistrarCampuses();
        $office = $campuses['BSIT']['office'];
        $office->update(['status' => Office::STATUS_OFFLINE]);

        $student = $this->makeUser(User::ROLE_STUDENT, [
            'student_id' => '24-080001',
            'course' => 'BSIT',
        ]);

        try {
            (new QueueTicketCreator())->create([
                'office_id' => $office->id,
                'service_id' => $office->services()->firstOrFail()->id,
                'course_id' => $campuses['BSIT']['course']->id,
                'user_id' => $student->id,
                'purpose' => 'Enrollment',
                'student_course' => 'BSIT',
            ]);

            $this->fail('An offline Registrar course office must refuse tickets.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(
                QueueTicketCreator::OFFLINE_MESSAGE,
                $e->errors()['office_id'][0]
            );
        }
    }

    public function test_assigning_staff_to_a_registrar_office_links_the_course(): void
    {
        $campuses = $this->makeRegistrarCampuses();
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $member = $this->makeUser(User::ROLE_STAFF, ['office_id' => $campuses['BEED']['office']->id]);

        $this->assertNull($campuses['BEED']['course']->fresh()->staff_id);

        $this->actingAs($admin)->put(route('admin.staff.update', $member), [
            'name' => $member->name,
            'email' => $member->email,
            'office_id' => $campuses['BEED']['office']->id,
            'is_active' => '1',
        ])->assertRedirect(route('admin.staff.index'));

        $this->assertSame(
            $member->id,
            $campuses['BEED']['course']->fresh()->staff_id
        );
    }

    public function test_close_rejects_new_tickets_and_redeploy_reopens_the_course(): void
    {
        $campuses = $this->makeRegistrarCampuses();
        $manager = new QueueDeploymentManager();
        $creator = new QueueTicketCreator();
        $bsit = $campuses['BSIT'];
        $bsoa = $campuses['BSOA'];

        $bsitStudent = $this->makeUser(User::ROLE_STUDENT, [
            'student_id' => '24-BSIT-1', 'course' => 'BSIT',
        ]);
        $bsoaStudent = $this->makeUser(User::ROLE_STUDENT, [
            'student_id' => '24-BSOA-1', 'course' => 'BSOA',
        ]);

        $issue = fn (array $campus, User $student, string $sid) => $creator->create([
            'office_id' => $campus['office']->id,
            'service_id' => $campus['office']->services()->firstOrFail()->id,
            'course_id' => $campus['course']->id,
            'user_id' => $student->id,
            'purpose' => 'Enrollment',
            'student_course' => $sid,
        ])['ticket'];

        // Deployed batch issues into its own queue; the sibling is untouched.
        $manager->deploy($bsit['office'], 100);
        $manager->deploy($bsoa['office'], 100);
        $t1 = $issue($bsit, $bsitStudent, 'BSIT');
        $this->assertSame('REG-BSIT-001', $t1->queue_number);

        // Closing BSIT stops its intake but keeps issued tickets and leaves
        // BSOA issuing normally.
        $manager->closeBatch($bsit['office']);
        $this->assertSame(QueueTicket::STATUS_WAITING, $t1->fresh()->status);

        try {
            $issue($bsit, $bsitStudent, 'BSIT');
            $this->fail('A closed Registrar batch must refuse new tickets.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('office_id', $e->errors());
        }

        $sibling = $issue($bsoa, $bsoaStudent, 'BSOA');
        $this->assertSame('REG-BSOA-001', $sibling->queue_number);

        // A fresh batch reopens the course with its own numbering sequence.
        $second = $manager->start($bsit['office'], 100);
        $this->assertSame(2, $second->batch_no);
        $t2 = $issue($bsit, $bsitStudent, 'BSIT');
        $this->assertSame('REG-BSIT-001', $t2->queue_number);
        $this->assertSame($second->id, $t2->deployment_id);
    }

    public function test_batch_reset_restores_deploy_batch_state(): void
    {
        $campuses = $this->makeRegistrarCampuses();
        $office = $campuses['BSED']['office'];
        $manager = new QueueDeploymentManager();

        $first = $manager->deploy($office, 100);
        $this->assertSame(1, $first->batch_no);

        $manager->resetBatchNumber($office);

        // The deployed batch is archived to history and nothing active
        // remains, so the dashboard offers Deploy Batch 1 again while the
        // sibling course queues are untouched.
        $this->assertSame(
            QueueDeployment::STATUS_COMPLETED,
            $first->fresh()->status
        );
        $this->assertNull(QueueDeployment::activeFor($office));
        $this->assertSame(1, QueueDeployment::nextBatchNoFor($office));
        $this->assertSame(
            0,
            QueueDeployment::where('office_id', $campuses['BEED']['office']->id)->count()
        );
    }
}
