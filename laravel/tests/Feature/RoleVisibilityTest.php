<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public static function teamRoles(): array
    {
        return [['superadmin', true], ['admin', true], ['manager', false], ['operations', false]];
    }

    #[DataProvider('teamRoles')]
    public function test_team_page_renders_for_administrators_only(string $role, bool $allowed): void
    {
        $user = $this->member($role);
        $colleague = User::factory()->create();
        $colleague->tenants()->attach($user->tenants()->first(), ['roles' => json_encode(['manager']), 'legacy_user_id' => 2]);
        $response = $this->actingAs($user)->get('/t/roles/company/team');

        if (! $allowed) {
            $response->assertForbidden();

            return;
        }

        $response->assertOk()->assertSee('Invite a team member')->assertSee('Manage member access')->assertSee($colleague->name)
            ->assertSee('value="manager"', false)->assertDontSee('@endforeach');
        if ($role === 'admin') {
            $response->assertDontSee('value="admin"', false);
        } else {
            $response->assertSee('value="admin"', false);
        }
    }

    private function member(string $role): User
    {
        $tenant = Tenant::create(['name' => 'Roles test', 'slug' => 'roles', 'database_name' => 'haven_suites_laravel13', 'active' => true]);
        $user = User::create(['name' => 'Role test', 'username' => 'role-test', 'email' => 'role@example.test', 'password' => 'Testing!Password123', 'active' => true]);
        $user->tenants()->attach($tenant, ['roles' => json_encode([$role]), 'legacy_user_id' => 1]);

        return $user;
    }

    public static function roles(): array
    {
        return [['superadmin', true], ['admin', true], ['manager', false], ['operations', false], ['cleaner', false], ['owner', false], ['user', false]];
    }

    #[DataProvider('roles')]
    public function test_invoice_payment_permission(string $role, bool $allowed): void
    {
        $this->actingAs($this->member($role));
        if (! $allowed) {
            $this->get('/t/roles/invoices/settings')->assertForbidden();
            $this->post('/t/roles/invoices/settings', ['business_name' => 'Unauthorised change'])->assertForbidden();
        }
        if (! in_array($role, ['superadmin', 'admin', 'manager'], true)) {
            $this->get('/t/roles/invoices/2/pdf')->assertForbidden();
        }
        $response = $this->get('/t/roles/invoices/2/payments');
        if (! $allowed) {
            $response->assertForbidden();
        } else {
            $response->assertRedirect('/t/roles/invoices/2');
        } // Existing standalone invoice.
        if (in_array($role, ['manager', 'admin', 'superadmin'], true)) {
            $invoice = $this->get('/t/roles/invoices/2')->assertOk();
            $invoice->assertDontSee('Manage Payments'); // Standalone invoices use Record Payment instead.
            if (! $allowed) {
                $invoice->assertDontSee('id="record-invoice-payment"', false);
            }
        }
    }

    public function test_operations_navigation_hides_finance_only_modules(): void
    {
        $this->actingAs($this->member('operations'))->get('/t/roles/dashboard')->assertOk()
            ->assertSee('Tasks needing attention')
            ->assertSee('Cleaning queue')
            ->assertDontSee('Financial summary')
            ->assertDontSee('href="http://127.0.0.1:8001/t/roles/customers"', false)
            ->assertDontSee('href="http://127.0.0.1:8001/t/roles/staff/kyc"', false)
            ->assertDontSee('href="http://127.0.0.1:8001/t/roles/invoices"', false);
    }

    public function test_company_switching_is_shown_only_when_there_is_another_active_membership(): void
    {
        $user = $this->member('operations');
        $this->actingAs($user)->get('/t/roles/dashboard')->assertOk()->assertDontSee('Switch company')->assertDontSee('Platform administration')->assertSee('Roles test');
        $other = Tenant::create(['name' => 'Other company', 'slug' => 'other-menu', 'database_name' => 'unused_menu', 'active' => true]);
        $this->get('/t/roles/dashboard')->assertOk()->assertDontSee('Switch company');
        $user->tenants()->attach($other, ['roles' => json_encode(['operations']), 'legacy_user_id' => 1]);
        $this->get('/t/roles/dashboard')->assertOk()->assertSee('Switch company');
        $this->get('/')->assertOk()->assertSee('Other company');
        $other->update(['active' => false]);
        $this->get('/t/roles/dashboard')->assertOk()->assertDontSee('Switch company');
        $this->get('/platform')->assertForbidden();
    }

    public function test_platform_administrator_has_a_clearly_labelled_platform_link(): void
    {
        $user = $this->member('superadmin');
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($user)->get('/t/roles/dashboard')->assertOk()->assertSee('Platform administration')->assertDontSee('Switch company');
    }

    public function test_sidebar_uses_one_active_destination_and_groups_payment_pages(): void
    {
        $this->actingAs($this->member('admin'));
        $response = $this->get('/t/roles/payments/cleaners/balances')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//aside//a[@aria-current="page"]')->length);
        $this->assertSame('http://127.0.0.1:8001/t/roles/payments/cleaners', $xpath->query('//aside//a[@aria-current="page"]')->item(0)->getAttribute('href'));
        foreach (['sales/connections', 'investor-notifications'] as $path) {
            $this->assertSame(1, $xpath->query('//aside//nav//a[@href="http://127.0.0.1:8001/t/roles/'.$path.'"]')->length);
        }
        $response->assertSee('Find a page')->assertSee('Bookings &amp; Guests', false)->assertSee('Guest verification')->assertSee('Close accounting month')->assertSee('Cleaner payments pages')->assertSee('Statements')->assertSee('Company settings')->assertSee('Team &amp; access', false)->assertSee('Subscription');
        $this->get('/t/roles/sales/agents')->assertOk()->assertSee('aria-current="page"', false);
    }

    public function test_manager_dashboard_renders_actionable_tasks_and_accessible_reporting(): void
    {
        $this->actingAs($this->member('manager'))->get('/t/roles/dashboard')->assertOk()
            ->assertSeeInOrder(['Tasks needing attention', 'Upcoming stays', 'Financial summary', 'Trends and occupancy', 'Property and team snapshot'])
            ->assertSee('New booking')
            ->assertSee('Report issue')
            ->assertSee('Monthly collections and expenses')
            ->assertSee('Reporting month')
            ->assertSee('All time')
            ->assertDontSee('chart.umd.min.js')
            ->assertDontSee('Welcome to Haven Suites');
    }

    public static function limitedRoles(): array
    {
        return [['cleaner', 'housekeeping'], ['owner', 'home'], ['user', 'home']];
    }

    #[DataProvider('limitedRoles')]
    public function test_limited_roles_have_accessible_landing_pages(string $role, string $page): void
    {
        $user = $this->member($role);
        $this->actingAs($user)->get('/')->assertRedirect('/t/roles/'.$page);
        if ($role !== 'cleaner') {
            $this->get('/t/roles/'.$page)->assertOk()->assertSee('Your company account');
        }
    }
}
