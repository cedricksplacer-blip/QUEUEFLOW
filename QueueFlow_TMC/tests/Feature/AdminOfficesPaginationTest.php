<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * Admin Offices pagination mirrors User Management.
 *
 * Both sections render the shared `vendor.pagination.queueflow`
 * component: result-range info plus Previous / page numbers / Next with
 * disabled edges. With 13 offices at 12 per page, page 1 holds twelve
 * rows and page 2 holds the last one.
 */
class AdminOfficesPaginationTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    private function makeOffices(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $code = sprintf('T%02d', $i);

            $this->makeOffice(['name' => "Test Office {$code}", 'code' => $code]);
        }
    }

    public function test_page_one_uses_the_shared_pagination_with_next_enabled(): void
    {
        $this->makeOffices(13);

        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->get(route('admin.offices.index'))
            ->assertOk()
            ->assertSee('qf-pagination', false)
            ->assertSee('qf-pagination__nav', false)
            ->assertSee('Showing 1 to 12 of 13 results')
            ->assertSee('?page=2', false)
            ->assertSee('qf-page-link--disabled', false)
            ->assertSee('Test Office T01')
            ->assertDontSee('Test Office T13');
    }

    public function test_page_two_shows_the_remaining_office_with_previous_enabled(): void
    {
        $this->makeOffices(13);

        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->get(route('admin.offices.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Showing 13 to 13 of 13 results')
            ->assertSee('?page=1', false)
            ->assertSee('qf-page-link--disabled', false)
            ->assertSee('Test Office T13')
            ->assertDontSee('Test Office T01');
    }

    public function test_single_page_hides_navigation_but_keeps_the_range(): void
    {
        $this->makeOffices(3);

        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->get(route('admin.offices.index'))
            ->assertOk()
            ->assertSee('Showing 1 to 3 of 3 results')
            ->assertDontSee('qf-pagination__nav', false);
    }
}
