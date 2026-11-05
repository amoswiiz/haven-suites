<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ExpenseCorrections;
use App\Services\ExpenseCorrectionSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpenseCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $this->company = Tenant::create(['name' => 'Expense test', 'slug' => 'expense-test', 'database_name' => ':memory:', 'active' => true]);
        $this->admin = User::factory()->create(['active' => true]);
        $this->admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 22]);
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($this->admin);
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('units', function (Blueprint $table): void {
            $table->id();
            $table->integer('property_id');
            $table->string('unit_number');
        });
        $schema->create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });
        $schema->create('unit_expenses', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id')->nullable();
            $table->integer('category_id');
            $table->date('expense_date');
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('payment_status')->default('unpaid');
            $table->date('paid_at')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('supplier_name')->nullable();
            $table->text('notes')->nullable();
            $table->string('source_type')->default('manual');
            $table->integer('source_id')->nullable();
            $table->integer('recorded_by')->nullable();
            $table->timestamps();
        });
        $schema->create('expense_payments', function (Blueprint $table): void {
            $table->id();
            $table->integer('expense_id');
            $table->date('payment_date');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method')->default('cash');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->integer('recorded_by')->nullable();
            $table->timestamps();
        });
        $schema->create('accounting_period_locks', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id');
            $table->date('period_month');
            $table->boolean('is_locked');
        });
        $schema->create('staff_bills', function (Blueprint $table): void {
            $table->id();
            $table->integer('expense_id')->nullable();
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Test apartments']);
        DB::connection('tenant')->table('units')->insert([['id' => 1, 'property_id' => 1, 'unit_number' => 'A1'], ['id' => 2, 'property_id' => 1, 'unit_number' => 'B2']]);
        DB::connection('tenant')->table('expense_categories')->insert([['id' => 1, 'name' => 'Internet', 'is_active' => true], ['id' => 2, 'name' => 'Utilities', 'is_active' => true]]);
        app(ExpenseCorrectionSchema::class)->install(DB::connection('tenant'));
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        parent::tearDown();
    }

    private function expense(array $overrides = []): object
    {
        $db = DB::connection('tenant');
        $id = $db->table('unit_expenses')->insertGetId(array_replace([
            'unit_id' => 1, 'category_id' => 1, 'expense_date' => '2026-11-04', 'description' => 'Internet bill',
            'amount' => '4000.00', 'amount_paid' => '4000.00', 'payment_status' => 'paid', 'paid_at' => '2026-11-04',
            'payment_method' => 'mpesa', 'payment_reference' => 'REF123', 'supplier_name' => 'Internet shop',
            'notes' => 'Original notes', 'source_type' => 'manual', 'source_id' => null, 'recorded_by' => 22,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $db->table('unit_expenses')->find($id);
    }

    private function payload(object $expense, array $overrides = []): array
    {
        return array_replace([
            'snapshot' => app(ExpenseCorrections::class)->snapshot($expense), 'unit_id' => $expense->unit_id,
            'category_id' => $expense->category_id, 'expense_date' => $expense->expense_date,
            'description' => 'Corrected internet bill', 'supplier_name' => $expense->supplier_name,
            'amount' => number_format((float) $expense->amount, 2, '.', ''), 'notes' => $expense->notes,
            'reason' => 'Corrected wrong entry',
        ], $overrides);
    }

    private function url(object $expense): string
    {
        return '/t/'.$this->company->slug.'/expenses/'.$expense->id.'/edit';
    }

    private function payment(object $expense, string $date = '2026-11-04', string $amount = '4000.00'): void
    {
        DB::connection('tenant')->table('expense_payments')->insert(['expense_id' => $expense->id, 'payment_date' => $date, 'amount' => $amount]);
    }

    public function test_historical_paid_expense_can_be_corrected_without_inventing_payments(): void
    {
        $expense = $this->expense();
        $this->get($this->url($expense))->assertOk()->assertSee('historical expense')->assertSee('Reason for correction');
        $this->post($this->url($expense), $this->payload($expense, ['unit_id' => 2, 'category_id' => 2, 'amount' => '4500.00', 'amount_paid' => 0]))->assertSessionHasNoErrors()->assertSessionHas('success');
        $updated = DB::connection('tenant')->table('unit_expenses')->find($expense->id);
        $this->assertSame(2, $updated->unit_id);
        $this->assertEquals(4500, $updated->amount);
        $this->assertEquals(4000, $updated->amount_paid);
        $this->assertSame('partial', $updated->payment_status);
        $this->assertSame('mpesa', $updated->payment_method);
        $this->assertSame('REF123', $updated->payment_reference);
        $this->assertSame(0, DB::connection('tenant')->table('expense_payments')->count());
        $history = DB::connection('tenant')->table('expense_corrections')->first();
        $this->assertEquals($this->admin->id, $history->actor_id);
        $this->assertSame('Internet bill', json_decode($history->before_snapshot, true)['description']);
        $this->get($this->url($expense))->assertOk()->assertSee('Corrected wrong entry')->assertSee('Correction history');
    }

    public function test_paid_floor_ledger_and_concurrent_updates_are_protected(): void
    {
        $expense = $this->expense();
        $this->payment($expense);
        $this->post($this->url($expense), $this->payload($expense, ['amount' => '3999.99']))->assertSessionHasErrors('amount');
        $this->post($this->url($expense), $this->payload($expense))->assertSessionHasNoErrors();
        $this->post($this->url($expense), $this->payload($expense))->assertSessionHasErrors('expense');
        $this->assertSame(1, DB::connection('tenant')->table('expense_payments')->count());
        $this->assertSame(1, DB::connection('tenant')->table('expense_corrections')->count());
    }

    public function test_company_expenses_can_move_to_apartments_without_changing_payments(): void
    {
        $expense = $this->expense(['unit_id' => null]);
        $this->payment($expense);
        $this->post($this->url($expense), $this->payload($expense, ['unit_id' => 2]))->assertSessionHasNoErrors();
        $updated = DB::connection('tenant')->table('unit_expenses')->find($expense->id);
        $this->post($this->url($expense), $this->payload($updated, ['unit_id' => null]))->assertSessionHasNoErrors();
        $this->assertNull(DB::connection('tenant')->table('unit_expenses')->find($expense->id)->unit_id);
        $this->assertSame(1, DB::connection('tenant')->table('expense_payments')->count());
    }

    public function test_old_new_and_payment_month_locks_block_financial_movement(): void
    {
        $expense = $this->expense(['expense_date' => '2026-10-04']);
        $this->payment($expense, '2026-11-04');
        $db = DB::connection('tenant');
        $db->table('accounting_period_locks')->insert(['unit_id' => 1, 'period_month' => '2026-10-01', 'is_locked' => true]);
        $this->post($this->url($expense), $this->payload($expense))->assertSessionHasErrors('expense');
        $db->table('accounting_period_locks')->delete();
        $db->table('accounting_period_locks')->insert(['unit_id' => 2, 'period_month' => '2026-12-01', 'is_locked' => true]);
        $unpaid = $this->expense(['expense_date' => '2026-10-04', 'amount_paid' => '0.00', 'payment_status' => 'unpaid', 'paid_at' => null, 'payment_method' => null, 'payment_reference' => null]);
        $this->post($this->url($unpaid), $this->payload($unpaid, ['unit_id' => 2, 'expense_date' => '2026-12-04']))->assertSessionHasErrors('expense');
        $db->table('accounting_period_locks')->delete();
        $db->table('accounting_period_locks')->insert(['unit_id' => 2, 'period_month' => '2026-11-01', 'is_locked' => true]);
        $this->post($this->url($expense), $this->payload($expense, ['unit_id' => 2]))->assertSessionHasErrors('expense');
        $this->assertSame(0, $db->table('expense_corrections')->count());
    }

    public function test_legacy_payment_date_and_mismatched_ledger_are_handled_conservatively(): void
    {
        $expense = $this->expense(['paid_at' => null]);
        $this->post($this->url($expense), $this->payload($expense, ['unit_id' => 2]))->assertSessionHasErrors('expense');
        $this->payment($expense, '2026-11-04', '3000.00');
        $this->post($this->url($expense), $this->payload($expense, ['amount' => '4500.00']))->assertSessionHasErrors('expense');
        $this->post($this->url($expense), $this->payload($expense))->assertSessionHasNoErrors();
        $this->assertEquals(4000, DB::connection('tenant')->table('unit_expenses')->find($expense->id)->amount_paid);
    }

    public function test_legacy_cash_month_is_protected_while_metadata_can_retain_an_inactive_category(): void
    {
        $expense = $this->expense(['expense_date' => '2026-10-04']);
        $db = DB::connection('tenant');
        $db->table('accounting_period_locks')->insert(['unit_id' => 1, 'period_month' => '2026-11-01', 'is_locked' => true]);
        $this->post($this->url($expense), $this->payload($expense, ['category_id' => 2]))->assertSessionHasErrors('expense');
        $db->table('expense_categories')->where('id', 1)->update(['is_active' => false]);
        $this->post($this->url($expense), $this->payload($expense))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(1, $db->table('expense_corrections')->count());
        $this->assertSame(0, $db->table('expense_payments')->count());
    }

    public function test_generated_and_linked_bill_expenses_are_not_generic_editable(): void
    {
        foreach (['housekeeping', 'rent', 'staff_bill'] as $source) {
            $expense = $this->expense(['source_type' => $source, 'source_id' => 1]);
            $this->get($this->url($expense))->assertRedirect('/t/expense-test/expenses')->assertSessionHas('error');
            $this->post($this->url($expense), $this->payload($expense))->assertSessionHasErrors('expense');
        }
        $expense = $this->expense();
        DB::connection('tenant')->table('staff_bills')->insert(['expense_id' => $expense->id]);
        $this->post($this->url($expense), $this->payload($expense))->assertSessionHasErrors('expense');
        $this->assertSame(0, DB::connection('tenant')->table('expense_corrections')->count());
    }

    public function test_permissions_tenant_membership_and_input_validation_are_enforced(): void
    {
        $expense = $this->expense();
        $this->postJson($this->url($expense), $this->payload($expense, ['unit_id' => 999, 'reason' => '', 'amount' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors(['unit_id', 'reason', 'amount']);
        $cleaner = User::factory()->create(['active' => true]);
        $cleaner->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 23]);
        $this->actingAs($cleaner)->get($this->url($expense))->assertForbidden();
        $this->post($this->url($expense), $this->payload($expense))->assertForbidden();
        $this->actingAs(User::factory()->create(['active' => true]))->post($this->url($expense), $this->payload($expense))->assertForbidden();
        $this->actingAs($this->admin);
        $this->company->modules = ['finance' => false];
        $this->company->save();
        $this->get($this->url($expense))->assertForbidden();
        $this->assertSame(0, DB::connection('tenant')->table('expense_corrections')->count());
    }

    public function test_history_install_is_repeatable_and_keeps_existing_expenses(): void
    {
        $expense = $this->expense();
        app(ExpenseCorrectionSchema::class)->install(DB::connection('tenant'));
        $this->assertEquals(4000, DB::connection('tenant')->table('unit_expenses')->find($expense->id)->amount);
        $this->assertSame(0, DB::connection('tenant')->table('expense_corrections')->count());
    }
}
