<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * The daily online/offline switch.
 *
 * The office status column is the single source of truth for "is this counter
 * accepting students right now". Every office — including each per-course
 * Registrar counter — carries its own switch: flipping REG-BSIT must leave
 * REG-BSOA and every other counter exactly as it was, so each course queue
 * opens and closes on its own staff's sign-in. API and web must agree, and
 * an offline office must refuse a ticket at every door - creator, web store
 * and API store.
 */
class OfficeStatusTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    public function test_an_office_created_without_a_status_defaults_to_offline(): void
    {
        $office = Office::create([
            'name' => 'Legacy Row',
            'code' => 'LEGACY',
            'is_active' => true,
        ]);

        $this->assertSame(Office::STATUS_OFFLINE, $office->fresh()->status);
        $this->assertFalse($office->fresh()->isOnline());
    }

    public function test_toggling_a_registrar_desk_leaves_its_siblings_alone(): void
    {
        $bsit = $this->makeOffice(['code' => 'REG-BSIT', 'name' => 'Registrar - BSIT']);
        $bsoa = $this->makeOffice(['code' => 'REG-BSOA', 'name' => 'Registrar - BSOA']);
        $cash = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);

        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->post(route('admin.offices.toggle-status', $bsit), [
                'status' => Office::STATUS_OFFLINE,
            ])
            ->assertRedirect();

        $this->assertFalse($bsit->fresh()->isOnline());
        // Sibling Registrar counters keep their own status.
        $this->assertTrue($bsoa->fresh()->isOnline());
        $this->assertTrue($cash->fresh()->isOnline());
    }

    public function test_registrar_counters_come_online_independently(): void
    {
        $bsit = $this->makeOffice(['code' => 'REG-BSIT', 'name' => 'Registrar - BSIT', 'status' => Office::STATUS_OFFLINE]);
        $bsoa = $this->makeOffice(['code' => 'REG-BSOA', 'name' => 'Registrar - BSOA', 'status' => Office::STATUS_OFFLINE]);

        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('admin.offices.toggle-status', $bsit), [
            'status' => Office::STATUS_ONLINE,
        ])->assertRedirect();

        // Only the flipped counter comes online.
        $this->assertTrue($bsit->fresh()->isOnline());
        $this->assertFalse($bsoa->fresh()->isOnline());
    }

    public function test_the_toggle_flips_correctly_when_no_status_is_sent(): void
    {
        $cash = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        // No status field: the controller flips whatever state the office is in.
        $this->actingAs($admin)->post(route('admin.offices.toggle-status', $cash))->assertRedirect();
        $this->assertFalse($cash->fresh()->isOnline());

        $this->actingAs($admin)->post(route('admin.offices.toggle-status', $cash))->assertRedirect();
        $this->assertTrue($cash->fresh()->isOnline());
    }

    public function test_the_admin_dashboard_lists_each_registrar_counter_separately(): void
    {
        $this->makeOffice(['code' => 'REG-BSIT', 'name' => 'Registrar - BSIT']);
        $this->makeOffice(['code' => 'REG-BSOA', 'name' => 'Registrar - BSOA']);
        $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);

        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->get(route('admin.dashboard'))
            ->assertOk()
            // The section itself.
            ->assertSee('Campus Office Status')
            // Each Registrar counter is its own row, not one shared group.
            ->assertSee('Registrar - BSIT')
            ->assertSee('REG-BSIT')
            ->assertSee('Registrar - BSOA')
            ->assertSee('REG-BSOA')
            ->assertSee('Cashier')
            // Online fixtures render as online badges. Status is automatic
            // (staff sign-in/out), so there are no manual toggle buttons.
            ->assertSee('ONLINE')
            ->assertDontSee('Turn Offline')
            ->assertDontSee('Turn Online');
    }

    public function test_the_staff_report_page_shows_office_status(): void
    {
        $cash = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $this->makeUser(User::ROLE_STAFF, ['office_id' => $cash->id]);

        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->get(route('admin.staff.index'))
            ->assertOk()
            ->assertSee('Office Status')
            ->assertSee('ONLINE')
            ->assertDontSee('Turn Offline')
            ->assertDontSee('Turn Online');
    }

    public function test_a_staff_member_can_flip_their_own_office(): void
    {
        $cash = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $cash->id]);

        $this->actingAs($staff)
            ->post(route('staff.office.toggle-status'))
            ->assertRedirect();

        $this->assertFalse($cash->fresh()->isOnline());

        // The office page agrees with what was saved and offers no manual
        // toggle: status is driven by staff sign-in/out.
        $this->actingAs($staff)
            ->get(route('staff.office.show'))
            ->assertOk()
            ->assertSee('OFFLINE')
            ->assertDontSee('Turn Online')
            ->assertDontSee('Turn Offline');
    }

    public function test_an_admin_cannot_use_the_staff_scoped_toggle(): void
    {
        $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);

        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->post(route('staff.office.toggle-status'))
            ->assertForbidden();
    }

    public function test_staff_cannot_use_the_admin_toggle(): void
    {
        $bsit = $this->makeOffice(['code' => 'REG-BSIT', 'name' => 'Registrar BSIT']);
        $cash = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $cash->id]);

        $this->actingAs($staff)
            ->post(route('admin.offices.toggle-status', $bsit), [
                'status' => Office::STATUS_OFFLINE,
            ])
            ->assertForbidden();
    }

    public function test_the_api_lets_an_admin_flip_an_office(): void
    {
        $cash = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson(route('api.v1.offices.status', $cash), [
                'status' => Office::STATUS_OFFLINE,
            ])
            ->assertOk();

        $this->assertSame(Office::STATUS_OFFLINE, $response->json('status'));
        $this->assertSame(Office::STATUS_OFFLINE, $response->json('office.status'));
        $this->assertFalse($cash->fresh()->isOnline());
    }

    public function test_the_api_status_endpoint_requires_a_status(): void
    {
        $cash = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);

        $this->actingAs($this->makeUser(User::ROLE_ADMIN), 'sanctum')
            ->postJson(route('api.v1.offices.status', $cash), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_the_api_status_endpoint_is_not_available_to_students(): void
    {
        $cash = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);

        $this->actingAs($this->makeUser(User::ROLE_STUDENT), 'sanctum')
            ->postJson(route('api.v1.offices.status', $cash), [
                'status' => Office::STATUS_OFFLINE,
            ])
            ->assertForbidden();
    }

    public function test_a_staff_member_can_only_flip_their_own_office_via_the_api(): void
    {
        $cash = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $bsit = $this->makeOffice(['code' => 'REG-BSIT', 'name' => 'Registrar BSIT']);
        $staff = $this->makeUser(User::ROLE_STAFF, ['office_id' => $cash->id]);

        $this->actingAs($staff, 'sanctum')
            ->postJson(route('api.v1.offices.status', $bsit), [
                'status' => Office::STATUS_OFFLINE,
            ])
            ->assertForbidden();

        $this->actingAs($staff, 'sanctum')
            ->postJson(route('api.v1.offices.status', $cash), [
                'status' => Office::STATUS_OFFLINE,
            ])
            ->assertOk()
            ->assertJsonPath('office.id', $cash->id);
    }

    public function test_queue_ticket_creator_refuses_tickets_for_an_offline_office(): void
    {
        $office = $this->makeOffice(['code' => 'CASH', 'status' => Office::STATUS_OFFLINE]);
        $service = $this->makeService($office);

        try {
            (new QueueTicketCreator())->create([
                'office_id' => $office->id,
                'service_id' => $service->id,
                'priority' => 'regular',
            ]);
            $this->fail('Expected a ValidationException for an offline office.');
        } catch (ValidationException $e) {
            $this->assertSame(QueueTicketCreator::OFFLINE_MESSAGE, $e->errors()['office_id'][0]);
        }
    }

    public function test_the_student_web_form_rejects_an_offline_office(): void
    {
        $office = $this->makeOffice(['code' => 'CASH', 'status' => Office::STATUS_OFFLINE]);
        $service = $this->makeService($office);
        $course = $this->makeCourse(['office_id' => $office->id]);
        $student = $this->makeUser(User::ROLE_STUDENT, ['course' => 'BSIT']);

        $this->actingAs($student)
            ->post(route('student.queues.store'), [
                'office_id' => $office->id,
                'service_id' => $service->id,
                'course_id' => $course->id,
                'priority' => 'regular',
            ])
            ->assertSessionHasErrors('office_id');

        $this->assertSame(0, $office->queueTickets()->count());
    }

    public function test_the_api_rejects_queue_tickets_for_an_offline_office(): void
    {
        $office = $this->makeOffice(['code' => 'CASH', 'status' => Office::STATUS_OFFLINE]);
        $service = $this->makeService($office);
        $course = $this->makeCourse(['office_id' => $office->id]);
        $student = $this->makeUser(User::ROLE_STUDENT, ['course' => 'BSIT']);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson(route('api.v1.tickets.store'), [
                'office_id' => $office->id,
                'service_id' => $service->id,
                'course_id' => $course->id,
                'purpose' => 'Enrollment transaction',
                'priority' => 'regular',
            ])
            ->assertStatus(422);

        $this->assertSame(QueueTicketCreator::OFFLINE_MESSAGE, $response->json('message'));
        $this->assertFalse($response->json('success'));
        $this->assertSame(0, $office->queueTickets()->count());
    }

    public function test_the_offices_payload_carries_the_status_and_offline_folds_into_accepting(): void
    {
        $cash = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier', 'status' => Office::STATUS_OFFLINE]);
        $student = $this->makeUser(User::ROLE_STUDENT, ['course' => 'BSIT']);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson(route('api.v1.offices'))
            ->assertOk();

        $office = collect($response->json('offices'))->firstWhere('id', $cash->id);

        $this->assertNotNull($office, 'The offline Cashier must still be listed.');
        $this->assertSame(Office::STATUS_OFFLINE, $office['status']);
        $this->assertFalse($office['accepting']);
        $this->assertNotSame('open', $office['queue_status']);
    }
}