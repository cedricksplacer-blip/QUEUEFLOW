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
 * Staff "Reset Batch Number" (closing/offline) flow.
 *
 * Resetting sends today's batches to history and restarts the displayed
 * sequence, so the next deployment is Batch 1, then #2, #3, ... It must
 * never touch offices.status, tickets, or any other office, and only the
 * staff member assigned to the office may do it.
 */
class StaffResetBatchNumberTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateLocalStorage();
    }

    private function makeStaff(Office $office): User
    {
        return $this->makeUser(User::ROLE_STAFF, ['office_id' => $office->id]);
    }

    private function makeStudent(array $attrs = []): User
    {
        return $this->makeUser(User::ROLE_STUDENT, array_merge([
            'student_id' => '24-019083',
            'course' => 'BSIT',
        ], $attrs));
    }

    private function issueTicket(Office $office): QueueTicket
    {
        $service = $this->makeService($office);
        $student = $this->makeStudent(['student_id' => '24-' . uniqid()]);

        return (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $student->id,
            'purpose' => 'Reset-flow check',
        ])['ticket'];
    }

    public function test_staff_resets_own_office_batch_to_one_and_it_persists(): void
    {
        $office = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $staff = $this->makeStaff($office);

        // Advance the office to batch #3 first.
        $manager = new QueueDeploymentManager();
        $manager->start($office);
        $manager->completeAndRestart($office);
        $manager->completeAndRestart($office);

        $this->assertSame(3, QueueDeployment::activeFor($office)->batch_no);

        $this->actingAs($staff)
            ->post(route('staff.admissions.reset-batch-number'))
            ->assertRedirect()
            ->assertSessionHas('status', 'Batch number successfully reset to Batch 1.');

        // No active batch remains; the next deployment will be Batch 1.
        $this->assertNull(QueueDeployment::activeFor($office->fresh()));
        $this->assertSame(1, QueueDeployment::nextBatchNoFor($office->fresh()));

        // Old batches are history, not deleted.
        $this->assertSame(
            0,
            QueueDeployment::where('office_id', $office->id)
                ->whereDate('created_at', today())
                ->where('status', '!=', QueueDeployment::STATUS_COMPLETED)
                ->count()
        );

        // The next deployment really is Batch 1 under the existing rules.
        $fresh = $manager->start($office->fresh());
        $this->assertSame(1, (int) $fresh->batch_no);
        $this->assertSame(QueueDeployment::STATUS_OPEN, $fresh->status);
    }

    public function test_reset_keeps_existing_tickets_and_reports_intact(): void
    {
        $office = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $staff = $this->makeStaff($office);

        (new QueueDeploymentManager())->start($office);
        $ticket = $this->issueTicket($office);
        $oldDeploymentId = $ticket->deployment_id;

        // Move to batch #2 so the reset has something to reset.
        (new QueueDeploymentManager())->completeAndRestart($office);

        $this->actingAs($staff)->post(route('staff.admissions.reset-batch-number'))->assertRedirect();

        // The ticket still exists on its original deployment (history).
        $kept = QueueTicket::find($ticket->id);
        $this->assertNotNull($kept);
        $this->assertSame($oldDeploymentId, $kept->deployment_id);
        $this->assertSame('CASH-001', $kept->queue_number);
        $this->assertSame(QueueTicket::STATUS_WAITING, $kept->status);

        // New tickets start fresh in Batch 1 with no identifier clash.
        $next = $this->issueTicket($office);
        $this->assertSame('CASH-001', $next->queue_number);
        $this->assertNotSame($oldDeploymentId, $next->deployment_id);
        $this->assertSame(1, (int) $next->deployment->batch_no);
    }

    public function test_reset_leaves_other_offices_untouched(): void
    {
        $cash = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $admissions = $this->makeOffice(['name' => 'Admissions', 'code' => 'ADM']);
        $cashStaff = $this->makeStaff($cash);

        (new QueueDeploymentManager())->start($cash);
        (new QueueDeploymentManager())->completeAndRestart($cash);
        (new QueueDeploymentManager())->start($admissions);
        (new QueueDeploymentManager())->completeAndRestart($admissions);

        $this->actingAs($cashStaff)->post(route('staff.admissions.reset-batch-number'))->assertRedirect();

        $this->assertNull(QueueDeployment::activeFor($cash->fresh()));
        $this->assertSame(1, QueueDeployment::nextBatchNoFor($cash->fresh()));
        $this->assertSame(2, (int) QueueDeployment::activeFor($admissions->fresh())->batch_no);
    }

    public function test_reset_keeps_an_offline_office_offline(): void
    {
        $office = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $staff = $this->makeStaff($office);

        (new QueueDeploymentManager())->start($office);
        $office->applyStatusToGroup(Office::STATUS_OFFLINE);

        $this->actingAs($staff)->post(route('staff.admissions.reset-batch-number'))->assertRedirect();

        $this->assertSame(Office::STATUS_OFFLINE, $office->fresh()->status);
        $this->assertSame(1, QueueDeployment::nextBatchNoFor($office->fresh()));

        // An offline office still refuses tickets even after a reset.
        try {
            $this->issueTicket($office);
            $this->fail('An offline office must refuse ticket creation after a reset.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(
                QueueTicketCreator::OFFLINE_MESSAGE,
                $e->errors()['office_id'][0]
            );
        }
    }

    public function test_reset_is_refused_for_guests_students_and_admins(): void
    {
        $office = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        (new QueueDeploymentManager())->start($office);

        $this->post(route('staff.admissions.reset-batch-number'))->assertRedirect('/admin/login');

        $this->actingAs($this->makeStudent(['student_id' => '24-000099']))->post(
            route('staff.admissions.reset-batch-number')
        )->assertForbidden();

        $this->actingAs($this->makeUser(User::ROLE_ADMIN))->post(
            route('staff.admissions.reset-batch-number')
        )->assertForbidden();

        // Nothing changed.
        $this->assertSame(1, (int) QueueDeployment::activeFor($office->fresh())->batch_no);
    }

    public function test_staff_cannot_reset_an_office_they_are_not_assigned_to(): void
    {
        $cash = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $admissions = $this->makeOffice(['name' => 'Admissions', 'code' => 'ADM']);
        $admissionsStaff = $this->makeStaff($admissions);

        (new QueueDeploymentManager())->start($cash);
        (new QueueDeploymentManager())->completeAndRestart($cash);
        (new QueueDeploymentManager())->start($admissions);

        // Posting as Admissions staff only ever touches Admissions.
        $this->actingAs($admissionsStaff)->post(route('staff.admissions.reset-batch-number'))->assertRedirect();

        $this->assertSame(2, (int) QueueDeployment::activeFor($cash->fresh())->batch_no);
        $this->assertNull(QueueDeployment::activeFor($admissions->fresh()));
        $this->assertSame(1, QueueDeployment::nextBatchNoFor($admissions->fresh()));
    }

    public function test_repeated_reset_creates_no_extra_batches(): void
    {
        $office = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $staff = $this->makeStaff($office);

        (new QueueDeploymentManager())->start($office);

        $this->actingAs($staff)
            ->post(route('staff.admissions.reset-batch-number'))
            ->assertRedirect()
            ->assertSessionHas('status', 'Batch number successfully reset to Batch 1.');

        $rowsAfterFirst = QueueDeployment::where('office_id', $office->id)->count();

        // A repeated click completes nothing new and anchors the same point.
        $this->actingAs($staff)
            ->post(route('staff.admissions.reset-batch-number'))
            ->assertRedirect()
            ->assertSessionHas('status', 'Batch number successfully reset to Batch 1.');

        $this->assertSame($rowsAfterFirst, QueueDeployment::where('office_id', $office->id)->count());
        $this->assertSame(1, QueueDeployment::nextBatchNoFor($office->fresh()));
    }

    public function test_dashboard_shows_the_reset_button_with_confirm_dialog(): void
    {
        $office = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $staff = $this->makeStaff($office);

        (new QueueDeploymentManager())->start($office);

        $this->actingAs($staff)->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('Reset Batch Number', false)
            ->assertSee('Reset Batch Number?', false)
            ->assertSee('Confirm Reset', false)
            ->assertSee(route('staff.admissions.reset-batch-number'), false);
    }

    public function test_my_office_page_shows_the_reset_button(): void
    {
        $office = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $staff = $this->makeStaff($office);

        $this->actingAs($staff)->get(route('staff.office.show'))
            ->assertOk()
            ->assertSee('Reset Batch Number', false)
            ->assertSee('Confirm Reset', false);
    }

    public function test_sequence_continues_from_two_after_a_reset_from_five(): void
    {
        $office = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $staff = $this->makeStaff($office);
        $manager = new QueueDeploymentManager();

        // Deploy five batches: 1, then 2, 3, 4, 5.
        $manager->start($office);
        for ($i = 2; $i <= 5; $i++) {
            $manager->completeAndRestart($office);
        }
        $this->assertSame(5, (int) QueueDeployment::activeFor($office)->batch_no);

        // Reset: no active batch remains; the next deployment is Batch 1.
        $this->actingAs($staff)->post(route('staff.admissions.reset-batch-number'))->assertRedirect();
        $this->assertNull(QueueDeployment::activeFor($office->fresh()));
        $this->assertSame(1, QueueDeployment::nextBatchNoFor($office->fresh()));

        // Deploy Batch 1, then continue 2, 3, 4 — history is preserved.
        $manager->start($office->fresh());
        $this->assertSame(1, (int) QueueDeployment::activeFor($office->fresh())->batch_no);

        foreach ([2, 3, 4] as $expected) {
            $manager->completeAndRestart($office->fresh());
            $this->assertSame($expected, (int) QueueDeployment::activeFor($office->fresh())->batch_no);
        }

        // Archived batches 1-5 plus the reset sequence 1-4 all still exist.
        $this->assertSame(5 + 4, QueueDeployment::where('office_id', $office->id)->count());
    }

    public function test_deploy_after_a_reset_opens_batch_one(): void
    {
        $office = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $staff = $this->makeStaff($office);

        (new QueueDeploymentManager())->start($office);
        (new QueueDeploymentManager())->completeAndRestart($office);

        $this->actingAs($staff)->post(route('staff.admissions.reset-batch-number'))->assertRedirect();

        // The existing deploy control opens Batch 1 for intake.
        $this->actingAs($staff)->post(route('staff.admissions.deploy'))->assertRedirect();
        $deployment = QueueDeployment::activeFor($office->fresh());
        $this->assertSame(1, (int) $deployment->batch_no);
        $this->assertSame(QueueDeployment::STATUS_OPEN, $deployment->status);
    }

    public function test_dashboard_deploy_button_shows_the_next_batch_number(): void
    {
        $office = $this->makeOffice(['name' => 'Cashier', 'code' => 'CASH']);
        $staff = $this->makeStaff($office);
        $manager = new QueueDeploymentManager();

        // Advance to batch #5, then reset: the deploy control must offer
        // Batch 1, reflecting the backend sequence rather than a static label.
        $manager->start($office);
        for ($i = 2; $i <= 5; $i++) {
            $manager->completeAndRestart($office);
        }

        $this->actingAs($staff)->post(route('staff.admissions.reset-batch-number'))->assertRedirect();

        $this->actingAs($staff)->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('Deploy Batch 1', false);

        // Deploy it, close it: the control now offers Batch 2.
        $manager->start($office->fresh());
        $manager->closeBatch($office->fresh());

        $this->actingAs($staff)->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('Deploy Batch 2', false);
    }
}
