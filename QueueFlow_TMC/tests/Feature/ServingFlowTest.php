<?php

namespace Tests\Feature;

use App\Models\QueueTicket;
use App\Models\User;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

class ServingFlowTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateLocalStorage();
    }

    private function seedWaitingTickets(bool $withPwd): array
    {
        $office = $this->makeOffice();
        $course = $this->makeCourse(['office_id' => $office->id]);
        $service = $this->makeService($office);
        $studentOne = $this->makeUser(User::ROLE_STUDENT);
        $studentTwo = $this->makeUser(User::ROLE_STUDENT);
        $creator = new QueueTicketCreator();

        $regular = $creator->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $studentOne->id,
            'course_id' => $course->id,
            'priority' => QueueTicket::PRIORITY_REGULAR,
        ])['ticket'];

        $pwd = $withPwd
            ? $creator->create([
                'office_id' => $office->id,
                'service_id' => $service->id,
                'user_id' => $studentTwo->id,
                'course_id' => $course->id,
                'priority' => QueueTicket::PRIORITY_PWD,
                'priority_evidence' => $this->evidenceUpload(),
            ])['ticket']
            : null;

        return [$office, $regular, $pwd];
    }

    public function test_call_next_serves_pwd_before_regular(): void
    {
        [$office, $regular, $pwd] = $this->seedWaitingTickets(true);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('admin.queues.call-next', $office));

        $serving = QueueTicket::where('office_id', $office->id)
            ->where('status', QueueTicket::STATUS_SERVING)
            ->orderByDesc('called_at')
            ->first();

        $this->assertSame($pwd->queue_number, $serving->queue_number);
        $this->assertSame($admin->id, $serving->served_by);
        $this->assertNotNull($serving->called_at);
        $this->assertNotSame($regular->queue_number, $serving->queue_number);
    }

    public function test_call_next_is_blocked_while_an_office_is_already_serving(): void
    {
        [$office] = $this->seedWaitingTickets(false);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('admin.queues.call-next', $office));
        $second = $this->actingAs($admin)->post(route('admin.queues.call-next', $office));

        $second->assertSessionHas('status', function (string $message) {
            return str_contains($message, 'already has a ticket being served');
        });

        $this->assertSame(
            1,
            QueueTicket::where('office_id', $office->id)
                ->where('status', QueueTicket::STATUS_SERVING)
                ->count()
        );
    }

    public function test_evidence_route_streams_the_uploaded_image_to_admin(): void
    {
        $office = $this->makeOffice();
        $service = $this->makeService($office);
        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ])['ticket'];
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.queues.evidence', $ticket))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_evidence_route_denies_students(): void
    {
        $office = $this->makeOffice();
        $service = $this->makeService($office);
        $ticket = (new QueueTicketCreator())->create([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ])['ticket'];
        $student = $this->makeUser(User::ROLE_STUDENT);

        $this->actingAs($student)
            ->get(route('admin.queues.evidence', $ticket))
            ->assertForbidden();
    }
}