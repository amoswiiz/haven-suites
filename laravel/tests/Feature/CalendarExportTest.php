<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CalendarExportTest extends TestCase
{
    use RefreshDatabase;

    private array $databaseFiles = [];

    private function company(string $slug = 'export-test', string $role = 'admin'): Tenant
    {
        $path = tempnam(sys_get_temp_dir(), 'haven_calendar_');
        $this->databaseFiles[] = $path;
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        Schema::connection('tenant')->create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::connection('tenant')->create('units', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('unit_number');
        });
        Schema::connection('tenant')->create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('unit_id');
            $table->string('status');
            $table->date('check_in');
            $table->date('check_out');
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->decimal('total_amount')->nullable();
            $table->timestamps();
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Test property']);
        DB::connection('tenant')->table('units')->insert(['id' => 1, 'property_id' => 1, 'unit_number' => 'A1']);
        $tenant = Tenant::factory()->create(['slug' => $slug, 'database_name' => $path, 'active' => true, 'plan' => 'legacy', 'subscription_status' => 'legacy']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['roles' => json_encode([$role]), 'legacy_user_id' => 1]);
        $this->actingAs($user);

        return $tenant;
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        DB::purge('calendar_export');
        foreach ($this->databaseFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function exportPath(Tenant $tenant): string
    {
        $encrypted = DB::table('unit_calendar_exports')->where('tenant_id', $tenant->id)->value('token_encrypted');

        return '/calendars/'.Crypt::decryptString($encrypted).'.ics';
    }

    public function test_export_can_be_generated_copied_rotated_and_revoked(): void
    {
        $tenant = $this->company();
        $page = '/t/export-test/availability/external';
        $this->get($page)->assertOk()->assertSee('Export local apartment calendars')->assertSee('No export link generated.')
            ->assertSee('data-keywords="External calendars Bookings &amp; Guests"', false);
        $this->post($page.'/exports/1')->assertSessionHas('success');
        $firstPath = $this->exportPath($tenant);
        $this->get($page)->assertOk()->assertSee($firstPath)->assertSee('Copy link');
        $this->assertStringNotContainsString(basename($firstPath, '.ics'), DB::table('unit_calendar_exports')->value('token_encrypted'));
        $this->get($firstPath)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->assertSee('BEGIN:VCALENDAR');
        $this->post($page.'/exports/1')->assertSessionHas('success');
        $secondPath = $this->exportPath($tenant);
        $this->assertNotSame($firstPath, $secondPath);
        $this->get($firstPath)->assertNotFound();
        $this->get($secondPath)->assertOk();
        $this->post($page.'/exports/1/revoke')->assertSessionHas('success');
        $this->get($secondPath)->assertNotFound();
        $this->assertDatabaseCount('unit_calendar_exports', 0);
    }

    public function test_public_feed_contains_only_local_booked_dates_and_reflects_updates(): void
    {
        $tenant = $this->company();
        $db = DB::connection('tenant');
        $base = ['unit_id' => 1, 'check_in' => '2035-01-10', 'check_out' => '2035-01-12', 'guest_name' => 'Secret Guest', 'guest_email' => 'private@example.test', 'total_amount' => 12345, 'created_at' => now(), 'updated_at' => now()];
        $id = $db->table('reservations')->insertGetId($base + ['status' => 'confirmed']);
        foreach (['pending', 'cancelled', 'checked_out'] as $status) {
            $db->table('reservations')->insert(array_replace($base, ['status' => $status, 'check_in' => '2035-02-10', 'check_out' => '2035-02-12']));
        }
        $db->table('reservations')->insert(array_replace($base, ['unit_id' => 2, 'status' => 'confirmed', 'check_in' => '2035-03-10']));
        $db->table('reservations')->insert(array_replace($base, ['status' => 'checked_in', 'check_in' => '2035-04-10', 'check_out' => '2035-04-12']));
        $feedId = DB::table('external_calendars')->insertGetId(['tenant_id' => $tenant->id, 'unit_id' => 1, 'name' => 'Airbnb', 'url_encrypted' => 'not-used']);
        DB::table('external_calendar_blocks')->insert(['calendar_id' => $feedId, 'tenant_id' => $tenant->id, 'unit_id' => 1, 'start_date' => '2035-05-10', 'end_date' => '2035-05-12']);
        $this->post('/t/export-test/availability/external/exports/1')->assertSessionHas('success');
        $path = $this->exportPath($tenant);
        $this->post('/logout');
        $this->assertGuest();
        $response = $this->get($path)->assertOk()->assertSee('DTSTART;VALUE=DATE:20350110')->assertSee('DTEND;VALUE=DATE:20350112')
            ->assertSee('DTSTART;VALUE=DATE:20350410')->assertDontSee('20350210')->assertDontSee('20350310')->assertDontSee('20350510')
            ->assertDontSee('Secret Guest')->assertDontSee('private@example.test')->assertDontSee('12345')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertSame(2, substr_count($response->getContent(), 'BEGIN:VEVENT'));
        $this->assertStringContainsString("\r\nEND:VCALENDAR\r\n", $response->getContent());
        preg_match('/UID:(.*)\r\n/', $response->getContent(), $uid);
        DB::connection('tenant')->table('reservations')->where('id', $id)->update(['check_out' => '2035-01-15']);
        $this->get($path)->assertOk()->assertSee('DTEND;VALUE=DATE:20350115')->assertSee($uid[1]);
        DB::connection('tenant')->table('reservations')->where('id', $id)->update(['status' => 'cancelled']);
        $this->get($path)->assertOk()->assertDontSee('20350110');
        $this->assertNull(config('database.connections.calendar_export'));
        $tenant->update(['active' => false]);
        $this->get($path)->assertNotFound();
    }

    public function test_export_management_requires_permission_and_valid_unit(): void
    {
        $tenant = $this->company();
        $this->post('/t/export-test/availability/external/exports/999')->assertNotFound();
        $this->post('/t/export-test/availability/external/exports/1')->assertSessionHas('success');
        auth()->user()->tenants()->updateExistingPivot($tenant->id, ['roles' => json_encode(['manager'])]);
        $this->get('/t/export-test/availability/external')->assertOk()->assertDontSee('Export local apartment calendars')->assertDontSee($this->exportPath($tenant));
        $this->post('/t/export-test/availability/external/exports/1')->assertForbidden();
        $this->post('/t/export-test/availability/external/exports/1/revoke')->assertForbidden();
        auth()->user()->tenants()->updateExistingPivot($tenant->id, ['roles' => json_encode(['cleaner'])]);
        $this->get('/t/export-test/availability/external')->assertForbidden();
        $this->get('/calendars/'.str_repeat('0', 64).'.ics')->assertNotFound();
        $this->get('/calendars/guess.ics')->assertNotFound();
    }

    public function test_exports_isolate_companies_with_matching_unit_ids(): void
    {
        $first = $this->company();
        DB::connection('tenant')->table('reservations')->insert(['unit_id' => 1, 'status' => 'confirmed', 'check_in' => '2035-01-10', 'check_out' => '2035-01-12']);
        $this->post('/t/export-test/availability/external/exports/1')->assertSessionHas('success');
        $firstPath = $this->exportPath($first);
        $second = $this->company('export-second');
        DB::connection('tenant')->table('reservations')->insert(['unit_id' => 1, 'status' => 'confirmed', 'check_in' => '2035-02-10', 'check_out' => '2035-02-12']);
        $this->post('/t/export-second/availability/external/exports/1')->assertSessionHas('success');
        $secondPath = $this->exportPath($second);
        $this->get('/t/export-second/availability/external')->assertOk()->assertSee($secondPath)->assertDontSee($firstPath);
        $this->get($firstPath)->assertOk()->assertSee('20350110')->assertDontSee('20350210');
        $this->get($secondPath)->assertOk()->assertSee('20350210')->assertDontSee('20350110');
        $this->assertSame($second->database_name, config('database.connections.tenant.database'));
        $this->post('/t/export-second/availability/external/exports/1/revoke')->assertSessionHas('success');
        $this->get($firstPath)->assertOk();
        $this->get($secondPath)->assertNotFound();
        $this->deleteUnusedUnit($first);
    }

    private function deleteUnusedUnit(Tenant $tenant): void
    {
        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');
        DB::connection('tenant')->table('units')->where('id', 1)->delete();

        $this->get($this->exportPath($tenant))->assertNotFound();
    }
}
