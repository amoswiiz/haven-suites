<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ExternalCalendars;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ExternalCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'admin', string $slug = 'calendar-test'): Tenant
    {
        $tenant = Tenant::create(['name' => 'Calendar test', 'slug' => $slug, 'database_name' => $slug === 'calendar-test' ? 'haven_suites_laravel13' : 'unused_'.$slug, 'active' => true]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['roles' => json_encode([$role]), 'legacy_user_id' => 1]);
        $this->actingAs($user);

        return $tenant;
    }

    private function calendar(string $events = "BEGIN:VEVENT\nUID:one\nDTSTART;VALUE=DATE:20350110\nDTEND;VALUE=DATE:20350112\nEND:VEVENT\n"): string
    {
        return "BEGIN:VCALENDAR\nVERSION:2.0\n".$events."END:VCALENDAR\n";
    }

    public function test_connection_import_display_failure_retention_and_disconnect(): void
    {
        $tenant = $this->member();
        $this->get('/t/calendar-test/availability/external')->assertOk()->assertSee('Connect a calendar');
        config(['database.connections.tenant.database' => 'haven_suites_laravel13']);
        $unitId = DB::connection('tenant')->table('units')->value('id');
        $service = Mockery::mock(ExternalCalendars::class)->makePartial();
        $url = 'https://www.airbnb.com/calendar/ical/test.ics?secret=private';
        $service->shouldReceive('download')->once()->with($url)->andReturn($this->calendar());
        $this->app->instance(ExternalCalendars::class, $service);
        $this->post('/t/calendar-test/availability/external', ['unit_id' => $unitId, 'name' => 'Airbnb test', 'url' => $url])->assertRedirect('/t/calendar-test/availability/external')->assertSessionHas('success');
        $feed = DB::table('external_calendars')->first();
        $this->assertNotSame($url, $feed->url_encrypted);
        $this->assertSame($url, Crypt::decryptString($feed->url_encrypted));
        $this->assertTrue($service->overlaps($tenant->id, $unitId, '2035-01-11', '2035-01-13'));
        $this->assertFalse($service->overlaps($tenant->id, $unitId, '2035-01-12', '2035-01-13'));
        $this->assertFalse($service->overlaps($tenant->id + 1, $unitId, '2035-01-11', '2035-01-13'));
        $this->get('/t/calendar-test/availability?month=2035-01')->assertOk()->assertSee('External block: Airbnb test');
        $this->get('/t/calendar-test/availability/external')->assertOk()->assertDontSee('secret=private');
        $service->shouldReceive('download')->once()->andReturn('<html>Login required</html>');
        $this->post('/t/calendar-test/availability/external/'.$feed->id.'/sync')->assertSessionHas('error');
        $this->assertDatabaseCount('external_calendar_blocks', 1);
        $this->assertNotNull(DB::table('external_calendars')->value('last_error'));
        $this->post('/t/calendar-test/availability/external/'.$feed->id.'/delete')->assertSessionHas('success');
        $this->assertDatabaseCount('external_calendars', 0);
        $this->assertDatabaseCount('external_calendar_blocks', 0);
    }

    public function test_permissions_url_validation_and_company_isolation(): void
    {
        $tenant = $this->member();
        config(['database.connections.tenant.database' => 'haven_suites_laravel13']);
        $unitId = DB::connection('tenant')->table('units')->value('id');
        $this->post('/t/calendar-test/availability/external', ['unit_id' => $unitId, 'name' => 'Unsafe', 'url' => 'https://127.0.0.1/private.ics'])->assertSessionHasErrors('url');
        $this->post('/t/calendar-test/availability/external', ['unit_id' => 99999999, 'name' => 'Wrong apartment', 'url' => 'https://www.airbnb.com/a.ics'])->assertSessionHasErrors('unit_id');
        $id = DB::table('external_calendars')->insertGetId(['tenant_id' => $tenant->id, 'unit_id' => $unitId, 'name' => 'Private calendar', 'url_encrypted' => Crypt::encryptString('https://www.airbnb.com/a.ics')]);
        $this->member('admin', 'other-calendar');
        $this->post('/t/other-calendar/availability/external/'.$id.'/sync')->assertNotFound();
        $this->post('/t/other-calendar/availability/external/'.$id.'/delete')->assertNotFound();
        $this->member('manager', 'readonly-calendar');
        $this->post('/t/readonly-calendar/availability/external', [])->assertForbidden();
        $this->post('/t/readonly-calendar/availability/external/'.$id.'/sync')->assertForbidden();
    }

    public function test_parser_uses_exclusive_checkout_and_reconciles_cancellations(): void
    {
        $service = new ExternalCalendars;
        $this->assertSame([['start_date' => '2035-01-10', 'end_date' => '2035-01-12']], $service->parse($this->calendar()));
        $this->assertSame([], $service->parse($this->calendar("BEGIN:VEVENT\nSTATUS:CANCELLED\nEND:VEVENT\n")));
        $this->assertSame([], $service->parse($this->calendar('')));
    }

    public function test_external_blocks_prevent_booking_creation_and_date_changes(): void
    {
        $tenant = $this->member();
        $this->withoutMiddleware(ResolveTenant::class);
        app()->instance('currentTenant', $tenant);
        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');
        $db = DB::connection('tenant');
        $db->beginTransaction();
        try {
            $unit = (array) $db->table('units')->first();
            unset($unit['id']);
            $unit['unit_number'] = 'CAL-'.bin2hex(random_bytes(4));
            $unit['status'] = 'active';
            $unitId = $db->table('units')->insertGetId($unit);
            $id = DB::table('external_calendars')->insertGetId(['tenant_id' => $tenant->id, 'unit_id' => $unitId, 'name' => 'Airbnb', 'url_encrypted' => Crypt::encryptString('https://www.airbnb.com/a.ics')]);
            DB::table('external_calendar_blocks')->insert(['tenant_id' => $tenant->id, 'unit_id' => $unitId, 'calendar_id' => $id, 'start_date' => '2035-01-10', 'end_date' => '2035-01-12']);
            $payload = ['booking_type' => 'managed', 'unit_id' => $unitId, 'guest_name' => 'Calendar test guest', 'guest_phone' => '0700000000', 'guest_email' => 'calendar@example.test',
                'check_in' => '2035-01-10', 'check_out' => '2035-01-12', 'adults' => 1, 'children' => 0, 'nightly_rate' => '100', 'amount_paid' => '0', 'status' => 'confirmed'];
            $this->post('/t/calendar-test/reservations/store', $payload)->assertSessionHas('error', 'This apartment is blocked by an external calendar for those dates.');
            $this->assertSame(0, $db->table('reservations')->where('unit_id', $unitId)->count());
            $this->post('/t/calendar-test/reservations/store', [...$payload, 'check_in' => '2035-01-12', 'check_out' => '2035-01-14'])->assertSessionHas('success');
            $reservationId = $db->table('reservations')->where('unit_id', $unitId)->value('id');
            $this->post('/t/calendar-test/reservations/update/'.$reservationId, $payload)->assertSessionHas('error', 'This apartment is blocked by an external calendar for those dates.');
            $this->assertSame('2035-01-12', $db->table('reservations')->where('id', $reservationId)->value('check_in'));
        } finally {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            DB::purge('tenant');
        }
    }

    public function test_malformed_recurring_and_timed_events_are_rejected(): void
    {
        $service = new ExternalCalendars;
        foreach (['<html>error</html>', $this->calendar("BEGIN:VEVENT\nDTSTART;VALUE=DATE:20350230\nDTEND;VALUE=DATE:20350302\nEND:VEVENT\n"),
            $this->calendar("BEGIN:VEVENT\nDTSTART:20350110T100000Z\nDTEND:20350112T100000Z\nEND:VEVENT\n"),
            $this->calendar("BEGIN:VEVENT\nRRULE:FREQ=DAILY\nDTSTART:20350110\nDTEND:20350112\nEND:VEVENT\n")] as $body) {
            try {
                $service->parse($body);
                $this->fail('Invalid calendar was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_url_validation_rejects_untrusted_hosts_and_credentials(): void
    {
        $service = new ExternalCalendars;
        foreach (['http://www.airbnb.com/a.ics', 'https://www.airbnb.com.evil.test/a.ics', 'https://user:pass@www.airbnb.com/a.ics', 'https://www.airbnb.com:444/a.ics'] as $url) {
            try {
                $service->validateUrl($url);
                $this->fail('Unsafe URL was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }

        $this->assertSame('www.airbnb.com', $service->validateUrl('https://www.airbnb.com/calendar/ical/a.ics'));
    }
}
