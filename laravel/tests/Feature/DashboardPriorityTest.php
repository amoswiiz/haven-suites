<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class DashboardPriorityTest extends TestCase
{
    use RefreshDatabase;

    private array $dashboard = [];

    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['name' => 'Dashboard test', 'slug' => 'dashboard-test', 'database_name' => 'haven_suites_laravel13', 'active' => true]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        $this->actingAs($user)->withoutMiddleware(ResolveTenant::class);
        app()->instance('currentTenant', $tenant);
        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');
        $db = DB::connection('tenant');
        $db->beginTransaction();
        $unit = (array) $db->table('units')->first();
        unset($unit['id']);
        $unit['unit_number'] = 'DASH-'.bin2hex(random_bytes(4));
        $unit['status'] = 'active';
        $this->unit = $db->table('units')->insertGetId($unit);
        View::composer('dashboard.index', function ($view): void {
            $this->dashboard = $view->getData();
        });
    }

    protected function tearDown(): void
    {
        $db = DB::connection('tenant');
        while ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
        DB::purge('tenant');
        app()->forgetInstance('currentTenant');
        parent::tearDown();
    }

    private function booking(string $name, string $status = 'confirmed', bool $departure = false, bool $partner = false): int
    {
        return DB::connection('tenant')->table('reservations')->insertGetId(['unit_id' => $partner ? null : $this->unit, 'booking_type' => $partner ? 'partner' : 'managed', 'guest_name' => $name, 'guest_phone' => 'DEMO-NO-CONTACT', 'check_in' => $departure ? date('Y-m-d', strtotime('-2 days')) : date('Y-m-d'), 'check_out' => $departure ? date('Y-m-d') : date('Y-m-d', strtotime('+2 days')), 'status' => $status, 'nightly_rate' => 100, 'total_amount' => 200, 'owner_net_amount' => $partner ? 100 : null]);
    }

    private function url(): string
    {
        return '/t/dashboard-test/dashboard?month='.date('Y-m').'&unit_id='.$this->unit;
    }

    public function test_partner_arrivals_are_visible_and_completed_departures_leave_the_attention_queue(): void
    {
        $db = DB::connection('tenant');
        $db->table('reservations')->update(['status' => 'cancelled']);
        $managed = $this->booking('Managed arrival');
        $partner = $this->booking('Partner arrival', partner: true);
        $remaining = $this->booking('Pending departure', 'checked_in', true);
        $completed = $this->booking('Completed departure', 'checked_out', true);
        $this->get($this->url())->assertOk()->assertSee('Partner arrival')->assertSee('Completed check-outs today: 1');
        $this->assertSame(2, $this->dashboard['todayCheckins']);
        $this->assertSame([$managed, $partner], array_column($this->dashboard['upcomingCheckins'], 'id'));
        $this->assertSame(1, $this->dashboard['remainingCheckouts']);
        $this->assertSame(1, $this->dashboard['completedCheckouts']);
        $this->assertSame([$remaining], array_column($this->dashboard['departureTasks'], 'id'));
        $this->get('/t/dashboard-test/reservations?activity=checkouts_pending')->assertOk()->assertSee('Pending departure')->assertDontSee('Completed departure');
        $this->get('/t/dashboard-test/reservations?activity=checkouts_completed')->assertOk()->assertSee('Completed departure')->assertDontSee('Pending departure');
        $this->assertNotContains($completed, array_column($this->dashboard['departureTasks'], 'id'));
    }

    public function test_urgent_old_maintenance_is_not_hidden_by_new_low_priority_reports(): void
    {
        $db = DB::connection('tenant');
        $ids = [];
        foreach ([['urgent', '1970-01-01'], ['urgent', '1970-01-02'], ['high', '1970-01-01'], ['low', date('Y-m-d')], ['low', date('Y-m-d')], ['low', date('Y-m-d')], ['low', date('Y-m-d')], ['low', date('Y-m-d')]] as [$priority, $date]) {
            $ids[] = $db->table('maintenance')->insertGetId(['unit_id' => $this->unit, 'title' => 'Priority test '.$priority, 'category' => 'General', 'description' => 'Transactional test', 'priority' => $priority, 'status' => 'open', 'reported_at' => $date.' 00:00:00']);
        }
        $this->get($this->url())->assertOk();
        $this->assertSame($ids[0], $this->dashboard['recentMaintenance'][0]['id']);
        $this->assertSame($ids[1], $this->dashboard['recentMaintenance'][1]['id']);
        $priorities = array_column($this->dashboard['recentMaintenance'], 'priority');
        $ranks = array_map(fn (string $priority): int => ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3][$priority], $priorities);
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks);
    }

    public function test_guest_balances_separate_cancellations_and_credits_and_flag_provisional_income(): void
    {
        $db = DB::connection('tenant');
        foreach ([['Active balance', 'confirmed', '100.01', '20.00'], ['Cancelled balance', 'cancelled', '100.00', '10.00'], ['Guest credit', 'checked_out', '50.00', '70.00']] as [$name, $status, $total, $paid]) {
            $id = $this->booking($name, $status);
            $db->table('reservations')->where('id', $id)->update(['check_in' => date('Y-m-d', strtotime('-1 day'))]);
            $db->table('booking_financials')->insert(['reservation_id' => $id, 'guest_total' => $total]);
            $db->table('guest_payments')->insert(['reservation_id' => $id, 'amount' => $paid, 'payment_date' => date('Y-m-d'), 'payment_method' => 'cash']);
        }
        $response = $this->get($this->url())->assertOk()->assertSee('Guest balances need reconciliation')->assertSee('Provisional')->assertSee('Needs accounting review');
        $financial = $this->dashboard['financialSummary'];
        $this->assertEquals(80.01, $financial['outstanding']);
        $this->assertEquals(90, $financial['cancelled_balance']);
        $this->assertSame(1, $financial['cancelled_count']);
        $this->assertEquals(20, $financial['credits']);
        $this->assertNotEmpty($this->dashboard['operatingReport']['issues']);
        $response->assertSee('No debt has been written off');
        $this->assertEquals('100.00', $db->table('booking_financials as b')->join('reservations as r', 'r.id', '=', 'b.reservation_id')->where('r.unit_id', $this->unit)->where('r.status', 'cancelled')->value('b.guest_total'));
    }

    public function test_unposted_rent_keeps_the_loss_visible_and_marks_the_result_provisional(): void
    {
        DB::table('unit_operating_arrangements')->insert(['tenant_id' => app('currentTenant')->id, 'unit_id' => $this->unit, 'mode' => 'leased', 'monthly_rent_minor' => 100000, 'starts_on' => date('Y-m-01'), 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        $this->get($this->url())->assertOk()->assertSee('Needs accounting review')->assertSee('Provisional')->assertSee('Operating loss')->assertSee('Rent due is included but has not been fully posted');
        $this->assertSame(-100000, $this->dashboard['operatingReport']['totals']['profit']);
        $this->assertSame(100000, $this->dashboard['operatingReport']['totals']['rent']);
    }
}
