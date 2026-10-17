<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\QueueTicketCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * Admin dashboard activity accordion + PWD proof viewing.
 *
 * Covers: six office groups with real rows, Registrar per-course lists
 * that never mix courses, the polling feed shape and its admin-only
 * guard, and the evidence endpoint (real file streams, missing file 404s,
 * non-PWD 404s, guests/students refused).
 */
class AdminRecentActivityTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateLocalStorage();
    }

    private function makeAdmin(): User
    {
        return $this->makeUser(User::ROLE_ADMIN);
    }

    private function makeTicket(Office $office, array $attrs = []): QueueTicket
    {
        $service = $this->makeService($office);
        $student = $this->makeUser(User::ROLE_STUDENT, [
            'name' => 'Activity Student',
            'student_id' => '2026-'.uniqid(),
        ]);

        return (new QueueTicketCreator())->create(array_merge([
            'office_id' => $office->id,
            'service_id' => $service->id,
            'user_id' => $student->id,
            'purpose' => 'Activity probe',
        ], $attrs))['ticket'];
    }

    private function makePwdTicket(Office $office, array $attrs = []): QueueTicket
    {
        return $this->makeTicket($office, array_merge([
            'priority' => QueueTicket::PRIORITY_PWD,
            'priority_evidence' => $this->evidenceUpload(),
        ], $attrs));
    }

    public function test_dashboard_shows_six_office_groups(): void
    {
        $this->makeOffice(['code' => 'REG-BSIT', 'name' => 'Registrar - BSIT']);
        $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $this->makeOffice(['code' => 'ADM', 'name' => 'Admissions']);
        $this->makeOffice(['code' => 'SASO', 'name' => 'SASO']);
        $this->makeOffice(['code' => 'SCH', 'name' => 'Scholarship Office']);
        $this->makeOffice(['code' => 'CLINIC', 'name' => 'School Clinic']);

        $response = $this->actingAs($this->makeAdmin())
            ->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('Recent Queue Activity');
        $response->assertSee('Registrar');
        $response->assertSee('Cashier');
        $response->assertSee('Admissions');
        $response->assertSee('SASO');
        $response->assertSee('Scholarship');
        $response->assertSee('Clinic');
    }

    public function test_registrar_course_lists_never_mix_courses(): void
    {
        $bsitOffice = $this->makeOffice(['code' => 'REG-BSIT', 'name' => 'Registrar - BSIT']);
        $bsoaOffice = $this->makeOffice(['code' => 'REG-BSOA', 'name' => 'Registrar - BSOA']);
        $bsit = $this->makeCourse(['code' => 'BSIT', 'office_id' => $bsitOffice->id]);
        $bsoa = $this->makeCourse(['code' => 'BSOA', 'office_id' => $bsoaOffice->id]);

        $bsitTicket = $this->makeTicket($bsitOffice, ['course_id' => $bsit->id]);
        $bsoaTicket = $this->makeTicket($bsoaOffice, ['course_id' => $bsoa->id]);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/admin/recent-activity');

        $response->assertOk();

        $groups = collect($response->json('groups'))->keyBy('key');
        $registrar = $groups->get(Office::REGISTRAR_GROUP);
        $this->assertNotNull($registrar, 'Registrar group missing from feed.');

        $courses = collect($registrar['courses'])->keyBy('code');
        $bsitIds = collect($courses->get('BSIT')['tickets'])->pluck('id')->all();
        $bsoaIds = collect($courses->get('BSOA')['tickets'])->pluck('id')->all();

        $this->assertContains($bsitTicket->id, $bsitIds);
        $this->assertNotContains($bsoaTicket->id, $bsitIds);
        $this->assertContains($bsoaTicket->id, $bsoaIds);
        $this->assertNotContains($bsitTicket->id, $bsoaIds);
    }

    public function test_recent_activity_feed_shape_and_guards(): void
    {
        // Guards first: actingAs() below persists for the rest of the test.
        $this->getJson('/admin/recent-activity')->assertUnauthorized();

        $guardOffice = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);

        $this->actingAs($this->makeUser(User::ROLE_STUDENT))
            ->getJson('/admin/recent-activity')
            ->assertForbidden();

        $this->actingAs($this->makeUser(User::ROLE_STAFF, ['office_id' => $guardOffice->id]))
            ->getJson('/admin/recent-activity')
            ->assertForbidden();

        $office = $this->makeOffice(['code' => 'ADM', 'name' => 'Admissions']);
        $ticket = $this->makePwdTicket($office);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/admin/recent-activity');

        $response->assertOk();

        $group = collect($response->json('groups'))->firstWhere('key', 'ADM');
        $this->assertNotNull($group);

        $row = collect($group['tickets'])->firstWhere('id', $ticket->id);
        $this->assertNotNull($row);
        $this->assertSame($ticket->queue_number, $row['queue_number']);
        $this->assertSame('Activity Student', $row['student']);
        $this->assertTrue($row['has_evidence']);
        $this->assertStringContainsString(
            "/admin/queues/{$ticket->id}/evidence",
            $row['evidence_url']
        );
    }

    public function test_admin_evidence_streams_real_file(): void
    {
        $office = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $ticket = $this->makePwdTicket($office);

        Storage::disk('local')->assertExists($ticket->priority_evidence);

        $response = $this->actingAs($this->makeAdmin())
            ->get("/admin/queues/{$ticket->id}/evidence");

        $response->assertOk();
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
    }

    public function test_admin_evidence_missing_file_is_404_not_broken_image(): void
    {
        $office = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $ticket = $this->makePwdTicket($office);
        Storage::disk('local')->delete($ticket->priority_evidence);

        $this->actingAs($this->makeAdmin())
            ->get("/admin/queues/{$ticket->id}/evidence")
            ->assertNotFound();
    }

    public function test_admin_evidence_refuses_non_pwd_guests_and_students(): void
    {
        $office = $this->makeOffice(['code' => 'ADM', 'name' => 'Admissions']);
        $regular = $this->makeTicket($office);
        $pwd = $this->makePwdTicket($office);

        // Guards first: actingAs() below persists for the rest of the test.
        $this->get("/admin/queues/{$pwd->id}/evidence")
            ->assertRedirect('/admin/login');

        $this->actingAs($this->makeUser(User::ROLE_STUDENT))
            ->get("/admin/queues/{$pwd->id}/evidence")
            ->assertForbidden();

        // Non-PWD tickets have no evidence, even for admins.
        $this->actingAs($this->makeAdmin())
            ->get("/admin/queues/{$regular->id}/evidence")
            ->assertNotFound();
    }

    public function test_pwd_proof_modal_is_present_on_dashboard_and_queue_list(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('pwdProofModal', false);

        $this->actingAs($this->makeAdmin())
            ->get('/admin/queues')
            ->assertOk()
            ->assertSee('pwdProofModal', false);
    }

    public function test_dashboard_pwd_badge_is_the_proof_trigger(): void
    {
        $cashier = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $adm = $this->makeOffice(['code' => 'ADM', 'name' => 'Admissions']);

        $withProof = $this->makePwdTicket($cashier);

        // A PWD ticket whose upload never landed: the badge still opens the
        // viewer but advertises the notice instead of a broken image.
        $withoutProof = $this->makePwdTicket($adm);
        $withoutProof->forceFill(['priority_evidence' => null])->save();

        $response = $this->actingAs($this->makeAdmin())->get('/admin/dashboard');

        $response->assertOk()
            // The PWD badge itself is the clickable trigger...
            ->assertSee('js-pwd-proof badge badge-pwd qf-pwd-badge', false)
            // ...pointing at the admin-only evidence route...
            ->assertSee("/admin/queues/{$withProof->id}/evidence", false)
            ->assertSee('View PWD verification proof', false)
            // ...while the upload-less PWD ticket shows the clear notice.
            ->assertSee('No PWD proof picture available for this student.', false);
    }

    public function test_activity_history_pwd_badges_are_proof_triggers(): void
    {
        $cashier = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $adm = $this->makeOffice(['code' => 'ADM', 'name' => 'Admissions']);

        $first = $this->makePwdTicket($cashier);
        $second = $this->makePwdTicket($adm);
        // A regular ticket in the same feed must stay a plain badge.
        $this->makeTicket($cashier);

        $response = $this->actingAs($this->makeAdmin())->get('/admin/activity');

        $response->assertOk()
            ->assertSee('pwdProofModal', false)
            // Each PWD row is wired to its own ticket's evidence route, never
            // a shared or hardcoded image.
            ->assertSee("/admin/queues/{$first->id}/evidence", false)
            ->assertSee("/admin/queues/{$second->id}/evidence", false)
            ->assertSee('No PWD proof picture available for this student.', false);

        // Exactly the two PWD rows are clickable; the regular row is not.
        $this->assertSame(
            2,
            substr_count($response->getContent(), 'badge badge-pwd qf-pwd-badge')
        );
    }
}
