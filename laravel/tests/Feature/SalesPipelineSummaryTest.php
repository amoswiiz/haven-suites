<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\SalesPipelineSummary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class SalesPipelineSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-06 10:00:00', config('app.timezone')));
        $this->company = Tenant::create(['name' => 'Pipeline company', 'slug' => 'pipeline-company', 'database_name' => ':memory:', 'active' => true, 'timezone' => 'America/Los_Angeles']);
        $this->staff = User::factory()->create(['active' => true]);
        $this->staff->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        app()->instance('currentTenant', $this->company);
        $this->actingAs($this->staff);
    }

    private function enquiry(string $stage, ?string $followUp = null, ?Tenant $company = null): int
    {
        return DB::table('sales_leads')->insertGetId([
            'tenant_id' => ($company ?? $this->company)->id, 'name' => 'Private guest details', 'phone' => '+254700000000',
            'source' => 'phone', 'channel' => 'phone', 'assigned_to' => $this->staff->id,
            'stage' => $stage, 'next_action' => 'Contact guest', 'follow_up_at' => $followUp,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dashboardData(array $overrides = []): array
    {
        return array_replace([
            'todayCheckins' => 0, 'remainingCheckouts' => 0, 'completedCheckouts' => 0, 'todayCheckouts' => 0,
            'pendingCleaning' => 0, 'openMaintenance' => 0, 'departureTasks' => [], 'cleaningTasks' => [],
            'recentMaintenance' => [], 'upcomingCheckins' => [], 'upcomingDepartures' => [], 'canViewFinance' => false,
            'totalUnits' => 0, 'activeUnits' => 0, 'totalReservations' => 0, 'confirmedReservations' => 0, 'totalStaff' => 0,
            'pipelineSummary' => app(SalesPipelineSummary::class)->summary(),
            'communicationQueue' => ['dateLabel' => 'Friday, 6 November 2026', 'readyCount' => 0, 'reviewCount' => 0, 'totalCount' => 0, 'rows' => collect()],
        ], $overrides);
    }

    public function test_all_seven_stages_are_visible_with_zero_counts_before_the_first_enquiry(): void
    {
        $summary = app(SalesPipelineSummary::class)->summary();
        $this->assertSame(array_keys(config('sales.stages')), array_column($summary['stages'], 'key'));
        $this->assertSame(array_values(config('sales.stages')), array_column($summary['stages'], 'label'));
        $this->assertSame(array_fill(0, 7, 0), array_column($summary['stages'], 'count'));
        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['openCount']);
        $this->assertSame(0, $summary['overdueCount']);
        $html = view('dashboard.pipeline', ['pipelineSummary' => $summary])->render();
        $this->assertStringContainsString('0 total enquiries', $html);
        $this->assertSame(7, substr_count($html, 'class="hs-pipeline-stage"'));
    }

    public function test_counts_are_company_scoped_and_include_every_record_before_pagination(): void
    {
        for ($index = 0; $index < 65; $index++) {
            $this->enquiry('new', now()->addDay()->format('Y-m-d H:i:s'));
        }
        $this->enquiry('qualifying');
        $this->enquiry('won', now()->subDay()->format('Y-m-d H:i:s'));
        $other = Tenant::create(['name' => 'Other company', 'slug' => 'other-pipeline', 'database_name' => 'other_pipeline_test', 'active' => true]);
        $this->enquiry('awaiting_deposit', now()->subDay()->format('Y-m-d H:i:s'), $other);

        $summary = app(SalesPipelineSummary::class)->summary();
        $counts = array_column($summary['stages'], 'count', 'key');
        $this->assertSame(65, $counts['new']);
        $this->assertSame(1, $counts['qualifying']);
        $this->assertSame(1, $counts['won']);
        $this->assertSame(0, $counts['awaiting_deposit']);
        $this->assertSame(67, $summary['total']);
        $this->assertSame(66, $summary['openCount']);
        $this->assertSame(0, $summary['overdueCount']);
    }

    public function test_overdue_follow_ups_use_the_storage_clock_and_exclude_closed_or_unscheduled_enquiries(): void
    {
        $this->enquiry('new', now()->subSecond()->format('Y-m-d H:i:s'));
        $this->enquiry('awaiting_deposit', now()->subDay()->format('Y-m-d H:i:s'));
        $this->enquiry('qualifying', now()->format('Y-m-d H:i:s'));
        $this->enquiry('options_shared', now()->addSecond()->format('Y-m-d H:i:s'));
        $this->enquiry('invoice_issued');
        $this->enquiry('won', now()->subDay()->format('Y-m-d H:i:s'));
        $this->enquiry('lost', now()->subDay()->format('Y-m-d H:i:s'));

        $summary = app(SalesPipelineSummary::class)->summary();
        $this->assertSame(7, $summary['total']);
        $this->assertSame(5, $summary['openCount']);
        $this->assertSame(2, $summary['overdueCount']);
    }

    public function test_summary_uses_one_grouped_query_without_loading_guest_details(): void
    {
        $this->enquiry('new');
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        app(SalesPipelineSummary::class)->summary();
        $queries = collect(DB::connection()->getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'sales_leads'))->values();
        DB::connection()->disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('group by', strtolower($queries[0]['query']));
        $this->assertStringNotContainsString('select *', strtolower($queries[0]['query']));
        $this->assertStringNotContainsString('phone', strtolower($queries[0]['query']));
        $this->assertContains($this->company->id, $queries[0]['bindings']);
    }

    public function test_stage_links_filter_the_pipeline_and_are_between_operations_and_communications(): void
    {
        $this->enquiry('new');
        $html = view('dashboard.index', $this->dashboardData())->render();
        $operations = strpos($html, 'id="daily-operations-heading"');
        $pipeline = strpos($html, 'id="sales-pipeline-heading"');
        $communications = strpos($html, 'id="communications-today-heading"');
        $this->assertNotFalse($operations);
        $this->assertNotFalse($pipeline);
        $this->assertNotFalse($communications);
        $this->assertLessThan($pipeline, $operations);
        $this->assertLessThan($communications, $pipeline);
        foreach (array_keys(config('sales.stages')) as $stage) {
            $this->assertStringContainsString(route('sales.index', [$this->company->slug, 'stage' => $stage]), $html);
        }
        $this->assertStringNotContainsString('Private guest details', $html);
    }

    public function test_unauthorized_roles_and_disabled_sales_cannot_query_or_render_the_summary(): void
    {
        $summary = app(SalesPipelineSummary::class)->summary();
        foreach (['cleaner', 'owner'] as $role) {
            $this->staff->tenants()->updateExistingPivot($this->company->id, ['roles' => json_encode([$role])]);
            $this->assertSummaryForbidden();
            $this->assertSame('', trim(view('dashboard.pipeline', ['pipelineSummary' => $summary])->render()));
        }
        $this->staff->tenants()->updateExistingPivot($this->company->id, ['roles' => json_encode(['admin'])]);
        $this->company->forceFill(['modules' => ['sales' => false]])->save();
        $this->assertSummaryForbidden();
        $this->assertSame('', trim(view('dashboard.pipeline', ['pipelineSummary' => $summary])->render()));
    }

    public function test_inactive_accounts_cannot_load_counts(): void
    {
        $this->staff->forceFill(['active' => false])->save();
        $this->assertSummaryForbidden();
    }

    private function assertSummaryForbidden(): void
    {
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            app(SalesPipelineSummary::class)->summary();
            $this->fail('Pipeline summary access should have been denied.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $queries = collect(DB::connection()->getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'sales_leads'));
            $this->assertCount(0, $queries);
        } finally {
            DB::connection()->disableQueryLog();
        }
    }
}
