<?php

namespace Tests\Feature;

use App\Models\QueueDeployment;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\QueueDeploymentManager;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

class QueueDeploymentTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateLocalStorage();
    }

    public function test_batch_lifecycle_deploy_serve_complete_and_restart(): void
    {
        $office = $this->makeOffice(['code' => 'CASH']);
        $course = $this->makeCourse(['office_id' => $office->id]);
        $service = $this->makeService($office);
        $student = $this->makeUser(User::ROLE_STUDENT);
        $manager = new QueueDeploymentManager();
        $creator = new QueueTicketCreator();

        $manager->start($office);

        $batch = QueueDeployment::openFor($office);
        $this->assertSame(QueueDeployment::STATUS_OPEN, $batch->status);
        $this->assertSame(1, $batch->batch_no);
        $this->assertSame(100, $batch->max_slots);

        $ticket = $creator->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $student->id,
            'course_id' => $course->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])['ticket'];

        $manager->deploy($office);
        $this->assertSame(
            QueueDeployment::STATUS_DEPLOYED,
            QueueDeployment::activeFor($office)->status
        );

        $ticket->update([
            'status' => QueueTicket::STATUS_SERVED,
            'served_at' => now(),
        ]);
        $manager->refreshAfterService($ticket);

        $this->assertNull(QueueDeployment::activeFor($office));
        $this->assertSame(
            QueueDeployment::STATUS_COMPLETED,
            QueueDeployment::where('office_id', $office->id)->value('status')
        );

        $restarted = $manager->start($office);
        $this->assertSame(QueueDeployment::STATUS_OPEN, $restarted->status);
        $this->assertSame(2, $restarted->batch_no);
    }

    public function test_slot_cap_rejects_overflow_and_marks_batch_full(): void
    {
        $office = $this->makeOffice(['max_slots' => 5]);
        $service = $this->makeService($office);
        $creator = new QueueTicketCreator();

        for ($i = 0; $i < 5; $i++) {
            $creator->create([
                'office_id' => $office->id,
                'service_id' => $service->id,
                'priority' => QueueTicket::PRIORITY_REGULAR,
            ]);
        }

        $batch = QueueDeployment::where('office_id', $office->id)->latest('id')->first();
        $this->assertSame(5, $batch->submittedCount());
        $this->assertSame(QueueDeployment::STATUS_OPEN, $batch->status);

        try {
            $creator->create([
                'office_id' => $office->id,
                'service_id' => $service->id,
                'priority' => QueueTicket::PRIORITY_REGULAR,
            ]);
            $this->fail('Expected a ValidationException for a full batch.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('maximum', $e->errors()['office_id'][0]);
        }

        $batch->refresh();
        $this->assertSame(QueueDeployment::STATUS_FULL, $batch->status);
    }

    public function test_complete_and_restart_while_deployed_opens_a_new_batch(): void
    {
        $office = $this->makeOffice();
        $service = $this->makeService($office);
        $manager = new QueueDeploymentManager();

        $manager->start($office);
        (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ]);
        $manager->deploy($office);

        $manager->completeAndRestart($office);

        $active = QueueDeployment::activeFor($office);
        $this->assertSame(QueueDeployment::STATUS_OPEN, $active->status);
        $this->assertSame(2, $active->batch_no);
        $this->assertSame(1, QueueDeployment::where('status', QueueDeployment::STATUS_COMPLETED)->count());
    }
}