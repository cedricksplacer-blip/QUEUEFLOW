<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesQueueFixtures;
use Tests\TestCase;

/**
 * Guards the QUEUEFLOW CAMPUS NEXUS admin design system.
 *
 * The admin shell used to be skinned by competing layers (inlined clay,
 * neumorphism, and public/css/skeuomorphism.css) whose later members needed
 * hundreds of !important declarations to win, and which stripped the borders
 * off every control. The design system now lives in a single source of truth,
 * resources/css/app.css, compiled through Vite. These tests fail if a competing
 * theme layer creeps back in, if that sheet is dropped, or if the control
 * borders regress.
 */
class AdminDesignSystemTest extends TestCase
{
    use CreatesQueueFixtures;
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->makeUser(User::ROLE_ADMIN);
    }

    public static function adminPages(): array
    {
        return [
            'dashboard' => ['admin.dashboard'],
            'settings' => ['admin.settings.index'],
            'announcements' => ['admin.announcements.index'],
            'announcement create' => ['admin.announcements.create'],
            'courses' => ['admin.courses.index'],
            'offices' => ['admin.offices.index'],
            'queues' => ['admin.queues.index'],
            'reports' => ['admin.reports.index'],
            'staff' => ['admin.staff.index'],
            'users' => ['admin.users.index'],
            'profile' => ['admin.profile.edit'],
        ];
    }

    /** @dataProvider adminPages */
    public function test_admin_page_renders(string $routeName): void
    {
        $this->actingAs($this->admin())
            ->get(route($routeName))
            ->assertOk();
    }

    /** @dataProvider adminPages */
    public function test_admin_page_loads_the_design_stylesheet(string $routeName): void
    {
        // The single source of truth is resources/css/app.css, served through
        // Vite, so the rendered page must reference the built asset.
        $this->actingAs($this->admin())
            ->get(route($routeName))
            ->assertOk()
            ->assertSee('build/assets/app', false);
    }

    public function test_layout_no_longer_loads_competing_theme_layers(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        // The neumorphic/skeuomorphic/clay layers must not return to the shell.
        $this->assertStringNotContainsString('css/skeuomorphism.css', $html);
        $this->assertStringNotContainsString('css/neumorphism.css', $html);
        $this->assertStringNotContainsString('css/admin-surface.css', $html);
        $this->assertStringNotContainsString('queueflow-neumorphism', $html);
        $this->assertStringNotContainsString('queueflow-clay', $html);
    }

    public function test_competing_theme_files_are_removed(): void
    {
        foreach ([
            'css/admin-surface.css',
            'css/neumorphism.css',
            'css/skeuomorphism.css',
        ] as $path) {
            $this->assertFileDoesNotExist(
                public_path($path),
                "{$path} must not be reintroduced; the design lives in resources/css/app.css"
            );
        }
    }

    public function test_design_system_defines_tokens_and_dark_mode(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($css, 'resources/css/app.css must exist');

        foreach ([
            '--qf-page',
            '--qf-surface',
            '--qf-border',
            '--qf-border-strong',
            '--qf-text',
            '--qf-brand-solid',
            '--qf-radius-lg',
            '--qf-shadow-sm',
            '--qf-ring',
        ] as $token) {
            $this->assertStringContainsString($token . ':', $css, "missing token {$token}");
        }

        // Dark mode must override the surface set, not just a couple of values.
        $this->assertMatchesRegularExpression('/html\.dark\s*\{[^}]*--qf-surface/ms', $css);
    }

    public function test_design_system_keeps_the_shared_primitive_selectors(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // Every existing class name stays intact so no admin view had to change.
        foreach ([
            '.admin-sidebar',
            '.admin-header',
            '.card',
            '.admin-table',
            '.nav-item',
            '.btn-primary',
            '.badge-served',
        ] as $selector) {
            $this->assertStringContainsString($selector, $css, "missing rule for {$selector}");
        }
    }

    public function test_controls_keep_a_visible_border_in_the_new_design(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // The regression this whole change exists to prevent: border:0 on
        // inputs and buttons, leaving controls indistinguishable from the page.
        $this->assertDoesNotMatchRegularExpression(
            '/(input|select|textarea|\.btn-primary)[^{]*\{[^}]*border\s*:\s*0\s*(important)?\s*[;}]/s',
            $css
        );
    }

    public function test_guest_auth_pages_use_the_nexus_auth_shell(): void
    {
        foreach (['admin.login', 'admin.password.request'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('qf-auth-shell', false)
                ->assertSee('qf-auth-aside', false);
        }
    }

    public function test_two_step_auth_pages_use_the_nexus_auth_shell(): void
    {
        $session = [
            'password_reset_email' => 'admin@tmc.test',
            'password_reset_verified' => true,
        ];

        $this->withSession($session)
            ->get(route('admin.password.otp'))
            ->assertOk()
            ->assertSee('qf-auth-shell', false)
            ->assertSee('admin@tmc.test');

        $this->withSession($session)
            ->get(route('admin.password.new'))
            ->assertOk()
            ->assertSee('qf-auth-shell', false)
            ->assertSee('password_confirmation', false);
    }
}
