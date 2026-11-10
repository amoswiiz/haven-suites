<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\WhatsAppEnquiryReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CallerEnquiryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    private array $files = [];

    private int $listingId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.roycetalk.manual_live' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'Africa/Nairobi'));
        $this->admin = User::factory()->create(['active' => true, 'name' => 'Front Desk']);
        $path = tempnam(sys_get_temp_dir(), 'haven_caller_');
        $this->files[] = $path;
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('units', function (Blueprint $table): void {
            $table->id();
            $table->integer('property_id');
            $table->string('unit_number');
            $table->integer('bedrooms')->default(1);
            $table->integer('bathrooms')->default(1);
            $table->string('status')->default('active');
            $table->decimal('nightly_rate', 12, 2)->default(10000);
        });
        $schema->create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id')->nullable();
            $table->integer('partner_apartment_id')->nullable();
            $table->string('booking_type')->default('managed');
            $table->string('status')->default('confirmed');
            $table->date('check_in');
            $table->date('check_out');
            $table->string('guest_name')->default('Guest');
            $table->decimal('total_amount', 12, 2)->default(0);
        });
        $schema->create('partner_apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('apartment_name');
            $table->string('status')->default('active');
            $table->integer('bedrooms')->default(1);
            $table->integer('bathrooms')->default(1);
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Amalia']);
        $unit = DB::connection('tenant')->table('units')->insertGetId(['property_id' => 1, 'unit_number' => 'A1.9']);
        $this->company = Tenant::factory()->create(['name' => 'Haven Suites', 'slug' => 'haven', 'database_name' => $path, 'active' => true,
            'timezone' => 'Africa/Nairobi', 'currency' => 'KES', 'plan' => 'legacy', 'subscription_status' => 'legacy']);
        $this->admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        DB::table('public_sites')->insert(['tenant_id' => $this->company->id, 'enabled' => true, 'enquiry_assignee_id' => $this->admin->id, 'response_hours' => 24]);
        $this->listingId = DB::table('public_listings')->insertGetId(['tenant_id' => $this->company->id, 'unit_id' => $unit, 'slug' => 'amalia-nyali-a19',
            'title' => 'Amalia Apartments / A1.9', 'description' => 'A comfortable one-bedroom apartment on Links Road with a pool and fast Wi-Fi.',
            'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'max_guests' => 4, 'min_nights' => 1, 'check_in_time' => '14:00', 'check_out_time' => '10:00',
            'starting_price' => 10000, 'amenities' => json_encode(['wifi']), 'status' => 'published', 'featured' => false, 'listing_code' => 'A19',
            'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('local')->put('listing-photos/p.png', 'png');
        DB::table('public_listing_photos')->insert(['tenant_id' => $this->company->id, 'listing_id' => $this->listingId, 'storage_key' => 'listing-photos/p.png', 'mime_type' => 'image/png', 'sort_order' => 0]);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function message(): string
    {
        return "Hello! I am interested in Amalia Apartments / A1.9: https://app.havensuitesbnb.com/stays/haven/amalia-nyali-a19\nCode: A19\nDates: Sat 12 Dec 2026 – Tue 15 Dec 2026 (3 nights)\nGuests: 2";
    }

    public function test_reader_extracts_listing_dates_and_guests_and_ignores_unsafe_values(): void
    {
        $reader = app(WhatsAppEnquiryReader::class);
        $this->assertSame(['listing_slug' => 'amalia-nyali-a19', 'listing_code' => 'A19', 'check_in' => '2026-12-12', 'check_out' => '2026-12-15', 'guests' => 2],
            $reader->read($this->company, $this->message()));
        $this->assertSame('2026-12-12', $reader->read($this->company, 'Dates: Mon 12 Dec 2026 – Tue 15 Dec 2026')['check_in'], 'A wrong weekday must not move the date.');
        $this->assertSame(['listing_slug' => null, 'listing_code' => null, 'check_in' => null, 'check_out' => null, 'guests' => null],
            $reader->read($this->company, "https://app.havensuitesbnb.com/stays/other-company/amalia-nyali-a19\nDates: 31 Feb 2026 – soon\nGuests: 0"));
        $this->assertNull($reader->read($this->company, 'Dates: 15 Dec 2026 – 12 Dec 2026')['check_out']);
    }

    public function test_staff_bar_links_to_a_prefilled_caller_enquiry_for_the_listing(): void
    {
        $page = route('public-stays.show', [$this->company->slug, 'amalia-nyali-a19']).'?check_in=2026-12-12&check_out=2026-12-15&guests=2';
        $expected = route('sales.create', $this->company->slug).'?listing='.$this->listingId.'&amp;check_in=2026-12-12&amp;check_out=2026-12-15&amp;guests=2';
        $this->actingAs($this->admin)->get($page)->assertOk()->assertSee('Create enquiry for a caller')->assertSee($expected, false);

        $this->actingAs($this->admin)->get(route('sales.create', $this->company->slug).'?listing='.$this->listingId.'&check_in=2026-12-12&check_out=2026-12-15&guests=2')
            ->assertOk()->assertSee('value="Amalia Apartments / A1.9 (Code A19)"', false)->assertSee('value="Nyali, Mombasa"', false)
            ->assertSee('name="public_listing_id" value="'.$this->listingId.'"', false)->assertSee('value="2026-12-12"', false)
            ->assertSee('<option value="phone" selected', false);
    }

    public function test_a_pasted_whatsapp_message_fills_in_the_enquiry(): void
    {
        $this->actingAs($this->admin)->get(route('sales.create', $this->company->slug).'?'.http_build_query(['message' => $this->message(), 'phone' => '0712 345 678', 'name' => 'Amina']))
            ->assertOk()->assertSee('value="Amalia Apartments / A1.9 (Code A19)"', false)->assertSee('value="2026-12-15"', false)
            ->assertSee('id="guests" name="guests" type="number" min="1" max="1000" value="2"', false)->assertSee('value="0712 345 678"', false)
            ->assertSee('Guest&#039;s WhatsApp message:', false)->assertSee('<option value="whatsapp" selected', false);

        $this->actingAs($this->admin)->get(route('sales.create', $this->company->slug).'?message='.rawurlencode('Hi, do you have anything in Diani for Christmas?'))
            ->assertOk()->assertSee('did not mention one of your published apartments')->assertDontSee('name="public_listing_id"', false);
    }

    public function test_saving_links_the_enquiry_to_the_listing_and_rejects_other_companies_listings(): void
    {
        $base = ['name' => 'Amina Caller', 'phone' => '0712345678', 'source' => 'phone', 'channel' => 'phone', 'assigned_to' => $this->admin->id,
            'check_in' => '2026-12-12', 'check_out' => '2026-12-15', 'guests' => 2, 'unit_preference' => 'Amalia Apartments / A1.9',
            'next_action' => 'Confirm availability', 'follow_up_at' => now()->addHour()->format('Y-m-d H:i:s')];

        $other = Tenant::factory()->create(['slug' => 'other', 'active' => true]);
        $foreign = DB::table('public_listings')->insertGetId(['tenant_id' => $other->id, 'unit_id' => 1, 'slug' => 'foreign', 'title' => 'Foreign', 'description' => 'x',
            'city' => 'Nairobi', 'max_guests' => 2, 'min_nights' => 1, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin)->post(route('sales.store', $this->company->slug), [...$base, 'public_listing_id' => $foreign])->assertSessionHasErrors('public_listing_id');
        $this->actingAs($this->admin)->post(route('sales.store', $this->company->slug), [...$base, 'phone' => '+254 ', 'email' => ''])->assertSessionHasErrors('phone');

        $this->actingAs($this->admin)->post(route('sales.store', $this->company->slug), [...$base, 'public_listing_id' => $this->listingId])->assertSessionHasNoErrors();
        $lead = DB::table('sales_leads')->where('tenant_id', $this->company->id)->first();
        $this->assertSame('staff', $lead->public_origin);
        $this->assertEquals($this->listingId, $lead->public_listing_id);
        $this->assertNotNull($lead->public_unit_id);
        $this->assertSame('+254712345678', $lead->phone);
        $this->assertDatabaseHas('manual_sms_messages', ['enquiry_id' => $lead->id, 'communication_stage' => 'enquiry_acknowledgement']);
    }
}
