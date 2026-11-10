<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicStaysTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    private Tenant $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', 'Africa/Nairobi'));
        $this->admin = User::factory()->create(['active' => true]);
        $this->company = $this->company('guest-stays');
        $this->admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
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

    private function company(string $slug): Tenant
    {
        $path = tempnam(sys_get_temp_dir(), 'haven_guest_stays_');
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
            $table->integer('bedrooms')->default(2);
            $table->integer('bathrooms')->default(2);
            $table->string('status')->default('active');
            $table->decimal('nightly_rate', 12, 2)->default(10000);
            $table->string('owner_phone')->default('SECRET-OWNER-PHONE');
            $table->string('notes')->default('SECRET-OWNER-CONTRACT');
        });
        $schema->create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id')->nullable();
            $table->integer('partner_apartment_id')->nullable();
            $table->string('booking_type')->default('managed');
            $table->string('status')->default('confirmed');
            $table->date('check_in');
            $table->date('check_out');
            $table->string('guest_name')->default('SECRET-BOOKING-GUEST');
        });
        $schema->create('partner_apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_name')->default('SECRET-PARTNER-OWNER');
            $table->string('owner_phone')->default('SECRET-PARTNER-PHONE');
            $table->string('owner_email')->default('private-partner@example.test');
            $table->string('apartment_name');
            $table->string('building_name')->default('Partner Building');
            $table->string('unit_number')->default('P1');
            $table->string('location')->default('Nyali');
            $table->integer('bedrooms')->default(2);
            $table->integer('bathrooms')->default(1);
            $table->string('notes')->default('SECRET-PARTNER-COMMISSION');
            $table->string('status')->default('active');
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Coral Gables']);
        $company = Tenant::factory()->create(['name' => 'Haven Guest Stays', 'slug' => $slug, 'database_name' => $path, 'active' => true,
            'timezone' => 'Africa/Nairobi', 'currency' => 'KES', 'plan' => 'legacy', 'subscription_status' => 'legacy']);
        DB::table('public_sites')->insert(['tenant_id' => $company->id, 'enabled' => true, 'headline' => 'Find your next stay',
            'tagline' => 'Comfortable homes, thoughtful hosting.', 'enquiry_assignee_id' => $this->admin->id, 'response_hours' => 24]);

        return $company;
    }

    private function listing(string $slug = 'coral-gables-f5', array $overrides = [], ?Tenant $company = null): array
    {
        $company ??= $this->company;
        $config = config('database.connections.tenant');
        $config['database'] = $company->database_name;
        $db = DB::build($config);
        try {
            $unitId = empty($overrides['partner_apartment_id'])
                ? $db->table('units')->insertGetId(['property_id' => 1, 'unit_number' => 'A-'.bin2hex(random_bytes(3))]) : null;
        } finally {
            $db->disconnect();
        }
        $id = DB::table('public_listings')->insertGetId(array_replace([
            'tenant_id' => $company->id, 'unit_id' => $unitId, 'slug' => $slug, 'title' => 'Bright Seaview Apartment',
            'description' => 'A peaceful apartment near the beach with two comfortable bedrooms and a bright balcony.',
            'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'public_location' => 'Near Nyali Beach',
            'max_guests' => 4, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00',
            'starting_price' => '12000.00', 'amenities' => json_encode(['wifi', 'pool']), 'house_rules' => 'No smoking indoors.',
            'status' => 'published', 'featured' => false, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
        $path = 'listing-photos/'.$company->id.'/'.$id.'/'.bin2hex(random_bytes(24)).'.png';
        Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='));
        $photoId = DB::table('public_listing_photos')->insertGetId(['tenant_id' => $company->id, 'listing_id' => $id,
            'storage_key' => $path, 'mime_type' => 'image/png', 'caption' => 'Apartment balcony', 'sort_order' => 0]);

        return ['id' => $id, 'slug' => $slug, 'unit_id' => $unitId, 'partner_apartment_id' => $overrides['partner_apartment_id'] ?? null, 'photo_id' => $photoId];
    }

    private function partnerListing(string $slug = 'partner-beach-home', array $overrides = [], ?Tenant $company = null): array
    {
        $company ??= $this->company;
        $config = config('database.connections.tenant');
        $config['database'] = $company->database_name;
        $db = DB::build($config);
        try {
            $partnerId = $db->table('partner_apartments')->insertGetId(['apartment_name' => 'Partner Beach Home']);
        } finally {
            $db->disconnect();
        }

        return $this->listing($slug, array_replace(['unit_id' => null, 'partner_apartment_id' => $partnerId,
            'title' => 'Partner Beach Home', 'starting_price' => null], $overrides), $company);
    }

    private function catalogue(array $filters = []): string
    {
        return route('public-stays.index', ['company' => $this->company->slug] + $filters);
    }

    public function test_guests_browse_only_published_active_apartments_without_private_data(): void
    {
        $this->listing();
        $this->listing('draft', ['status' => 'draft', 'title' => 'Private Draft']);
        $inactive = $this->listing('inactive', ['title' => 'Inactive Apartment']);
        DB::connection('tenant')->table('units')->where('id', $inactive['unit_id'])->update(['status' => 'inactive']);
        $this->get($this->catalogue())->assertOk()->assertViewHas('stays', fn ($rows): bool => $rows->total() === 1)
            ->assertSee('Bright Seaview Apartment')->assertDontSee('Private Draft')->assertDontSee('Inactive Apartment')
            ->assertDontSee('SECRET-OWNER-PHONE')->assertDontSee('SECRET-OWNER-CONTRACT')->assertDontSee('id="company-navigation"', false);
        $this->assertGuest();
        $this->get(route('public-stays.show', [$this->company->slug, 'draft']))->assertNotFound();
        $this->get(route('public-stays.show', [$this->company->slug, 'inactive']))->assertNotFound();
    }

    public function test_location_guest_price_bedroom_and_amenity_filters_work(): void
    {
        $wanted = $this->listing();
        $this->listing('nairobi', ['title' => 'Nairobi Home', 'city' => 'Nairobi', 'neighbourhood' => 'Westlands', 'starting_price' => 5000, 'max_guests' => 2, 'amenities' => json_encode(['wifi'])]);
        $this->get($this->catalogue(['city' => 'Mombasa', 'guests' => 4, 'min_price' => 10000, 'max_price' => 14000, 'amenities' => ['pool']]))
            ->assertOk()->assertViewHas('stays', fn ($rows): bool => $rows->total() === 1 && $rows->first()['id'] === $wanted['id']);
        $this->get($this->catalogue(['max_price' => 7000]))->assertOk()->assertViewHas('stays', fn ($rows): bool => $rows->total() === 1 && $rows->first()['slug'] === 'nairobi');
        $this->get($this->catalogue(['q' => 'Nyali', 'bedrooms' => 2]))->assertOk()->assertViewHas('stays', fn ($rows): bool => $rows->total() === 1);
    }

    public function test_destinations_localities_types_collections_and_codes_help_guests_find_stays(): void
    {
        $nyali = $this->listing('nyali-a19', ['title' => 'Nyali Seaview', 'property_type' => 'apartment', 'collections' => json_encode(['beachfront']), 'listing_code' => 'A19']);
        $bamburi = $this->listing('bamburi-villa', ['title' => 'Bamburi Villa', 'neighbourhood' => 'Bamburi', 'property_type' => 'villa', 'collections' => json_encode(['long_stay']), 'listing_code' => 'V2']);
        $this->listing('westlands', ['title' => 'Westlands Flat', 'city' => 'Nairobi', 'neighbourhood' => 'Westlands', 'property_type' => 'apartment']);
        $matches = fn (array $expected) => fn ($rows): bool => $rows->pluck('id')->sort()->values()->all() === collect($expected)->sort()->values()->all();

        $this->get($this->catalogue())->assertOk()
            ->assertSee('data-destinations-menu', false)->assertSee('Mombasa <span>(2)</span>', false)->assertSee('Bamburi <span>(1)</span>', false)
            ->assertSee('Mombasa (2)')->assertSee('<optgroup label="Mombasa">', false)->assertSee('id="stay-type"', false)
            ->assertSee('Beachfront <span>1</span>', false)->assertSee('Long stays <span>1</span>', false)->assertDontSee('Business stays')
            ->assertSee('Code A19')->assertSee('Find a stay')->assertSee('Serviced apartments &amp; short stays in Kenya', false);

        $this->get($this->catalogue(['city' => 'Mombasa', 'area' => 'Nyali']))->assertOk()->assertViewHas('stays', $matches([$nyali['id']]));
        $this->get($this->catalogue(['type' => 'villa']))->assertOk()->assertViewHas('stays', $matches([$bamburi['id']]));
        $this->get($this->catalogue(['collection' => 'beachfront']))->assertOk()->assertViewHas('stays', $matches([$nyali['id']]))
            ->assertSee('aria-current="true"', false)->assertSee('stay-active-filters', false);
        $this->get($this->catalogue(['q' => 'v2']))->assertOk()->assertViewHas('stays', $matches([$bamburi['id']]));
        $this->get($this->catalogue(['q' => 'beachfront']))->assertOk()->assertViewHas('stays', $matches([$nyali['id']]));
        $this->get($this->catalogue(['q' => 'VILLA']))->assertOk()->assertViewHas('stays', $matches([$bamburi['id']]));
        $this->get($this->catalogue(['q' => '  nyali   beach ']))->assertOk()->assertViewHas('stays', $matches([$nyali['id']]));
        $this->get($this->catalogue(['q' => 'westlands balcony']))->assertOk()->assertViewHas('stays', fn ($rows): bool => $rows->total() === 1 && $rows->first()['slug'] === 'westlands');
        $this->get($this->catalogue(['q' => 'nyali westlands']))->assertOk()->assertViewHas('stays', $matches([]));
        $this->get($this->catalogue())->assertOk()->assertSee('class="stay-keyword"', false)->assertSee('id="stay-q"', false);
        $this->get($this->catalogue(['city' => 'Nairobi']))->assertOk()->assertDontSee('Beachfront <span>', false);
        $this->get($this->catalogue(['city' => 'Mombasa', 'area' => 'Bamburi']))->assertOk()->assertSee('Long stays <span>1</span>', false)->assertDontSee('Beachfront <span>', false);
        $this->get($this->catalogue(['collection' => 'not-a-collection']))->assertSessionHasErrors('collection');
        $this->get(route('public-stays.show', [$this->company->slug, 'nyali-a19']))->assertOk()->assertSee('Code A19')->assertSee('Beachfront');
    }

    public function test_only_signed_in_listing_managers_of_this_company_see_the_staff_editing_bar(): void
    {
        $listing = $this->listing();
        $page = route('public-stays.show', [$this->company->slug, $listing['slug']]);
        $editUrl = route('public-listings.edit', ['tenant' => $this->company->slug, 'id' => $listing['id']]);

        $this->get($page)->assertOk()->assertDontSee('Staff tools')->assertDontSee('Edit this listing');

        $this->actingAs($this->admin)->get($page)->assertOk()->assertSee('aria-label="Staff tools"', false)
            ->assertSee('Edit this listing')->assertSee($editUrl, false)->assertSee(route('public-listings.settings', $this->company->slug), false);
        $this->actingAs($this->admin)->get($this->catalogue())->assertOk()->assertSee('aria-label="Staff tools"', false)->assertDontSee('Edit this listing');

        $cleaner = User::factory()->create(['active' => true]);
        $cleaner->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 2]);
        $this->actingAs($cleaner)->get($page)->assertOk()->assertDontSee('Staff tools');

        $otherAdmin = User::factory()->create(['active' => true]);
        $otherAdmin->tenants()->attach(Tenant::factory()->create(['slug' => 'other-company', 'active' => true]), ['roles' => json_encode(['admin']), 'legacy_user_id' => 3]);
        $this->actingAs($otherAdmin)->get($page)->assertOk()->assertDontSee('Staff tools');

        $partner = $this->partnerListing();
        $partnerPage = route('public-stays.show', [$this->company->slug, $partner['slug']]);
        $this->actingAs($this->admin)->get($partnerPage)->assertOk()->assertSee('Partner owner')->assertSee('SECRET-PARTNER-OWNER')->assertSee('private-partner@example.test');
        $this->actingAs($cleaner)->get($partnerPage)->assertOk()->assertDontSee('SECRET-PARTNER-OWNER')->assertDontSee('private-partner@example.test');
        auth()->logout();
        $this->get($partnerPage)->assertOk()->assertDontSee('SECRET-PARTNER-OWNER')->assertDontSee('SECRET-PARTNER-PHONE')->assertDontSee('private-partner@example.test');
        $this->admin->update(['active' => false]);
        $this->actingAs($this->admin->fresh())->get($page)->assertOk()->assertDontSee('Staff tools');
    }

    public function test_about_page_explains_who_we_are_how_we_work_and_where_we_are(): void
    {
        $this->listing();
        $this->listing('diani-home', ['title' => 'Diani Home', 'city' => 'Diani', 'neighbourhood' => 'Galu']);
        $about = route('public-stays.about', $this->company->slug);

        $this->get($about)->assertOk()->assertSee('<title>About us | Haven Guest Stays</title>', false)
            ->assertSee('Who we are')->assertSee('Haven Guest Stays offers comfortable serviced apartments')
            ->assertSee('How we work')->assertSee('We confirm the details')->assertSee('Based in Nairobi and Mombasa')
            ->assertSee('See stays in Mombasa')->assertSee('Ask us about apartments in Nairobi')
            ->assertSee('We also arrange stays in other parts of Kenya')->assertSee('?city=Diani', false)
            ->assertSee('"@type":"LodgingBusiness"', false)->assertSee('"name":"Nairobi"', false);
        $this->get($this->catalogue())->assertOk()->assertSee('>About us</a>', false)->assertSee($about, false);
        $this->get(route('public-stays.sitemap', $this->company->slug))->assertOk()->assertSee($about, false);

        DB::table('public_sites')->where('tenant_id', $this->company->id)->update(['about_intro' => "We are a Kenyan hospitality team.\n\nWe love hosting.",
            'about_how' => "Message us: Tell us what you need.\nWe book it", 'about_main_locations' => 'Mombasa', 'about_other_areas' => 'We also help in Lamu.']);
        $this->get($about)->assertOk()->assertSee('<p>We are a Kenyan hospitality team.</p>', false)->assertSee('<p>We love hosting.</p>', false)
            ->assertSee('<strong>Message us</strong>', false)->assertSee('<strong>We book it</strong>', false)->assertSee('Based in Mombasa')
            ->assertSee('We also help in Lamu.')->assertDontSee('Haven Guest Stays offers comfortable');

        DB::table('public_sites')->where('tenant_id', $this->company->id)->update(['enabled' => false]);
        $this->get($about)->assertNotFound();
    }

    public function test_pagination_and_invalid_search_dates_and_prices_are_handled(): void
    {
        for ($number = 1; $number <= 13; $number++) {
            $this->listing('stay-'.$number, ['title' => sprintf('Stay %02d', $number)]);
        }
        $this->get($this->catalogue())->assertOk()->assertViewHas('stays', fn ($rows): bool => $rows->total() === 13 && $rows->count() === 12);
        $this->get($this->catalogue(['page' => 2]))->assertOk()->assertViewHas('stays', fn ($rows): bool => $rows->count() === 1);
        $this->get($this->catalogue(['check_in' => '2026-11-10', 'check_out' => '2027-03-10']))->assertSessionHasErrors('check_out');
        $this->get($this->catalogue(['min_price' => 10000, 'max_price' => 1000]))->assertSessionHasErrors('max_price');
        $this->get($this->catalogue(['check_in' => '2028-11-06', 'check_out' => '2028-11-08']))->assertSessionHasErrors('check_out');
    }

    public function test_available_only_filters_out_booked_and_stale_units(): void
    {
        $free = $this->listing();
        $busy = $this->listing('busy');
        $stale = $this->listing('stale');
        DB::connection('tenant')->table('reservations')->insert(['unit_id' => $busy['unit_id'], 'check_in' => '2026-11-10', 'check_out' => '2026-11-12']);
        DB::table('external_calendars')->insert(['tenant_id' => $this->company->id, 'unit_id' => $stale['unit_id'], 'name' => 'Private calendar', 'url_encrypted' => 'SECRET-ICAL-URL', 'last_synced_at' => now()->subHour()]);
        $filters = ['check_in' => '2026-11-10', 'check_out' => '2026-11-12', 'available_only' => 1];
        $this->get($this->catalogue($filters))->assertOk()->assertViewHas('stays', fn ($rows): bool => $rows->total() === 1 && $rows->first()['id'] === $free['id']);
        $this->get($this->catalogue(array_replace($filters, ['available_only' => 0])))->assertOk()->assertViewHas('stays', fn ($rows): bool => $rows->total() === 3);
    }

    public function test_detail_has_signed_form_and_calendar_hides_guest_and_owner_information(): void
    {
        $listing = $this->listing();
        DB::connection('tenant')->table('reservations')->insert(['unit_id' => $listing['unit_id'], 'check_in' => '2026-11-10', 'check_out' => '2026-11-12']);
        $this->get(route('public-stays.show', [$this->company->slug, $listing['slug']]))->assertOk()
            ->assertSee('name="capture_token"', false)->assertSee('name="form_signature"', false)->assertSee('name="consent"', false)
            ->assertDontSee('SECRET-BOOKING-GUEST')->assertDontSee('SECRET-OWNER-PHONE')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(route('public-stays.calendar', [$this->company->slug, $listing['slug'], 'from' => '2026-11-01', 'to' => '2026-12-01']))
            ->assertOk()->assertExactJson(['blocked' => [['start' => '2026-11-10', 'end' => '2026-11-12']], 'fresh' => true, 'last_synced_at' => null, 'requires_confirmation' => true]);
        $this->getJson(route('public-stays.calendar', [$this->company->slug, $listing['slug'], 'from' => '2000-01-01', 'to' => '2026-12-01']))->assertUnprocessable();
    }

    public function test_photo_and_sitemap_access_is_company_scoped(): void
    {
        $listing = $this->listing();
        $other = $this->company('other-guest-stays');
        $otherListing = $this->listing('other-home', ['title' => 'Other Company Unit'], $other);
        $this->get(route('public-stays.photo', [$this->company->slug, $listing['slug'], $listing['photo_id']]))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('public-stays.photo', [$this->company->slug, $listing['slug'], $otherListing['photo_id']]))->assertNotFound();
        $this->get(route('public-stays.sitemap', $this->company->slug))->assertOk()->assertSee('/stays/guest-stays/coral-gables-f5', false)->assertDontSee('other-home');
        $this->get($this->catalogue())->assertOk()->assertDontSee('Other Company Unit');
        $this->get(route('public-stays.received', [$this->company->slug, str_repeat('f', 64)]))->assertNotFound();
    }

    public function test_draft_preview_is_staff_only_and_has_no_public_form(): void
    {
        $listing = $this->listing('draft-preview', ['status' => 'draft']);
        $this->actingAs($this->admin)->get(route('public-listings.preview', [$this->company->slug, $listing['id']]))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee('name="form_signature"', false);
    }

    public function test_site_and_module_switches_hide_the_catalogue_and_staff_login_still_works(): void
    {
        $this->listing();
        DB::table('public_sites')->where('tenant_id', $this->company->id)->update(['enabled' => false]);
        $this->get($this->catalogue())->assertNotFound();
        DB::table('public_sites')->where('tenant_id', $this->company->id)->update(['enabled' => true]);
        $this->company->forceFill(['modules' => ['properties' => false]])->save();
        $this->get($this->catalogue())->assertNotFound();
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    public function test_zero_unit_rate_displays_price_on_request_and_is_excluded_by_budget_filters(): void
    {
        $listing = $this->listing('price-on-request', ['starting_price' => null]);
        DB::connection('tenant')->table('units')->where('id', $listing['unit_id'])->update(['nightly_rate' => 0]);
        $this->get($this->catalogue())->assertOk()->assertSee('Price on request')->assertDontSee('KES 0');
        $this->get(route('public-stays.show', [$this->company->slug, $listing['slug']]))->assertOk()
            ->assertSee('Price on request')->assertDontSee('KES 0');
        $this->get($this->catalogue(['max_price' => 20000]))->assertOk()->assertViewHas('stays', fn ($rows): bool => $rows->total() === 0);
    }

    public function test_partner_page_quotes_on_request_and_requires_owner_confirmation_without_revealing_private_details(): void
    {
        $this->listing();
        $partner = $this->partnerListing();
        $this->get($this->catalogue())->assertOk()->assertSee('Partner Beach Home')->assertSee('Price on request');
        $response = $this->get(route('public-stays.show', [$this->company->slug, $partner['slug']]))->assertOk()
            ->assertSee('Price on request')->assertSee('name="form_signature"', false)
            ->assertSee('Our team confirms these dates with the apartment owner before accepting your booking.');
        foreach (['SECRET-PARTNER-OWNER', 'SECRET-PARTNER-PHONE', 'private-partner@example.test', 'SECRET-PARTNER-COMMISSION'] as $private) {
            $response->assertDontSee($private);
        }
        $this->get($this->catalogue(['max_price' => 20000]))->assertOk()
            ->assertViewHas('stays', fn ($rows): bool => $rows->total() === 1 && $rows->first()['unit_id'] !== null);
    }

    public function test_partner_public_calendar_uses_partner_bookings_and_ignores_matching_managed_unit_id(): void
    {
        $managed = $this->listing();
        $partner = $this->partnerListing();
        $this->assertSame($managed['unit_id'], $partner['partner_apartment_id']);
        DB::connection('tenant')->table('reservations')->insert([
            ['unit_id' => $managed['unit_id'], 'partner_apartment_id' => null, 'booking_type' => 'managed', 'check_in' => '2026-11-07', 'check_out' => '2026-11-09'],
            ['unit_id' => null, 'partner_apartment_id' => $partner['partner_apartment_id'], 'booking_type' => 'partner', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14'],
        ]);
        $this->getJson(route('public-stays.calendar', [$this->company->slug, $partner['slug'], 'from' => '2026-11-01', 'to' => '2026-12-01']))
            ->assertOk()->assertExactJson(['blocked' => [['start' => '2026-11-12', 'end' => '2026-11-14']],
                'fresh' => false, 'last_synced_at' => null, 'requires_confirmation' => true]);
        $this->getJson(route('public-stays.calendar', [$this->company->slug, $managed['slug'], 'from' => '2026-11-01', 'to' => '2026-12-01']))
            ->assertOk()->assertExactJson(['blocked' => [['start' => '2026-11-07', 'end' => '2026-11-09']],
                'fresh' => true, 'last_synced_at' => null, 'requires_confirmation' => true]);
    }

    public function test_mixed_search_keeps_availability_attached_to_listing_id_and_excludes_unconfirmed_partners_from_available_only(): void
    {
        $partner = $this->partnerListing();
        $managed = $this->listing();
        $this->assertNotSame($managed['id'], $managed['unit_id']);
        $dates = ['check_in' => '2026-11-10', 'check_out' => '2026-11-12'];
        $this->get($this->catalogue($dates))->assertOk()->assertViewHas('stays', function ($rows) use ($partner, $managed): bool {
            $stays = $rows->getCollection()->keyBy('id');

            return $rows->total() === 2
                && $stays[$managed['id']]['availability'] === ['available' => true, 'fresh' => true]
                && $stays[$partner['id']]['availability'] === ['available' => true, 'fresh' => false];
        });
        $this->get($this->catalogue($dates + ['available_only' => 1]))->assertOk()
            ->assertViewHas('stays', fn ($rows): bool => $rows->total() === 1 && $rows->first()['id'] === $managed['id']);
    }

    public function test_inactive_partner_listing_and_photos_are_hidden_from_guests(): void
    {
        $partner = $this->partnerListing();
        DB::connection('tenant')->table('partner_apartments')->where('id', $partner['partner_apartment_id'])->update(['status' => 'inactive']);
        $this->get($this->catalogue())->assertOk()->assertDontSee('Partner Beach Home');
        $this->get(route('public-stays.show', [$this->company->slug, $partner['slug']]))->assertNotFound();
        $this->get(route('public-stays.photo', [$this->company->slug, $partner['slug'], $partner['photo_id']]))->assertNotFound();
        $this->getJson(route('public-stays.calendar', [$this->company->slug, $partner['slug'], 'from' => '2026-11-01', 'to' => '2026-12-01']))->assertNotFound();
        $this->get(route('public-stays.sitemap', $this->company->slug))->assertOk()->assertDontSee($partner['slug']);
    }

    public function test_incomplete_partner_draft_can_be_previewed_without_inventing_capacity_minimum_stay_or_public_form(): void
    {
        $partner = $this->partnerListing('incomplete-partner', ['status' => 'draft', 'max_guests' => null, 'min_nights' => null]);
        $this->actingAs($this->admin)->get(route('public-listings.preview', [$this->company->slug, $partner['id']]))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('Guest capacity to be confirmed')->assertSee('To be confirmed')
            ->assertSee('Price on request')->assertDontSee('name="form_signature"', false)->assertDontSee('SECRET-PARTNER-PHONE');
    }
}
