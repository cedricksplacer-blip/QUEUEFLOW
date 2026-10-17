<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * The admin Staff Management form, in particular the "Account is active"
 * toggle on the edit screen.
 *
 * The switch is a checkbox underneath, so the case worth covering carefully is
 * switching it OFF: an unchecked box submits no value at all, and the
 * controller reads it with $request->boolean(). If that ever regresses, an
 * admin would click the toggle, save, and the staff account would stay active.
 */
class AdminStaffManagementTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    private ?Office $office = null;

    public function test_the_edit_screen_renders_a_clickable_toggle(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $member = $this->makeStaffMember();

        $this->actingAs($admin)
            ->get(route('admin.staff.edit', $member))
            ->assertOk()
            // The wrapper makes the whole row, not just the track, clickable.
            ->assertSee('class="toggle"', false)
            ->assertSee('name="is_active"', false)
            ->assertSee('Account is active')
            // A real checkbox, not a div or a scripted button, so it still
            // submits a plain name/value pair.
            ->assertSee('type="checkbox"', false);
    }

    public function test_an_active_member_renders_the_toggle_as_on(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $member = $this->makeStaffMember(['is_active' => true]);

        $this->actingAs($admin)
            ->get(route('admin.staff.edit', $member))
            ->assertOk()
            ->assertSee('name="is_active" value="1" checked', false);
    }

    public function test_an_inactive_member_renders_the_toggle_as_off(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $member = $this->makeStaffMember(['is_active' => false]);

        $this->actingAs($admin)
            ->get(route('admin.staff.edit', $member))
            ->assertOk()
            ->assertSee('name="is_active" value="1"', false)
            ->assertDontSee('name="is_active" value="1" checked', false);
    }

    public function test_an_admin_can_switch_the_toggle_off(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $office = $this->makeOperationalOffice();
        $member = $this->makeStaffMember(['office_id' => $office->id, 'is_active' => true]);

        $this->actingAs($admin)
            ->put(route('admin.staff.update', $member), [
                'name' => $member->name,
                'email' => $member->email,
                'office_id' => $office->id,
                // Deliberately no is_active key: that is exactly what an
                // unchecked box sends.
            ])
            ->assertRedirect(route('admin.staff.index'));

        $this->assertFalse($member->refresh()->is_active);
    }

    public function test_an_admin_can_switch_the_toggle_on(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $office = $this->makeOperationalOffice();
        $member = $this->makeStaffMember(['office_id' => $office->id, 'is_active' => false]);

        $this->actingAs($admin)
            ->put(route('admin.staff.update', $member), [
                'name' => $member->name,
                'email' => $member->email,
                'office_id' => $office->id,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.staff.index'));

        $this->assertTrue($member->refresh()->is_active);
    }

    public function test_switching_the_toggle_off_survives_a_reload(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $office = $this->makeOperationalOffice();
        $member = $this->makeStaffMember(['office_id' => $office->id, 'is_active' => true]);

        $this->actingAs($admin)->put(route('admin.staff.update', $member), [
            'name' => $member->name,
            'email' => $member->email,
            'office_id' => $office->id,
        ])->assertRedirect(route('admin.staff.index'));

        // The index badge has to agree with what was saved.
        $this->actingAs($admin)
            ->get(route('admin.staff.index'))
            ->assertOk()
            ->assertSee('Blocked');

        $this->actingAs($admin)
            ->get(route('admin.staff.edit', $member))
            ->assertOk()
            ->assertDontSee('name="is_active" value="1" checked', false);
    }

    public function test_the_toggle_keeps_the_other_fields_saving(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $office = $this->makeOperationalOffice();
        $member = $this->makeStaffMember(['office_id' => $office->id, 'is_active' => true]);

        $this->actingAs($admin)
            ->put(route('admin.staff.update', $member), [
                'name' => 'Renamed Staffer',
                'email' => 'renamed@tmc.test',
                'phone' => '09171234567',
                'office_id' => $office->id,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.staff.index'));

        $member->refresh();

        $this->assertSame('Renamed Staffer', $member->name);
        $this->assertSame('renamed@tmc.test', $member->email);
        $this->assertSame('09171234567', $member->phone);
        $this->assertTrue($member->is_active);
    }

    public function test_staff_management_is_admin_only(): void
    {
        $member = $this->makeStaffMember();

        $this->actingAs($this->makeUser(User::ROLE_STAFF))
            ->get(route('admin.staff.edit', $member))
            ->assertForbidden();
    }

    public function test_the_edit_screen_404s_for_a_non_staff_account(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $student = $this->makeUser(User::ROLE_STUDENT);

        $this->actingAs($admin)
            ->get(route('admin.staff.edit', $student))
            ->assertNotFound();
    }

    private function makeStaffMember(array $attrs = []): User
    {
        return $this->makeUser(User::ROLE_STAFF, array_merge([
            'office_id' => $this->makeOperationalOffice()->id,
            'is_active' => true,
        ], $attrs));
    }

    /**
     * The controller only accepts offices whose code is operational, so the
     * shared REG fixture (code "REG") would be rejected with a 404 on save.
     * offices.code is unique, so build it once per test and reuse it.
     */
    private function makeOperationalOffice(): Office
    {
        return $this->office ??= $this->makeOffice([
            'name' => 'Registrar BSIT',
            'code' => 'REG-BSIT',
        ]);
    }
}
