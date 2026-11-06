<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\ListingAvailability;
use App\Services\PartnerAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PartnerAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    private Tenant $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', 'Africa/Nairobi'));
        $this->company = $this->company('partner-dates');
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $this->company->database_name, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function company(string $slug, bool $withPartners = true, bool $withBookingType = true): Tenant
    {
        $path = tempnam(sys_get_temp_dir(), 'haven_partner_dates_');
        $this->files[] = $path;
        $db = DB::build(['driver' => 'sqlite', 'database' => $path, 'prefix' => '']);
        if ($withPartners) {
            $db->getSchemaBuilder()->create('partner_apartments', function (Blueprint $table): void {
                $table->id();
                $table->string('apartment_name');
                $table->string('owner_name')->default('Private owner');
                $table->string('owner_phone')->default('+254700000000');
                $table->string('notes')->default('Private owner terms');
                $table->string('status')->default('active');
            });
            $db->table('partner_apartments')->insert([
                ['id' => 1, 'apartment_name' => 'Partner one', 'status' => 'active'],
                ['id' => 2, 'apartment_name' => 'Partner two', 'status' => 'active'],
                ['id' => 3, 'apartment_name' => 'Paused partner', 'status' => 'inactive'],
            ]);
        }
        $db->getSchemaBuilder()->create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('active');
        });
        $db->table('units')->insert([['id' => 1], ['id' => 2]]);
        $db->getSchemaBuilder()->create('reservations', function (Blueprint $table) use ($withBookingType): void {
            $table->id();
            $table->integer('unit_id')->nullable();
            $table->integer('partner_apartment_id')->nullable();
            if ($withBookingType) {
                $table->string('booking_type')->default('partner');
            }
            $table->string('guest_name')->default('Private Guest');
            $table->string('guest_phone')->default('+254711111111');
            $table->date('check_in');
            $table->date('check_out');
            $table->string('status')->default('confirmed');
        });
        $db->disconnect();

        return Tenant::factory()->create(['slug' => $slug, 'database_name' => $path, 'active' => true, 'timezone' => 'Africa/Nairobi']);
    }

    private function db(?Tenant $tenant = null): Connection
    {
        $config = config('database.connections.tenant');
        $config['database'] = ($tenant ?? $this->company)->database_name;

        return DB::build($config);
    }

    private function booking(string $from, string $to, array $values = [], ?Tenant $tenant = null): void
    {
        $db = $this->db($tenant);
        try {
            $db->table('reservations')->insert([...['partner_apartment_id' => 1, 'check_in' => $from, 'check_out' => $to, 'status' => 'confirmed'], ...$values]);
        } finally {
            $db->disconnect();
        }
    }

    public function test_partner_calendar_merges_known_stays_without_claiming_owner_calendar_sync_or_exposing_private_details(): void
    {
        $this->booking('2026-11-01', '2026-11-08');
        $this->booking('2026-11-08', '2026-11-10', ['status' => 'checked_in']);
        $this->booking('2026-11-12', '2026-11-20');
        foreach (['pending', 'cancelled', 'checked_out'] as $status) {
            $this->booking('2026-11-10', '2026-11-12', ['status' => $status]);
        }
        $data = app(PartnerAvailability::class)->calendar($this->company, 1, '2026-11-06', '2026-11-15');

        $this->assertSame(['blocked' => [
            ['start' => '2026-11-06', 'end' => '2026-11-10'],
            ['start' => '2026-11-12', 'end' => '2026-11-15'],
        ], 'fresh' => false, 'last_synced_at' => null, 'requires_confirmation' => true], $data);
        foreach (['Private', 'Guest', 'owner_name', 'partner_apartment_id', 'unit_id', '+254'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($data));
        }
    }

    public function test_partner_and_managed_inventory_with_the_same_id_never_share_calendar_blocks(): void
    {
        $this->booking('2026-11-07', '2026-11-09', ['booking_type' => 'managed', 'unit_id' => 1, 'partner_apartment_id' => 1]);
        $this->booking('2026-11-10', '2026-11-12', ['partner_apartment_id' => 2]);
        $feedId = DB::table('external_calendars')->insertGetId(['tenant_id' => $this->company->id, 'unit_id' => 1, 'name' => 'Managed calendar', 'url_encrypted' => 'PRIVATE', 'last_synced_at' => now()]);
        DB::table('external_calendar_blocks')->insert(['tenant_id' => $this->company->id, 'unit_id' => 1, 'calendar_id' => $feedId, 'start_date' => '2026-11-08', 'end_date' => '2026-11-10']);
        DB::table('public_unit_closures')->insert(['tenant_id' => $this->company->id, 'unit_id' => 1, 'start_date' => '2026-11-10', 'end_date' => '2026-11-12', 'kind' => 'owner', 'created_by' => User::factory()->create()->id]);
        $facade = app(ListingAvailability::class);
        $managed = ['id' => 10, 'unit_id' => 1, 'partner_apartment_id' => null];
        $partner = ['id' => 20, 'unit_id' => null, 'partner_apartment_id' => 1];

        $this->assertSame([], $facade->calendar($this->company, $partner, '2026-11-01', '2026-12-01')['blocked']);
        $this->assertSame([['start' => '2026-11-07', 'end' => '2026-11-12']], $facade->calendar($this->company, $managed, '2026-11-01', '2026-12-01')['blocked']);
        $this->assertSame([10 => ['available' => false, 'fresh' => true], 20 => ['available' => true, 'fresh' => false]],
            $facade->availabilityForListings($this->company, [$managed, $partner], '2026-11-08', '2026-11-10'));
    }

    public function test_partner_checkout_is_exclusive_and_batch_reports_inactive_or_missing_sources_as_unavailable(): void
    {
        $this->booking('2026-11-07', '2026-11-09');
        $service = app(PartnerAvailability::class);
        $this->assertSame(['available' => false, 'fresh' => false], $service->availabilityForPartners($this->company, [1], '2026-11-08', '2026-11-10')[1]);
        $this->assertSame([
            1 => ['available' => true, 'fresh' => false],
            2 => ['available' => true, 'fresh' => false],
            3 => ['available' => false, 'fresh' => false],
            999 => ['available' => false, 'fresh' => false],
        ], $service->availabilityForPartners($this->company, [1, 2, 3, 999, 1], '2026-11-09', '2026-11-10'));
        $this->assertSame([], $service->calendar($this->company, 1, '2026-11-09', '2026-11-10')['blocked']);
    }

    public function test_different_companies_with_the_same_partner_id_remain_isolated_without_mutating_shared_connection(): void
    {
        $other = $this->company('other-partner-dates');
        $this->booking('2026-11-07', '2026-11-09');
        $this->booking('2026-11-12', '2026-11-14', tenant: $other);
        $originalConfig = config('database.connections.tenant');
        $originalConnection = DB::connection('tenant');
        $service = app(PartnerAvailability::class);

        $this->assertSame([['start' => '2026-11-12', 'end' => '2026-11-14']], $service->calendar($other, 1, '2026-11-01', '2026-12-01')['blocked']);
        $this->assertSame(['available' => true, 'fresh' => false], $service->availabilityForPartners($other, [1], '2026-11-08', '2026-11-10')[1]);
        $this->assertSame($originalConfig, config('database.connections.tenant'));
        $this->assertSame($originalConnection, DB::connection('tenant'));
        $this->assertSame('2026-11-07', $originalConnection->table('reservations')->value('check_in'));
    }

    public function test_missing_partner_schema_is_unavailable_and_never_falls_back_to_a_managed_unit(): void
    {
        $legacy = $this->company('without-partners', withPartners: false);
        $service = app(PartnerAvailability::class);
        $this->assertSame([1 => ['available' => false, 'fresh' => false]], $service->availabilityForPartners($legacy, [1], '2026-11-08', '2026-11-10'));
        try {
            $service->calendar($legacy, 1, '2026-11-01', '2026-12-01');
            $this->fail('A missing partner schema must return not found.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_old_reservation_schema_without_booking_type_still_uses_only_the_partner_foreign_key(): void
    {
        $legacy = $this->company('old-partner-schema', withBookingType: false);
        $this->booking('2026-11-07', '2026-11-09', tenant: $legacy);
        $this->booking('2026-11-12', '2026-11-14', ['partner_apartment_id' => null, 'unit_id' => 1], $legacy);
        $service = app(PartnerAvailability::class);
        $this->assertSame([['start' => '2026-11-07', 'end' => '2026-11-09']], $service->calendar($legacy, 1, '2026-11-01', '2026-12-01')['blocked']);
    }

    public function test_calendar_rejects_invalid_dates_unbounded_windows_and_inactive_partners(): void
    {
        $service = app(PartnerAvailability::class);
        foreach ([
            ['2026-02-30', '2026-03-02'], ['2026-11-09', '2026-11-09'],
            ['2026-10-31', '2026-11-02'], ['2026-11-01', '2027-11-03'],
            ['2028-11-01', '2028-11-07'],
        ] as [$from, $to]) {
            try {
                $service->calendar($this->company, 1, $from, $to);
                $this->fail('Invalid calendar dates must fail validation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('dates', $exception->errors());
            }
        }
        try {
            $service->calendar($this->company, 3, '2026-11-01', '2026-12-01');
            $this->fail('Inactive apartments must not expose a public calendar.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }
}
