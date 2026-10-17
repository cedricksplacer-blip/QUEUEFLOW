<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

class AnnouncementFilterTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    private function office(string $code, string $name): Office
    {
        return $this->makeOffice([
            'name' => $name,
            'code' => $code,
            'opening_time' => '00:00',
            'closing_time' => '23:59',
        ]);
    }

    private function notice(string $title, string $status, ?Office $office = null, bool $active = false): Announcement
    {
        return Announcement::create([
            'title' => $title,
            'body' => 'Body for ' . $title,
            'status' => $status,
            'is_active' => $active,
            'office_id' => $office?->id,
            'posted_by' => $this->makeUser(User::ROLE_ADMIN)->id,
            'submitted_by' => $office ? $this->makeUser(User::ROLE_STAFF)->id : null,
        ]);
    }

    /**
     * Titles of the listed notices, sorted.
     *
     * The list is ordered by created_at, but the column is second-precision, so
     * rows created inside one test tie and MySQL may return either order. These
     * tests assert which notices survive the filter, not their sequence.
     */
    private function titles($announcements): array
    {
        $titles = $announcements->pluck('title')->all();
        sort($titles);

        return $titles;
    }

    public function test_status_counts_cover_every_office(): void
    {
        $cashier = $this->office('CASH', 'Cashier');
        $admissions = $this->office('ADM', 'Admissions');

        $this->notice('C-pending', Announcement::STATUS_PENDING, $cashier);
        $this->notice('C-published', Announcement::STATUS_PUBLISHED, $cashier, true);
        $this->notice('C-rejected', Announcement::STATUS_REJECTED, $cashier);
        $this->notice('A-pending', Announcement::STATUS_PENDING, $admissions);

        $admin = $this->makeUser(User::ROLE_ADMIN);

        $counts = $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->viewData('counts');

        $this->assertSame(4, $counts['all']);
        $this->assertSame(2, $counts[Announcement::STATUS_PENDING]);
        $this->assertSame(1, $counts[Announcement::STATUS_PUBLISHED]);
        $this->assertSame(1, $counts[Announcement::STATUS_REJECTED]);
    }

    public function test_counts_narrow_to_the_selected_office(): void
    {
        $cashier = $this->office('CASH', 'Cashier');
        $admissions = $this->office('ADM', 'Admissions');

        $this->notice('C-pending', Announcement::STATUS_PENDING, $cashier);
        $this->notice('C-published', Announcement::STATUS_PUBLISHED, $cashier, true);
        $this->notice('A-pending', Announcement::STATUS_PENDING, $admissions);

        $admin = $this->makeUser(User::ROLE_ADMIN);

        $response = $this->actingAs($admin)
            ->get(route('admin.announcements.index', ['office' => $cashier->id]))
            ->assertOk();

        $counts = $response->viewData('counts');

        $this->assertSame(2, $counts['all']);
        $this->assertSame(1, $counts[Announcement::STATUS_PENDING]);
        $this->assertSame(1, $counts[Announcement::STATUS_PUBLISHED]);
        $this->assertSame(0, $counts[Announcement::STATUS_REJECTED]);

        $this->assertSame(
            ['C-pending', 'C-published'],
            $this->titles($response->viewData('announcements'))
        );
    }

    public function test_office_and_status_filters_combine(): void
    {
        $cashier = $this->office('CASH', 'Cashier');
        $admissions = $this->office('ADM', 'Admissions');

        $this->notice('C-pending', Announcement::STATUS_PENDING, $cashier);
        $this->notice('C-published', Announcement::STATUS_PUBLISHED, $cashier, true);
        $this->notice('A-pending', Announcement::STATUS_PENDING, $admissions);

        $admin = $this->makeUser(User::ROLE_ADMIN);

        $response = $this->actingAs($admin)
            ->get(route('admin.announcements.index', [
                'office' => $cashier->id,
                'status' => Announcement::STATUS_PENDING,
            ]))
            ->assertOk();

        $this->assertSame(
            ['C-pending'],
            $this->titles($response->viewData('announcements'))
        );
    }

    public function test_unpublished_is_an_alias_for_pending(): void
    {
        $cashier = $this->office('CASH', 'Cashier');
        $this->notice('The pending one', Announcement::STATUS_PENDING, $cashier);
        $this->notice('The live one', Announcement::STATUS_PUBLISHED, $cashier, true);

        $admin = $this->makeUser(User::ROLE_ADMIN);

        $response = $this->actingAs($admin)
            ->get(route('admin.announcements.index', ['status' => 'unpublished']))
            ->assertOk();

        $this->assertSame(
            ['The pending one'],
            $this->titles($response->viewData('announcements'))
        );
    }

    public function test_retired_global_filter_value_falls_back_to_every_office(): void
    {
        $cashier = $this->office('CASH', 'Cashier');
        $this->notice('Office notice', Announcement::STATUS_PUBLISHED, $cashier, true);
        $this->notice('Global notice', Announcement::STATUS_PUBLISHED, null, true);

        $admin = $this->makeUser(User::ROLE_ADMIN);

        // "global" is no longer offered. Old bookmarked links must degrade to
        // "every office" rather than matching a non-existent office 0.
        $response = $this->actingAs($admin)
            ->get(route('admin.announcements.index', ['office' => 'global']))
            ->assertOk();

        $this->assertNull($response->viewData('office'));
        $this->assertSame(
            ['Global notice', 'Office notice'],
            $this->titles($response->viewData('announcements'))
        );
    }

    public function test_unknown_office_value_falls_back_to_every_office(): void
    {
        $cashier = $this->office('CASH', 'Cashier');
        $this->notice('Office notice', Announcement::STATUS_PUBLISHED, $cashier, true);

        $admin = $this->makeUser(User::ROLE_ADMIN);

        $response = $this->actingAs($admin)
            ->get(route('admin.announcements.index', ['office' => 'not-an-office']))
            ->assertOk();

        $this->assertNull($response->viewData('office'));
        $this->assertSame(
            ['Office notice'],
            $this->titles($response->viewData('announcements'))
        );
    }

    public function test_office_filter_no_longer_offers_a_global_option(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertDontSee('Global — no office');
    }

    public function test_office_filter_offers_retired_offices_that_still_have_notices(): void
    {
        $this->office('CASH', 'Cashier');
        $legacy = $this->makeOffice([
            'name' => 'College Library',
            'code' => 'LIB',
            'is_active' => false,
        ]);
        $this->notice('Legacy notice', Announcement::STATUS_PUBLISHED, $legacy, true);

        $admin = $this->makeUser(User::ROLE_ADMIN);

        $response = $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertSee('College Library');

        $offices = $response->viewData('offices');

        $this->assertContains('Cashier', $offices->all());
        $this->assertContains('College Library', $offices->all());
    }

    public function test_office_filter_does_not_list_offices_without_notices(): void
    {
        $this->office('CASH', 'Cashier');
        $this->makeOffice(['name' => 'College Library', 'code' => 'LIB', 'is_active' => false]);

        $admin = $this->makeUser(User::ROLE_ADMIN);

        $offices = $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertDontSee('College Library')
            ->viewData('offices');

        $this->assertNotContains('College Library', $offices->all());
    }

    public function test_pending_notice_can_be_accepted_and_published(): void
    {
        $cashier = $this->office('CASH', 'Cashier');
        $notice = $this->notice('Needs review', Announcement::STATUS_PENDING, $cashier);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->from(route('admin.announcements.index'))
            ->post(route('admin.announcements.approve', $notice))
            ->assertRedirect(route('admin.announcements.index'));

        $notice->refresh();

        $this->assertSame(Announcement::STATUS_PUBLISHED, $notice->status);
        $this->assertTrue($notice->is_active);
        $this->assertSame($admin->id, $notice->reviewed_by);
        $this->assertNotNull($notice->reviewed_at);
    }

    public function test_pending_notice_can_be_rejected(): void
    {
        $cashier = $this->office('CASH', 'Cashier');
        $notice = $this->notice('Bad notice', Announcement::STATUS_PENDING, $cashier);

        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->from(route('admin.announcements.index'))
            ->post(route('admin.announcements.reject', $notice))
            ->assertRedirect(route('admin.announcements.index'));

        $notice->refresh();

        $this->assertSame(Announcement::STATUS_REJECTED, $notice->status);
        $this->assertFalse($notice->is_active);
    }

    public function test_already_reviewed_notice_cannot_be_approved_again(): void
    {
        $cashier = $this->office('CASH', 'Cashier');
        $notice = $this->notice('Already live', Announcement::STATUS_PUBLISHED, $cashier, true);

        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->post(route('admin.announcements.approve', $notice))
            ->assertStatus(422);
    }

    public function test_announcements_are_admin_only(): void
    {
        $this->actingAs($this->makeUser(User::ROLE_STAFF))
            ->get(route('admin.announcements.index'))
            ->assertForbidden();
    }

    public function test_dashboard_hides_unpublished_notices(): void
    {
        $this->notice('Live one', Announcement::STATUS_PUBLISHED, null, true);
        // A pending record that is somehow still flagged active must not leak.
        $this->notice('Leaky pending', Announcement::STATUS_PENDING, null, true);

        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Live one')
            ->assertDontSee('Leaky pending');
    }
}
