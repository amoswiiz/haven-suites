<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ExpenseCorrections;
use App\Services\ExpenseCorrectionSchema;
use App\Services\ExpensePaymentCorrections;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpensePaymentCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $this->company = Tenant::create(['name' => 'Payment corrections', 'slug' => 'payment-corrections', 'database_name' => ':memory:', 'active' => true]);
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
            $table->string('payment_method');
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
        DB::connection('tenant')->table('units')->insert(['id' => 1, 'property_id' => 1, 'unit_number' => 'A1']);
        DB::connection('tenant')->table('expense_categories')->insert(['id' => 1, 'name' => 'Internet', 'is_active' => true]);
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
            'notes' => 'Keep these expense notes', 'source_type' => 'manual', 'source_id' => null, 'recorded_by' => 22,
            'created_at' => '2026-11-04 10:00:00', 'updated_at' => '2026-11-04 10:00:00',
        ], $overrides));

        return $db->table('unit_expenses')->find($id);
    }

    private function payment(object $expense, array $overrides = []): object
    {
        $db = DB::connection('tenant');
        $id = $db->table('expense_payments')->insertGetId(array_replace([
            'expense_id' => $expense->id, 'payment_date' => '2026-11-04', 'amount' => '4000.00',
            'payment_method' => 'mpesa', 'reference' => 'REF123', 'notes' => 'Original payment note',
            'recorded_by' => 22, 'created_at' => '2026-11-04 10:00:00', 'updated_at' => '2026-11-04 10:00:00',
        ], $overrides));

        return $db->table('expense_payments')->find($id);
    }

    private function payload(object $expense, ?object $payment, array $overrides = []): array
    {
        return array_replace([
            'snapshot' => app(ExpensePaymentCorrections::class)->snapshot($expense, $payment),
            'amount' => '3000.00', 'payment_date' => $payment?->payment_date ?? $expense->paid_at ?? $expense->expense_date,
            'payment_method' => $payment?->payment_method ?? $expense->payment_method,
            'reference' => $payment?->reference ?? $expense->payment_reference,
            'notes' => $payment?->notes, 'reason' => 'Payment entered as 4000 instead of 3000',
        ], $overrides);
    }

    private function endpoint(object $expense, ?object $payment): string
    {
        return '/t/'.$this->company->slug.'/expenses/'.$expense->id.'/payments/'.($payment ? $payment->id : 'historical').'/edit';
    }

    private function correct(object $expense, ?object $payment, array $overrides = []): void
    {
        app(ExpensePaymentCorrections::class)->correct($expense->id, $payment?->id, $this->payload($expense, $payment, $overrides));
    }

    private function assertBlocked(callable $operation, string $field): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());

            return;
        }
        $this->fail('Expected a validation error for '.$field.'.');
    }

    public function test_recorded_payment_can_be_corrected_then_expense_total_reduced_to_match(): void
    {
        $expense = $this->expense();
        $payment = $this->payment($expense);
        $this->get('/t/'.$this->company->slug.'/expenses/'.$expense->id.'/payments')
            ->assertOk()->assertSee('Correct payment')->assertSee($this->endpoint($expense, $payment), false);
        $this->get($this->endpoint($expense, $payment))->assertOk();
        $this->post($this->endpoint($expense, $payment), $this->payload($expense, $payment))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->get('/t/'.$this->company->slug.'/expenses/'.$expense->id.'/payments')
            ->assertOk()->assertSee('Payment correction history')->assertSee('Payment entered as 4000 instead of 3000')->assertSee('3,000.00');
        $db = DB::connection('tenant');
        $updatedExpense = $db->table('unit_expenses')->find($expense->id);
        $updatedPayment = $db->table('expense_payments')->find($payment->id);
        $this->assertEquals(3000, $updatedPayment->amount);
        $this->assertEquals(3000, $updatedExpense->amount_paid);
        $this->assertEquals(4000, $updatedExpense->amount);
        $this->assertSame('partial', $updatedExpense->payment_status);
        $this->assertSame(22, $updatedPayment->recorded_by);
        $this->assertSame($payment->created_at, $updatedPayment->created_at);
        $this->post('/t/'.$this->company->slug.'/expenses/'.$expense->id.'/edit', [
            'snapshot' => app(ExpenseCorrections::class)->snapshot($updatedExpense), 'unit_id' => 1,
            'category_id' => 1, 'expense_date' => $expense->expense_date, 'description' => $expense->description,
            'supplier_name' => $expense->supplier_name, 'amount' => '3000.00', 'notes' => $expense->notes,
            'reason' => 'Correct bill total after correcting its payment',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('paid', $db->table('unit_expenses')->find($expense->id)->payment_status);
        $this->assertSame(1, $db->table('expense_corrections')->count());
        $history = $db->table('expense_payment_corrections')->first();
        $this->assertEquals($this->admin->id, $history->actor_id);
        $this->assertSame('payment', $history->kind);
        $this->assertEquals(4000, json_decode($history->before_snapshot, true)['payment']['amount']);
        $this->assertEquals(3000, json_decode($history->after_snapshot, true)['payment']['amount']);
    }

    public function test_historical_balance_can_be_corrected_or_cleared_without_creating_payments(): void
    {
        $expense = $this->expense();
        $this->get('/t/'.$this->company->slug.'/expenses/'.$expense->id.'/payments')
            ->assertOk()->assertSee('Historical paid balance')->assertSee('Correct historical paid balance')->assertSee('4,000.00');
        $this->get($this->endpoint($expense, null))->assertOk()->assertSee('Correct historical paid balance');
        $this->post($this->endpoint($expense, null), $this->payload($expense, null, ['notes' => 'Do not overwrite expense notes']))->assertSessionHasNoErrors()->assertSessionHas('success');
        $db = DB::connection('tenant');
        $updated = $db->table('unit_expenses')->find($expense->id);
        $this->assertEquals(3000, $updated->amount_paid);
        $this->assertSame('Keep these expense notes', $updated->notes);
        $this->correct($updated, null, ['amount' => '0.00', 'payment_method' => null]);
        $cleared = $db->table('unit_expenses')->find($expense->id);
        $this->assertEquals(0, $cleared->amount_paid);
        $this->assertSame('unpaid', $cleared->payment_status);
        $this->assertNull($cleared->paid_at);
        $this->assertNull($cleared->payment_method);
        $this->assertNull($cleared->payment_reference);
        $this->assertEquals(4000, $cleared->amount);
        $this->assertSame(0, $db->table('expense_payments')->count());
        $this->assertSame(2, $db->table('expense_payment_corrections')->where('kind', 'historical')->count());
        $this->assertNull(json_decode($db->table('expense_payment_corrections')->first()->before_snapshot, true)['payment']);
    }

    public function test_multiple_payment_total_and_latest_payment_metadata_are_recomputed(): void
    {
        $expense = $this->expense();
        $payment = $this->payment($expense, ['amount' => '2000.00']);
        $second = $this->payment($expense, ['amount' => '2000.00', 'payment_date' => '2026-11-05', 'payment_method' => 'cash', 'reference' => 'LATEST']);
        $this->assertBlocked(fn () => $this->correct($expense, $payment, ['amount' => '2500.00']), 'amount');
        $this->correct($expense, $payment, ['amount' => '1500.00']);
        $db = DB::connection('tenant');
        $updated = $db->table('unit_expenses')->find($expense->id);
        $this->assertEquals(3500, $updated->amount_paid);
        $this->assertSame($second->payment_date, $updated->paid_at);
        $this->assertSame('LATEST', $updated->payment_reference);
        $payment = $db->table('expense_payments')->find($payment->id);
        $this->correct($updated, $payment, ['amount' => '1500.00', 'payment_date' => '2026-11-06', 'payment_method' => 'bank', 'reference' => 'NEW-LATEST']);
        $updated = $db->table('unit_expenses')->find($expense->id);
        $this->assertSame('2026-11-06', $updated->paid_at);
        $this->assertSame('bank', $updated->payment_method);
        $this->assertSame('NEW-LATEST', $updated->payment_reference);
        $this->assertEquals(2000, $db->table('expense_payments')->find($second->id)->amount);
    }

    public function test_existing_overpayment_can_be_corrected_down_to_expense_total(): void
    {
        $expense = $this->expense(['amount' => '3000.00']);
        $payment = $this->payment($expense);
        $this->correct($expense, $payment);
        $updated = DB::connection('tenant')->table('unit_expenses')->find($expense->id);
        $this->assertEquals(3000, $updated->amount_paid);
        $this->assertSame('paid', $updated->payment_status);
    }

    public function test_old_and_new_cash_months_and_unknown_historical_date_are_guarded(): void
    {
        $expense = $this->expense(['paid_at' => '2026-10-31']);
        $payment = $this->payment($expense, ['payment_date' => '2026-10-31']);
        $db = DB::connection('tenant');
        $db->table('accounting_period_locks')->insert(['unit_id' => 1, 'period_month' => '2026-10-01', 'is_locked' => true]);
        $this->assertBlocked(fn () => $this->correct($expense, $payment, ['payment_date' => '2026-11-04']), 'expense');
        $db->table('accounting_period_locks')->delete();
        $db->table('accounting_period_locks')->insert(['unit_id' => 1, 'period_month' => '2026-11-01', 'is_locked' => true]);
        $this->assertBlocked(fn () => $this->correct($expense, $payment, ['payment_date' => '2026-11-04']), 'expense');
        $historical = $this->expense(['paid_at' => null]);
        $this->assertBlocked(fn () => $this->correct($historical, null, ['amount' => '0.00']), 'expense');
        $this->assertSame(0, $db->table('expense_payment_corrections')->count());
        $this->assertEquals(4000, $db->table('expense_payments')->find($payment->id)->amount);
    }

    public function test_advance_payment_date_can_be_retained_when_correcting_its_amount(): void
    {
        $expense = $this->expense(['paid_at' => '2026-10-31']);
        $payment = $this->payment($expense, ['payment_date' => '2026-10-31']);
        $this->assertBlocked(fn () => $this->correct($expense, $payment, ['payment_date' => '2026-10-30']), 'payment_date');
        $this->correct($expense, $payment);
        $this->assertEquals(3000, DB::connection('tenant')->table('unit_expenses')->find($expense->id)->amount_paid);
    }

    public function test_stale_edits_and_ledger_mismatches_are_rejected(): void
    {
        $expense = $this->expense();
        $payment = $this->payment($expense);
        $this->correct($expense, $payment);
        $this->assertBlocked(fn () => $this->correct($expense, $payment), 'expense');
        $mismatched = $this->expense(['amount_paid' => '3500.00']);
        $mismatchedPayment = $this->payment($mismatched);
        $this->assertBlocked(fn () => $this->correct($mismatched, $mismatchedPayment), 'expense');
        $this->assertBlocked(fn () => $this->correct($mismatched, null), 'expense');
        $this->assertSame(1, DB::connection('tenant')->table('expense_payment_corrections')->count());
    }

    public function test_generated_and_approved_bill_linked_expenses_are_protected(): void
    {
        foreach (['housekeeping', 'operating_rent', 'staff_bill'] as $source) {
            $expense = $this->expense(['source_type' => $source, 'source_id' => 1]);
            $payment = $this->payment($expense);
            $this->assertBlocked(fn () => $this->correct($expense, $payment), 'expense');
        }
        $expense = $this->expense();
        $payment = $this->payment($expense);
        DB::connection('tenant')->table('staff_bills')->insert(['expense_id' => $expense->id]);
        $this->assertBlocked(fn () => $this->correct($expense, $payment), 'expense');
        $this->assertSame(0, DB::connection('tenant')->table('expense_payment_corrections')->count());
    }

    public function test_payment_cannot_be_corrected_through_another_expense_or_by_unauthorized_users(): void
    {
        $expense = $this->expense();
        $other = $this->expense();
        $payment = $this->payment($other);
        $this->post($this->endpoint($expense, $payment), $this->payload($expense, $payment))->assertNotFound();
        $cleaner = User::factory()->create(['active' => true]);
        $cleaner->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 23]);
        $this->actingAs($cleaner)->post($this->endpoint($other, $payment), $this->payload($other, $payment))->assertForbidden();
        $this->actingAs(User::factory()->create(['active' => true]))->post($this->endpoint($other, $payment), $this->payload($other, $payment))->assertForbidden();
        $this->admin->active = false;
        $this->admin->save();
        $this->actingAs($this->admin)->post($this->endpoint($other, $payment), $this->payload($other, $payment))->assertForbidden();
        $this->assertSame(0, DB::connection('tenant')->table('expense_payment_corrections')->count());
    }

    public function test_audit_failure_rolls_back_payment_and_expense_updates(): void
    {
        $expense = $this->expense();
        $payment = $this->payment($expense);
        DB::connection('tenant')->getSchemaBuilder()->drop('expense_payment_corrections');
        try {
            $this->correct($expense, $payment);
            $this->fail('Expected the missing audit table to abort the correction.');
        } catch (QueryException) {
            $this->assertEquals(4000, DB::connection('tenant')->table('unit_expenses')->find($expense->id)->amount_paid);
            $this->assertEquals(4000, DB::connection('tenant')->table('expense_payments')->find($payment->id)->amount);
        }
    }

    public function test_history_schema_can_be_upgraded_repeatedly_without_changing_payments(): void
    {
        $expense = $this->expense();
        $payment = $this->payment($expense);
        $this->correct($expense, $payment);
        app(ExpenseCorrectionSchema::class)->install(DB::connection('tenant'));
        $this->assertSame(1, DB::connection('tenant')->table('expense_payment_corrections')->count());
        $this->assertEquals(3000, DB::connection('tenant')->table('expense_payments')->find($payment->id)->amount);
    }
}
