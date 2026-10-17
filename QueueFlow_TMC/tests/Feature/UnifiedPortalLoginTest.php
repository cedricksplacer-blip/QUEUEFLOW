<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedPortalLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_login_screen_renders_without_a_staff_login_option(): void
    {
        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('AUTHENTICATION')
            ->assertDontSee('Login as Staff');
    }

    public function test_admin_credentials_open_the_admin_dashboard(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_staff_credentials_open_the_assigned_office(): void
    {
        $office = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $staff = User::factory()->create([
            'role' => User::ROLE_STAFF,
            'office_id' => $office->id,
        ]);

        $response = $this->post(route('admin.login.store'), [
            'email' => $staff->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('staff.dashboard'));
        $this->assertAuthenticatedAs($staff);
        $response->assertSessionHas('staff_office_id', $office->id);
    }

    public function test_staff_login_ignores_a_submitted_office_and_uses_the_account_assignment(): void
    {
        $assignedOffice = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $otherOffice = $this->makeOffice(['code' => 'ADM', 'name' => 'Admissions']);
        $staff = User::factory()->create([
            'role' => User::ROLE_STAFF,
            'office_id' => $assignedOffice->id,
        ]);

        $response = $this->post(route('admin.login.store'), [
            'email' => $staff->email,
            'password' => 'password',
            'office_id' => $otherOffice->id,
        ]);

        $response->assertRedirect(route('staff.dashboard'));
        $response->assertSessionHas('staff_office_id', $assignedOffice->id);
    }

    public function test_staff_office_scope_ignores_a_tampered_session_office(): void
    {
        $assignedOffice = $this->makeOffice(['code' => 'CASH', 'name' => 'Cashier']);
        $otherOffice = $this->makeOffice(['code' => 'ADM', 'name' => 'Admissions']);
        $staff = User::factory()->create([
            'role' => User::ROLE_STAFF,
            'office_id' => $assignedOffice->id,
        ]);

        $response = $this->actingAs($staff)
            ->withSession(['staff_office_id' => $otherOffice->id])
            ->get(route('staff.dashboard'));

        $response->assertOk();
        $response->assertSee($assignedOffice->name);
        $response->assertDontSee($otherOffice->name);
    }

    public function test_staff_without_an_active_operational_office_cannot_login(): void
    {
        $staff = User::factory()->create([
            'role' => User::ROLE_STAFF,
            'office_id' => null,
        ]);

        $response = $this->post(route('admin.login.store'), [
            'email' => $staff->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_legacy_staff_login_url_redirects_to_the_unified_portal(): void
    {
        $this->get('/staff/login')
            ->assertRedirect(route('admin.login'));
    }

    private function makeOffice(array $attributes = []): Office
    {
        return Office::create(array_merge([
            'name' => 'Test Office',
            'code' => 'CASH',
            'description' => null,
            'is_active' => true,
            'opening_time' => '08:00',
            'closing_time' => '15:00',
            'max_slots' => 100,
        ], $attributes));
    }
}
