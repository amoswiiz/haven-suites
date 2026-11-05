<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DueCommunications;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DueCommunicationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.timezone' => 'UTC', 'database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $this->company = Tenant::create(['name' => 'Due messages test', 'slug' => 'due-messages', 'database_name' => ':memory:', 'active' => true, 'timezone' => 'Africa/Nairobi']);
        $this->admin = $this->member('admin');
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($this->admin);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 22:30:00', 'UTC'));
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
        $schema->create('partner_apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('apartment_name');
            $table->string('unit_number')->nullable();
        });
        $schema->create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id')->nullable();
            $table->integer('partner_apartment_id')->nullable();
            $table->string('guest_name');
            $table->string('status');
            $table->date('check_in');
            $table->date('check_out');
            $table->dateTime('created_at')->nullable();
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Beach House']);
        DB::connection('tenant')->table('units')->insert(['id' => 1, 'property_id' => 1, 'unit_number' => 'A1']);
        DB::connection('tenant')->table('partner_apartments')->insert(['id' => 1, 'apartment_name' => 'Partner Home', 'unit_number' => 'P4']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::purge('tenant');
        parent::tearDown();
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['active' => true]);
        $user->tenants()->attach($this->company, ['roles' => json_encode([$role]), 'legacy_user_id' => 1]);

        return $user;
    }

    private function booking(array $overrides = []): int
    {
        return DB::connection('tenant')->table('reservations')->insertGetId(array_replace([
            'unit_id' => 1, 'partner_apartment_id' => null, 'guest_name' => 'Queue guest', 'status' => 'confirmed',
            'check_in' => '2026-11-10', 'check_out' => '2026-11-12', 'created_at' => '2026-11-01 09:00:00',
        ], $overrides));
    }

    private function message(int $booking, string $stage, string $status, array $overrides = []): void
    {
        DB::table('manual_sms_messages')->insert(array_replace([
            'tenant_id' => $this->company->id, 'sent_by' => $this->admin->id, 'send_token' => (string) Str::uuid(),
            'phone' => '+254720602805', 'body' => 'A recorded message', 'sender_id' => 'TestSMS',
            'reservation_id' => $booking, 'communication_stage' => $stage, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function url(): string
    {
        return '/t/'.$this->company->slug.'/communications/due';
    }

    public function test_today_uses_company_timezone_and_selects_all_five_due_stages(): void
    {
        $confirmation = $this->booking(['created_at' => '2026-11-05 21:05:00']);
        $upcoming = $this->booking(['check_in' => '2026-11-07']);
        $arrival = $this->booking(['check_in' => '2026-11-06']);
        $departure = $this->booking(['status' => 'checked_in', 'check_in' => '2026-11-03', 'check_out' => '2026-11-06']);
        $review = $this->booking(['status' => 'checked_out', 'check_in' => '2026-11-03', 'check_out' => '2026-11-05']);
        $this->booking(['created_at' => '2026-11-05 20:59:59']);
        $this->booking(['created_at' => '2026-11-06 21:00:00']);
        $this->booking(['status' => 'pending', 'check_in' => '2026-11-06']);
        $this->booking(['status' => 'cancelled', 'check_in' => '2026-11-07']);
        $this->booking(['status' => 'checked_out', 'check_out' => '2026-11-06']);
        $this->booking(['status' => 'confirmed', 'check_out' => '2026-11-05']);
        $this->booking(['created_at' => '2026-11-05 21:30:00', 'check_in' => '2026-11-01', 'check_out' => '2026-11-03']);
        $summary = app(DueCommunications::class)->summary(10);
        $this->assertSame('2026-11-06', $summary['date']);
        $this->assertSame('Friday, 6 November 2026', $summary['dateLabel']);
        $this->assertSame(5, $summary['readyCount']);
        $this->assertSame(0, $summary['reviewCount']);
        $byStage = $summary['rows']->keyBy('stage');
        foreach (['confirmation' => $confirmation, 'upcoming' => $upcoming, 'check_in' => $arrival, 'checkout_reminder' => $departure, 'review' => $review] as $stage => $id) {
            $this->assertSame($id, $byStage[$stage]['booking_id']);
        }
        $this->assertSame('checkout_reminder', $summary['rows']->first()['stage']);
        $this->assertDatabaseCount('manual_sms_messages', 0);
        Http::assertNothingSent();
    }

    public function test_accepted_messages_are_suppressed_and_uncertain_attempts_need_review(): void
    {
        foreach (['submitted', 'delivered', 'queued', 'pending', 'processing', 'unknown', 'failed', 'rejected', 'undelivered', 'future-state'] as $status) {
            $booking = $this->booking(['check_in' => '2026-11-06', 'guest_name' => $status]);
            $this->message($booking, 'check_in', $status);
        }
        $this->booking(['check_in' => '2026-11-06', 'guest_name' => 'No history']);
        $summary = app(DueCommunications::class)->summary(20);
        $this->assertSame(4, $summary['readyCount']);
        $this->assertSame(3, $summary['reviewCount']);
        $this->assertSame(7, $summary['totalCount']);
        $this->assertSame(['processing', 'unknown', 'future-state'], $summary['rows']->where('state', 'review')->pluck('guest_name')->values()->all());
        $this->get($this->url().'?state=review')->assertOk()->assertSee('Check delivery before retrying.')
            ->assertSee('Review delivery history')->assertDontSee('Prepare SMS')->assertDontSee('Review and retry SMS');
        Http::assertNothingSent();
    }

    public function test_latest_attempt_and_company_booking_stage_scope_determine_what_is_due(): void
    {
        $accepted = $this->booking(['check_in' => '2026-11-06']);
        $this->message($accepted, 'check_in', 'failed');
        $this->message($accepted, 'check_in', 'submitted');
        $retry = $this->booking(['check_in' => '2026-11-06']);
        $this->message($retry, 'check_in', 'delivered');
        $this->message($retry, 'check_in', 'failed');
        $unlinked = $this->booking(['check_in' => '2026-11-06']);
        $this->message($unlinked, 'custom', 'submitted');
        $this->message($unlinked, 'check_in', 'submitted', ['reservation_id' => null]);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-due', 'database_name' => 'unused_other_due', 'active' => true]);
        $this->message($unlinked, 'check_in', 'submitted', ['tenant_id' => $other->id]);
        $rows = app(DueCommunications::class)->items();
        $this->assertSame([$retry, $unlinked], $rows->pluck('booking_id')->all());
        $this->assertSame('failed', $rows->first()['message_status']);
        Http::assertNothingSent();
    }

    public function test_full_queue_is_paginated_and_dashboard_sample_shows_correct_counts(): void
    {
        for ($index = 1; $index <= 25; $index++) {
            $this->booking(['check_in' => '2026-11-06', 'guest_name' => 'Due guest '.$index]);
        }
        $this->get($this->url())->assertOk()->assertSee('Due guest 20')->assertDontSee('Due guest 21')
            ->assertSee('Prepare SMS')->assertSee('Booking confirmations for confirmed bookings created today, where the stay has not ended.')
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 20);
        $this->get($this->url().'?page=2')->assertOk()->assertSee('Due guest 25')
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 5);
        $summary = app(DueCommunications::class)->summary();
        $html = view('dashboard.communications', ['communicationQueue' => $summary])->render();
        $this->assertSame(25, $summary['readyCount']);
        $this->assertCount(5, $summary['rows']);
        $this->assertStringContainsString('25 ready to send', $html);
        $this->assertStringContainsString('Due guest 5', $html);
        $this->assertStringNotContainsString('Due guest 6', $html);
        $this->assertStringContainsString('Showing 5 of 25', $html);
        $this->assertStringContainsString('communication_stage=check_in', $html);
    }

    public function test_partner_bookings_and_empty_state_are_supported_without_sending(): void
    {
        $this->get($this->url())->assertOk()->assertSee('No communications match this filter.');
        $empty = app(DueCommunications::class)->summary();
        $this->assertStringContainsString('No communications need sending or delivery review today.', view('dashboard.communications', ['communicationQueue' => $empty])->render());
        $this->booking(['unit_id' => null, 'partner_apartment_id' => 1, 'check_in' => '2026-11-06']);
        $this->get($this->url())->assertOk()->assertSee('Partner Home / P4');
        Http::assertNothingSent();
    }

    public function test_view_only_staff_can_review_but_cannot_prepare_messages(): void
    {
        config(['haven.roles.message_reader' => ['messaging.view']]);
        $reader = $this->member('message_reader');
        $booking = $this->booking(['check_in' => '2026-11-06', 'guest_name' => '<script>private</script>']);
        $this->actingAs($reader)->get($this->url())->assertOk()->assertSee('&lt;script&gt;private&lt;/script&gt;', false)
            ->assertDontSee('<script>private</script>', false)->assertDontSee('Prepare SMS')->assertSee('Sending requires communication management permission.');
        $this->message($booking, 'check_in', 'unknown');
        $this->get($this->url())->assertOk()->assertSee('Review delivery history')->assertDontSee('/reservations/'.$booking.'#booking-communications', false);
    }

    public function test_permissions_inactive_members_and_disabled_messaging_block_access(): void
    {
        $this->actingAs($this->member('cleaner'))->get($this->url())->assertForbidden();
        $this->actingAs(User::factory()->create(['active' => true]))->get($this->url())->assertForbidden();
        $this->admin->active = false;
        $this->admin->save();
        $this->actingAs($this->admin)->get($this->url())->assertForbidden();
        $this->admin->active = true;
        $this->admin->save();
        $this->company->modules = ['messaging' => false];
        $this->company->save();
        $this->actingAs($this->admin)->get($this->url())->assertForbidden();
        Http::assertNothingSent();
    }
}
