<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Office;
use App\Models\QueueTicket;
use App\Models\StaffNotification;
use App\Models\User;
use App\Services\QueueTicketCreator;
use App\Support\SidebarBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * Sidebar badge counts for the Blade consoles.
 *
 * Every badge is a real COUNT from existing tables: today's waiting
 * tickets, unread cancellation alerts, pending-review announcements, and
 * offline operational offices. Staff endpoints are scoped to the caller's
 * own office; the admin endpoint is admin-only.
 */
class SidebarBadgeCountsTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateLocalStorage();
        SidebarBadges::flush();
    }

    private function makeAdmin(): User
    {
        return $this->makeUser(User::ROLE_ADMIN);
    }

    private function makeStaff(Office $office): User
    {
        return $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);
    }

    private function makeWaitingTicket(Office $office, array $attrs = []): QueueTicket
    {
        $service = $this->makeService($office);
        $student = $this->makeUser(User::ROLE_STUDENT, ['student_id' => '2026-'.uniqid()]);

        return (new QueueTicketCreator())->create(array_merge([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $student->id,
            'purpose' => 'Badge probe',
        ], $attrs))['ticket'];
    }

    public function test_admin_counts_reflect_real_records(): void
    {
        $online = $this->makeOffice(['code' => 'ADM', 'status' => Office::STATUS_ONLINE]);
        $this->makeOffice(['code' => 'CASH', 'status' => Office::STATUS_OFFLINE]);

        $this->makeWaitingTicket($online);

        $ticket = $this->makeWaitingTicket($online);
        $ticket->update(['status' => QueueTicket::STATUS_CANCELLED]);
        \App\Services\TicketCancellationNotifier::notify($ticket->fresh());

        Announcement::create([
            'title' => 'Pending review',
            'body' => 'Awaiting admin approval.',
            'status' => Announcement::STATUS_PENDING,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/admin/sidebar-counts');

        $response->assertOk();
        $response->assertJsonPath('waiting_today', 1);
        $response->assertJsonPath('alerts_unread', 1);
        $response->assertJsonPath('announcements_pending', 1);
        $response->assertJsonPath('offices_offline', 1);
    }

    public function test_admin_counts_are_zero_when_nothing_pends(): void
    {
        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/admin/sidebar-counts');

        $response->assertOk();
        $response->assertJson([
            'waiting_today' => 0,
            'alerts_unread' => 0,
            'announcements_pending' => 0,
            'offices_offline' => 0,
        ]);
    }

    public function test_admin_endpoint_rejects_guests_students_and_staff(): void
    {
        $this->getJson('/admin/sidebar-counts')->assertUnauthorized();

        $office = $this->makeOffice(['code' => 'ADM']);

        $this->actingAs($this->makeUser(User::ROLE_STUDENT))
            ->getJson('/admin/sidebar-counts')
            ->assertForbidden();

        $this->actingAs($this->makeStaff($office))
            ->getJson('/admin/sidebar-counts')
            ->assertForbidden();
    }

    public function test_staff_counts_are_scoped_to_their_own_office(): void
    {
        $adm = $this->makeOffice(['code' => 'ADM']);
        $cash = $this->makeOffice(['code' => 'CASH']);

        $this->makeWaitingTicket($adm);
        $this->makeWaitingTicket($adm);
        $this->makeWaitingTicket($cash);

        $cancelled = $this->makeWaitingTicket($adm);
        $cancelled->update(['status' => QueueTicket::STATUS_CANCELLED]);
        \App\Services\TicketCancellationNotifier::notify($cancelled->fresh());

        $response = $this->actingAs($this->makeStaff($adm))
            ->getJson('/staff/sidebar-counts');

        $response->assertOk();
        $response->assertJsonPath('waiting_today', 2);
        $response->assertJsonPath('alerts_unread', 1);
        $response->assertJsonPath('pwd_waiting', 0);
    }

    public function test_staff_pwd_waiting_counts_only_priority_tickets(): void
    {
        $office = $this->makeOffice(['code' => 'ADM']);

        $this->makeWaitingTicket($office);
        $this->makeWaitingTicket($office, [
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ]);

        $response = $this->actingAs($this->makeStaff($office))
            ->getJson('/staff/sidebar-counts');

        $response->assertOk();
        $response->assertJsonPath('waiting_today', 2);
        $response->assertJsonPath('pwd_waiting', 1);
    }

    public function test_staff_endpoint_rejects_guests_and_other_roles(): void
    {
        $this->getJson('/staff/sidebar-counts')->assertUnauthorized();

        $this->actingAs($this->makeUser(User::ROLE_STUDENT))
            ->getJson('/staff/sidebar-counts')
            ->assertForbidden();

        $this->actingAs($this->makeAdmin())
            ->getJson('/staff/sidebar-counts')
            ->assertForbidden();
    }

    public function test_staff_without_office_is_refused_like_other_endpoints(): void
    {
        $this->actingAs($this->makeUser(User::ROLE_STAFF))
            ->getJson('/staff/sidebar-counts')
            ->assertForbidden();
    }

    public function test_fmt_caps_at_99_plus(): void
    {
        $this->assertSame('0', SidebarBadges::fmt(0));
        $this->assertSame('7', SidebarBadges::fmt(7));
        $this->assertSame('99', SidebarBadges::fmt(99));
        $this->assertSame('99+', SidebarBadges::fmt(100));
        $this->assertSame('99+', SidebarBadges::fmt(1234));
    }

    public function test_marking_read_lowers_the_badge_count(): void
    {
        $office = $this->makeOffice(['code' => 'ADM']);
        $staff = $this->makeStaff($office);

        $ticket = $this->makeWaitingTicket($office);
        $ticket->update(['status' => QueueTicket::STATUS_CANCELLED]);
        $notification = \App\Services\TicketCancellationNotifier::notify($ticket->fresh());

        $this->actingAs($staff)->getJson('/staff/sidebar-counts')
            ->assertJsonPath('alerts_unread', 1);

        $notification->markAsRead($staff);
        SidebarBadges::flush();

        $this->actingAs($staff)->getJson('/staff/sidebar-counts')
            ->assertJsonPath('alerts_unread', 0);
    }
}
