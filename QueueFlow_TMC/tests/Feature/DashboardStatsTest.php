<?php

namespace Tests\Feature;

use App\Models\OfficeStation;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateLocalStorage();
    }

    private function seedTodayActivity(): array
    {
        $office = $this->makeOffice([
            'name' => 'SASO',
            'code' => 'SASO',
            'opening_time' => '00:00',
            'closing_time' => '23:59',
        ]);
        $service = $this->makeService($office);
        $course = $this->makeCourse(['office_id' => $office->id]);
        $student = $this->makeUser(User::ROLE_STUDENT);
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $creator = new QueueTicketCreator();

        $ticket = $creator->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $student->id,
            'course_id' => $course->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])['ticket'];

        $ticket->update([
            'status' => QueueTicket::STATUS_SERVED,
            'served_at' => now(),
        ]);

        return [$office, $admin];
    }

    public function test_admin_dashboard_renders_per_office_stats(): void
    {
        [$office, $admin] = $this->seedTodayActivity();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee($office->name)
            ->assertSee('1');
    }

    public function test_dashboard_renders_per_office_counters(): void
    {
        [$office, $admin] = $this->seedTodayActivity();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('officeStats', function ($offices) use ($office) {
                $found = $offices->firstWhere('name', $office->name);

                return $found
                    && $found->tickets_today === 1
                    && $found->served_count === 1;
            })
            // The counters are rendered as count-up targets for the motion
            // engine in resources/js/app.js, so the figures have to survive
            // into the markup rather than only into the view payload.
            ->assertSee('data-target="1"', false);
    }

    public function test_reports_include_pwd_count(): void
    {
        // Must be an operational code: the summary only fuses real offices.
        $office = $this->makeOffice([
            'name' => 'Cashier',
            'code' => 'CASH',
            'opening_time' => '00:00',
            'closing_time' => '23:59',
        ]);
        $service = $this->makeService($office);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ]);

        $today = today()->toDateString();

        $response = $this->actingAs($admin)
            ->get(route('admin.reports.index', ['from' => $today, 'to' => $today]))
            ->assertOk();

        $summary = $response->viewData('summary');

        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['pwd']);
    }

    public function test_report_summary_counts_only_operational_offices(): void
    {
        $operational = $this->makeOffice([
            'name' => 'Cashier',
            'code' => 'CASH',
            'opening_time' => '00:00',
            'closing_time' => '23:59',
        ]);

        $retired = $this->makeOffice([
            'name' => 'College Library',
            'code' => 'LIB',
            'is_active' => false,
            'opening_time' => '00:00',
            'closing_time' => '23:59',
        ]);

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $student = $this->makeUser(User::ROLE_STUDENT);

        // One live ticket at a real office, one stranded ticket at an office
        // that no longer exists. Only the first may reach the summary cards.
        $operational->queueTickets()->create([
            'queue_number' => 'CASH-001',
            'user_id' => $student->id,
            'status' => QueueTicket::STATUS_SERVED,
        ]);
        $retired->queueTickets()->create([
            'queue_number' => 'LIB-001',
            'user_id' => $student->id,
            'status' => QueueTicket::STATUS_SERVED,
        ]);

        $today = today()->toDateString();
        $query = ['from' => $today, 'to' => $today];

        $response = $this->actingAs($admin)
            ->get(route('admin.reports.index', $query))
            ->assertOk();

        $summary = $response->viewData('summary');
        $byOffice = $response->viewData('byOffice');

        $this->assertSame(1, $summary['total'], 'Summary must ignore retired offices.');
        $this->assertSame(1, $summary['served']);
        $this->assertSame(0, $summary['cancelled']);

        // The six KPI cards have to reconcile with the table underneath them.
        $this->assertSame(
            $byOffice->sum('total_tickets'),
            $summary['total'],
            'Summary total must equal the sum of the by-office table.'
        );
    }

    public function test_reports_by_office_exclude_non_operational_offices(): void
    {
        $operational = $this->makeOffice([
            'name' => 'Cashier',
            'code' => 'CASH',
            'opening_time' => '00:00',
            'closing_time' => '23:59',
        ]);

        $retired = $this->makeOffice([
            'name' => 'College Library',
            'code' => 'LIB',
            'is_active' => false,
            'opening_time' => '00:00',
            'closing_time' => '23:59',
        ]);

        // A station on a retired office is active, so only the office-code
        // filter can keep it out of the report.
        OfficeStation::create([
            'office_id' => $retired->id,
            'name' => 'Library Services Station',
            'is_active' => true,
        ]);

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $today = today()->toDateString();

        $byOffice = $this->actingAs($admin)
            ->get(route('admin.reports.index', ['from' => $today, 'to' => $today]))
            ->assertOk()
            ->viewData('byOffice');

        $this->assertSame([$operational->code], $byOffice->pluck('code')->all());
        $this->assertNotContains($retired->code, $byOffice->pluck('code')->all());

        $this->actingAs($admin)
            ->get('/admin/reports?from=' . $today . '&to=' . $today)
            ->assertOk()
            ->assertSee($operational->name)
            ->assertDontSee($retired->name);
    }
}