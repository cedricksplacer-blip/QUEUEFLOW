<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * Staff-entered cancellation reason reaches the owning student's ticket.
 *
 * When staff cancel a queue ticket with a reason, the student's own
 * ticket payload must carry that exact reason (status `cancelled`,
 * `cancel_reason`, `cancelled_at`) so the Flutter popup can display it.
 * Other students must not see the ticket at all.
 */
class StaffCancellationReasonApiTest extends TestCase
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
            'name' => 'Juan Dela Cruz',
            'student_id' => '2026-0001',
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

    public function test_staff_cancel_reason_reaches_the_owning_student_ticket(): void
    {
        $office = $this->makeOffice(['name' => 'Registrar', 'code' => 'REG-BSIT']);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);
        $service = $this->makeService($office);
        $student = $this->makeStudent();

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $student->id,
            'purpose' => 'Transcript of Records',
        ])['ticket'];

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->postJson("/api/v1/queues/{$office->id}/tickets/{$ticket->id}/cancel", [
                'cancel_reason' => 'Office is temporarily closed',
            ])
            ->assertOk();

        $this->withToken($this->tokenFor($student, '2026-0001', 'phone'))
            ->getJson("/api/v1/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('ticket.status', QueueTicket::STATUS_CANCELLED)
            ->assertJsonPath('ticket.cancel_reason', 'Office is temporarily closed');

        $payload = $this->withToken($this->tokenFor($student, '2026-0001', 'phone2'))
            ->getJson("/api/v1/tickets/{$ticket->id}")
            ->assertOk()
            ->json('ticket');

        $this->assertNotEmpty($payload['cancelled_at']);
    }

    public function test_another_student_cannot_see_someone_elses_cancelled_ticket(): void
    {
        $office = $this->makeOffice(['name' => 'Registrar', 'code' => 'REG-BSIT']);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);
        $service = $this->makeService($office);
        $student = $this->makeStudent();
        $stranger = $this->makeStudent(['student_id' => '2026-0002', 'email' => 'stranger@tmc.test']);

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $student->id,
            'purpose' => 'Transcript of Records',
        ])['ticket'];

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->postJson("/api/v1/queues/{$office->id}/tickets/{$ticket->id}/cancel", [
                'cancel_reason' => 'Office is temporarily closed',
            ])
            ->assertOk();

        $this->withToken($this->tokenFor($stranger, '2026-0002'))
            ->getJson("/api/v1/tickets/{$ticket->id}")
            ->assertNotFound();

        $this->withToken($this->tokenFor($stranger, '2026-0002', 'again'))
            ->getJson('/api/v1/tickets')
            ->assertOk()
            ->assertJsonMissing(['queue_number' => $ticket->queue_number]);
    }
}
