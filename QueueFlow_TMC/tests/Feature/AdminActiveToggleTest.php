<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Course;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * The admin edit screens that all share the same "is this thing live?"
 * question: users, courses, offices and announcements.
 *
 * Each of them reads the field with $request->boolean('is_active'), so the
 * value worth protecting is the one an unchecked switch produces: no key in
 * the request body at all. If that regresses, an admin flips a switch off,
 * saves, and the record quietly stays live.
 */
class AdminActiveToggleTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    private User $admin;

    private ?Office $office = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser(User::ROLE_ADMIN);
    }

    public function test_the_user_edit_screen_uses_the_toggle(): void
    {
        $target = $this->makeUser(User::ROLE_STAFF, [
            'office_id' => $this->office()->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee('class="toggle"', false)
            ->assertSee('name="is_active" value="1" checked', false)
            ->assertSee('Account is active');
    }

    public function test_the_user_edit_screen_keeps_an_inactive_user_looking_off(): void
    {
        $target = $this->makeUser(User::ROLE_STAFF, [
            'office_id' => $this->office()->id,
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee('name="is_active" value="1"', false)
            ->assertDontSee('name="is_active" value="1" checked', false);
    }

    public function test_an_admin_can_deactivate_a_user_with_the_toggle(): void
    {
        $target = $this->makeUser(User::ROLE_STAFF, [
            'office_id' => $this->office()->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => User::ROLE_STAFF,
                'office_id' => $this->office()->id,
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertFalse($target->refresh()->is_active);
    }

    public function test_the_user_edit_screen_warns_that_your_own_account_stays_active(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.edit', $this->admin))
            ->assertOk()
            ->assertSee('your own account, so it stays active');
    }

    public function test_the_course_edit_screen_uses_the_toggle(): void
    {
        $course = $this->makeCourse(['is_active' => true]);

        $this->actingAs($this->admin)
            ->get(route('admin.courses.edit', $course))
            ->assertOk()
            ->assertSee('class="toggle"', false)
            ->assertSee('name="is_active" value="1" checked', false)
            ->assertSee('Course is active');
    }

    public function test_an_admin_can_deactivate_a_course_with_the_toggle(): void
    {
        $course = $this->makeCourse(['is_active' => true]);

        $this->actingAs($this->admin)
            ->put(route('admin.courses.update', $course), [
                'code' => $course->code,
                'name' => $course->name,
            ])
            ->assertRedirect(route('admin.courses.index'));

        $this->assertFalse($course->refresh()->is_active);
    }

    public function test_an_admin_can_reactivate_a_course_with_the_toggle(): void
    {
        $course = $this->makeCourse(['is_active' => false]);

        $this->actingAs($this->admin)
            ->put(route('admin.courses.update', $course), [
                'code' => $course->code,
                'name' => $course->name,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.courses.index'));

        $this->assertTrue($course->refresh()->is_active);
    }

    public function test_the_office_edit_screen_uses_the_toggle(): void
    {
        $office = $this->office();

        $this->actingAs($this->admin)
            ->get(route('admin.offices.edit', $office))
            ->assertOk()
            ->assertSee('class="toggle"', false)
            ->assertSee('name="is_active" value="1" checked', false)
            ->assertSee('Office is active');
    }

    public function test_an_admin_can_deactivate_an_office_with_the_toggle(): void
    {
        $office = $this->office();

        $this->actingAs($this->admin)
            ->put(route('admin.offices.update', $office), $this->officePayload($office))
            ->assertRedirect(route('admin.offices.index'));

        $this->assertFalse($office->refresh()->is_active);
    }

    public function test_an_office_keeps_its_times_and_slot_cap_when_the_toggle_is_used(): void
    {
        $office = $this->office();

        $this->actingAs($this->admin)
            ->put(route('admin.offices.update', $office), $this->officePayload($office, [
                'opening_time' => '09:30',
                'closing_time' => '16:00',
                'max_slots' => 42,
            ]))
            ->assertRedirect(route('admin.offices.index'));

        $office->refresh();

        // offices.opening_time/closing_time are TIME columns, so they read
        // back with seconds even though the form posts H:i.
        $this->assertSame('09:30:00', $office->opening_time);
        $this->assertSame('16:00:00', $office->closing_time);
        $this->assertSame(42, $office->max_slots);
        $this->assertFalse($office->is_active);
    }

    public function test_the_announcement_edit_screen_uses_the_toggle(): void
    {
        $notice = Announcement::create([
            'title' => 'Exam week',
            'body' => 'Registration for the second trimester is open.',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.announcements.edit', $notice))
            ->assertOk()
            ->assertSee('class="toggle"', false)
            ->assertSee('name="is_active" value="1" checked', false)
            ->assertSee('Visible to students');
    }

    public function test_an_admin_can_hide_an_announcement_with_the_toggle(): void
    {
        $notice = Announcement::create([
            'title' => 'Exam week',
            'body' => 'Registration for the second trimester is open.',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.announcements.update', $notice), [
                'title' => $notice->title,
                'body' => $notice->body,
            ])
            ->assertRedirect(route('admin.announcements.index'));

        $this->assertFalse($notice->refresh()->is_active);
    }

    public function test_hiding_an_announcement_keeps_its_text(): void
    {
        $notice = Announcement::create([
            'title' => 'Exam week',
            'body' => 'Registration for the second trimester is open.',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->put(route('admin.announcements.update', $notice), [
            'title' => 'Exam week (updated)',
            'body' => $notice->body,
        ])->assertRedirect(route('admin.announcements.index'));

        $notice->refresh();

        $this->assertSame('Exam week (updated)', $notice->title);
        $this->assertFalse($notice->is_active);
    }

    /**
     * The controller only accepts operational office codes, and offices.code
     * is unique, so build one per test and reuse it.
     */
    private function office(): Office
    {
        return $this->office ??= $this->makeOffice([
            'name' => 'Registrar BSIT',
            'code' => 'REG-BSIT',
        ]);
    }

    private function officePayload(Office $office, array $overrides = []): array
    {
        return array_merge([
            'name' => $office->name,
            'code' => $office->code,
            'description' => $office->description,
            'opening_time' => '08:00',
            'closing_time' => '17:00',
            'max_slots' => 100,
        ], $overrides);
    }
}
