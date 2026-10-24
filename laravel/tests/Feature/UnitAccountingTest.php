<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OwnerRemittanceCalculator;
use App\Services\UnitAccounting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnitAccountingTest extends TestCase
{
    use RefreshDatabase;

    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['name' => 'Accounting test', 'slug' => 'accounting-test', 'database_name' => 'haven_suites_laravel13', 'active' => true]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['roles' => json_encode(['superadmin']), 'legacy_user_id' => 1]);
        $this->actingAs($user)->withoutMiddleware(ResolveTenant::class);
        app()->instance('currentTenant', $tenant);
        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');
        $db = DB::connection('tenant');
        $db->beginTransaction();
        $row = (array) $db->table('units')->first();
        unset($row['id']);
        $row['unit_number'] = 'ACCOUNTING-'.bin2hex(random_bytes(4));
        $this->unit = $db->table('units')->insertGetId($row);
        $this->travelTo(Carbon::parse('2026-10-24 12:00:00', 'Africa/Nairobi'));
    }

    protected function tearDown(): void
    {
        $db = DB::connection('tenant');
        while ($db->transactionLevel() > 0) {
            $db->rollBack();
        }DB::purge('tenant');
        app()->forgetInstance('currentTenant');
        $this->travelBack();
        parent::tearDown();
    }

    private function activity(int $revenue, int $expense): void
    {
        $db = DB::connection('tenant');
        $id = $db->table('reservations')->insertGetId(['unit_id' => $this->unit, 'booking_type' => 'managed', 'guest_name' => 'Accounting Test Guest', 'guest_phone' => 'TEST', 'check_in' => '2026-09-01', 'check_out' => '2026-09-03', 'nightly_rate' => $revenue / 2, 'total_amount' => $revenue, 'status' => 'checked_out']);
        $db->table('guest_payments')->insert(['reservation_id' => $id, 'amount' => $revenue, 'payment_date' => '2026-09-03', 'payment_method' => 'cash', 'notes' => 'Transactional test']);
        $category = $db->table('expense_categories')->where('name', 'Cleaning')->value('id');
        $db->table('unit_expenses')->insert(['unit_id' => $this->unit, 'category_id' => $category, 'expense_date' => '2026-09-03', 'description' => 'Test operating cost', 'amount' => $expense, 'source_type' => 'manual']);
    }

    private function terms(string $mode, array $extra = []): void
    {
        $this->post('/t/accounting-test/accounting/units', ['unit_id' => $this->unit, 'mode' => $mode, 'effective_month' => '2026-09', 'notes' => 'Test agreement', ...$extra])->assertSessionHasNoErrors();
    }

    public function test_managed_company_losses_never_reduce_owner_entitlement(): void
    {
        $this->terms('managed', ['commission' => 20]);
        $this->activity(50000, 12000);
        $report = (new UnitAccounting)->report('2026-09', $this->unit);
        $this->assertSame(1000000, $report['totals']['company_income']);
        $this->assertSame(-200000, $report['totals']['profit']);
        $owner = (new OwnerRemittanceCalculator)->calculate($this->unit, '2026-09');
        $this->assertSame('40000.00', $owner['entitlement']);
        $this->assertSame('0.00', $owner['expenses']);
        $this->assertSame('12000.00', $owner['company_funded_expenses']);
        $this->assertSame('0.00', $owner['deficit_to_recover']);
        $this->get('/t/accounting-test/accounting/units?month=2026-09')->assertOk()->assertSee('Operating loss');
        $this->get('/t/accounting-test/reports/monthly-company-income?month=2026-09')->assertOk()->assertSee('10,000.00');
        $this->get('/t/accounting-test/dashboard?month=2026-09&unit_id='.$this->unit)->assertOk()->assertSee('Operating loss');
    }

    public function test_leased_rent_accrues_once_and_payment_does_not_change_profit(): void
    {
        $this->terms('leased', ['monthly_rent' => 30000, 'landlord_name' => 'Test landlord']);
        $this->activity(40000, 15000);
        $service = new UnitAccounting;
        $expense = $service->postRent($this->unit, '2026-09');
        $this->assertSame($expense, $service->postRent($this->unit, '2026-09'));
        $this->assertSame(1, DB::connection('tenant')->table('unit_expenses')->where('unit_id', $this->unit)->where('source_type', 'operating_rent')->where('expense_date', '2026-09-01')->count());
        $this->assertSame(-500000, $service->report('2026-09', $this->unit)['totals']['profit']);
        $owner = (new OwnerRemittanceCalculator)->calculate($this->unit, '2026-09');
        $this->assertSame('30000.00', $owner['entitlement']);
        $this->assertSame('0.00', $owner['collected']);
        $this->assertSame('0.00', $owner['management_fee']);
        $this->post('/t/accounting-test/expenses/'.$expense.'/payments', ['amount' => 30000, 'payment_date' => '2026-10-01', 'payment_method' => 'bank', 'reference' => 'TEST-RENT'])->assertSessionHas('success');
        $this->assertSame(-500000, (new UnitAccounting)->report('2026-09', $this->unit)['totals']['profit']);
        $owner = (new OwnerRemittanceCalculator)->calculate($this->unit, '2026-09');
        $this->assertSame('30000.00', $owner['paid']);
        $this->assertSame('0.00', $owner['closing_balance']);
        $investor = DB::table('investors')->insertGetId(['tenant_id' => app('currentTenant')->id, 'name' => 'Test landlord investor', 'email' => 'landlord@example.test', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $assignment = DB::table('investor_assignments')->insertGetId(['tenant_id' => app('currentTenant')->id, 'investor_id' => $investor, 'unit_id' => $this->unit, 'share_basis_points' => 10000, 'starts_on' => '2026-09-01', 'agreement_reference' => 'LEASE-TEST', 'created_at' => now(), 'updated_at' => now()]);
        $rentPayment = DB::connection('tenant')->table('expense_payments')->where('expense_id', $expense)->value('id');
        $this->post('/t/accounting-test/investors/'.$investor.'/payouts', ['assignment_id' => $assignment, 'rent_payment_id' => $rentPayment])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('investor_payouts', ['investor_id' => $investor, 'rent_payment_id' => $rentPayment, 'remittance_id' => null, 'amount_minor' => 3000000]);
        $this->post('/t/accounting-test/owner-remittances', ['unit_id' => $this->unit, 'month' => '2026-09', 'amount' => 30000])->assertSessionHas('error');
    }

    public function test_accounting_configuration_requires_finance_management_and_module_access(): void
    {
        $user = auth()->user();
        $user->tenants()->updateExistingPivot(app('currentTenant')->id, ['roles' => json_encode(['manager'])]);
        $this->get('/t/accounting-test/accounting/units')->assertOk();
        $this->post('/t/accounting-test/accounting/units', ['unit_id' => $this->unit, 'mode' => 'managed', 'effective_month' => '2026-09', 'commission' => 20, 'notes' => 'Unauthorized'])->assertForbidden();
        app('currentTenant')->modules = ['finance' => false];
        $this->get('/t/accounting-test/accounting/units')->assertForbidden();
    }

    public function test_approved_snapshots_and_dated_arrangements_are_preserved(): void
    {
        $this->terms('managed', ['commission' => 20]);
        $this->post('/t/accounting-test/accounting/units', ['unit_id' => $this->unit, 'mode' => 'managed', 'effective_month' => '2026-10', 'commission' => 25, 'notes' => 'New dated terms'])->assertSessionHasNoErrors();
        $service = new UnitAccounting;
        $this->assertSame(2000, $service->rate($this->unit, '2026-09-15'));
        $this->assertSame(2500, $service->rate($this->unit, '2026-10-15'));
        $snapshot = ['opening_balance' => '0.00', 'collected' => '100.00', 'management_fee' => '20.00', 'expenses' => '10.00', 'entitlement' => '70.00', 'paid' => '0.00', 'closing_balance' => '70.00', 'outstanding' => '70.00', 'deficit_recovered' => '0.00', 'deficit_to_recover' => '0.00', 'issues' => []];
        $json = json_encode($snapshot);
        DB::connection('tenant')->table('owner_statement_closings')->insert(['unit_id' => $this->unit, 'period_month' => '2026-09-01', 'statement_json' => $json, 'statement_hash' => hash('sha256', $json), 'approved_by' => 1, 'approved_at' => now()]);
        $this->assertSame($snapshot, (new OwnerRemittanceCalculator)->calculate($this->unit, '2026-09'));
        $this->assertSame('70.00', (new OwnerRemittanceCalculator)->calculate($this->unit, '2026-10')['opening_balance']);
        $this->post('/t/accounting-test/accounting/units', ['unit_id' => $this->unit, 'mode' => 'managed', 'effective_month' => '2026-09', 'commission' => 30, 'notes' => 'Forbidden historical change'])->assertSessionHasErrors('effective_month');
    }

    public function test_prepaid_rent_and_late_payments_preserve_months_and_published_balances(): void
    {
        $this->terms('leased', ['monthly_rent' => 30000, 'landlord_name' => 'Test landlord']);
        $service = new UnitAccounting;
        $september = $service->postRent($this->unit, '2026-09');
        $report = (new OwnerRemittanceCalculator)->calculate($this->unit, '2026-09');
        $json = json_encode($report);
        DB::connection('tenant')->table('owner_statement_closings')->insert(['unit_id' => $this->unit, 'period_month' => '2026-09-01', 'statement_json' => $json, 'statement_hash' => hash('sha256', $json), 'approved_by' => 1, 'approved_at' => now()]);
        $this->post('/t/accounting-test/expenses/'.$september.'/payments', ['amount' => 30000, 'payment_date' => '2026-10-02', 'payment_method' => 'bank', 'reference' => 'LATE-RENT'])->assertSessionHas('success');
        $october = (new OwnerRemittanceCalculator)->calculate($this->unit, '2026-10');
        $this->assertSame('30000.00', $october['opening_balance']);
        $this->assertSame('30000.00', $october['paid']);
        $this->assertSame('30000.00', $october['closing_balance']);
        $this->assertSame($report, (new OwnerRemittanceCalculator)->calculate($this->unit, '2026-09'));
        $november = $service->postRent($this->unit, '2026-11');
        $this->post('/t/accounting-test/expenses/'.$november.'/payments', ['amount' => 30000, 'payment_date' => '2026-10-03', 'payment_method' => 'bank', 'reference' => 'PREPAID-RENT'])->assertSessionHas('success');
        $this->assertSame(3000000, (new UnitAccounting)->report('2026-10', $this->unit)['totals']['rent']);
        $this->assertSame(3000000, (new UnitAccounting)->report('2026-11', $this->unit)['totals']['rent']);
        $this->assertSame('30000.00', (new OwnerRemittanceCalculator)->calculate($this->unit, '2026-11')['closing_balance']);
    }
}
