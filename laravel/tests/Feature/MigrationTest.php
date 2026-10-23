<?php
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Integration checks use only the imported COPY, never the CI database. */
class MigrationTest extends TestCase
{
    use RefreshDatabase;
    private function tenant(): Tenant {
        return Tenant::create(['name'=>'Migration test','slug'=>'migration-test','database_name'=>'haven_suites_laravel13','active'=>true]);
    }
    private function user(Tenant $tenant, array $roles = ['superadmin']): User {
        $user = User::create(['name'=>'Test user','username'=>'migration-test','email'=>'test@example.test','password'=>'Testing!Password123','active'=>true]);
        $user->tenants()->attach($tenant,['roles'=>json_encode($roles),'legacy_user_id'=>1]);
        return $user;
    }
    public function test_guest_is_redirected_to_login(): void {
        $this->tenant(); $this->get('/t/migration-test/dashboard')->assertRedirect('/login');
    }
    public function test_company_membership_is_required(): void {
        $tenant = $this->tenant(); $user = $this->user($tenant);
        $other = Tenant::create(['name'=>'Other','slug'=>'other','database_name'=>'must_not_connect_to_this_database','active'=>true]);
        $this->actingAs($user)->get('/t/other/dashboard')->assertForbidden();
    }
    public function test_read_only_role_cannot_write_finance(): void {
        $tenant = $this->tenant(); $this->actingAs($this->user($tenant,['manager']))
            ->post('/t/migration-test/payments/cleaners',['amount'=>'100'])->assertForbidden();
    }
    public function test_manager_can_view_units_but_cannot_edit_them(): void {
        $tenant=$this->tenant(); $this->actingAs($this->user($tenant,['manager']));
        $this->get('/t/migration-test/units')->assertOk()
            ->assertDontSee('href="http://127.0.0.1:8001/t/migration-test/units/edit/',false)
            ->assertDontSee('href="http://127.0.0.1:8001/t/migration-test/units/create"',false);
        $this->get('/t/migration-test/units/edit/1')->assertForbidden();
        $this->post('/t/migration-test/units/update/1',[])->assertForbidden();
    }
    public function test_login_works_and_logout_ends_session(): void {
        $tenant = $this->tenant(); $user = $this->user($tenant);
        $this->post('/login',['email'=>$user->email,'password'=>'Testing!Password123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login'); $this->assertGuest();
    }
    public function test_existing_record_pages_render(): void {
        $tenant = $this->tenant(); $this->actingAs($this->user($tenant));
        config(['database.connections.tenant.database'=>$tenant->database_name]);
        DB::purge('tenant');
        $pages = [
            ['reservations',['reservations/{id}','reservations/{id}/edit','reservations/{id}/quotation','reservations/{id}/kyc-link','payments/guests/{id}','payments/hosts/{id}']],
            ['properties',['properties/edit/{id}']], ['units',['units/edit/{id}']],
            ['staff',['staff/edit/{id}']], ['maintenance',['maintenance/edit/{id}']],
            ['housekeeping',['housekeeping/edit/{id}']], ['customers',['customers/{id}']],
            ['invoices',['invoices/{id}','invoices/{id}/payments']],
            ['customer_kyc',['staff/kyc/{id}']], ['cleaner_payments',['payments/cleaners/{id}']],
            ['unit_expenses',['expenses/{id}/payments']], ['unit_host_agreements',['host-agreements/{id}/edit']],
        ];
        foreach ($pages as [$table,$paths]) {
            $query = DB::connection('tenant')->table($table);
            if ($table === 'customer_kyc') $query->whereNotNull('submitted_at');
            $id = $query->value('id');
            if (!$id) continue;
            foreach ($paths as $path) {
                $url = '/t/migration-test/'.str_replace('{id}',(string)$id,$path);
                $response = $this->get($url);
                if ($table === 'invoices' && str_ends_with($path,'/payments') && !DB::connection('tenant')->table('invoices')->where('id',$id)->value('reservation_id')) {
                    $response->assertRedirect('/t/migration-test/invoices/'.$id); continue;
                }
                $this->assertSame(200,$response->status(),$url.' redirected to '.$response->headers->get('Location').' '.session('error'));
            }
        }
    }
    public function test_reports_for_an_existing_unit(): void {
        $tenant = $this->tenant(); $this->actingAs($this->user($tenant));
        config(['database.connections.tenant.database'=>$tenant->database_name]); DB::purge('tenant');
        $unit = DB::connection('tenant')->table('units')->value('id');
        foreach (['owner-remittances','owner-statement-closing','reports/investor','reports/monthly-host-commissions','reports/monthly-unit-expenses'] as $page) {
            $this->get('/t/migration-test/'.$page.'?unit_id='.$unit.'&month=2026-10')->assertOk();
        }
        $this->get('/t/migration-test/owner-remittances/export?unit_id='.$unit.'&month=2026-10')->assertOk()->assertHeader('content-type','text/csv; charset=UTF-8');
        $this->get('/t/migration-test/reports/investor?unit_id='.$unit.'&pdf=1')->assertOk()->assertHeader('content-type','application/pdf');
    }
    public static function pages(): array {
        return array_map(fn($path)=>[$path], [
            'dashboard','reservations','reservations?activity=checkins','reservations?activity=checkouts',
            'reservations/create','availability','quotations','quotations/payment-claims',
            'properties','properties/create','units','units/create','staff','staff/create',
            'housekeeping','housekeeping?activity=pending','housekeeping/create','housekeeping/my-cleanings/new',
            'maintenance','maintenance?activity=open','maintenance/create','customers','customers/create',
            'invoices','invoices/create','staff/kyc','expenses','expenses/create',
            'payments/guests','payments/hosts','payments/partners','payments/partners/owners',
            'payments/cleaners','payments/cleaners/create','payments/cleaners/statements','payments/cleaners/balances','payments/cleaners/earnings',
            'host-agreements','host-agreements/create','owner-remittances','owner-statement-closing',
            'reports/investor','reports/monthly-company-income','reports/monthly-host-commissions',
            'reports/monthly-unit-expenses','reports/housekeeping','settings/cleaning-rates',
        ]);
    }
    #[DataProvider('pages')]
    public function test_migrated_pages_render(string $path): void {
        $tenant = $this->tenant();
        $this->actingAs($this->user($tenant));
        $this->get('/t/migration-test/'.$path)->assertOk();
    }
}
