<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CleanerDashboardNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Tenant::create([
            'name' => 'Navigation test', 'slug' => 'cleaner-nav', 'database_name' => 'unused_cleaner_nav', 'active' => true,
        ]);
        app()->instance('currentTenant', $this->company);
    }

    private function member(array $roles): User
    {
        $user = User::factory()->create(['active' => true]);
        $user->tenants()->attach($this->company, ['roles' => json_encode($roles), 'legacy_user_id' => 22]);

        return $user;
    }

    public function test_cleaners_land_on_their_summary_and_management_roles_keep_their_dashboard(): void
    {
        $this->actingAs($this->member(['cleaner']))->get('/')->assertRedirect('/t/cleaner-nav/cleaner/dashboard');
        $this->actingAs($this->member(['admin', 'cleaner']))->get('/')->assertRedirect('/t/cleaner-nav/dashboard');
        $this->actingAs($this->member(['manager', 'cleaner']))->get('/')->assertRedirect('/t/cleaner-nav/dashboard');
        $this->actingAs($this->member(['sales', 'cleaner']))->get('/')->assertRedirect('/t/cleaner-nav/sales');
        $this->actingAs($this->member(['user']))->get('/')->assertRedirect('/t/cleaner-nav/home');
    }

    public function test_cleaner_dashboard_permission_does_not_grant_company_dashboard_or_finance_access(): void
    {
        $cleaner = $this->member(['cleaner']);
        $this->assertTrue($cleaner->can('cleaner.dashboard'));
        foreach (['dashboard.view', 'finance.view', 'finance.manage', 'messaging.view', 'reservations.view'] as $permission) {
            $this->assertFalse($cleaner->can($permission), $permission);
        }
        foreach (['admin', 'manager', 'operations', 'sales', 'owner', 'user'] as $role) {
            $this->assertFalse($this->member([$role])->can('cleaner.dashboard'), $role);
        }

        $this->withoutMiddleware(ResolveTenant::class)->actingAs($cleaner)
            ->get('/t/cleaner-nav/dashboard')->assertForbidden();
        $this->actingAs($this->member(['user']))->get('/t/cleaner-nav/cleaner/dashboard')->assertForbidden();
    }

    public function test_cleaner_dashboard_route_uses_its_specific_permission(): void
    {
        $route = $this->app['router']->getRoutes()->match(Request::create('/t/cleaner-nav/cleaner/dashboard'));
        $this->assertSame('cleaner.dashboard', $route->getName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.member', $route->gatherMiddleware());
        $this->assertContains('permission:cleaner.dashboard', $route->gatherMiddleware());
        $this->assertNotContains('permission:dashboard.view', $route->gatherMiddleware());
    }

    public function test_cleaner_sidebar_has_one_prominent_active_summary_link(): void
    {
        $this->actingAs($this->member(['cleaner']));
        $request = Request::create('/t/cleaner-nav/cleaner/dashboard');
        $this->app->instance('request', $request);
        $this->app['url']->setRequest($request);
        $html = view('layouts.sidebar')->render();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $active = $xpath->query('//aside//a[@aria-current="page"]');

        $this->assertSame(1, $active->length);
        $this->assertSame('My dashboard', trim($active->item(0)->textContent));
        $this->assertStringEndsWith('/t/cleaner-nav/cleaner/dashboard', $active->item(0)->getAttribute('href'));
        $this->assertSame(1, $xpath->query('//aside//a[contains(@href,"/cleaner/dashboard")]')->length);
        $this->assertStringContainsString('My cleanings', $html);
        $this->assertStringNotContainsString('data-group="Finance"', $html);
        $this->assertStringNotContainsString('/communications/due', $html);
    }

    public function test_management_sidebar_links_to_due_communications(): void
    {
        $this->actingAs($this->member(['manager']));
        $request = Request::create('/t/cleaner-nav/communications/due');
        $this->app->instance('request', $request);
        $this->app['url']->setRequest($request);
        $html = view('layouts.sidebar')->render();

        $this->assertStringContainsString('Communications due today', $html);
        $this->assertStringContainsString('/t/cleaner-nav/communications/due', $html);
    }
}
