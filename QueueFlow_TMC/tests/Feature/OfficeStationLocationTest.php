<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\OfficeStation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

class OfficeStationLocationTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    private function operationalOffice(array $attrs = []): Office
    {
        return $this->makeOffice(array_merge([
            'name' => 'Cashier',
            'code' => 'CASH',
        ], $attrs));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cashier Window 1',
            'location' => 'Main Campus',
            'course_codes' => [],
            'min_daily_students' => 40,
            'target_daily_students' => 60,
            'max_daily_slots' => 100,
            'opening_time' => '08:00',
            'closing_time' => '15:00',
            'is_active' => 1,
        ], $overrides);
    }

    public function test_admin_can_create_a_station_with_a_location(): void
    {
        $office = $this->operationalOffice();
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.offices.stations.store', $office), $this->payload([
                'location' => 'Ground Floor, Admin Building, Room 3',
            ]))
            ->assertRedirect(route('admin.offices.index'));

        $this->assertDatabaseHas('office_stations', [
            'office_id' => $office->id,
            'name' => 'Cashier Window 1',
            'location' => 'Ground Floor, Admin Building, Room 3',
        ]);
    }

    public function test_new_station_form_prefills_the_verified_campus_address(): void
    {
        $office = $this->operationalOffice();
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.offices.stations.create', $office))
            ->assertOk()
            ->assertSee(OfficeStation::CAMPUS_LOCATION);
    }

    public function test_admin_can_edit_a_station_location(): void
    {
        $office = $this->operationalOffice();
        $station = OfficeStation::create([
            'office_id' => $office->id,
            'name' => 'Cashier Window 1',
            'location' => OfficeStation::CAMPUS_LOCATION,
            'course_codes' => [],
            'min_daily_students' => 40,
            'target_daily_students' => 60,
            'max_daily_slots' => 100,
            'opening_time' => '08:00',
            'closing_time' => '15:00',
            'is_active' => true,
        ]);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->put(route('admin.offices.stations.update', [$office, $station]), $this->payload([
                'location' => 'Ground Floor, Admin Building, Room 3',
            ]))
            ->assertRedirect(route('admin.offices.index'));

        $this->assertSame('Ground Floor, Admin Building, Room 3', $station->fresh()->location);
    }

    public function test_station_routes_are_admin_only(): void
    {
        $office = $this->operationalOffice();
        $student = $this->makeUser(User::ROLE_STUDENT);

        $this->actingAs($student)
            ->get(route('admin.offices.stations.create', $office))
            ->assertForbidden();
    }

    public function test_station_routes_reject_retired_offices(): void
    {
        $retired = $this->makeOffice(['name' => 'College Library', 'code' => 'LIB']);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.offices.stations.create', $retired))
            ->assertNotFound();
    }

    public function test_station_cannot_be_edited_through_another_offices_url(): void
    {
        $office = $this->operationalOffice();
        $other = $this->makeOffice(['name' => 'Admissions', 'code' => 'ADM']);
        $station = OfficeStation::create([
            'office_id' => $office->id,
            'name' => 'Cashier Window 1',
            'is_active' => true,
        ]);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.offices.stations.edit', [$other, $station]))
            ->assertNotFound();
    }

    public function test_station_with_queue_history_cannot_be_deleted(): void
    {
        $office = $this->operationalOffice();
        $station = OfficeStation::create([
            'office_id' => $office->id,
            'name' => 'Cashier Window 1',
            'is_active' => true,
        ]);
        $student = $this->makeUser(User::ROLE_STUDENT);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $office->queueTickets()->create([
            'queue_number' => 'CASH-001',
            'user_id' => $student->id,
            'station_id' => $station->id,
            'status' => 'served',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.offices.index'))
            ->delete(route('admin.offices.stations.destroy', [$office, $station]))
            ->assertRedirect(route('admin.offices.index'))
            ->assertSessionHasErrors('station');

        $this->assertDatabaseHas('office_stations', ['id' => $station->id]);
    }

    public function test_unused_station_can_be_deleted(): void
    {
        $office = $this->operationalOffice();
        $station = OfficeStation::create([
            'office_id' => $office->id,
            'name' => 'Unused Window',
            'is_active' => true,
        ]);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->delete(route('admin.offices.stations.destroy', [$office, $station]))
            ->assertRedirect();

        $this->assertDatabaseMissing('office_stations', ['id' => $station->id]);
    }

    public function test_offices_index_renders_station_links(): void
    {
        $office = $this->operationalOffice();
        $station = OfficeStation::create([
            'office_id' => $office->id,
            'name' => 'Cashier Window 1',
            'location' => 'Ground Floor, Admin Building, Room 3',
            'course_codes' => [],
            'min_daily_students' => 40,
            'target_daily_students' => 60,
            'max_daily_slots' => 100,
            'opening_time' => '08:00',
            'closing_time' => '15:00',
            'is_active' => true,
        ]);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.offices.index'))
            ->assertOk()
            ->assertSee(route('admin.offices.stations.create', $office), false)
            ->assertSee(route('admin.offices.stations.edit', [$office, $station]), false)
            ->assertSee(route('admin.offices.stations.destroy', [$office, $station]), false)
            ->assertSee('Ground Floor, Admin Building, Room 3');
    }

    public function test_seeding_does_not_clobber_an_admin_edited_location(): void
    {
        $office = $this->operationalOffice();
        $station = OfficeStation::create([
            'office_id' => $office->id,
            'name' => 'Cashier Main Station',
            'location' => 'Ground Floor, Admin Building, Room 3',
            'course_codes' => [],
            'min_daily_students' => 40,
            'target_daily_students' => 60,
            'max_daily_slots' => 100,
            'opening_time' => '08:00',
            'closing_time' => '15:00',
            'is_active' => true,
        ]);

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame('Ground Floor, Admin Building, Room 3', $station->fresh()->location);
    }

    public function test_seeding_applies_the_campus_address_to_a_fresh_station(): void
    {
        $office = $this->operationalOffice();
        OfficeStation::create([
            'office_id' => $office->id,
            'name' => 'Cashier Main Station',
            'location' => null,
            'is_active' => true,
        ]);

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame(
            OfficeStation::CAMPUS_LOCATION,
            OfficeStation::where('name', 'Cashier Main Station')->value('location')
        );
    }
}
