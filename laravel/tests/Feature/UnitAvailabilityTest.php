<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\UnitAvailability;
use App\Services\UnitCalendarExport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UnitAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    private Tenant $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', 'Africa/Nairobi'));
        $this->company = $this->company('public-dates');
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $this->company->database_name, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $this->admin = User::factory()->create(['active' => true]);
        $this->admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        $this->actingAs($this->admin)->withoutMiddleware(ResolveTenant::class);
        app()->instance('currentTenant', $this->company);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        DB::purge('calendar_export');
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function company(string $slug): Tenant
    {
        $path = tempnam(sys_get_temp_dir(), 'haven_public_dates_');
        $this->files[] = $path;
        $db = DB::build(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
        $schema = $db->getSchemaBuilder();
        $schema->create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('units', function (Blueprint $table): void {
            $table->id();
            $table->integer('property_id');
            $table->string('unit_number');
            $table->string('status')->default('active');
            $table->decimal('nightly_rate', 12, 2)->default(10000);
        });
        $schema->create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id')->nullable();
            $table->integer('partner_apartment_id')->nullable();
            $table->string('booking_type')->default('managed');
            $table->string('guest_name')->default('Secret Guest');
            $table->string('guest_phone')->default('0700000000');
            $table->string('guest_email')->nullable();
            $table->date('check_in');
            $table->date('check_out');
            $table->integer('adults')->default(1);
            $table->integer('children')->default(0);
            $table->string('booking_source')->default('Direct');
            $table->string('status')->default('confirmed');
            $table->decimal('nightly_rate', 12, 2)->default(10000);
            $table->decimal('total_amount', 12, 2)->default(20000);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('owner_net_amount', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        $db->table('properties')->insert(['id' => 1, 'name' => 'Coral Gables']);
        $db->table('units')->insert([['id' => 1, 'property_id' => 1, 'unit_number' => 'F5'], ['id' => 2, 'property_id' => 1, 'unit_number' => 'F6']]);
        $db->disconnect();

        return Tenant::factory()->create(['slug' => $slug, 'database_name' => $path, 'active' => true, 'timezone' => 'Africa/Nairobi', 'plan' => 'legacy', 'subscription_status' => 'legacy']);
    }

    private function booking(string $start, string $end, string $status = 'confirmed', int $unitId = 1): int
    {
        return DB::connection('tenant')->table('reservations')->insertGetId(['unit_id' => $unitId, 'check_in' => $start, 'check_out' => $end, 'status' => $status,
            'guest_name' => 'Secret Guest', 'guest_email' => 'private@example.test', 'notes' => 'Private finance note']);
    }

    private function closure(string $start = '2026-11-12', string $end = '2026-11-14', ?Tenant $company = null, int $unitId = 1): int
    {
        return DB::table('public_unit_closures')->insertGetId(['tenant_id' => ($company ?? $this->company)->id, 'unit_id' => $unitId, 'start_date' => $start, 'end_date' => $end,
            'kind' => 'owner', 'reason' => 'Private owner travel details', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function feed(?string $synced = null, ?string $error = null, ?Tenant $company = null, int $unitId = 1): int
    {
        return DB::table('external_calendars')->insertGetId(['tenant_id' => ($company ?? $this->company)->id, 'unit_id' => $unitId, 'name' => 'Private Airbnb calendar',
            'url_encrypted' => 'SECRET-FEED', 'last_synced_at' => $synced, 'last_error' => $error]);
    }

    public function test_public_calendar_merges_only_busy_dates_without_exposing_private_data(): void
    {
        $this->booking('2026-11-07', '2026-11-09');
        $this->booking('2026-11-09', '2026-11-10', 'checked_in');
        foreach (['pending', 'cancelled', 'checked_out'] as $status) {
            $this->booking('2026-11-20', '2026-11-22', $status);
        }
        $feed = $this->feed(now()->toDateTimeString());
        DB::table('external_calendar_blocks')->insert(['tenant_id' => $this->company->id, 'unit_id' => 1, 'calendar_id' => $feed, 'start_date' => '2026-11-10', 'end_date' => '2026-11-12']);
        $this->closure();
        $data = app(UnitAvailability::class)->calendar($this->company, 1, '2026-11-01', '2026-12-01');
        $this->assertSame([['start' => '2026-11-07', 'end' => '2026-11-14']], $data['blocked']);
        $this->assertTrue($data['fresh']);
        $this->assertTrue($data['requires_confirmation']);
        foreach (['Secret Guest', 'private@example.test', 'Private', 'SECRET-FEED', 'owner', 'calendar_id', 'reservation_id'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($data));
        }
    }

    public function test_checkout_is_exclusive_and_a_booking_can_be_excluded_during_amendment(): void
    {
        $id = $this->booking('2026-11-07', '2026-11-09');
        $service = app(UnitAvailability::class);
        $this->assertTrue($service->overlaps($this->company, 1, '2026-11-08', '2026-11-10'));
        $this->assertFalse($service->overlaps($this->company, 1, '2026-11-09', '2026-11-10'));
        $this->assertFalse($service->overlaps($this->company, 1, '2026-11-08', '2026-11-10', $id));
        $this->closure();
        $this->assertFalse($service->overlaps($this->company, 1, '2026-11-14', '2026-11-15'));
        $this->assertTrue($service->overlaps($this->company, 1, '2026-11-13', '2026-11-15'));
    }

    public function test_failed_stale_or_never_imported_feeds_retain_busy_dates_and_require_confirmation(): void
    {
        $feed = $this->feed(now()->subMinutes(11)->toDateTimeString());
        DB::table('external_calendar_blocks')->insert(['tenant_id' => $this->company->id, 'unit_id' => 1, 'calendar_id' => $feed, 'start_date' => '2026-11-10', 'end_date' => '2026-11-12']);
        $service = app(UnitAvailability::class);
        $this->assertFalse($service->calendar($this->company, 1, '2026-11-01', '2026-12-01')['fresh']);
        DB::table('external_calendars')->where('id', $feed)->update(['last_synced_at' => now(), 'last_error' => 'Private provider error']);
        $data = $service->calendar($this->company, 1, '2026-11-01', '2026-12-01');
        $this->assertFalse($data['fresh']);
        $this->assertSame([['start' => '2026-11-10', 'end' => '2026-11-12']], $data['blocked']);
        DB::table('external_calendars')->where('id', $feed)->update(['last_synced_at' => null, 'last_error' => null]);
        $this->assertNull($service->calendar($this->company, 1, '2026-11-01', '2026-12-01')['last_synced_at']);
    }

    public function test_batch_availability_handles_bookings_inactive_units_and_stale_feeds(): void
    {
        $this->booking('2026-11-07', '2026-11-09');
        $this->feed(now()->subMinutes(11)->toDateTimeString(), unitId: 2);
        $service = app(UnitAvailability::class);
        $original = config('database.connections.tenant');
        $data = $service->availabilityForUnits($this->company, [1, 2, 999], '2026-11-08', '2026-11-10');
        $this->assertSame(['available' => false, 'fresh' => true], $data[1]);
        $this->assertSame(['available' => true, 'fresh' => false], $data[2]);
        $this->assertFalse($data[999]['available']);
        $this->assertSame($original, config('database.connections.tenant'));
        DB::connection('tenant')->table('units')->where('id', 2)->update(['status' => 'maintenance']);
        $this->assertFalse($service->availabilityForUnits($this->company, [2], '2026-11-08', '2026-11-10')[2]['available']);
    }

    public function test_companies_with_matching_unit_ids_do_not_share_bookings_or_blocks(): void
    {
        $other = $this->company('other-public-dates');
        $this->booking('2026-11-07', '2026-11-09');
        $this->closure();
        $this->closure('2026-11-20', '2026-11-22', $other);
        $data = app(UnitAvailability::class)->calendar($other, 1, '2026-11-01', '2026-12-01');
        $this->assertSame([['start' => '2026-11-20', 'end' => '2026-11-22']], $data['blocked']);
        $this->assertFalse(app(UnitAvailability::class)->overlaps($other, 1, '2026-11-07', '2026-11-09'));
    }

    public function test_public_date_ranges_are_validated_and_cannot_request_unbounded_history(): void
    {
        foreach ([['2026-02-30', '2026-03-02'], ['2026-11-10', '2026-11-09'], ['2026-10-01', '2026-12-01'], ['2026-11-01', '2028-11-08']] as [$from, $to]) {
            try {
                app(UnitAvailability::class)->calendar($this->company, 1, $from, $to);
                $this->fail('Invalid public calendar dates must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('dates', $exception->errors());
            }
        }
    }

    public function test_owner_and_repair_blocks_export_without_private_details_or_imported_events(): void
    {
        $this->closure();
        $feed = $this->feed(now()->toDateTimeString());
        DB::table('external_calendar_blocks')->insert(['tenant_id' => $this->company->id, 'unit_id' => 1, 'calendar_id' => $feed, 'start_date' => '2026-11-20', 'end_date' => '2026-11-22']);
        $body = app(UnitCalendarExport::class)->render($this->company, 1);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261112', $body);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261114', $body);
        $this->assertStringContainsString('SUMMARY:Unavailable', $body);
        $this->assertStringNotContainsString('owner', $body);
        $this->assertStringNotContainsString('Private', $body);
        $this->assertStringNotContainsString('20261120', $body);
    }

    public function test_closure_management_rejects_confirmed_stays_and_cross_company_units(): void
    {
        $this->booking('2026-11-07', '2026-11-09');
        $payload = ['unit_id' => 1, 'start_date' => '2026-11-08', 'end_date' => '2026-11-10', 'kind' => 'maintenance', 'reason' => 'Replace lights'];
        $this->post('/t/public-dates/availability/closures', $payload)->assertSessionHasErrors('start_date');
        $this->assertDatabaseCount('public_unit_closures', 0);
        $this->post('/t/public-dates/availability/closures', array_replace($payload, ['unit_id' => 999]))->assertNotFound();
        $this->post('/t/public-dates/availability/closures', array_replace($payload, ['start_date' => '2026-11-09']))->assertSessionHasNoErrors()->assertSessionHas('success');
        $closure = DB::table('public_unit_closures')->first();
        $this->get('/t/public-dates/availability/closures')->assertOk()->assertSee('Replace lights');
        $this->post('/t/public-dates/availability/closures/'.$closure->id.'/delete')->assertSessionHas('success');
        $this->assertDatabaseCount('public_unit_closures', 0);
    }

    public function test_cleaners_cannot_manage_blocks_or_delete_another_company_block(): void
    {
        $id = $this->closure();
        $other = $this->company('other-blocks');
        $otherId = $this->closure(company: $other);
        $this->post('/t/public-dates/availability/closures/'.$otherId.'/delete')->assertNotFound();
        $this->admin->tenants()->updateExistingPivot($this->company->id, ['roles' => json_encode(['cleaner'])]);
        $this->get('/t/public-dates/availability/closures')->assertForbidden();
        $this->post('/t/public-dates/availability/closures/'.$id.'/delete')->assertForbidden();
        $this->assertDatabaseCount('public_unit_closures', 2);
    }

    public function test_confirmed_booking_is_blocked_but_can_start_at_a_closure_end(): void
    {
        $this->closure();
        $payload = ['booking_type' => 'managed', 'unit_id' => 1, 'guest_name' => 'Guest', 'guest_phone' => '0700000000', 'check_in' => '2026-11-13', 'check_out' => '2026-11-15',
            'adults' => 1, 'children' => 0, 'nightly_rate' => 10000, 'status' => 'confirmed'];
        $this->post('/t/public-dates/reservations/store', $payload)->assertSessionHas('error');
        $this->assertSame(0, DB::connection('tenant')->table('reservations')->count());
        $this->post('/t/public-dates/reservations/store', array_replace($payload, ['check_in' => '2026-11-14']))->assertSessionHas('success');
        $this->assertSame(1, DB::connection('tenant')->table('reservations')->count());
    }
}
