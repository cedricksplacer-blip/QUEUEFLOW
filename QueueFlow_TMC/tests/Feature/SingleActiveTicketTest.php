<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\QueueTicket;
use App\Models\Setting;
use App\Models\User;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * One active queue ticket per student, across all offices.
 *
 * A student holding a waiting or serving ticket anywhere — Registrar,
 * Cashier, or any other office, under any course — is refused a second one
 * with the blocking ticket attached to the refusal. Served, cancelled and
 * terminal No-Show tickets free the slot; serving (called) does not. The
 * creator-level check runs inside the issue transaction, so two
 * simultaneous requests cannot both win.
 */
class SingleActiveTicketTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateLocalStorage();
    }

    private function makeStudent(array $attrs = []): User
    {
        return $this->makeUser(User::ROLE_STUDENT, array_merge([
            'name' => 'Single Ticket Student',
            'student_id' => '2026-'.uniqid(),
            'course' => 'BSIT',
        ], $attrs));
    }

    private function makeStaff(Office $office): User
    {
        return $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);
    }

    /** Offices + services + one student ready to queue. */
    private function seedOffices(): array
    {
        $registrar = $this->makeOffice(['code' => 'REG-BSIT', 'name' => 'Registrar - BSIT']);
        $cashier = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);

        return [
            'registrar' => $registrar,
            'cashier' => $cashier,
            'regService' => $this->makeService($registrar),
            'cashService' => $this->makeService($cashier),
            'course' => $this->makeCourse(['code' => 'BSIT', 'office_id' => $registrar->id]),
            'student' => $this->makeStudent(),
        ];
    }

    private function ticketPayload(Office $office, $service, $course): array
    {
        return [
            'office_id' => $office->id,
            'service_id' => $service->id,
            'course_id' => $course->id,
            'purpose' => 'Single ticket probe',
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ];
    }

    public function test_second_ticket_at_another_office_is_refused_with_details(): void
    {
        $seed = $this->seedOffices();
        $student = $seed['student'];

        $firstId = $this->actingAs($student, 'sanctum')
            ->postJson(
                route('api.v1.tickets.store'),
                $this->ticketPayload($seed['registrar'], $seed['regService'], $seed['course'])
            )
            ->assertCreated()
            ->json('ticket.id');

        $response = $this->actingAs($student, 'sanctum')
            ->postJson(
                route('api.v1.tickets.store'),
                $this->ticketPayload($seed['cashier'], $seed['cashService'], $seed['course'])
            )
            ->assertStatus(422);

        // Same sentence the client already reads from the errors bag.
        $this->assertStringContainsString(
            'already have an active queue ticket',
            $response->json('errors.office_id.0')
        );
        $this->assertStringContainsString(
            'already have an active queue ticket',
            $response->json('message')
        );

        // Plus the blocking ticket, so the app can name it without a re-read.
        $this->assertSame($firstId, $response->json('active_ticket.id'));
        $this->assertSame('REG-BSIT', $response->json('active_ticket.office.code'));

        $this->assertSame(
            1,
            QueueTicket::where('user_id', $student->id)->count(),
            'The refused request must not have created a second ticket.'
        );
    }

    public function test_second_ticket_at_same_office_is_refused_too(): void
    {
        $seed = $this->seedOffices();
        $student = $seed['student'];

        $this->actingAs($student, 'sanctum')
            ->postJson(
                route('api.v1.tickets.store'),
                $this->ticketPayload($seed['cashier'], $seed['cashService'], $seed['course'])
            )
            ->assertCreated();

        $this->actingAs($student, 'sanctum')
            ->postJson(
                route('api.v1.tickets.store'),
                $this->ticketPayload($seed['cashier'], $seed['cashService'], $seed['course'])
            )
            ->assertStatus(422);
    }

    public function test_served_ticket_frees_the_slot(): void
    {
        $seed = $this->seedOffices();
        $student = $seed['student'];
        $staff = $this->makeStaff($seed['registrar']);

        $ticketId = $this->actingAs($student, 'sanctum')
            ->postJson(
                route('api.v1.tickets.store'),
                $this->ticketPayload($seed['registrar'], $seed['regService'], $seed['course'])
            )
            ->assertCreated()
            ->json('ticket.id');

        $this->actingAs($staff, 'sanctum')
            ->postJson(route('api.v1.queues.call-next', $seed['registrar']->id))
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->postJson(route('api.v1.queues.serve', [$seed['registrar']->id, $ticketId]))
            ->assertOk();

        $this->assertSame(
            QueueTicket::STATUS_SERVED,
            QueueTicket::whereKey($ticketId)->first()->status
        );

        // Served is finished: the student may queue again, anywhere.
        $this->actingAs($student, 'sanctum')
            ->postJson(
                route('api.v1.tickets.store'),
                $this->ticketPayload($seed['cashier'], $seed['cashService'], $seed['course'])
            )
            ->assertCreated();
    }

    public function test_called_ticket_still_blocks(): void
    {
        $seed = $this->seedOffices();
        $student = $seed['student'];
        $staff = $this->makeStaff($seed['registrar']);

        $this->actingAs($student, 'sanctum')
            ->postJson(
                route('api.v1.tickets.store'),
                $this->ticketPayload($seed['registrar'], $seed['regService'], $seed['course'])
            )
            ->assertCreated();

        $this->actingAs($staff, 'sanctum')
            ->postJson(route('api.v1.queues.call-next', $seed['registrar']->id))
            ->assertOk();

        // Called means serving, which is still active — no new ticket.
        $this->actingAs($student, 'sanctum')
            ->postJson(
                route('api.v1.tickets.store'),
                $this->ticketPayload($seed['cashier'], $seed['cashService'], $seed['course'])
            )
            ->assertStatus(422);
    }

    public function test_no_show_is_terminal_and_frees_the_slot(): void
    {
        $seed = $this->seedOffices();
        $student = $seed['student'];
        $staff = $this->makeStaff($seed['registrar']);

        $ticketId = $this->actingAs($student, 'sanctum')
            ->postJson(
                route('api.v1.tickets.store'),
                $this->ticketPayload($seed['registrar'], $seed['regService'], $seed['course'])
            )
            ->assertCreated()
            ->json('ticket.id');

        $this->actingAs($staff, 'sanctum')
            ->postJson(route('api.v1.queues.call-next', $seed['registrar']->id))
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->postJson(route('api.v1.queues.no-show', [$seed['registrar']->id, $ticketId]))
            ->assertOk()
            ->assertJsonPath('ticket.status', QueueTicket::STATUS_NO_SHOW);

        $this->assertSame(
            QueueTicket::STATUS_NO_SHOW,
            QueueTicket::whereKey($ticketId)->first()->status
        );

        // Terminal No-Show frees the per-student slot: a fresh ticket is allowed.
        $this->actingAs($student, 'sanctum')
            ->postJson(
                route('api.v1.tickets.store'),
                $this->ticketPayload($seed['cashier'], $seed['cashService'], $seed['course'])
            )
            ->assertCreated();
    }

    public function test_creator_level_guard_refuses_atomically(): void
    {
        $office = $this->makeOffice(['code' => 'ADM', 'name' => 'Admissions']);
        $service = $this->makeService($office);
        $student = $this->makeStudent();

        $base = [
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $student->id,
            'purpose' => 'Atomic probe',
            'enforce_student_limit' => true,
        ];

        (new QueueTicketCreator())->create($base);

        // The second create through the same entry point is refused inside
        // the issue transaction — the race the controller pre-check cannot
        // see.
        try {
            (new QueueTicketCreator())->create($base);
            $this->fail('Expected a ValidationException for the second active ticket.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString(
                'already have an active queue ticket',
                $e->errors()['office_id'][0]
            );
        }

        $this->assertSame(1, QueueTicket::where('user_id', $student->id)->count());
    }

    public function test_creator_level_guard_is_opt_in_for_counter_flows(): void
    {
        $office = $this->makeOffice(['code' => 'ADM', 'name' => 'Admissions']);
        $service = $this->makeService($office);
        $student = $this->makeStudent();

        $base = [
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $student->id,
            'purpose' => 'Counter probe',
        ];

        // Without the flag (staff/admin counter semantics) nothing is capped.
        (new QueueTicketCreator())->create($base);
        (new QueueTicketCreator())->create($base);

        $this->assertSame(2, QueueTicket::where('user_id', $student->id)->count());
    }

    public function test_limit_follows_the_configured_setting(): void
    {
        Setting::set('max_active_tickets_per_student', '2');

        $seed = $this->seedOffices();
        $student = $seed['student'];

        $this->actingAs($student, 'sanctum')
            ->postJson(
                route('api.v1.tickets.store'),
                $this->ticketPayload($seed['registrar'], $seed['regService'], $seed['course'])
            )
            ->assertCreated();

        $this->actingAs($student, 'sanctum')
            ->postJson(
                route('api.v1.tickets.store'),
                $this->ticketPayload($seed['cashier'], $seed['cashService'], $seed['course'])
            )
            ->assertCreated();

        $third = $this->makeOffice(['code' => 'ADM', 'name' => 'Admissions']);
        $response = $this->actingAs($student, 'sanctum')
            ->postJson(route('api.v1.tickets.store'), [
                'office_id' => $third->id,
                'service_id' => $this->makeService($third)->id,
                'course_id' => $seed['course']->id,
                'purpose' => 'Third probe',
                'priority' => QueueTicket::PRIORITY_REGULAR,
            ])
            ->assertStatus(422);

        $this->assertStringContainsString(
            'maximum of 2 active tickets',
            $response->json('message')
        );
    }
}
