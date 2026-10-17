<?php

namespace Tests\Feature;

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
 * Deploy / Complete-batch integration with the Flutter student app.
 *
 * The staff console's two batch buttons have to leave the backend in a
 * state the phone can actually use: after "Deploy 100 Slots" a student
 * must get a real ticket + receipt (not a refusal), and after
 * "Complete & Start New Batch" the next ticket must land in the NEW
 * batch with fresh numbering while the old batch's history stays intact.
 */
class BatchTicketFlowTest extends TestCase
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
            'student_id' => '24-019083',
            'course' => 'BSIT',
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

    /**
     * Test A — first batch: Deploy 100 Slots opens intake, the student gets
     * a real queue number and a receipt payload.
     */
    public function test_deploy_from_nothing_opens_intake_and_issues_a_receipt(): void
    {
        $office = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $service = $this->makeService($office);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);
        $student = $this->makeStudent();

        $deploy = $this->withToken($this->tokenFor($staff, $staff->email))
            ->postJson("/api/v1/offices/{$office->id}/deploy")
            ->assertOk();

        $deploy->assertJsonPath('batch.status', QueueDeployment::STATUS_OPEN);
        $deploy->assertJsonPath('batch.batch_no', 1);
        $deploy->assertJsonPath('batch.max_slots', 100);

        $create = $this->withToken($this->tokenFor($student, '24-019083'))
            ->postJson('/api/v1/tickets', [
                'office_id' => $office->id,
                'service_id' => $service->id,
                'purpose' => 'Tuition payment',
            ])
            ->assertCreated();

        // The receipt: real queue number, batch link, position.
        $create->assertJsonPath('ticket.queue_number', 'CASH-001')
            ->assertJsonPath('ticket.status', QueueTicket::STATUS_WAITING)
            ->assertJsonStructure([
                'message',
                'ticket' => ['id', 'queue_number', 'status', 'office' => ['id', 'code'], 'deployment' => ['id', 'batch_no']],
                'progress' => ['position', 'people_ahead'],
            ]);

        $ticket = QueueTicket::where('queue_number', 'CASH-001')->firstOrFail();
        $this->assertSame($office->id, $ticket->office_id);
        $this->assertSame(1, $ticket->deployment->batch_no);

        // The office payload the phone polls must read as accepting.
        $payload = $this->withToken($this->tokenFor($student, '24-019083', 'phone'))
            ->getJson('/api/v1/offices')
            ->assertOk()
            ->json('offices');

        $cash = collect($payload)->firstWhere('code', 'CASH');
        $this->assertTrue($cash['accepting']);
        $this->assertSame('open', $cash['queue_status']);
        $this->assertSame(1, $cash['used_slots']);
        $this->assertSame(99, $cash['available_slots']);
    }

    /**
     * Deploying an intake batch that already exists still moves it to
     * serving (intake closes) — the pinned counter-console semantics.
     */
    public function test_deploying_an_open_batch_still_starts_serving(): void
    {
        $office = $this->makeOffice(['code' => 'CASH']);
        $service = $this->makeService($office);

        (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ]);

        (new QueueDeploymentManager())->deploy($office);

        $this->assertSame(
            QueueDeployment::STATUS_DEPLOYED,
            QueueDeployment::activeFor($office)->status
        );

        try {
            (new QueueTicketCreator())->create([
                'office_id' => $office->id,
                'service_id' => $service->id,
                'priority' => QueueTicket::PRIORITY_REGULAR,
            ]);
            $this->fail('Expected intake to be closed once the batch is deployed.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('closed', $e->errors()['office_id'][0]);
        }
    }

    /**
     * Test B — new batch: Complete & Start New Batch completes the old one,
     * opens a fresh 100-slot batch, and the next ticket lands there with
     * restarted numbering.
     */
    public function test_complete_and_restart_hands_the_next_ticket_to_a_fresh_batch(): void
    {
        $office = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $service = $this->makeService($office);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);
        $firstStudent = $this->makeStudent();

        $manager = new QueueDeploymentManager();
        $manager->start($office);

        $firstTicket = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $firstStudent->id,
            'purpose' => 'Tuition payment',
        ])['ticket'];

        $manager->deploy($office);

        $complete = $this->withToken($this->tokenFor($staff, $staff->email))
            ->postJson("/api/v1/offices/{$office->id}/complete-batch")
            ->assertOk();

        $complete->assertJsonPath('batch.status', QueueDeployment::STATUS_OPEN);
        $complete->assertJsonPath('batch.batch_no', 2);
        $complete->assertJsonPath('batch.max_slots', 100);
        $complete->assertJsonPath('batch.submitted_count', 0);

        // The old batch is completed and its history is intact.
        $this->assertSame(
            QueueDeployment::STATUS_COMPLETED,
            QueueDeployment::where('office_id', $office->id)->where('batch_no', 1)->value('status')
        );
        $this->assertDatabaseHas('queue_tickets', [
            'id' => $firstTicket->id,
            'queue_number' => $firstTicket->queue_number,
            'office_id' => $office->id,
        ]);

        // A new student gets a real ticket in batch #2, numbering restarted.
        $secondStudent = $this->makeStudent(['student_id' => '24-019084', 'email' => 's2@tmc.test']);

        $create = $this->withToken($this->tokenFor($secondStudent, '24-019084'))
            ->postJson('/api/v1/tickets', [
                'office_id' => $office->id,
                'service_id' => $service->id,
                'purpose' => 'Document request',
            ])
            ->assertCreated();

        $create->assertJsonPath('ticket.queue_number', 'CASH-001')
            ->assertJsonPath('ticket.deployment.batch_no', 2);

        // No duplicate numbers inside the new batch.
        $this->assertSame(1, QueueTicket::where('deployment_id', $create->json('ticket.deployment.id'))->count());

        $payload = $this->withToken($this->tokenFor($secondStudent, '24-019084', 'phone'))
            ->getJson('/api/v1/offices')
            ->assertOk()
            ->json('offices');

        $cash = collect($payload)->firstWhere('code', 'CASH');
        $this->assertTrue($cash['accepting']);
        $this->assertSame(2, $cash['batch_no']);
    }

    /**
     * Test C — batch full: 100 tickets consume the batch, the 101st is
     * refused with a slots message, and the batch flips to full.
     */
    public function test_a_full_100_slot_batch_refuses_further_tickets(): void
    {
        $office = $this->makeOffice(['code' => 'CASH']);
        $service = $this->makeService($office);
        $creator = new QueueTicketCreator();

        (new QueueDeploymentManager())->start($office);

        for ($i = 0; $i < 100; $i++) {
            $creator->create([
                'office_id' => $office->id,
                'service_id' => $service->id,
                'priority' => QueueTicket::PRIORITY_REGULAR,
            ]);
        }

        $batch = QueueDeployment::openFor($office);
        $this->assertSame(100, $batch->submittedCount());
        $this->assertSame('CASH-100', QueueTicket::where('deployment_id', $batch->id)->orderByDesc('id')->value('queue_number'));

        try {
            $creator->create([
                'office_id' => $office->id,
                'service_id' => $service->id,
                'priority' => QueueTicket::PRIORITY_REGULAR,
            ]);
            $this->fail('Expected the 101st ticket to be refused.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('maximum 100 slots', $e->errors()['office_id'][0]);
        }

        $this->assertSame(QueueDeployment::STATUS_FULL, $batch->refresh()->status);
        $this->assertSame(100, QueueTicket::where('deployment_id', $batch->id)->count());

        // The phone reads the batch as full with zero slots left.
        $student = $this->makeStudent();
        $payload = $this->withToken($this->tokenFor($student, '24-019083'))
            ->getJson('/api/v1/offices')
            ->assertOk()
            ->json('offices');

        $cash = collect($payload)->firstWhere('code', 'CASH');
        $this->assertSame(0, $cash['available_slots']);
        $this->assertFalse($cash['accepting']);
    }

    /**
     * Test D (backend half) — an offline office refuses with the offline
     * sentence, which is what the phone renders instead of inventing a
     * ticket.
     */
    public function test_an_offline_office_refuses_with_the_offline_sentence(): void
    {
        $office = $this->makeOffice(['code' => 'CASH', 'status' => Office::STATUS_OFFLINE]);
        $service = $this->makeService($office);
        $student = $this->makeStudent();

        $this->withToken($this->tokenFor($student, '24-019083'))
            ->postJson('/api/v1/tickets', [
                'office_id' => $office->id,
                'service_id' => $service->id,
                'purpose' => 'Payment',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', \App\Services\QueueTicketCreator::OFFLINE_MESSAGE);

        $this->assertSame(0, QueueTicket::count());
    }

    /**
     * Test E — data consistency: each ticket lands in the correct office
     * queue, other offices are untouched, and batch history survives.
     */
    public function test_tickets_land_in_the_correct_office_queue_only(): void
    {
        $cashier = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $admissions = $this->makeOffice(['name' => 'Admissions', 'code' => 'ADM']);
        $cashService = $this->makeService($cashier);
        $admService = $this->makeService($admissions);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $cashier->id]);
        $student = $this->makeStudent();

        $manager = new QueueDeploymentManager();
        $manager->deploy($cashier);

        $ticket = $this->withToken($this->tokenFor($student, '24-019083'))
            ->postJson('/api/v1/tickets', [
                'office_id' => $cashier->id,
                'service_id' => $cashService->id,
                'purpose' => 'Payment',
            ])
            ->assertCreated()
            ->json('ticket');

        // Correct staff queue shows it; the other office shows nothing.
        $queue = $this->withToken($this->tokenFor($staff, $staff->email))
            ->getJson("/api/v1/queues/{$cashier->id}")
            ->assertOk();

        $this->assertSame($ticket['queue_number'], $queue->json('waiting.0.queue_number'));

        $this->assertSame(0, QueueTicket::where('office_id', $admissions->id)->count());

        // Staff web console renders the same ticket.
        $this->actingAs($staff)
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee($ticket['queue_number']);

        // History survives a complete + restart.
        $manager->deploy($cashier);
        $manager->completeAndRestart($cashier);

        $this->assertDatabaseHas('queue_tickets', [
            'id' => $ticket['id'],
            'queue_number' => $ticket['queue_number'],
            'office_id' => $cashier->id,
        ]);
    }
}
