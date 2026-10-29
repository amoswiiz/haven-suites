<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApartmentReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkCentreTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Tenant::create(['name' => 'Work test', 'slug' => 'work-test', 'database_name' => 'haven_suites_laravel13', 'active' => true]);
        $this->admin = $this->member('admin');
        $this->actingAs($this->admin)->withoutMiddleware(ResolveTenant::class);
        app()->instance('currentTenant', $this->company);
        config(['database.connections.tenant.database' => $this->company->database_name]);
        DB::purge('tenant');
        DB::connection('tenant')->beginTransaction();
    }

    protected function tearDown(): void
    {
        $db = DB::connection('tenant');
        while ($db->transactionLevel() > 0) {
            $db->rollBack();
        }DB::purge('tenant');
        parent::tearDown();
    }

    private function member(string $role): User
    {
        $u = User::factory()->create();
        $u->tenants()->attach($this->company, ['roles' => json_encode([$role]), 'legacy_user_id' => 1]);

        return $u;
    }

    private function unit(): int
    {
        $db = DB::connection('tenant');
        $u = (array) $db->table('units')->first();
        unset($u['id']);

        return $db->table('units')->insertGetId([...$u, 'unit_number' => 'WORK-'.bin2hex(random_bytes(4)), 'status' => 'active', 'bedrooms' => 1]);
    }

    private function booking(int $unit): int
    {
        return DB::connection('tenant')->table('reservations')->insertGetId(['booking_type' => 'managed', 'unit_id' => $unit, 'guest_name' => 'Work test guest', 'guest_phone' => '0700000000', 'check_in' => date('Y-m-d'), 'check_out' => date('Y-m-d', strtotime('+2 days')), 'adults' => 1, 'children' => 0, 'nightly_rate' => 100, 'total_amount' => 200, 'amount_paid' => 0, 'status' => 'confirmed', 'booking_source' => 'Direct']);
    }

    public function test_finance_handoff_enforces_assignment_duplicate_and_resolution_guards(): void
    {
        $booking = $this->booking($this->unit());
        $operator = $this->member('operations');
        $this->actingAs($operator);
        $data = ['kind' => 'finance', 'resource_id' => $booking, 'assigned_to' => $this->admin->id, 'due_at' => now()->addDay()->format('Y-m-d H:i:s'), 'message' => 'Please review invoice and deposit.'];
        $this->get('/t/work-test/reservations/'.$booking)->assertOk()->assertSee('Request finance action')->assertDontSee('Recorded Guest Payments');
        $this->post('/t/work-test/work', $data)->assertSessionHas('success');
        $row = DB::table('workflow_requests')->first();
        $this->get('/t/work-test/work')->assertOk()->assertSee($data['message']);
        $this->post('/t/work-test/work', $data)->assertSessionHasErrors('message');
        $resolution = ['status' => 'completed', 'resolution' => 'Reviewed and awaiting money received.', 'version' => 1];
        $this->post('/t/work-test/work/'.$row->id.'/resolve', $resolution)->assertForbidden();
        $this->actingAs($this->admin);
        $this->post('/t/work-test/work/'.$row->id.'/resolve', $resolution)->assertSessionHas('success');
        $this->post('/t/work-test/work/'.$row->id.'/resolve', $resolution)->assertSessionHasErrors('version');
        $this->assertSame(0, DB::connection('tenant')->table('guest_payments')->where('reservation_id', $booking)->count());
        $this->actingAs($operator);
        $this->post('/t/work-test/work', [...$data, 'assigned_to' => $operator->id])->assertSessionHasErrors('assigned_to');
        $this->post('/t/work-test/work', [...$data, 'resource_id' => 999999999])->assertNotFound();
    }

    public function test_sales_handoff_preserves_role_and_company_boundaries(): void
    {
        $sales = $this->member('sales');
        $lead = DB::table('sales_leads')->insertGetId(['tenant_id' => $this->company->id, 'name' => 'Sales request guest', 'source' => 'direct', 'channel' => 'phone', 'assigned_to' => $sales->id, 'next_action' => 'Prepare booking', 'stage' => 'new', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($sales);
        $this->get('/t/work-test/sales/'.$lead)->assertOk()->assertSee('Send to reservations');
        $data = ['kind' => 'reservations', 'resource_id' => $lead, 'assigned_to' => $this->admin->id, 'due_at' => now()->addDay()->format('Y-m-d H:i:s'), 'message' => 'Please prepare booking'];
        $this->post('/t/work-test/work', $data)->assertSessionHas('success');
        $this->get('/t/work-test/reservations/create')->assertForbidden();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-work', 'database_name' => 'other_work_test', 'active' => true]);
        DB::table('sales_leads')->where('id', $lead)->update(['tenant_id' => $other->id]);
        $this->post('/t/work-test/work', $data)->assertNotFound();
        $this->actingAs($this->member('owner'));
        $this->get('/t/work-test/work')->assertForbidden();
        $this->get('/t/work-test/today')->assertForbidden();
        $this->get('/t/work-test/company/setup')->assertForbidden();
    }

    public function test_readiness_keeps_occupancy_cleaning_and_maintenance_separate(): void
    {
        $db = DB::connection('tenant');
        $unit = $this->unit();
        $this->booking($unit);
        $db->table('housekeeping')->insert(['unit_id' => $unit, 'cleaning_type' => 'routine', 'scheduled_at' => date('Y-m-d').' 08:00:00', 'status' => 'verified', 'completed_at' => date('Y-m-d').' 09:00:00', 'verified_at' => date('Y-m-d').' 10:00:00']);
        $s = app(ApartmentReadiness::class);
        $state = $s->overview($unit)->first();
        $this->assertSame('Booked today', $state->availability);
        $this->assertSame('Verified clean', $state->cleaning);
        $this->assertSame('No open issues', $state->maintenance);
        $this->get('/t/work-test/today')->assertOk()->assertSee("Today's work");
        $db->table('maintenance')->insert(['unit_id' => $unit, 'title' => 'Work test broken lock', 'category' => 'Other', 'description' => 'Test', 'priority' => 'urgent', 'status' => 'open', 'reported_at' => now()]);
        $this->assertSame('Needs attention', $s->overview($unit)->first()->readiness);
        $db->table('housekeeping')->insert(['unit_id' => $unit, 'cleaning_type' => 'checkout', 'scheduled_at' => date('Y-m-d').' 11:00:00', 'status' => 'pending']);
        $this->assertSame('Cleaning pending', $s->overview($unit)->first()->cleaning);
    }

    public function test_setup_and_approvals_render_for_administrators(): void
    {
        $this->get('/t/work-test/work')->assertOk()->assertSee('Finance reviews');
        $this->get('/t/work-test/company/setup')->assertOk()->assertSee('Company setup checklist')->assertSee('Invoice details and defaults');
        $this->actingAs($this->member('manager'));
        $this->get('/t/work-test/company/setup')->assertForbidden();
    }

    public function test_cleaner_can_progress_only_their_assigned_task_without_duplicate_entries(): void
    {
        $db = DB::connection('tenant');
        $unit = $this->unit();
        $db->table('cleaning_rates')->updateOrInsert(['bedrooms' => 1], ['amount' => 500]);
        $legacyUser = (array) $db->table('users')->first();
        unset($legacyUser['id']);
        $legacyId = $db->table('users')->insertGetId([...$legacyUser, 'username' => 'cleaner-work-'.bin2hex(random_bytes(5))]);
        $staff = $db->table('staff')->insertGetId(['full_name' => 'Task cleaner', 'user_id' => $legacyId, 'phone' => '', 'location' => 'Nairobi', 'role' => 'cleaner', 'status' => 'active']);
        $other = $db->table('staff')->insertGetId(['full_name' => 'Other cleaner', 'phone' => '', 'location' => 'Nairobi', 'role' => 'cleaner', 'status' => 'active']);
        $task = ['unit_id' => $unit, 'assigned_staff_id' => $staff, 'cleaning_type' => 'routine', 'scheduled_at' => now(), 'status' => 'assigned'];
        $own = $db->table('housekeeping')->insertGetId($task);
        $foreign = $db->table('housekeeping')->insertGetId([...$task, 'assigned_staff_id' => $other]);
        $cleaner = $this->member('cleaner');
        $cleaner->tenants()->updateExistingPivot($this->company->id, ['legacy_user_id' => $legacyId]);
        $this->actingAs($cleaner);
        $this->get('/t/work-test/housekeeping/my-cleanings/new')->assertOk()->assertSee('Your assigned cleaning tasks')->assertSee('housekeeping/progress/'.$own, false)->assertDontSee('housekeeping/progress/'.$foreign, false);
        $data = ['status' => 'in_progress', 'bedrooms_cleaned' => 1, 'notes' => 'Starting assigned job'];
        $this->post('/t/work-test/housekeeping/progress/'.$foreign, $data)->assertForbidden();
        $this->post('/t/work-test/housekeeping/progress/'.$own, $data)->assertSessionHas('success');
        $this->assertSame('in_progress', $db->table('housekeeping')->where('id', $own)->value('status'));
        $lock = $db->table('accounting_period_locks')->insertGetId(['unit_id' => $unit, 'period_month' => date('Y-m-01'), 'is_locked' => 1]);
        $this->post('/t/work-test/housekeeping/progress/'.$own, [...$data, 'status' => 'completed'])->assertSessionHas('error');
        $this->assertSame('in_progress', $db->table('housekeeping')->where('id', $own)->value('status'));
        $db->table('accounting_period_locks')->where('id', $lock)->delete();
        $this->post('/t/work-test/housekeeping/progress/'.$own, [...$data, 'status' => 'completed'])->assertSessionHas('success');
        $this->assertSame('completed', $db->table('housekeeping')->where('id', $own)->value('status'));
        $this->assertSame(2, $db->table('housekeeping')->where('unit_id', $unit)->count());
    }
}
