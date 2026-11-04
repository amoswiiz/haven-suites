<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class InvoicePermissionsTest extends TestCase
{
    private function actor(string $role): User
    {
        return new class($role) extends User {
            public function __construct(private string $testRole = 'user')
            {
                parent::__construct();
            }

            public function roles(): array
            {
                return [$this->testRole];
            }
        };
    }

    public function test_invoice_roles_do_not_expand_general_finance_access(): void
    {
        $this->app->instance('currentTenant', (object) ['modules' => ['finance' => true]]);
        foreach (['manager', 'operations', 'admin', 'superadmin'] as $role) {
            $user = $this->actor($role);
            $this->assertTrue($user->can('invoices.view'), $role);
            $this->assertTrue($user->can('invoices.manage'), $role);
            $this->assertSame(in_array($role, ['admin', 'superadmin']), $user->can('finance.manage'), $role);
        }
        foreach (['cleaner', 'owner', 'user', 'sales'] as $role) {
            $this->assertFalse($this->actor($role)->can('invoices.manage'), $role);
        }
    }

    public function test_disabled_finance_module_blocks_invoice_permissions(): void
    {
        $this->app->instance('currentTenant', (object) ['modules' => ['finance' => false]]);
        foreach (['manager', 'operations', 'admin', 'superadmin'] as $role) {
            $this->assertFalse($this->actor($role)->can('invoices.view'), $role);
            $this->assertFalse($this->actor($role)->can('invoices.manage'), $role);
        }
    }

    public function test_invoice_routes_use_specific_permissions_and_settings_remain_restricted(): void
    {
        $cases = [
            ['GET', 'invoices', 'invoices.view'],
            ['GET', 'invoices/1', 'invoices.view'],
            ['GET', 'invoices/1/pdf', 'invoices.view'],
            ['GET', 'invoices/create', 'invoices.manage'],
            ['POST', 'invoices', 'invoices.manage'],
            ['POST', 'invoices/1/issue', 'invoices.manage'],
            ['POST', 'invoices/1/customer', 'invoices.manage'],
            ['GET', 'invoices/1/payments', 'invoices.manage'],
            ['POST', 'invoices/1/payments', 'invoices.manage'],
            ['POST', 'invoices/1/payments/record', 'invoices.manage'],
            ['POST', 'staff/kyc/1/invoice', 'invoices.manage'],
            ['GET', 'invoices/settings', 'finance.manage'],
            ['POST', 'invoices/settings', 'finance.manage'],
        ];
        foreach ($cases as [$method, $path, $permission]) {
            $route = $this->app['router']->getRoutes()->match(Request::create('/t/haven/'.$path, $method));
            $this->assertContains('permission:'.$permission, $route->gatherMiddleware(), $method.' '.$path);
            if (str_starts_with($permission, 'invoices.')) {
                $this->assertNotContains('permission:finance.manage', $route->gatherMiddleware(), $path);
                $this->assertNotContains('permission:finance.view', $route->gatherMiddleware(), $path);
            }
        }
    }
}
