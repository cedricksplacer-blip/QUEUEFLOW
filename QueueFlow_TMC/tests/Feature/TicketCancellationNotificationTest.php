<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\QueueTicket;
use App\Models\StaffNotification;
use App\Models\User;
use App\Services\QueueDeploymentManager;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * Flutter Cancel → Laravel → staff office alert.
 *
 * Covers the whole workflow: a student POSTs /api/v1/tickets/{id}/cancel,
 * the ticket moves to `cancelled`, exactly one StaffNotification row is
 * written for that ticket's office, other offices see nothing, the owning
 * staff can poll / mark read / revisit history, and admins see everything.
 */
class TicketCancellationNotificationTest extends TestCase
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

    /** Registrar office + staff + service + student ticket, ready to cancel. */
    private function seedTicket(string $officeCode = 'REG-BSIT'): array
    {
        $office = $this->makeOffice(['name' => 'Registrar', 'code' => $officeCode]);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);
        $service = $this->makeService($office);
        $student = $this->makeStudent();

        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $student->id,
            'purpose' => 'Transcript of Records',
        ])['ticket'];

        return compact('office', 'staff', 'service', 'student', 'ticket');
    }

    public function test_student_cancel_creates_one_office_scoped_notification(): void
    {
        ['office' => $office, 'student' => $student, 'ticket' => $ticket] = $this->seedTicket();

        $this->withToken($this->tokenFor($student, '2026-0001'))
            ->postJson("/api/v1/tickets/{$ticket->id}/cancel")
            ->assertOk()
            ->assertJsonPath('ticket.status', QueueTicket::STATUS_CANCELLED);

        $this->assertSame(QueueTicket::STATUS_CANCELLED, $ticket->refresh()->status);

        $notifications = StaffNotification::where('ticket_id', $ticket->id)->get();
        $this->assertCount(1, $notifications);

        $notification = $notifications->first();
        $this->assertSame($office->id, $notification->office_id);
        $this->assertSame('Student Cancelled Ticket', $notification->title);
        $this->assertSame('Juan Dela Cruz', $notification->student_name);
        $this->assertSame('2026-0001', $notification->student_id_no);
        $this->assertSame($ticket->queue_number, $notification->queue_number);
        $this->assertSame('Registrar', $notification->office_name);
        $this->assertSame('Transcript of Records', $notification->purpose);
        $this->assertNotNull($notification->cancelled_at);
        $this->assertFalse((bool) $notification->is_read);
        $this->assertStringContainsString('2026-0001', $notification->message);
        $this->assertStringContainsString($ticket->queue_number, $notification->message);
    }

    public function test_other_offices_receive_no_notification(): void
    {
        ['office' => $office, 'student' => $student, 'ticket' => $ticket] = $this->seedTicket('REG-BSIT');
        $other = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);

        $this->withToken($this->tokenFor($student, '2026-0001'))
            ->postJson("/api/v1/tickets/{$ticket->id}/cancel")
            ->assertOk();

        $this->assertSame(0, StaffNotification::where('office_id', $other->id)->count());
        $this->assertSame(1, StaffNotification::where('office_id', $office->id)->count());
    }

    public function test_duplicate_cancel_requests_create_no_duplicate_notification(): void
    {
        ['student' => $student, 'ticket' => $ticket] = $this->seedTicket();

        $token = $this->tokenFor($student, '2026-0001');

        $this->withToken($token)->postJson("/api/v1/tickets/{$ticket->id}/cancel")->assertOk();
        $this->withToken($token)->postJson("/api/v1/tickets/{$ticket->id}/cancel")->assertStatus(422);

        $this->assertSame(1, StaffNotification::where('ticket_id', $ticket->id)->count());
    }

    public function test_failed_cancellation_creates_no_notification(): void
    {
        $seed = $this->seedTicket();
        $office = $seed['office'];
        $staff = $seed['staff'];
        $student = $seed['student'];
        $ticket = $seed['ticket'];

        // Move the ticket to serving via the counter, then the student tries
        // to cancel: refused, and no alert may be written.
        (new QueueDeploymentManager())->deploy($office);

        $this->withToken($this->tokenFor($staff, $staff->email))
            ->postJson("/api/v1/queues/{$office->id}/call-next")
            ->assertOk();

        $this->withToken($this->tokenFor($student, '2026-0001'))
            ->postJson("/api/v1/tickets/{$ticket->id}/cancel")
            ->assertStatus(422);

        $this->assertSame(0, StaffNotification::where('ticket_id', $ticket->id)->count());
    }

    public function test_cancelled_ticket_frees_the_waiting_line_but_keeps_its_record(): void
    {
        ['student' => $student, 'ticket' => $ticket] = $this->seedTicket();

        $this->withToken($this->tokenFor($student, '2026-0001'))
            ->postJson("/api/v1/tickets/{$ticket->id}/cancel")
            ->assertOk();

        $fresh = $ticket->refresh();
        $this->assertSame(QueueTicket::STATUS_CANCELLED, $fresh->status);
        $this->assertNull($fresh->called_at);
        $this->assertDatabaseHas('queue_tickets', ['id' => $ticket->id, 'status' => 'cancelled']);
        $this->assertSame(0, QueueTicket::where('id', $ticket->id)->where('status', QueueTicket::STATUS_WAITING)->count());
    }

    public function test_staff_sees_unread_count_recent_feed_and_can_mark_read(): void
    {
        ['office' => $office, 'staff' => $staff, 'student' => $student, 'ticket' => $ticket] = $this->seedTicket();

        $this->withToken($this->tokenFor($student, '2026-0001'))
            ->postJson("/api/v1/tickets/{$ticket->id}/cancel")
            ->assertOk();

        // Web polling endpoints (session auth, office-scoped).
        $this->actingAs($staff)
            ->getJson(route('staff.notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $this->actingAs($staff)
            ->getJson(route('staff.notifications.recent'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.queue_number', $ticket->queue_number)
            ->assertJsonPath('notifications.0.student_id', '2026-0001');

        // Staff dashboard renders the alert card.
        $this->actingAs($staff)
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('Cancellation Alerts')
            ->assertSee($ticket->queue_number);

        // History page lists it with full detail.
        $this->actingAs($staff)
            ->get(route('staff.notifications.index'))
            ->assertOk()
            ->assertSee('Student Cancelled Ticket')
            ->assertSee('2026-0001')
            ->assertSee($ticket->queue_number);

        $notification = StaffNotification::where('ticket_id', $ticket->id)->firstOrFail();

        $this->actingAs($staff)
            ->post(route('staff.notifications.read', $notification))
            ->assertRedirect();

        $this->assertTrue($notification->refresh()->is_read);

        $this->actingAs($staff)
            ->getJson(route('staff.notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        // History persists after read.
        $this->actingAs($staff)
            ->get(route('staff.notifications.index'))
            ->assertOk()
            ->assertSee($ticket->queue_number);
    }

    public function test_staff_cannot_read_another_offices_notification(): void
    {
        ['ticket' => $ticket, 'student' => $student] = $this->seedTicket('REG-BSIT');

        $otherOffice = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $otherStaff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $otherOffice->id]);

        $this->withToken($this->tokenFor($student, '2026-0001'))
            ->postJson("/api/v1/tickets/{$ticket->id}/cancel")
            ->assertOk();

        $notification = StaffNotification::where('ticket_id', $ticket->id)->firstOrFail();

        // Other office's feed is empty and the direct read is refused.
        $this->actingAs($otherStaff)
            ->getJson(route('staff.notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->actingAs($otherStaff)
            ->post(route('staff.notifications.read', $notification))
            ->assertNotFound();

        $this->assertFalse($notification->refresh()->is_read);
    }

    public function test_admin_views_cancellations_across_all_offices(): void
    {
        $first = $this->seedTicket('REG-BSIT');
        $secondOffice = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $secondService = $this->makeService($secondOffice);
        $secondStudent = $this->makeStudent(['name' => 'Maria Santos', 'student_id' => '2026-0002']);

        $secondTicket = (new QueueTicketCreator())->create([
            'office_id' => $secondOffice->id,
            'service_id' => $secondService->id,
            'user_id' => $secondStudent->id,
            'purpose' => 'Tuition payment',
        ])['ticket'];

        $this->withToken($this->tokenFor($first['student'], '2026-0001'))
            ->postJson("/api/v1/tickets/{$first['ticket']->id}/cancel")
            ->assertOk();

        $this->withToken($this->tokenFor($secondStudent, '2026-0002'))
            ->postJson("/api/v1/tickets/{$secondTicket->id}/cancel")
            ->assertOk();

        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee($first['ticket']->queue_number)
            ->assertSee($secondTicket->queue_number);

        // API overview for admins also spans offices.
        $this->withToken($this->tokenFor($admin, $admin->email))
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 2);
    }

    public function test_staff_api_feed_is_scoped_to_their_office(): void
    {
        $first = $this->seedTicket('REG-BSIT');

        $otherOffice = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $otherStaff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $otherOffice->id]);

        $this->withToken($this->tokenFor($first['student'], '2026-0001'))
            ->postJson("/api/v1/tickets/{$first['ticket']->id}/cancel")
            ->assertOk();

        $this->withToken($this->tokenFor($first['staff'], $first['staff']->email))
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $this->withToken($this->tokenFor($otherStaff, $otherStaff->email))
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    public function test_notification_survives_relogin_persistence(): void
    {
        ['staff' => $staff, 'student' => $student, 'ticket' => $ticket] = $this->seedTicket();

        $this->withToken($this->tokenFor($student, '2026-0001'))
            ->postJson("/api/v1/tickets/{$ticket->id}/cancel")
            ->assertOk();

        // Simulate a fresh login: the row is in the database, not in session.
        $this->post(route('staff.logout'));

        $this->actingAs($staff)
            ->get(route('staff.notifications.index'))
            ->assertOk()
            ->assertSee($ticket->queue_number);
    }
}
