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
    private function member(string $role): User {
        $tenant=Tenant::create(['name'=>'Roles test','slug'=>'roles','database_name'=>'haven_suites_laravel13','active'=>true]);
        $user=User::create(['name'=>'Role test','username'=>'role-test','email'=>'role@example.test','password'=>'Testing!Password123','active'=>true]);
        $user->tenants()->attach($tenant,['roles'=>json_encode([$role]),'legacy_user_id'=>1]);
        return $user;
    }
    public static function roles(): array { return [['superadmin',true],['admin',true],['manager',false],['operations',false],['cleaner',false],['owner',false],['user',false]]; }
    #[DataProvider('roles')]
    public function test_invoice_payment_permission(string $role,bool $allowed): void {
        $this->actingAs($this->member($role));
        $response=$this->get('/t/roles/invoices/2/payments');
        if (!$allowed) $response->assertForbidden();
        else $response->assertRedirect('/t/roles/invoices/2'); // Existing standalone invoice.
        if (in_array($role,['manager','admin','superadmin'],true)) {
            $invoice=$this->get('/t/roles/invoices/2')->assertOk();
            $invoice->assertDontSee('Manage Payments'); // Standalone invoices use Record Payment instead.
            if (!$allowed) $invoice->assertDontSee('id="record-invoice-payment"',false);
        }
    }
    public function test_operations_navigation_hides_finance_only_modules(): void {
        $this->actingAs($this->member('operations'))->get('/t/roles/dashboard')->assertOk()
            ->assertDontSee('href="http://127.0.0.1:8001/t/roles/customers"',false)
            ->assertDontSee('href="http://127.0.0.1:8001/t/roles/staff/kyc"',false)
            ->assertDontSee('href="http://127.0.0.1:8001/t/roles/invoices"',false);
    }
    public static function limitedRoles(): array { return [['cleaner','housekeeping'],['owner','home'],['user','home']]; }
    #[DataProvider('limitedRoles')]
    public function test_limited_roles_have_accessible_landing_pages(string $role,string $page): void {
        $user=$this->member($role);$this->actingAs($user)->get('/')->assertRedirect('/t/roles/'.$page);
        if ($role!=='cleaner') $this->get('/t/roles/'.$page)->assertOk()->assertSee('Your company account');
    }
}
