<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\StaffNotification;
use App\Models\User;
use App\Services\QueueCounter;
use App\Services\QueueDeploymentManager;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

class QueueTimeoutTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateLocalStorage();
    }

    private function staffedOffice(string $code = 'REG-BSIT'): array
    {
        $office = $this->makeOffice(['code' => $code, 'name' => 'Office '.$code]);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);

        return [$office, $staff];
    }

    private function deploy(Office $office): void
    {
        $manager = new QueueDeploymentManager();

        if (! QueueDeployment::activeFor($office)) {
            $manager->start($office);
        }

        $manager->deploy($office);
    }

    private function issue(Office $office): QueueTicket
    {
        $service = $this->makeService($office);

        return (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])['ticket'];
    }

    public function test_timeout_defaults_to_five_and_validates(): void
    {
        [$office] = $this->staffedOffice();

        $this->assertSame(5, $office->callTimeoutMinutes());

        $office->setCallTimeoutMinutes(10);
        $this->assertSame(10, $office->fresh()->callTimeoutMinutes());

        foreach ([0, -1, 61, 999] as $bad) {
            try {
                $office->setCallTimeoutMinutes($bad);
                $this->fail("Invalid timeout {$bad} was accepted.");
            } catch (\Illuminate\Validation\ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_timeouts_are_per_office(): void
    {
        [$reg] = $this->staffedOffice('REG-BSIT');
        [$cash] = $this->staffedOffice('CASH');

        $reg->setCallTimeoutMinutes(3);
        $cash->setCallTimeoutMinutes(10);

        $this->assertSame(3, $reg->fresh()->callTimeoutMinutes());
        $this->assertSame(10, $cash->fresh()->callTimeoutMinutes());
    }

    public function test_call_uses_saved_timeout_when_no_duration_given(): void
    {
        [$office, $staff] = $this->staffedOffice();
        $ticket = $this->issue($office);
        $this->deploy($office);
        $office->setCallTimeoutMinutes(10);

        // Web: omit duration, saved 10 applies.
        $this->actingAs($staff)->post(route('staff.queues.call-next'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Now serving'));

        $serving = QueueTicket::where('office_id', $office->id)->where('status', 'serving')->firstOrFail();
        $this->assertSame($ticket->queue_number, $serving->queue_number);
        $this->assertSame(10, $serving->call_duration_minutes);
        $this->assertNotNull($serving->expires_at);
    }

    public function test_arrival_stops_timer_and_blocks_expiry(): void
    {
        [$office, $staff] = $this->staffedOffice();
        $ticket = $this->issue($office);
        $this->deploy($office);

        $this->actingAs($staff)->post(route('staff.queues.call-next'), ['call_duration_minutes' => 5]);

        $ticket = QueueTicket::where('office_id', $office->id)->where('status', 'serving')->firstOrFail();

        $this->actingAs($staff)->post(route('staff.queues.arrive', $ticket))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'arrived'));

        $ticket->refresh();
        $this->assertNotNull($ticket->arrived_at);
        $this->assertNull($ticket->expires_at);
        $this->assertSame('serving', $ticket->status);
        $this->assertFalse($ticket->isExpired());
        $this->assertSame(0, $ticket->remainingSeconds());
        $this->assertTrue($ticket->isArrived());

        // Force the old deadline into the past: arrived tickets never expire.
        $ticket->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($ticket->fresh()->isExpired());

        // Arrival is idempotent.
        $this->actingAs($staff)->post(route('staff.queues.arrive', $ticket))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'arrived'));

        // Still servable after arrival.
        $this->actingAs($staff)->post(route('staff.queues.served', $ticket))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'served'));
        $this->assertSame('served', $ticket->fresh()->status);
    }

    public function test_no_show_refused_after_arrival(): void
    {
        [$office, $staff] = $this->staffedOffice();
        $this->issue($office);
        $this->deploy($office);

        $this->actingAs($staff)->post(route('staff.queues.call-next'), ['call_duration_minutes' => 5]);
        $ticket = QueueTicket::where('office_id', $office->id)->where('status', 'serving')->firstOrFail();
        $this->actingAs($staff)->post(route('staff.queues.arrive', $ticket));

        $this->actingAs($staff)->post(route('staff.queues.no-show', $ticket))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'already arrived') || str_contains($m, 'no-show'));

        $this->assertSame('serving', $ticket->fresh()->status);
    }

    public function test_expired_ticket_blocks_until_recalled_or_no_show(): void
    {
        [$office, $staff] = $this->staffedOffice();
        $first = $this->issue($office);
        $second = $this->issue($office);
        $this->deploy($office);

        $this->actingAs($staff)->post(route('staff.queues.call-next'), ['call_duration_minutes' => 5]);
        $this->assertSame($first->queue_number, QueueTicket::where('office_id', $office->id)->where('status', 'serving')->firstOrFail()->queue_number);
        $serving = QueueTicket::where('office_id', $office->id)->where('status', 'serving')->firstOrFail();
        $serving->update(['expires_at' => now()->subMinute()]);
        $this->assertTrue($serving->fresh()->isExpired());

        // Arrival after expiry is refused.
        $this->actingAs($staff)->post(route('staff.queues.arrive', $serving))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'expired') || str_contains($m, 'no-show'));

        // An expired call still occupies the window: next call is blocked.
        $this->actingAs($staff)->post(route('staff.queues.call-next'), ['call_duration_minutes' => 5])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'already has a ticket being served'));
        $this->assertSame(
            $serving->queue_number,
            QueueTicket::where('office_id', $office->id)->where('status', 'serving')->firstOrFail()->queue_number
        );

        // Call Again re-arms the SAME ticket with a fresh deadline.
        $this->actingAs($staff)->post(route('staff.queues.recall', $serving), ['call_duration_minutes' => 5])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'called again'));
        $serving->refresh();
        $this->assertSame('serving', $serving->status);
        $this->assertFalse($serving->isExpired());
        $this->assertSame(1, (int) $serving->recall_count);
        $this->assertNotNull($serving->last_recalled_at);

        // No-Show terminals the ticket and frees the window for the next student.
        $serving->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($staff)->post(route('staff.queues.no-show', $serving))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'No-Show'));
        $this->assertSame(QueueTicket::STATUS_NO_SHOW, $serving->fresh()->status);
        $this->assertNotNull($serving->fresh()->no_show_at);

        $this->actingAs($staff)->post(route('staff.queues.call-next'), ['call_duration_minutes' => 5])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Now serving'));
        $this->assertSame(
            1,
            QueueTicket::where('office_id', $office->id)->where('status', 'serving')->whereDate('created_at', today())->count()
        );
    }

    public function test_cancel_records_reason_and_log(): void
    {
        [$office, $staff] = $this->staffedOffice();
        $this->issue($office);
        $this->deploy($office);

        $this->actingAs($staff)->post(route('staff.queues.call-next'), ['call_duration_minutes' => 5]);
        $ticket = QueueTicket::where('office_id', $office->id)->where('status', 'serving')->firstOrFail();

        $this->actingAs($staff)->post(route('staff.queues.cancel', $ticket), ['cancel_reason' => 'Student did not arrive'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'cancelled'));

        $ticket->refresh();
        $this->assertSame('cancelled', $ticket->status);
        $this->assertSame('Student did not arrive', $ticket->cancel_reason);
        $this->assertNotNull($ticket->cancelled_at);
        $this->assertNull($ticket->expires_at);

        $alert = StaffNotification::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('Student did not arrive', $alert->reason);
        $this->assertSame($office->id, $alert->office_id);
        $this->assertSame($ticket->queue_number, $alert->queue_number);
        $this->assertSame($staff->id, $alert->triggered_by);

        // Duplicate cancel refused, no second alert.
        $this->actingAs($staff)->post(route('staff.queues.cancel', $ticket))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'already'));
        $this->assertSame(1, StaffNotification::where('ticket_id', $ticket->id)->count());
    }

    public function test_api_timeout_arrive_cancel_expire(): void
    {
        [$office, $staff] = $this->staffedOffice();
        $ticket = $this->issue($office);
        $this->deploy($office);

        // Timeout GET default + PUT set.
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/queues/'.$office->id.'/timeout')
            ->assertOk()->assertJson(['call_timeout_minutes' => 5]);

        $this->actingAs($staff, 'sanctum')->putJson('/api/v1/queues/'.$office->id.'/timeout', ['call_duration_minutes' => 3])
            ->assertOk()->assertJson(['call_timeout_minutes' => 3]);

        $this->actingAs($staff, 'sanctum')->putJson('/api/v1/queues/'.$office->id.'/timeout', ['call_duration_minutes' => 0])
            ->assertStatus(422);

        $call = $this->actingAs($staff, 'sanctum')->postJson('/api/v1/queues/'.$office->id.'/call-next', []);
        $call->assertOk();
        $this->assertSame(3, $call->json('ticket.call_duration_minutes'));

        $ticketId = $call->json('ticket.id');

        // Arrive stops the timer.
        $this->actingAs($staff, 'sanctum')->postJson("/api/v1/queues/{$office->id}/tickets/{$ticketId}/arrive")
            ->assertOk()->assertJsonPath('ticket.is_arrived', true);

        // Second arrive is idempotent.
        $this->actingAs($staff, 'sanctum')->postJson("/api/v1/queues/{$office->id}/tickets/{$ticketId}/arrive")
            ->assertOk();

        // Cancel after arrival still terminal with reason.
        $this->actingAs($staff, 'sanctum')->postJson("/api/v1/queues/{$office->id}/tickets/{$ticketId}/cancel", ['cancel_reason' => 'Duplicate'])
            ->assertOk();
        $this->assertSame('cancelled', QueueTicket::find($ticketId)->status);

        // Duplicate cancel refused.
        $this->actingAs($staff, 'sanctum')->postJson("/api/v1/queues/{$office->id}/tickets/{$ticketId}/cancel", [])
            ->assertStatus(422);

        // Expire sweep with nothing overdue.
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/queues/'.$office->id.'/expire')
            ->assertOk()->assertJson(['released' => 0]);
    }

    public function test_api_office_scope_enforced(): void
    {
        [$officeA, $staffA] = $this->staffedOffice('REG-BSIT');
        [$officeB] = $this->staffedOffice('CASH');
        $this->deploy($officeA);
        $this->deploy($officeB);

        $this->actingAs($staffA, 'sanctum')->getJson('/api/v1/queues/'.$officeB->id.'/timeout')
            ->assertForbidden();
    }

    public function test_scheduler_command_counts_overdue_without_releasing(): void
    {
        [$office, $staff] = $this->staffedOffice();
        $this->issue($office);
        $this->deploy($office);

        $ticket = (new QueueCounter())->callNext($office, $staff, null, 5);
        $ticket->update(['expires_at' => now()->subMinute()]);

        $this->artisan('queues:expire-overdue')->assertSuccessful();
        // Non-destructive: the expired call stays serving until staff choose
        // Call Again or No-Show, so the popup has something to act on.
        $this->assertSame('serving', $ticket->fresh()->status);
        $this->assertTrue($ticket->fresh()->isExpired());
        $this->assertSame(1, (new QueueCounter())->expireAllOverdue());
    }
}
