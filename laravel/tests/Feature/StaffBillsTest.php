<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StaffBillSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffBillsTest extends TestCase
{
    use RefreshDatabase;

    private User $cleaner;

    private User $reviewer;

    private Tenant $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        Storage::fake('local');
        $this->company = Tenant::create(['name' => 'Bills test', 'slug' => 'bills', 'database_name' => ':memory:', 'active' => true]);
        $this->cleaner = User::factory()->create(['active' => true]);
        $this->reviewer = User::factory()->create(['active' => true]);
        $this->cleaner->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 11]);
        $this->reviewer->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 22]);
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($this->cleaner);
        $s = DB::connection('tenant')->getSchemaBuilder();
        $s->create('staff', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('status');
            $t->string('full_name');
        });
        $s->create('properties', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        $s->create('units', function (Blueprint $t): void {
            $t->id();
            $t->integer('property_id');
            $t->string('unit_number');
        });
        $s->create('housekeeping', function (Blueprint $t): void {
            $t->id();
            $t->integer('unit_id');
            $t->integer('assigned_staff_id');
            $t->string('status');
            $t->dateTime('scheduled_at');
        });
        $s->create('expense_categories', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->boolean('is_active');
        });
        $s->create('accounting_period_locks', function (Blueprint $t): void {
            $t->id();
            $t->integer('unit_id');
            $t->date('period_month');
            $t->boolean('is_locked');
        });
        $s->create('unit_expenses', function (Blueprint $t): void {
            $t->id();
            $t->integer('unit_id');
            $t->integer('category_id');
            $t->date('expense_date');
            $t->string('description');
            $t->decimal('amount', 12, 2);
            $t->decimal('amount_paid', 12, 2)->default(0);
            $t->string('payment_status')->default('unpaid');
            $t->string('supplier_name')->nullable();
            $t->text('notes')->nullable();
            $t->string('source_type')->default('manual');
            $t->integer('source_id')->nullable();
            $t->integer('recorded_by')->nullable();
            $t->date('paid_at')->nullable();
            $t->string('payment_method')->nullable();
            $t->string('payment_reference')->nullable();
            $t->timestamps();
            $t->unique(['source_type', 'source_id']);
        });
        $s->create('expense_payments', function (Blueprint $t): void {
            $t->id();
            $t->integer('expense_id');
            $t->date('payment_date');
            $t->decimal('amount', 12, 2);
            $t->string('payment_method');
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->integer('recorded_by');
            $t->timestamps();
        });
        app(StaffBillSchema::class)->install(DB::connection('tenant'));
        DB::connection('tenant')->table('staff')->insert(['id' => 1, 'user_id' => 11, 'status' => 'active', 'full_name' => 'Cleaner One']);
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Test apartments']);
        DB::connection('tenant')->table('units')->insert([['id' => 1, 'property_id' => 1, 'unit_number' => 'A1'], ['id' => 2, 'property_id' => 1, 'unit_number' => 'A2']]);
        DB::connection('tenant')->table('housekeeping')->insert(['unit_id' => 1, 'assigned_staff_id' => 1, 'status' => 'assigned', 'scheduled_at' => now()]);
        DB::connection('tenant')->table('expense_categories')->insert(['id' => 1, 'name' => 'Utilities', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['submission_token' => (string) Str::uuid(), 'unit_id' => 1, 'purchase_date' => now()->toDateString(),
            'category' => 'Drinking water', 'description' => 'Water refill', 'supplier' => 'Water shop', 'amount' => '500.00', 'payer' => 'personal',
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf')], $overrides);
    }

    private function submit(array $overrides = []): object
    {
        $this->actingAs($this->cleaner)->post('/t/bills/staff-bills', $this->payload($overrides))->assertSessionHasNoErrors()->assertRedirect();

        return DB::connection('tenant')->table('staff_bills')->orderByDesc('id')->first();
    }

    private function approve(object $bill): void
    {
        $this->actingAs($this->reviewer)->post('/t/bills/staff-bills/'.$bill->id.'/review',
            ['version' => $bill->version, 'action' => 'approved', 'category_id' => 1])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_submission_private_receipt_and_duplicate_submit(): void
    {
        $data = $this->payload();
        $this->post('/t/bills/staff-bills', $data)->assertSessionHasNoErrors();
        $this->post('/t/bills/staff-bills', $data)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::connection('tenant')->table('staff_bills')->count());
        $this->assertSame(0, DB::connection('tenant')->table('unit_expenses')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->get('/t/bills/staff-bills')->assertOk()->assertSee('Water refill');
        $this->get('/t/bills/staff-bills/1')->assertOk()->assertSee('Awaiting approval');
        $this->get('/t/bills/staff-bills/1/receipts/1')->assertOk()->assertDownload('bill-1.pdf');
        $other = User::factory()->create(['active' => true]);
        $other->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 33]);
        $this->actingAs($other)->get('/t/bills/staff-bills/1')->assertNotFound();
        $this->get('/t/bills/staff-bills/1/receipts/1')->assertNotFound();
    }

    public function test_apartment_and_upload_validation(): void
    {
        $this->post('/t/bills/staff-bills', $this->payload(['unit_id' => 999]))->assertForbidden();
        $this->post('/t/bills/staff-bills', $this->payload(['receipt' => UploadedFile::fake()->create('bad.html', 2, 'text/html')]))->assertSessionHasErrors('receipt');
        $this->post('/t/bills/staff-bills', $this->payload(['amount' => '-1']))->assertSessionHasErrors('amount');
        $this->get('/t/bills/staff-bills/new')->assertOk()->assertSee('Submit a bill');
    }

    public function test_approval_posts_once_and_reimbursement_does_not_duplicate_expense(): void
    {
        $bill = $this->submit();
        $this->approve($bill);
        $expense = DB::connection('tenant')->table('unit_expenses')->first();
        $this->assertSame('staff_bill', $expense->source_type);
        $this->assertEquals(500, $expense->amount);
        $this->assertEquals(0, $expense->amount_paid);
        $this->post('/t/bills/staff-bills/'.$bill->id.'/review', ['version' => 1, 'action' => 'approved', 'category_id' => 1])->assertSessionHasErrors('bill');
        $this->post('/t/bills/expenses/'.$expense->id.'/payments', ['amount' => '200', 'payment_date' => now()->toDateString(), 'payment_method' => 'cash'])->assertSessionHas('success');
        $this->actingAs($this->cleaner)->get('/t/bills/staff-bills/'.$bill->id)->assertOk()->assertSee('Partially reimbursed');
        $this->actingAs($this->reviewer)->post('/t/bills/expenses/'.$expense->id.'/payments', ['amount' => '301', 'payment_date' => now()->toDateString(), 'payment_method' => 'cash'])->assertSessionHas('error');
        $this->post('/t/bills/expenses/'.$expense->id.'/payments', ['amount' => '300', 'payment_date' => now()->toDateString(), 'payment_method' => 'cash'])->assertSessionHas('success');
        $this->actingAs($this->cleaner)->get('/t/bills/staff-bills/'.$bill->id)->assertOk()->assertSee('Reimbursed');
        $this->assertSame(1, DB::connection('tenant')->table('unit_expenses')->count());
    }

    public function test_cleaner_and_self_approval_forbidden(): void
    {
        $bill = $this->submit();
        $this->post('/t/bills/staff-bills/'.$bill->id.'/review', ['version' => 1, 'action' => 'approved', 'category_id' => 1])->assertForbidden();
        DB::connection('tenant')->table('staff_bills')->where('id', $bill->id)->update(['submitted_by' => $this->reviewer->id]);
        $this->actingAs($this->reviewer)->post('/t/bills/staff-bills/'.$bill->id.'/review', ['version' => 1, 'action' => 'approved', 'category_id' => 1])->assertForbidden();
    }

    public function test_return_resubmission_preserves_history_and_rejection_does_not_post(): void
    {
        $bill = $this->submit();
        $this->actingAs($this->reviewer)->post('/t/bills/staff-bills/'.$bill->id.'/review', ['version' => 1, 'action' => 'returned'])->assertSessionHasErrors('note');
        $this->post('/t/bills/staff-bills/'.$bill->id.'/review', ['version' => 1, 'action' => 'returned', 'note' => 'Correct amount'])->assertSessionHasNoErrors();
        $this->actingAs($this->cleaner)->get('/t/bills/staff-bills/'.$bill->id.'/edit')->assertOk();
        $this->post('/t/bills/staff-bills/'.$bill->id, $this->payload(['version' => 2, 'amount' => '450']))->assertSessionHasNoErrors();
        $this->assertSame(3, DB::connection('tenant')->table('staff_bill_events')->count());
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->actingAs($this->reviewer)->post('/t/bills/staff-bills/'.$bill->id.'/review', ['version' => 3, 'action' => 'rejected', 'note' => 'Duplicate purchase'])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::connection('tenant')->table('unit_expenses')->count());
    }

    public function test_locked_month_prevents_approval(): void
    {
        $bill = $this->submit();
        DB::connection('tenant')->table('accounting_period_locks')->insert(['unit_id' => 1, 'period_month' => now()->startOfMonth()->toDateString(), 'is_locked' => true]);
        $this->actingAs($this->reviewer)->post('/t/bills/staff-bills/'.$bill->id.'/review', ['version' => 1, 'action' => 'approved', 'category_id' => 1])->assertSessionHasErrors('bill');
        $this->assertSame('pending', DB::connection('tenant')->table('staff_bills')->value('status'));
        $this->assertSame(0, DB::connection('tenant')->table('unit_expenses')->count());
    }

    public function test_company_paid_bill_links_existing_expense_without_duplicate(): void
    {
        $bill = $this->submit(['payer' => 'company']);
        $this->actingAs($this->reviewer)->post('/t/bills/staff-bills/'.$bill->id.'/review', ['version' => 1, 'action' => 'approved'])->assertSessionHasErrors('expense_id');
        $id = DB::connection('tenant')->table('unit_expenses')->insertGetId(['unit_id' => 1, 'category_id' => 1, 'expense_date' => now()->toDateString(), 'description' => 'Water', 'amount' => 500, 'amount_paid' => 500, 'payment_status' => 'paid', 'source_type' => 'manual']);
        $this->post('/t/bills/staff-bills/'.$bill->id.'/review', ['version' => 1, 'action' => 'approved', 'expense_id' => $id])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::connection('tenant')->table('unit_expenses')->count());
        $this->assertSame(0, DB::connection('tenant')->table('expense_payments')->count());
    }

    public function test_supplier_bill_is_payable_to_supplier(): void
    {
        $bill = $this->submit(['payer' => 'supplier']);
        $this->approve($bill);
        $this->get('/t/bills/staff-bills/'.$bill->id)->assertOk()->assertSee('Supplier unpaid')->assertSee('Record supplier payment');
    }

    public function test_tenant_membership_and_module_are_enforced(): void
    {
        $bill = $this->submit();
        $outsider = User::factory()->create(['active' => true]);
        $this->actingAs($outsider)->get('/t/bills/staff-bills/'.$bill->id)->assertForbidden();
        $this->company->forceFill(['modules' => ['housekeeping' => false]])->save();
        $this->actingAs($this->cleaner)->get('/t/bills/staff-bills')->assertForbidden();
    }

    public function test_schema_install_is_repeatable_without_deleting_bills(): void
    {
        $this->submit();
        app(StaffBillSchema::class)->install(DB::connection('tenant'));
        $this->assertSame(1, DB::connection('tenant')->table('staff_bills')->count());
    }

    public function test_any_company_apartment_can_be_used_without_assignment(): void
    {
        DB::connection('tenant')->table('housekeeping')->delete();
        $bill = $this->submit(['unit_id' => 2, 'category' => 'Repairs and replacements']);
        $this->assertEquals(2, $bill->unit_id);
        $this->get('/t/bills/staff-bills/new')->assertOk()->assertSee('A2')->assertDontSee('No assigned apartments');
    }

    public function test_company_bill_can_be_approved_and_reimbursed_without_apartments(): void
    {
        DB::connection('tenant')->table('housekeeping')->delete();
        DB::connection('tenant')->table('units')->delete();
        $this->get('/t/bills/staff-bills/new')->assertOk()->assertSee('General company expense');
        $bill = $this->submit(['unit_id' => '', 'description' => 'Shared bucket', 'category' => 'Equipment and tools']);
        $this->assertNull($bill->unit_id);
        $this->approve($bill);
        $expense = DB::connection('tenant')->table('unit_expenses')->first();
        $this->assertNull($expense->unit_id);
        $this->get('/t/bills/expenses/'.$expense->id.'/payments')->assertOk()->assertSee('General company expense');
        $this->post('/t/bills/expenses/'.$expense->id.'/payments', ['amount' => '500', 'payment_date' => now()->toDateString(), 'payment_method' => 'cash'])->assertSessionHas('success');
        $this->actingAs($this->cleaner)->get('/t/bills/staff-bills/'.$bill->id)->assertOk()->assertSee('Reimbursed')->assertSee('General company expense');
    }

    public function test_company_funded_general_bill_links_only_general_expense(): void
    {
        $bill = $this->submit(['unit_id' => null, 'payer' => 'company']);
        $this->actingAs($this->reviewer)->post('/t/bills/expenses', [
            'unit_id' => '', 'category_id' => 1, 'expense_date' => now()->toDateString(),
            'description' => 'Shared bucket', 'supplier_name' => 'Shop', 'notes' => '',
            'amount' => '500', 'amount_paid' => '500', 'payment_method' => 'cash',
            'payment_reference' => '', 'paid_at' => now()->toDateString(),
        ])->assertSessionHas('success');
        $expense = DB::connection('tenant')->table('unit_expenses')->first();
        $this->assertNull($expense->unit_id);
        $this->assertEquals(500, DB::connection('tenant')->table('expense_payments')->sum('amount'));
        $this->post('/t/bills/staff-bills/'.$bill->id.'/review', ['version' => 1, 'action' => 'approved', 'expense_id' => $expense->id])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::connection('tenant')->table('unit_expenses')->count());
    }

    public function test_review_screen_and_reimbursement_payee_are_visible(): void
    {
        $bill = $this->submit();
        $this->actingAs($this->reviewer)->get('/t/bills/staff-bills/'.$bill->id)->assertOk()->assertSee('Review bill');
        $this->approve($bill);
        $id = DB::connection('tenant')->table('unit_expenses')->value('id');
        $this->get('/t/bills/expenses/'.$id.'/payments')->assertOk()->assertSee('Reimburse Cleaner One');
    }

    public function test_inactive_staff_and_missing_receipts_cannot_submit(): void
    {
        $this->post('/t/bills/staff-bills', $this->payload(['receipt' => null]))->assertSessionHasErrors('receipt');
        $this->post('/t/bills/staff-bills', $this->payload(['purchase_date' => now()->addDay()->toDateString()]))->assertSessionHasErrors('purchase_date');
        DB::connection('tenant')->table('staff')->update(['status' => 'inactive']);
        $this->post('/t/bills/staff-bills', $this->payload())->assertForbidden();
        $this->assertSame(0, DB::connection('tenant')->table('staff_bills')->count());
    }

    public function test_invalid_category_rolls_back_and_company_expense_cannot_be_linked_twice(): void
    {
        $personal = $this->submit();
        $this->actingAs($this->reviewer)->post('/t/bills/staff-bills/'.$personal->id.'/review', ['version' => 1, 'action' => 'approved', 'category_id' => 999])->assertSessionHasErrors('category_id');
        $this->assertSame(0, DB::connection('tenant')->table('unit_expenses')->count());
        $one = $this->submit(['payer' => 'company']);
        $two = $this->submit(['payer' => 'company']);
        $id = DB::connection('tenant')->table('unit_expenses')->insertGetId(['unit_id' => 1, 'category_id' => 1, 'expense_date' => now()->toDateString(), 'description' => 'Water', 'amount' => 500, 'amount_paid' => 500, 'payment_status' => 'paid', 'source_type' => 'manual']);
        $this->actingAs($this->reviewer)->post('/t/bills/staff-bills/'.$one->id.'/review', ['version' => 1, 'action' => 'approved', 'expense_id' => $id])->assertSessionHasNoErrors();
        $this->post('/t/bills/staff-bills/'.$two->id.'/review', ['version' => 1, 'action' => 'approved', 'expense_id' => $id])->assertSessionHasErrors('expense_id');
        $this->assertSame(1, DB::connection('tenant')->table('unit_expenses')->count());
    }
}
