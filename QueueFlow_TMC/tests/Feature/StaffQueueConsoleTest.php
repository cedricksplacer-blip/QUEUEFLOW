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

class StaffQueueConsoleTest extends TestCase
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
        $office = $this->makeOffice(array_merge(['code' => 'REG-BSIT'], $officeAttrs));
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);

        return [$office, $staff];
    }

    private function deployBatch(Office $office): void
    {
        $manager = new QueueDeploymentManager();

        if (! QueueDeployment::activeFor($office)) {
            $manager->start($office);
        }

        $manager->deploy($office);
    }

    public function test_staff_dashboard_renders_their_office_batch(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();

        $service = $this->makeService($office);

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])['ticket'];

        $this->deployBatch($office);

        $this->actingAs($staff)
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee($office->name)
            ->assertSee($ticket->queue_number);
    }

    public function test_staff_without_an_assigned_office_is_forbidden(): void
    {
        $staff = $this->makeUser(User::ROLE_STAFF);

        $this->actingAs($staff)
            ->get(route('staff.dashboard'))
            ->assertForbidden();
    }

    public function test_non_staff_cannot_open_the_staff_console(): void
    {
        $student = $this->makeUser(User::ROLE_STUDENT);

        $this->actingAs($student)
            ->get(route('staff.dashboard'))
            ->assertForbidden();
    }

    public function test_staff_call_next_serves_their_offices_ticket(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();

        $service = $this->makeService($office);

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])['ticket'];

        $this->deployBatch($office);

        $this->actingAs($staff)
            ->post(route('staff.queues.call-next'))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'Now serving'));

        $serving = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVING)
            ->firstOrFail();

        $this->assertSame($ticket->queue_number, $serving->queue_number);
        $this->assertSame($staff->id, $serving->served_by);
        $this->assertNotNull($serving->called_at);
    }

    public function test_staff_call_next_is_scoped_to_their_office_only(): void
    {
        [$officeOne, $staff] = $this->makeStaffedOffice();

        $this->deployBatch($officeOne);

        $otherOffice = $this->makeOffice(['name' => 'Cashier', 'code' => 'CAS']);
        $otherService = $this->makeService($otherOffice);
        $otherTicket = (new QueueTicketCreator())->create([
            'office_id' => $otherOffice->id,
            'service_id' => $otherService->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])['ticket'];

        $this->actingAs($staff)
            ->post(route('staff.queues.call-next'))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'No waiting tickets'));

        $this->assertSame(
            QueueTicket::STATUS_WAITING,
            $otherTicket->refresh()->status
        );

        $this->assertFalse(
            QueueTicket::where('office_id', $officeOne->id)
                ->where('status', QueueTicket::STATUS_SERVING)
                ->exists()
        );
    }

    public function test_staff_mark_served_finishes_the_serving_ticket(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();

        $service = $this->makeService($office);

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])['ticket'];

        $this->deployBatch($office);

        $this->actingAs($staff)->post(route('staff.queues.call-next'));

        $this->actingAs($staff)
            ->post(route('staff.queues.served', $ticket))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'marked as served'))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'REG-BSIT-001'));

        $this->assertSame(QueueTicket::STATUS_SERVED, $ticket->refresh()->status);
        $this->assertSame(QueueDeployment::STATUS_COMPLETED, $ticket->deployment->status);

        $this->actingAs($staff)
            ->get(route('staff.dashboard'))
            ->assertSee('fully served');
    }

    public function test_staff_cannot_serve_another_offices_ticket(): void
    {
        [$otherOffice, $staff] = $this->makeStaffedOffice();

        $anotherOffice = $this->makeOffice(['name' => 'Cashier', 'code' => 'CAS']);
        $anotherService = $this->makeService($anotherOffice);

        $foreignTicket = (new QueueTicketCreator())->create([
            'office_id' => $anotherOffice->id,
            'service_id' => $anotherService->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])['ticket'];

        $this->actingAs($staff)
            ->post(route('staff.queues.served', $foreignTicket))
            ->assertForbidden();
    }

    public function test_staff_can_view_evidence_only_for_their_office(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();

        $service = $this->makeService($office);

        $ownPwd = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ])['ticket'];

        $this->actingAs($staff)
            ->get(route('staff.queues.evidence', $ownPwd))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $otherOffice = $this->makeOffice(['name' => 'Cashier', 'code' => 'CAS']);
        $otherService = $this->makeService($otherOffice);
        $foreignPwd = (new QueueTicketCreator())->create([
            'office_id' => $otherOffice->id,
            'service_id' => $otherService->id,
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ])['ticket'];

        $this->actingAs($staff)
            ->get(route('staff.queues.evidence', $foreignPwd))
            ->assertForbidden();
    }

    public function test_staff_cannot_access_the_admin_panel(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();

        $this->actingAs($staff)
            ->get(route('admin.queues.index'))
            ->assertForbidden();
    }

    public function test_dashboard_redirects_each_role_to_its_own_area(): void
    {
        [$office] = $this->makeStaffedOffice();
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);
        $student = $this->makeUser(User::ROLE_STUDENT);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertRedirect(route('staff.dashboard'));

        $this->actingAs($student)->get(route('dashboard'))
            ->assertRedirect(route('student.queues.index'));

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertRedirect(route('admin.dashboard'));
    }
}