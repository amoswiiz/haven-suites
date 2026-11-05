<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApartmentReadiness;
use App\Services\BookingCommunications;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingCommunicationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'services.roycetalk.manual_live' => true, 'services.roycetalk.api_key' => 'fake-key', 'services.roycetalk.sender_id' => 'TestSMS']);
        DB::purge('tenant');
        $this->company = Tenant::create(['name' => 'Haven booking test', 'slug' => 'booking-comms', 'database_name' => ':memory:', 'active' => true, 'timezone' => 'Africa/Nairobi']);
        $this->admin = User::factory()->create(['active' => true]);
        $this->admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
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
        $schema->create('partner_apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('apartment_name');
            $table->string('building_name');
            $table->string('unit_number');
            $table->string('location')->nullable();
            $table->string('owner_name')->nullable();
        });
        $schema->create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id')->nullable();
            $table->integer('partner_apartment_id')->nullable();
            $table->string('booking_type')->default('managed');
            $table->string('guest_name');
            $table->string('guest_phone');
            $table->string('guest_email')->nullable();
            $table->date('check_in');
            $table->date('check_out');
            $table->string('status');
            $table->decimal('total_amount')->default(1000);
            $table->decimal('amount_paid')->default(0);
            $table->decimal('nightly_rate')->default(500);
            $table->integer('adults')->default(1);
            $table->integer('children')->default(0);
            $table->string('booking_source')->default('Direct');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Beach House']);
        DB::connection('tenant')->table('units')->insert([['id' => 1, 'property_id' => 1, 'unit_number' => 'A1'], ['id' => 2, 'property_id' => 1, 'unit_number' => 'B2']]);
        DB::connection('tenant')->table('partner_apartments')->insert(['id' => 1, 'apartment_name' => 'Partner Home', 'building_name' => 'Partner Tower', 'unit_number' => 'P4']);
        DB::connection('tenant')->table('reservations')->insert(['id' => 1, 'unit_id' => 1, 'guest_name' => 'Amina Guest', 'guest_phone' => '0720602805',
            'check_in' => '2026-11-03', 'check_out' => '2026-11-05', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::purge('tenant');
        parent::tearDown();
    }

    private function url(string $path): string
    {
        return '/t/booking-comms/communications/'.$path;
    }

    private function sms(array $overrides = []): array
    {
        return array_replace(['phone' => '+254720602805', 'body' => 'Your stay details are ready.', 'send_token' => (string) Str::uuid(), 'reservation_id' => 1, 'communication_stage' => 'check_in'], $overrides);
    }

    private function message(array $overrides = []): void
    {
        DB::table('manual_sms_messages')->insert(array_replace(['tenant_id' => $this->company->id, 'sent_by' => $this->admin->id, 'send_token' => (string) Str::uuid(),
            'phone' => '+254720602805', 'body' => 'Booking-linked private message', 'sender_id' => 'TestSMS', 'status' => 'submitted', 'provider_status' => 'pending',
            'reservation_id' => 1, 'communication_stage' => 'check_in', 'created_at' => now(), 'updated_at' => now()], $overrides));
    }

    public function test_workflow_uses_company_dates_and_only_explicit_booking_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 22:30:00', 'UTC'));
        config(['app.timezone' => 'UTC']);
        $this->message(['created_at' => '2026-11-04 22:30:00']);
        $this->message(['reservation_id' => null, 'body' => 'Unlinked same-phone message']);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-booking-comms', 'database_name' => 'unused', 'active' => true]);
        $this->message(['tenant_id' => $other->id, 'body' => 'Other company message']);
        $booking = (array) DB::connection('tenant')->table('reservations')->find(1);
        $workflow = app(BookingCommunications::class)->overview($booking);
        $this->assertCount(1, $workflow['messages']);
        $this->assertSame('Suggested today', collect($workflow['stages'])->firstWhere('key', 'checkout_reminder')['timing']);
        $this->assertSame('2026-11-06', collect($workflow['stages'])->firstWhere('key', 'review')['date']);
        $html = view('reservations.communications', ['workflow' => $workflow, 'id' => 1])->render();
        $this->assertStringContainsString('Booking-linked private message', $html);
        $this->assertStringContainsString('Sent to provider', $html);
        $this->assertStringContainsString('05 Nov 2026 01:30', $html);
        $this->assertStringNotContainsString('Unlinked same-phone message', $html);
        $this->assertStringNotContainsString('Other company message', $html);
        DB::table('manual_sms_messages')->where('tenant_id', $this->company->id)->where('reservation_id', 1)->update(['status' => 'unknown']);
        $unknown = app(BookingCommunications::class)->overview($booking);
        $this->assertTrue(collect($unknown['stages'])->firstWhere('key', 'check_in')['uncertain']);
        Http::assertNothingSent();
    }

    public function test_preview_preserves_booking_stage_and_sms_send_is_recorded_once(): void
    {
        $preview = $this->post($this->url('guests/preview'), ['reservation_id' => 1, 'communication_stage' => 'check_in', 'body' => 'Hello {{guest_name}}, apartment {{apartment}} is ready.']);
        $preview->assertOk()->assertSee('Hello Amina Guest, apartment A1 is ready.')
            ->assertSee('name="reservation_id" value="1"', false)->assertSee('name="communication_stage" value="check_in"', false);
        Http::assertNothingSent();
        Http::fake(['https://roycetalk.com/api/v1/sms-api/send/' => Http::response(['success' => true, 'data' => ['message_id' => 'booking-message-1']])]);
        $payload = $this->sms();
        $this->post($this->url('sms'), $payload)->assertRedirect(site_url('reservations/1').'#booking-communications');
        $this->post($this->url('sms'), $payload)->assertRedirect();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('manual_sms_messages', ['tenant_id' => $this->company->id, 'reservation_id' => 1, 'communication_stage' => 'check_in', 'status' => 'submitted']);
        $this->get($this->url('sms'))->assertOk()->assertSee(site_url('reservations/1').'#booking-communications', false);
    }

    public function test_booking_context_and_cancelled_stages_are_checked_again_on_final_send(): void
    {
        $this->post($this->url('guests/preview'), ['reservation_id' => 999, 'body' => 'Hello'])->assertNotFound();
        $this->post($this->url('sms'), $this->sms(['reservation_id' => 999]))->assertNotFound();
        $this->postJson($this->url('sms'), $this->sms(['reservation_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('reservation_id');
        $this->postJson($this->url('sms'), $this->sms(['communication_stage' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('communication_stage');
        DB::connection('tenant')->table('reservations')->where('id', 1)->update(['status' => 'cancelled']);
        $this->postJson($this->url('sms'), $this->sms())->assertUnprocessable()->assertJsonValidationErrors('communication_stage');
        $this->postJson($this->url('guests/preview'), ['reservation_id' => 1, 'communication_stage' => 'check_in', 'body' => 'Hello'])->assertUnprocessable();
        $this->post($this->url('guests/preview'), ['reservation_id' => 1, 'communication_stage' => 'custom', 'body' => 'Please contact us about your booking.'])->assertOk();
        Http::assertNothingSent();
        $this->assertDatabaseCount('manual_sms_messages', 0);
    }

    public function test_pending_booking_cannot_prepare_a_confirmation_claim(): void
    {
        DB::connection('tenant')->table('reservations')->where('id', 1)->update(['status' => 'pending']);
        $this->getJson($this->url('guests').'?reservation_id=1&communication_stage=confirmation')->assertUnprocessable();
        $this->postJson($this->url('sms'), $this->sms(['communication_stage' => 'confirmation']))->assertUnprocessable();
        $this->post($this->url('guests/preview'), ['reservation_id' => 1, 'communication_stage' => 'custom', 'body' => 'We received your enquiry.'])->assertOk();
        Http::assertNothingSent();
    }

    public function test_stage_composer_prefers_matching_apartment_template_and_supports_partner_bookings(): void
    {
        $attributes = ['tenant_id' => $this->company->id, 'purpose' => 'check_in', 'channel' => 'sms', 'active' => true, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()];
        DB::table('guest_message_templates')->insert($attributes + ['unit_id' => null, 'name' => 'Company', 'body' => 'Welcome {{guest_name}}']);
        $id = DB::table('guest_message_templates')->insertGetId($attributes + ['unit_id' => 1, 'name' => 'Apartment A1', 'body' => 'A1 key instructions']);
        $this->get($this->url('guests').'?reservation_id=1&communication_stage=check_in')->assertOk()->assertViewHas('selectedTemplate', $id);
        DB::connection('tenant')->table('reservations')->where('id', 1)->update(['unit_id' => null, 'partner_apartment_id' => 1, 'booking_type' => 'partner']);
        $this->post($this->url('guests/preview'), ['reservation_id' => 1, 'body' => '{{apartment}} at {{property_name}}'])->assertOk()->assertSee('P4 at Partner Tower');
        $this->post($this->url('guests/preview'), ['reservation_id' => 1, 'template_id' => $id])->assertSessionHasErrors('template_id');
    }

    public function test_booking_page_exposes_manual_communication_flow_for_authorized_staff(): void
    {
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->create('guest_payments', function (Blueprint $t): void {
            $t->id();
            $t->integer('reservation_id');
            $t->date('payment_date');
        });
        $schema->create('invoices', function (Blueprint $t): void {
            $t->id();
            $t->integer('reservation_id');
        });
        $schema->create('housekeeping', function (Blueprint $t): void {
            $t->id();
            $t->integer('reservation_id');
            $t->integer('unit_id');
            $t->dateTime('scheduled_at');
        });
        $schema->create('maintenance', function (Blueprint $t): void {
            $t->id();
            $t->integer('unit_id');
            $t->string('status');
            $t->dateTime('reported_at');
        });
        $operator = User::factory()->create(['active' => true]);
        $operator->tenants()->attach($this->company, ['roles' => json_encode(['operations']), 'legacy_user_id' => 2]);
        $this->company->modules = ['finance' => false];
        $this->company->save();
        $this->mock(ApartmentReadiness::class)->shouldReceive('overview')->andReturn(collect());
        $this->actingAs($operator)->get('/t/booking-comms/reservations/1')->assertOk()->assertSee('Booking communications')->assertSee('Checkout reminder')->assertSee('automatic scheduling is not active');
        $this->company->modules = ['finance' => false, 'messaging' => false];
        $this->company->save();
        $this->get('/t/booking-comms/reservations/1')->assertOk()->assertDontSee('id="booking-communications"', false);
        Http::assertNothingSent();
    }
}
