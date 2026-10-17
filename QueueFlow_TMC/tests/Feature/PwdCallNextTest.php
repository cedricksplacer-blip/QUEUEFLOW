<?php

namespace Tests\Feature;

use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\QueueCounter;
use App\Services\QueueDeploymentManager;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * Per-PWD "Call Next" in the staff console.
 *
 * Every office's Students in Line list offers a dedicated Call Next button
 * beside each waiting ticket with verified PWD evidence. Pressing it calls
 * exactly that ticket through the shared QueueCounter, so the Blade console
 * and the Flutter app (which reads the same rows) can never disagree.
 */
class PwdCallNextTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateLocalStorage();
    }

    private function makeStaffedOffice(array $officeAttrs = []): array
    {
        $office = $this->makeOffice(array_merge(['code' => 'CASH'], $officeAttrs));
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);

        return [$office, $staff];
    }

    private function makePwdTicket($office, $service, array $attrs = []): QueueTicket
    {
        return (new QueueTicketCreator())->create(array_merge([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ], $attrs))['ticket'];
    }

    private function makeRegularTicket($office, $service, array $attrs = []): QueueTicket
    {
        return (new QueueTicketCreator())->create(array_merge([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ], $attrs))['ticket'];
    }

    public function test_staff_calls_a_verified_pwd_ticket_ahead_of_regular(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $regular = $this->makeRegularTicket($office, $service);
        $pwd = $this->makePwdTicket($office, $service);

        $this->actingAs($staff)
            ->post(route('staff.queues.call-next'), ['ticket_id' => $pwd->id])
            ->assertRedirect()
            ->assertSessionHas('status', "PWD priority — Now serving {$pwd->queue_number}.");

        $this->assertSame(QueueTicket::STATUS_SERVING, $pwd->refresh()->status);
        $this->assertSame($staff->id, $pwd->refresh()->served_by);
        $this->assertSame(QueueTicket::STATUS_WAITING, $regular->refresh()->status);
    }

    public function test_only_verified_pwd_tickets_get_the_button(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $regular = $this->makeRegularTicket($office, $service);
        $pwd = $this->makePwdTicket($office, $service);
        // PWD priority but no proof on file: never qualifies.
        $unverified = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_PWD,
        ], false)['ticket'];

        $response = $this->actingAs($staff)->get(route('staff.dashboard'))->assertOk();

        $response->assertSee('name="ticket_id" value="'.$pwd->id.'"', false);
        $response->assertDontSee('name="ticket_id" value="'.$regular->id.'"', false);
        $response->assertDontSee('name="ticket_id" value="'.$unverified->id.'"', false);
    }

    public function test_pwd_without_evidence_is_refused(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $unverified = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_PWD,
        ], false)['ticket'];

        $this->actingAs($staff)
            ->post(route('staff.queues.call-next'), ['ticket_id' => $unverified->id])
            ->assertRedirect()
            ->assertSessionHas('status', "Ticket {$unverified->queue_number} has no verified PWD evidence.");

        $this->assertSame(QueueTicket::STATUS_WAITING, $unverified->refresh()->status);
    }

    public function test_pwd_with_a_missing_proof_file_is_unverified(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $pwd = $this->makePwdTicket($office, $service);

        // The path is recorded but the file is gone: not trusted.
        Storage::disk('local')->delete($pwd->priority_evidence);
        $this->assertFalse($pwd->refresh()->hasVerifiedEvidence());

        $response = $this->actingAs($staff)->get(route('staff.dashboard'))->assertOk();
        $response->assertDontSee('name="ticket_id" value="'.$pwd->id.'"', false);

        $this->actingAs($staff)
            ->post(route('staff.queues.call-next'), ['ticket_id' => $pwd->id])
            ->assertRedirect()
            ->assertSessionHas('status', "Ticket {$pwd->queue_number} has no verified PWD evidence.");

        $this->assertSame(QueueTicket::STATUS_WAITING, $pwd->refresh()->status);
    }

    public function test_staff_cannot_call_another_offices_ticket(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $otherOffice = $this->makeOffice(['name' => 'Admissions', 'code' => 'ADM']);
        $foreign = $this->makePwdTicket($otherOffice, $this->makeService($otherOffice));

        $this->actingAs($staff)
            ->post(route('staff.queues.call-next'), ['ticket_id' => $foreign->id])
            ->assertForbidden();

        $this->assertSame(QueueTicket::STATUS_WAITING, $foreign->refresh()->status);
    }

    public function test_non_waiting_tickets_cannot_be_called(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $served = $this->makePwdTicket($office, $service);
        $served->update(['status' => QueueTicket::STATUS_SERVED, 'served_at' => now()]);

        $cancelled = $this->makePwdTicket($office, $service);
        $cancelled->update(['status' => QueueTicket::STATUS_CANCELLED]);

        foreach ([$served, $cancelled] as $ticket) {
            $this->actingAs($staff)
                ->post(route('staff.queues.call-next'), ['ticket_id' => $ticket->id])
                ->assertRedirect()
                ->assertSessionHas('status', "Ticket {$ticket->queue_number} is not waiting any more.");
        }

        $this->assertSame(QueueTicket::STATUS_SERVED, $served->refresh()->status);
        $this->assertSame(QueueTicket::STATUS_CANCELLED, $cancelled->refresh()->status);
    }

    public function test_calling_while_serving_is_refused_and_calling_twice_is_safe(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $first = $this->makePwdTicket($office, $service);
        $second = $this->makePwdTicket($office, $service);

        $this->actingAs($staff)
            ->post(route('staff.queues.call-next'), ['ticket_id' => $first->id])
            ->assertRedirect();

        // A second call while the window is busy refuses; nothing moves.
        $this->actingAs($staff)
            ->post(route('staff.queues.call-next'), ['ticket_id' => $second->id])
            ->assertRedirect()
            ->assertSessionHas('status', "{$office->name} already has a ticket being served.");

        $this->assertSame(QueueTicket::STATUS_SERVING, $first->refresh()->status);
        $this->assertSame(QueueTicket::STATUS_WAITING, $second->refresh()->status);

        // Re-calling the ticket already at the window is refused the same way.
        $this->actingAs($staff)
            ->post(route('staff.queues.call-next'), ['ticket_id' => $first->id])
            ->assertRedirect();

        $this->assertSame(QueueTicket::STATUS_SERVING, $first->refresh()->status);
    }

    public function test_dashboard_shows_the_called_ticket_serving(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $pwd = $this->makePwdTicket($office, $service);

        $this->actingAs($staff)->post(route('staff.queues.call-next'), ['ticket_id' => $pwd->id]);

        $response = $this->actingAs($staff)->get(route('staff.dashboard'))->assertOk();
        $response->assertSee($pwd->queue_number);
        $response->assertSee('Now Serving');
        // No longer waiting, so no per-row button for it.
        $response->assertDontSee('name="ticket_id" value="'.$pwd->id.'"', false);
    }

    public function test_called_ticket_is_visible_through_the_api_board(): void
    {
        // The Flutter app reads the same rows through QueueCounter, so the
        // number the console called is the number the phone sees serving.
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $pwd = $this->makePwdTicket($office, $service);

        (new QueueCounter())->callNext($office, $staff, $pwd);

        $nowServing = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVING)
            ->firstOrFail();

        $this->assertSame($pwd->id, $nowServing->id);
        $this->assertSame($pwd->queue_number, $nowServing->queue_number);
    }

    public function test_automatic_call_next_still_prefers_pwd(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();
        $service = $this->makeService($office);

        $this->makeRegularTicket($office, $service);
        $pwd = $this->makePwdTicket($office, $service);

        $this->actingAs($staff)
            ->post(route('staff.queues.call-next'))
            ->assertRedirect()
            ->assertSessionHas('status', "PWD priority — Now serving {$pwd->queue_number}.");

        $this->assertSame(QueueTicket::STATUS_SERVING, $pwd->refresh()->status);
    }
}
