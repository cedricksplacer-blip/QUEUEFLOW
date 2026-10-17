<?php

namespace Tests\Feature;

use App\Models\OfficeService;
use App\Models\OfficeStation;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\QueueDeploymentManager;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

class TicketCreationTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateLocalStorage();
    }

    public function test_queue_numbers_are_office_prefixed_and_batch_scoped(): void
    {
        $office = $this->makeOffice(['code' => 'REG']);
        $service = $this->makeService($office);
        $creator = new QueueTicketCreator();

        $ticketOne = $creator->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])['ticket'];

        $ticketTwo = $creator->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])['ticket'];

        $this->assertSame('REG-001', $ticketOne->queue_number);
        $this->assertSame('REG-002', $ticketTwo->queue_number);
        $this->assertSame($ticketOne->deployment_id, $ticketTwo->deployment_id);
        $this->assertSame(1, $ticketOne->deployment->batch_no);
    }

    public function test_pwd_ticket_requires_an_evidence_file(): void
    {
        $office = $this->makeOffice();
        $service = $this->makeService($office);

        try {
            (new QueueTicketCreator())->create([
                'office_id' => $office->id,
                'service_id' => $service->id,
                'priority' => QueueTicket::PRIORITY_PWD,
            ]);
            $this->fail('Expected a ValidationException for missing PWD evidence.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('priority_evidence', $e->errors());
        }
    }

    public function test_pwd_ticket_with_real_evidence_is_created_and_persisted(): void
    {
        $office = $this->makeOffice();
        $service = $this->makeService($office);

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ])['ticket'];

        $this->assertSame(QueueTicket::PRIORITY_PWD, $ticket->priority);
        $this->assertNotNull($ticket->priority_evidence);
        Storage::disk('local')->assertExists($ticket->priority_evidence);
    }

    public function test_station_rejects_a_course_outside_its_allowed_courses(): void
    {
        $registrar = $this->makeOffice(['code' => 'REG', 'name' => 'Registrar']);
        $cashier = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $course = $this->makeCourse(['code' => 'BSCS', 'office_id' => $registrar->id]);

        $station = OfficeStation::create([
            'office_id' => $cashier->id,
            'name' => 'Cashier Main Station',
            'location' => 'Finance / Cashier Counter',
            'course_codes' => ['BSIT'],
            'is_active' => true,
        ]);

        $service = OfficeService::create([
            'office_id' => $cashier->id,
            'station_id' => $station->id,
            'name' => 'Tuition Payment',
            'requires_course' => true,
            'is_active' => true,
        ]);

        try {
            (new QueueTicketCreator())->create([
                'office_id' => $cashier->id,
                'service_id' => $service->id,
                'course_id' => $course->id,
                'student_course' => $course->code,
                'priority' => QueueTicket::PRIORITY_REGULAR,
            ]);
            $this->fail('Expected a ValidationException for a mismatched course.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('service_id', $e->errors());
        }
    }

    public function test_ticket_creation_rejects_once_the_batch_is_deployed(): void
    {
        $office = $this->makeOffice();
        $service = $this->makeService($office);
        $manager = new QueueDeploymentManager();

        (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ]);
        $manager->deploy($office);

        try {
            (new QueueTicketCreator())->create([
                'office_id' => $office->id,
                'service_id' => $service->id,
                'priority' => QueueTicket::PRIORITY_REGULAR,
            ]);
            $this->fail('Expected a ValidationException once the batch is deployed.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('closed', $e->errors()['office_id'][0]);
        }
    }

    public function test_max_active_tickets_per_student_is_enforced_on_the_web_route(): void
    {
        $office = $this->makeOffice();
        $service = $this->makeService($office);
        $course = $this->makeCourse(['office_id' => $office->id]);
        $student = $this->makeUser(User::ROLE_STUDENT, ['course' => 'BSIT']);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('admin.queues.store'), [
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $student->id,
            'course_id' => $course->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])->assertRedirect(route('admin.queues.index'));

        $this->actingAs($admin)->post(route('admin.queues.store'), [
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $student->id,
            'course_id' => $course->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])->assertRedirect()->assertSessionHasErrors('user_id');
    }

    public function test_max_active_tickets_per_student_is_enforced_on_the_api_route(): void
    {
        $office = $this->makeOffice(['code' => 'CASH']);
        $service = $this->makeService($office);
        $course = $this->makeCourse(['office_id' => $office->id]);
        $student = $this->makeUser(User::ROLE_STUDENT, ['course' => 'BSIT']);

        $payload = [
            'office_id' => $office->id,
            'service_id' => $service->id,
            'course_id' => $course->id,
            'purpose' => 'First transaction',
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ];

        // First ticket from the phone is accepted.
        $this->actingAs($student, 'sanctum')
            ->postJson(route('api.v1.tickets.store'), $payload)
            ->assertCreated();

        // The second is not. This is the rule the Flutter client depends on, and
        // the one that used to exist only on the Blade route.
        $response = $this->actingAs($student, 'sanctum')
            ->postJson(route('api.v1.tickets.store'), $payload)
            ->assertStatus(422);

        $this->assertStringContainsString(
            'already have an active queue ticket',
            $response->json('errors.office_id.0')
        );

        $this->assertSame(
            1,
            QueueTicket::where('user_id', $student->id)->count(),
            'The refused request must not have created a second ticket.'
        );
    }

    public function test_the_api_active_ticket_limit_frees_up_once_the_ticket_is_cancelled(): void
    {
        $office = $this->makeOffice(['code' => 'CASH']);
        $service = $this->makeService($office);
        $course = $this->makeCourse(['office_id' => $office->id]);
        $student = $this->makeUser(User::ROLE_STUDENT, ['course' => 'BSIT']);

        $payload = [
            'office_id' => $office->id,
            'service_id' => $service->id,
            'course_id' => $course->id,
            'purpose' => 'First transaction',
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ];

        $ticketId = $this->actingAs($student, 'sanctum')
            ->postJson(route('api.v1.tickets.store'), $payload)
            ->assertCreated()
            ->json('ticket.id');

        $this->actingAs($student, 'sanctum')
            ->postJson(route('api.v1.tickets.cancel', $ticketId))
            ->assertOk();

        // A cancelled ticket is no longer active, so the student may queue again.
        $this->actingAs($student, 'sanctum')
            ->postJson(route('api.v1.tickets.store'), $payload)
            ->assertCreated();
    }

    public function test_staff_issuing_a_ticket_for_a_student_is_not_capped_by_the_student_limit(): void
    {
        $office = $this->makeOffice(['code' => 'CASH']);
        $service = $this->makeService($office);
        $course = $this->makeCourse(['office_id' => $office->id]);
        $student = $this->makeUser(User::ROLE_STUDENT, ['course' => 'BSIT']);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);

        $payload = [
            'office_id' => $office->id,
            'service_id' => $service->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'purpose' => 'Handed out at the counter',
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ];

        // Issuing a replacement number at the counter is a legitimate operator
        // action, so the student-facing cap must not block it.
        $this->actingAs($staff, 'sanctum')
            ->postJson(route('api.v1.tickets.store'), $payload)
            ->assertCreated();

        $this->actingAs($staff, 'sanctum')
            ->postJson(route('api.v1.tickets.store'), $payload)
            ->assertCreated();

        $this->assertSame(2, QueueTicket::where('user_id', $student->id)->count());
    }
}
