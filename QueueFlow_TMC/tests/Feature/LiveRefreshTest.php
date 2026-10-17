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
 * Background refresh endpoints for both dashboards.
 *
 * The staff console polls staff.queues.state for in-place waiting-list and
 * batch updates; the admin dashboard polls admin.queue-summary for its stat
 * cards and status pills; filtered table pages poll admin.queue-ping for
 * stale banners only. All three read the live database through the same
 * office scoping and role gates as the pages they serve.
 */
class LiveRefreshTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateLocalStorage();
    }

    private function makeStaffedOffice(string $code = 'CASH'): array
    {
        $office = $this->makeOffice(['code' => $code, 'name' => "Office {$code}"]);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);

        return [$office, $staff];
    }

    private function issueTicket(Office $office, string $course = 'BSIT'): QueueTicket
    {
        $service = $this->makeService($office);

        return (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
            'student_course' => $course,
        ])['ticket'];
    }

    public function test_staff_state_carries_waiting_rows_batch_and_counts(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();

        $first = $this->issueTicket($office);
        $second = $this->issueTicket($office);

        $response = $this->actingAs($staff)
            ->getJson(route('staff.queues.state'))
            ->assertOk();

        $response
            ->assertJsonPath('office_id', $office->id)
            ->assertJsonPath('waiting_count', 2)
            ->assertJsonPath('served_today', 0)
            ->assertJsonPath('latest_ticket_id', $second->id)
            ->assertJsonCount(2, 'waiting_tickets')
            ->assertJsonPath('waiting_tickets.0.id', $first->id)
            ->assertJsonPath('waiting_tickets.0.queue_number', $first->queue_number)
            ->assertJsonStructure([
                'deployment',
                'waiting_tickets' => [
                    ['id', 'queue_number', 'student_name', 'priority', 'purpose', 'has_evidence', 'evidence_url'],
                ],
            ]);

        // Issuing opens the first batch automatically: never a fabricated one.
        $response
            ->assertJsonPath('deployment.batch_no', 1)
            ->assertJsonPath('deployment.status', QueueDeployment::STATUS_OPEN)
            ->assertJsonPath('deployment.submitted', 2)
            ->assertJsonPath('deployment.served', 0);

        (new QueueDeploymentManager())->closeBatch($office);

        $this->actingAs($staff)
            ->getJson(route('staff.queues.state'))
            ->assertOk()
            ->assertJsonPath('deployment.batch_no', 1)
            ->assertJsonPath('deployment.status', QueueDeployment::STATUS_CLOSED);
    }

    public function test_staff_state_is_scoped_to_the_staff_office(): void
    {
        [$officeA, $staffA] = $this->makeStaffedOffice('CASH');
        [$officeB] = $this->makeStaffedOffice('ADM');

        $this->issueTicket($officeA);
        $other = $this->issueTicket($officeB);

        $payload = $this->actingAs($staffA)
            ->getJson(route('staff.queues.state'))
            ->assertOk()
            ->json();

        $this->assertSame(1, $payload['waiting_count']);
        $this->assertNotContains($other->id, collect($payload['waiting_tickets'])->pluck('id')->all());
        $this->assertNotSame($other->id, $payload['latest_ticket_id']);
    }

    public function test_staff_state_requires_a_staff_session(): void
    {
        $this->getJson(route('staff.queues.state'))->assertUnauthorized();

        $student = $this->makeUser(User::ROLE_STUDENT);

        $this->actingAs($student)
            ->getJson(route('staff.queues.state'))
            ->assertForbidden();
    }

    public function test_admin_queue_summary_reports_all_active_offices(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        [$office] = $this->makeStaffedOffice('CASH');
        // A custom office added via Offices auto-registers on the dashboard.
        $custom = $this->makeOffice(['code' => 'LIB', 'name' => 'College Library']);
        // A deactivated office stays hidden, consistent with Offices rules.
        $retired = $this->makeOffice(['code' => 'OLD', 'name' => 'Old Office', 'is_active' => false]);

        $this->issueTicket($office);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.queue-summary'))
            ->assertOk();

        $response->assertJsonStructure([
            'server_time',
            'stats' => ['total_today', 'waiting', 'serving', 'served'],
            'offices' => [['id', 'code', 'name', 'status', 'tickets_today', 'waiting', 'serving', 'served']],
        ]);

        $codes = collect($response->json('offices'))->pluck('code')->all();

        $this->assertContains('CASH', $codes);
        $this->assertContains($custom->code, $codes);
        $this->assertNotContains($retired->code, $codes);

        $cash = collect($response->json('offices'))->firstWhere('code', 'CASH');

        $this->assertSame(1, $cash['tickets_today']);
        $this->assertSame(1, $cash['waiting']);
        $this->assertSame('online', $cash['status']);
        $this->assertSame(1, $response->json('stats.total_today'));
        $this->assertSame(1, $response->json('stats.waiting'));
    }

    public function test_admin_queue_summary_is_admin_only(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();

        $this->getJson(route('admin.queue-summary'))->assertUnauthorized();

        $this->actingAs($staff)
            ->getJson(route('admin.queue-summary'))
            ->assertForbidden();
    }

    public function test_admin_queue_ping_detects_new_tickets_per_office(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        [$officeA] = $this->makeStaffedOffice('CASH');
        [$officeB] = $this->makeStaffedOffice('ADM');

        $first = $this->issueTicket($officeA);

        $this->actingAs($admin)
            ->getJson(route('admin.queue-ping'))
            ->assertOk()
            ->assertJsonPath('latest_ticket_id', $first->id)
            ->assertJsonPath('today_total', 1);

        $second = $this->issueTicket($officeB);

        // Unscoped ping follows the newest ticket anywhere.
        $this->actingAs($admin)
            ->getJson(route('admin.queue-ping'))
            ->assertOk()
            ->assertJsonPath('latest_ticket_id', $second->id)
            ->assertJsonPath('today_total', 2);

        // Office-scoped ping ignores the other office's tickets.
        $this->actingAs($admin)
            ->getJson(route('admin.queue-ping', ['office_id' => $officeA->id]))
            ->assertOk()
            ->assertJsonPath('latest_ticket_id', $first->id)
            ->assertJsonPath('today_total', 1);
    }

    public function test_admin_queue_ping_is_admin_only(): void
    {
        [$office, $staff] = $this->makeStaffedOffice();

        $this->getJson(route('admin.queue-ping'))->assertUnauthorized();

        $this->actingAs($staff)
            ->getJson(route('admin.queue-ping'))
            ->assertForbidden();
    }
}
