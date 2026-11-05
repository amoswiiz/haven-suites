<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CleanerDashboardSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CleanerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $cleaner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $this->company = Tenant::create(['name' => 'Cleaner test', 'slug' => 'cleaner-test', 'database_name' => ':memory:', 'active' => true, 'timezone' => 'Africa/Nairobi']);
        $this->cleaner = User::factory()->create(['name' => 'Current cleaner', 'active' => true]);
        $this->other = User::factory()->create(['name' => 'Other cleaner', 'active' => true]);
        $this->cleaner->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 11]);
        $this->other->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 22]);
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($this->cleaner);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 22:30:00', 'UTC'));
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->create('staff', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('status');
            $table->string('full_name');
        });
        $schema->create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('units', function (Blueprint $table): void {
            $table->id();
            $table->integer('property_id');
            $table->string('unit_number');
        });
        $schema->create('housekeeping', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id');
            $table->integer('assigned_staff_id');
            $table->string('status');
            $table->dateTime('scheduled_at');
            $table->dateTime('completed_at')->nullable();
            $table->decimal('cleaning_charge', 12, 2)->default(0);
        });
        $schema->create('cleaner_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->integer('housekeeping_id');
            $table->decimal('amount', 12, 2);
        });
        $schema->create('unit_expenses', function (Blueprint $table): void {
            $table->id();
            $table->decimal('amount_paid', 12, 2)->default(0);
        });
        $schema->create('staff_bills', function (Blueprint $table): void {
            $table->id();
            $table->integer('submitted_by');
            $table->string('description');
            $table->string('status');
            $table->string('payer');
            $table->integer('amount_minor');
            $table->integer('expense_id')->nullable();
        });
        $schema->create('maintenance', function (Blueprint $table): void {
            $table->id();
            $table->integer('reported_by_user_id')->nullable();
            $table->string('title');
            $table->string('status');
        });
        DB::connection('tenant')->table('staff')->insert([
            ['id' => 1, 'user_id' => 11, 'full_name' => 'Current cleaner', 'status' => 'active'],
            ['id' => 2, 'user_id' => 22, 'full_name' => 'Other cleaner', 'status' => 'active'],
        ]);
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Cleaner property']);
        DB::connection('tenant')->table('units')->insert([
            ['id' => 1, 'property_id' => 1, 'unit_number' => 'MY-APT'],
            ['id' => 2, 'property_id' => 1, 'unit_number' => 'OTHER-APT'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::purge('tenant');
        parent::tearDown();
    }

    private function url(): string
    {
        return '/t/cleaner-test/cleaner/dashboard';
    }

    private function job(array $values = []): int
    {
        return DB::connection('tenant')->table('housekeeping')->insertGetId(array_replace([
            'unit_id' => 1, 'assigned_staff_id' => 1, 'status' => 'assigned', 'scheduled_at' => '2026-11-06 09:00:00',
            'completed_at' => null, 'cleaning_charge' => 0,
        ], $values));
    }

    private function task(array $values = []): int
    {
        return DB::table('task_items')->insertGetId(array_replace([
            'tenant_id' => $this->company->id, 'created_by' => $this->other->id, 'assigned_to' => $this->cleaner->id,
            'title' => 'My due task', 'due_date' => '2026-11-06', 'status' => 'todo', 'created_at' => now(), 'updated_at' => now(),
        ], $values));
    }

    public function test_summary_uses_company_day_staff_mapping_and_verified_work_without_duplicate_allocations(): void
    {
        $db = DB::connection('tenant');
        $this->job();
        $this->job(['scheduled_at' => '2026-11-05 10:00:00']);
        $this->job(['scheduled_at' => '2026-11-07 10:00:00']);
        $verified = $this->job(['status' => 'verified', 'completed_at' => '2026-11-06 10:00:00', 'cleaning_charge' => '1000.00']);
        $paid = $this->job(['status' => 'verified', 'scheduled_at' => '2026-11-02 10:00:00', 'completed_at' => '2026-11-02 12:00:00', 'cleaning_charge' => '500.00']);
        $this->job(['status' => 'completed', 'completed_at' => '2026-11-06 11:00:00', 'cleaning_charge' => '9000.00']);
        $this->job(['status' => 'disputed', 'completed_at' => '2026-11-06 12:00:00', 'cleaning_charge' => '7000.00']);
        $this->job(['status' => 'verified', 'scheduled_at' => '2026-10-31 12:00:00', 'completed_at' => '2026-10-31 12:00:00', 'cleaning_charge' => '6000.00']);
        $this->job(['assigned_staff_id' => 2, 'unit_id' => 2, 'cleaning_charge' => '88000.00']);
        $this->job(['assigned_staff_id' => 11, 'unit_id' => 2]);
        $db->table('cleaner_payment_allocations')->insert([
            ['housekeeping_id' => $verified, 'amount' => '300.00'], ['housekeeping_id' => $verified, 'amount' => '200.00'],
            ['housekeeping_id' => $paid, 'amount' => '500.00'],
        ]);

        $summary = app(CleanerDashboardSummary::class)->summary();
        $this->assertSame('2026-11-06', $summary['today']->toDateString());
        $this->assertSame(1, $summary['staff']->id);
        $this->assertSame(4, $summary['cleaning']['today']);
        $this->assertSame(3, $summary['cleaning']['pending']);
        $this->assertSame(1, $summary['cleaning']['overdue']);
        $this->assertSame(1, $summary['cleaning']['awaitingVerification']);
        $this->assertSame(1, $summary['cleaning']['disputed']);
        $this->assertSame(2, $summary['earnings']['verifiedJobs']);
        $this->assertSame(150000, $summary['earnings']['earnedMinor']);
        $this->assertSame(100000, $summary['earnings']['paidMinor']);
        $this->assertSame(50000, $summary['earnings']['outstandingMinor']);
        $this->get($this->url().'?staff_id=2')->assertOk()->assertSee('My dashboard')->assertSee('MY-APT')
            ->assertSee('KES 1,500.00')->assertDontSee('OTHER-APT')->assertDontSee('Financial summary')->assertDontSee('Guest outstanding');
    }

    public function test_personal_bills_reports_and_tasks_use_native_user_and_company_scope(): void
    {
        $db = DB::connection('tenant');
        $db->table('unit_expenses')->insert(['id' => 1, 'amount_paid' => '100.00']);
        $db->table('staff_bills')->insert([
            ['submitted_by' => $this->cleaner->id, 'description' => 'My approved water bill', 'status' => 'approved', 'payer' => 'personal', 'amount_minor' => 50000, 'expense_id' => 1],
            ['submitted_by' => $this->cleaner->id, 'description' => 'My returned bill', 'status' => 'returned', 'payer' => 'personal', 'amount_minor' => 1000, 'expense_id' => null],
            ['submitted_by' => 11, 'description' => 'Legacy ID bill must not leak', 'status' => 'approved', 'payer' => 'personal', 'amount_minor' => 9000000, 'expense_id' => null],
            ['submitted_by' => $this->other->id, 'description' => 'Other cleaner bill', 'status' => 'pending', 'payer' => 'personal', 'amount_minor' => 9000, 'expense_id' => null],
        ]);
        $db->table('maintenance')->insert([
            ['reported_by_user_id' => $this->cleaner->id, 'title' => 'My leaking tap report', 'status' => 'open'],
            ['reported_by_user_id' => 11, 'title' => 'Legacy ID report must not leak', 'status' => 'open'],
            ['reported_by_user_id' => $this->other->id, 'title' => 'Other cleaner report', 'status' => 'open'],
        ]);
        $this->task();
        $this->task(['title' => 'My completed task', 'status' => 'done']);
        $this->task(['title' => 'Other assigned task', 'created_by' => $this->cleaner->id, 'assigned_to' => $this->other->id]);
        $otherCompany = Tenant::create(['name' => 'Other', 'slug' => 'other-cleaner', 'database_name' => 'unused', 'active' => true]);
        $this->task(['title' => 'Other company task', 'tenant_id' => $otherCompany->id]);
        $summary = app(CleanerDashboardSummary::class)->summary();
        $this->assertSame(40000, $summary['bills']['reimbursementMinor']);
        $this->assertSame(1, $summary['bills']['returned']);
        $this->assertSame(1, $summary['maintenance']['open']);
        $this->assertSame(1, $summary['tasks']['due']);
        $this->get($this->url())->assertOk()->assertSee('My approved water bill')->assertSee('My leaking tap report')->assertSee('My due task')
            ->assertDontSee('Other cleaner bill')->assertDontSee('Legacy ID bill')->assertDontSee('Other cleaner report')->assertDontSee('Legacy ID report')
            ->assertDontSee('Other assigned task')->assertDontSee('Other company task')->assertDontSee('My completed task');
    }

    public function test_missing_inactive_or_ambiguous_staff_link_never_falls_back_to_other_cleaners(): void
    {
        $db = DB::connection('tenant');
        $this->job(['unit_id' => 2, 'assigned_staff_id' => 2]);
        $db->table('staff')->where('id', 1)->update(['status' => 'inactive']);
        $this->get($this->url())->assertOk()->assertSee('not linked to an active staff record')->assertSee('Complete my profile')->assertDontSee('OTHER-APT');
        $this->assertNull(app(CleanerDashboardSummary::class)->summary()['staff']);
        $db->table('staff')->insert(['id' => 3, 'user_id' => 11, 'full_name' => 'New active staff', 'status' => 'active']);
        $this->assertSame(3, app(CleanerDashboardSummary::class)->summary()['staff']->id);
        $db->table('staff')->where('id', 1)->update(['status' => 'active']);
        $this->assertNull(app(CleanerDashboardSummary::class)->summary()['staff']);
        $this->get($this->url())->assertOk()->assertSee('More than one active staff record')
            ->assertDontSee('OTHER-APT')->assertDontSee('My earnings ·');
    }

    public function test_disabled_modules_hide_and_do_not_query_operational_sections(): void
    {
        $this->company->modules = ['housekeeping' => false, 'maintenance' => false, 'tasks' => false];
        $this->company->save();
        $schema = DB::connection('tenant')->getSchemaBuilder();
        foreach (['housekeeping', 'cleaner_payment_allocations', 'staff_bills', 'maintenance'] as $table) {
            $schema->drop($table);
        }
        $this->get($this->url())->assertOk()->assertSee('Complete my profile')->assertDontSee('My cleaning summary')
            ->assertDontSee('My tasks due')->assertDontSee('My bills &amp; reimbursements', false)->assertDontSee('My maintenance reports')
            ->assertDontSee('Record cleaning')->assertDontSee('Submit a bill')->assertDontSee('Report maintenance');
    }

    public function test_profile_completion_is_specific_to_current_company_and_employee(): void
    {
        $otherCompany = Tenant::create(['name' => 'Other', 'slug' => 'profile-cleaner', 'database_name' => 'unused', 'active' => true]);
        $profile = ['tenant_id' => $otherCompany->id, 'user_id' => $this->cleaner->id, 'legal_name' => 'Cleaner', 'phone' => '0700000000', 'document_type' => 'national_id', 'document_path' => 'private-id.pdf', 'document_mime_type' => 'application/pdf', 'document_original_name' => 'id.pdf', 'created_at' => now(), 'updated_at' => now()];
        DB::table('staff_profiles')->insert($profile);
        $this->assertFalse(app(CleanerDashboardSummary::class)->summary()['profileComplete']);
        DB::table('staff_profiles')->insert([...$profile, 'tenant_id' => $this->company->id]);
        $this->assertTrue(app(CleanerDashboardSummary::class)->summary()['profileComplete']);
        $this->get($this->url())->assertOk()->assertDontSee('Complete my profile');
    }

    public function test_inactive_nonmember_and_noncleaner_accounts_cannot_open_cleaner_dashboard(): void
    {
        $this->cleaner->update(['active' => false]);
        $this->get($this->url())->assertForbidden();
        $outsider = User::factory()->create(['active' => true]);
        $this->actingAs($outsider)->get($this->url())->assertForbidden();
        $this->other->tenants()->updateExistingPivot($this->company->id, ['roles' => json_encode(['admin'])]);
        $this->actingAs($this->other)->get($this->url())->assertForbidden();
    }
}
