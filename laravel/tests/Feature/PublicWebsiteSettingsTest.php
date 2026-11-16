<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\ListingPhotoVariants;
use App\Services\PublicWebsiteLinks;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicWebsiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    private array $databaseFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->company = $this->company('website-settings');
        $this->admin = User::factory()->create(['active' => true]);
        $this->admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        $this->actingAs($this->admin);
        app()->instance('currentTenant', $this->company);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        foreach ($this->databaseFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function company(string $slug): Tenant
    {
        $path = tempnam(sys_get_temp_dir(), 'haven_website_');
        $this->databaseFiles[] = $path;
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('units', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('property_id');
            $table->string('unit_number');
            $table->integer('bedrooms');
            $table->integer('bathrooms');
            $table->string('status');
            $table->decimal('nightly_rate', 12, 2);
            $table->string('owner_phone')->nullable();
            $table->string('notes')->nullable();
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Coral Gables']);
        DB::connection('tenant')->table('units')->insert([
            'id' => 1, 'property_id' => 1, 'unit_number' => 'F5', 'bedrooms' => 2, 'bathrooms' => 2,
            'status' => 'active', 'nightly_rate' => '10000.00', 'owner_phone' => null, 'notes' => null,
        ]);

        return Tenant::factory()->create(['name' => 'Website company', 'slug' => $slug, 'database_name' => $path, 'active' => true, 'plan' => 'legacy', 'subscription_status' => 'legacy']);
    }

    private function sitePayload(array $overrides = []): array
    {
        return array_replace([
            'version' => 0, 'settings_page' => 1, 'enabled' => 0, 'headline' => 'Your next stay',
            'enquiry_assignee_id' => $this->admin->id, 'response_hours' => 24,
            'seo_title' => 'Serviced apartments in Mombasa',
            'seo_description' => 'Browse furnished apartments and send an enquiry for your dates.',
            'whatsapp_number' => '+254712345678',
            'whatsapp_message' => 'Hello! I am interested in {listing_title}: {listing_url}',
            'navigation_links' => [
                ['label' => 'Stays', 'target' => 'stays'],
                ['label' => 'Welcome', 'target' => 'home'],
                ['label' => '', 'target' => ''],
            ],
        ], $overrides);
    }

    private function site(): ?object
    {
        return DB::table('public_sites')->where('tenant_id', $this->company->id)->first();
    }

    public function test_settings_page_saves_search_whatsapp_and_menu_and_returns_to_settings(): void
    {
        $this->get(route('public-listings.settings', $this->company->slug))
            ->assertOk()->assertSee('Search appearance')->assertSee('WhatsApp contact')->assertSee('Website menu');

        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('public-listings.settings', $this->company->slug));

        $site = $this->site();
        $this->assertSame('Serviced apartments in Mombasa', $site->seo_title);
        $this->assertSame('+254712345678', $site->whatsapp_number);
        $this->assertSame(1, (int) $site->version);
        $this->assertSame(
            [['label' => 'Stays', 'target' => 'stays'], ['label' => 'Welcome', 'target' => 'home']],
            json_decode($site->navigation_links, true),
        );
        $this->get(route('public-listings.settings', $this->company->slug))
            ->assertOk()->assertSee('value="Stays"', false)->assertSee('+254712345678');
    }

    public function test_invalid_website_settings_are_rejected_without_saving(): void
    {
        $invalid = [
            'whatsapp_number' => ['whatsapp_number' => '+25470289894'],
            'contact_phone' => ['contact_phone' => '0712 34'],
            'seo_title' => ['seo_title' => '<script>alert(1)</script>'],
            'navigation_links.1.target' => ['navigation_links' => [['label' => 'Stays', 'target' => 'stays'], ['label' => 'More stays', 'target' => 'stays']]],
            'navigation_links.0.target' => ['navigation_links' => [['label' => 'Blog', 'target' => 'https://example.com']]],
            'navigation_links.0.label' => ['navigation_links' => [['label' => '', 'target' => 'home']]],
        ];
        foreach ($invalid as $field => $overrides) {
            $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload($overrides))
                ->assertSessionHasErrors($field);
        }
        $this->assertNull($this->site());
    }

    public function test_phone_fields_are_prefilled_and_saved_in_international_form(): void
    {
        $this->get(route('public-listings.settings', $this->company->slug))
            ->assertOk()->assertSee('id="whatsapp_number"', false)->assertSee('value="+254 "', false);

        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload([
            'whatsapp_number' => '0712 345 678', 'contact_phone' => '+254 0733 000 111',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('+254712345678', $this->site()->whatsapp_number);
        $this->assertSame('+254733000111', $this->site()->contact_phone);

        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload([
            'version' => 1, 'whatsapp_number' => '+254 ', 'contact_phone' => '+254',
        ]))->assertSessionHasNoErrors();
        $this->assertNull($this->site()->whatsapp_number);
        $this->assertNull($this->site()->contact_phone);

        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload([
            'version' => 2, 'whatsapp_number' => '020 123 4567',
        ]))->assertSessionHasErrors('whatsapp_number');
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload([
            'version' => 2, 'whatsapp_number' => '', 'contact_phone' => '020 123 4567',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('+254201234567', $this->site()->contact_phone);
    }

    public function test_json_save_returns_the_redirect_and_message(): void
    {
        $this->postJson(route('public-listings.site.update', $this->company->slug), $this->sitePayload())
            ->assertOk()
            ->assertJson([
                'redirect' => route('public-listings.settings', $this->company->slug),
                'message' => 'Public site settings saved.',
            ]);
        $this->assertSame('+254712345678', $this->site()->whatsapp_number);
    }

    public function test_site_form_without_website_fields_keeps_existing_values(): void
    {
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload())->assertSessionHasNoErrors();

        $this->post(route('public-listings.site.update', $this->company->slug), [
            'version' => 1, 'headline' => 'Updated heading', 'enquiry_assignee_id' => $this->admin->id, 'response_hours' => 12,
        ])->assertSessionHasNoErrors()->assertRedirect(route('public-listings.index', $this->company->slug));

        $site = $this->site();
        $this->assertSame('Updated heading', $site->headline);
        $this->assertSame('Serviced apartments in Mombasa', $site->seo_title);
        $this->assertSame('+254712345678', $site->whatsapp_number);
        $this->assertCount(2, json_decode($site->navigation_links, true));
    }

    public function test_listing_saves_its_own_search_and_whatsapp_overrides(): void
    {
        $this->post(route('public-listings.store', $this->company->slug), [
            'unit_id' => 1, 'slug' => 'coral-gables-f5', 'title' => 'Seaview stay at Coral Gables',
            'description' => 'A bright apartment with comfortable bedrooms, a private balcony and a convenient location near the beach.',
            'city' => 'Mombasa', 'max_guests' => 4, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00',
            'starting_price' => '12000.00', 'amenities' => ['wifi'], 'status' => 'draft', 'featured' => 0,
            'seo_title' => 'Coral Gables F5 seaview apartment', 'seo_description' => 'Two-bedroom apartment near Nyali Beach.',
            'whatsapp_number' => '+254700000001', 'whatsapp_message' => 'Hi, is {listing_title} free?',
        ])->assertSessionHasNoErrors();

        $listing = DB::table('public_listings')->where('tenant_id', $this->company->id)->first();
        $this->assertSame('Coral Gables F5 seaview apartment', $listing->seo_title);
        $this->assertSame('+254700000001', $listing->whatsapp_number);
        $this->assertSame('Hi, is {listing_title} free?', $listing->whatsapp_message);
    }

    public function test_track_my_enquiry_can_be_a_menu_item_and_reserved_words_cannot_be_listing_addresses(): void
    {
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload([
            'navigation_links' => [['label' => 'Stays', 'target' => 'stays'], ['label' => 'My enquiry', 'target' => 'track']],
        ]))->assertSessionHasNoErrors();
        $this->assertSame('track', json_decode($this->site()->navigation_links, true)[1]['target']);
        $this->get(route('public-listings.settings', $this->company->slug))->assertOk()->assertSee('<option value="track"', false);

        $this->post(route('public-listings.store', $this->company->slug), [
            'unit_id' => 1, 'slug' => 'track', 'title' => 'Seaview stay at Coral Gables',
            'description' => 'A bright apartment with comfortable bedrooms, a private balcony and a convenient location near the beach.',
            'city' => 'Mombasa', 'max_guests' => 4, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00',
            'starting_price' => '12000.00', 'status' => 'draft', 'featured' => 0,
        ])->assertSessionHasErrors('slug');
        $this->assertDatabaseCount('public_listings', 0);
    }

    public function test_logo_is_reencoded_served_replaced_and_removed(): void
    {
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['logo' => UploadedFile::fake()->image('logo.jpg', 400, 120)]))
            ->assertSessionHasErrors('logo');

        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['logo' => UploadedFile::fake()->image('logo.png', 1600, 400)]))
            ->assertSessionHasNoErrors();
        $first = $this->site()->logo_path;
        $this->assertMatchesRegularExpression('#^public-sites/'.$this->company->id.'/logo-[A-Za-z0-9]{16}\.webp$#', $first);
        Storage::disk('local')->assertExists($first);
        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($first));
        $this->assertSame([800, 200], [$width, $height]);
        $this->get(route('public-stays.logo', $this->company->slug))->assertOk()
            ->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['version' => 1, 'logo' => UploadedFile::fake()->image('new.png', 300, 100)]))
            ->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($first);
        $second = $this->site()->logo_path;
        $this->get(route('public-listings.settings', $this->company->slug))->assertOk()->assertSee('Remove the logo');

        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['version' => 2, 'remove_logo' => 1]))->assertSessionHasNoErrors();
        $this->assertNull($this->site()->logo_path);
        Storage::disk('local')->assertMissing($second);
        $this->get(route('public-stays.logo', $this->company->slug))->assertNotFound();
    }

    public function test_listing_type_collections_and_code_are_validated_and_saved(): void
    {
        $listing = fn (array $overrides): array => array_replace([
            'unit_id' => 1, 'slug' => 'coral-gables-f5', 'title' => 'Seaview stay at Coral Gables',
            'description' => 'A bright apartment with comfortable bedrooms, a private balcony and a convenient location near the beach.',
            'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'max_guests' => 4, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00',
            'starting_price' => '12000.00', 'status' => 'draft', 'featured' => 0,
        ], $overrides);

        $this->post(route('public-listings.store', $this->company->slug), $listing(['property_type' => 'castle', 'collections' => ['beachfront', 'bogus'], 'listing_code' => 'A 19!']))
            ->assertSessionHasErrors(['property_type', 'collections.1', 'listing_code']);
        $this->post(route('public-listings.store', $this->company->slug), $listing(['property_type' => 'apartment', 'collections' => ['beachfront', 'sea_view'], 'listing_code' => ' a19 ']))
            ->assertSessionHasNoErrors();
        $saved = DB::table('public_listings')->where('tenant_id', $this->company->id)->first();
        $this->assertSame('apartment', $saved->property_type);
        $this->assertSame(['beachfront', 'sea_view'], json_decode($saved->collections, true));
        $this->assertSame('A19', $saved->listing_code);

        $this->post(route('public-listings.store', $this->company->slug), $listing(['slug' => 'second-listing', 'listing_code' => 'a19']))->assertSessionHasErrors('listing_code');
        $this->get(route('public-listings.create', $this->company->slug))->assertOk()->assertSee('<option value="Mombasa">', false)->assertSee('<option value="Nyali">', false)
            ->assertSee('name="collections[]"', false)->assertSee('id="listing_code"', false);
    }

    public function test_only_administrators_can_open_or_save_website_settings(): void
    {
        foreach (['cleaner', 'sales', 'owner', 'manager'] as $role) {
            $user = User::factory()->create(['active' => true]);
            $user->tenants()->attach($this->company, ['roles' => json_encode([$role]), 'legacy_user_id' => $user->id]);
            $this->actingAs($user)->get(route('public-listings.settings', $this->company->slug))->assertForbidden();
            $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload())->assertForbidden();
        }
        $this->assertNull($this->site());
    }

    public function test_about_page_text_is_saved_from_website_settings(): void
    {
        $this->get(route('public-listings.settings', $this->company->slug))->assertOk()->assertSee('About us page')->assertSee('placeholder="Nairobi, Mombasa"', false);
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload([
            'about_intro' => 'We host guests across Kenya.', 'about_how' => 'Enquire: Tell us your dates.', 'about_main_locations' => 'Nairobi, Mombasa', 'about_other_areas' => 'Diani and Watamu too.',
        ]))->assertSessionHasNoErrors();
        $site = $this->site();
        $this->assertSame('We host guests across Kenya.', $site->about_intro);
        $this->assertSame('Diani and Watamu too.', $site->about_other_areas);
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['version' => 1, 'about_main_locations' => str_repeat('a', 300)]))
            ->assertSessionHasErrors('about_main_locations');
    }

    public function test_favicon_is_resized_to_a_square_icon_and_used_in_page_heads(): void
    {
        $this->get(route('public-listings.settings', $this->company->slug))->assertOk()->assertSee('Favicon (browser tab icon)');
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['favicon' => UploadedFile::fake()->create('icon.svg', 2, 'image/svg+xml')]))
            ->assertSessionHasErrors('favicon');
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['favicon' => UploadedFile::fake()->image('tiny.png', 16, 16)]))
            ->assertSessionHasErrors('favicon');

        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['favicon' => UploadedFile::fake()->image('icon.png', 600, 300)]))
            ->assertSessionHasNoErrors();
        $path = $this->site()->favicon_path;
        $this->assertMatchesRegularExpression('#^public-sites/'.$this->company->id.'/favicon-[A-Za-z0-9]{16}\.png$#', $path);
        $this->assertSame([192, 192], array_slice(getimagesizefromstring(Storage::disk('local')->get($path)), 0, 2), 'Wide images are fitted into a square without cropping.');
        $this->get(route('public-stays.favicon', $this->company->slug))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['version' => 1, 'favicon' => UploadedFile::fake()->image('new.png', 512, 512)]))
            ->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($path);
        $replaced = $this->site()->favicon_path;
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['version' => 2, 'remove_favicon' => 1]))->assertSessionHasNoErrors();
        $this->assertNull($this->site()->favicon_path);
        Storage::disk('local')->assertMissing($replaced);
        $this->get(route('public-stays.favicon', $this->company->slug))->assertNotFound();
    }

    public function test_homepage_photo_is_saved_with_smaller_copies_and_can_be_removed(): void
    {
        $this->get(route('public-listings.settings', $this->company->slug))->assertOk()->assertSee('Homepage photo');
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['hero_photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]))
            ->assertSessionHasErrors('hero_photo');
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['hero_photo' => UploadedFile::fake()->image('sea-view.jpg', 2400, 1200)]))
            ->assertSessionHasNoErrors();
        $path = $this->site()->hero_photo_path;
        $this->assertMatchesRegularExpression('#^public-sites/'.$this->company->id.'/hero-[A-Za-z0-9]{16}\.jpg$#', $path);
        Storage::disk('local')->assertExists([$path, ListingPhotoVariants::key($path, 480), ListingPhotoVariants::key($path, 1200)]);
        $this->get(route('public-stays.hero-photo', $this->company->slug).'?w=480')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get(route('public-stays.hero-photo', $this->company->slug))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['version' => 1, 'remove_hero_photo' => 1]))->assertSessionHasNoErrors();
        $this->assertNull($this->site()->hero_photo_path);
        Storage::disk('local')->assertMissing([$path, ListingPhotoVariants::key($path, 480)]);
        $this->get(route('public-stays.hero-photo', $this->company->slug))->assertNotFound();
    }

    public function test_footer_offices_and_social_links_are_validated_and_saved(): void
    {
        $this->get(route('public-listings.settings', $this->company->slug))->assertOk()->assertSee('Footer: offices and social media')->assertSee('name="social_links[tiktok]"', false);
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['social_links' => ['facebook' => 'https://evil.example/havenholidayhomes']]))
            ->assertSessionHasErrors('social_links.facebook');
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload(['social_links' => ['instagram' => 'http://www.instagram.com/havensuites.bnb/']]))
            ->assertSessionHasErrors('social_links.instagram');
        $this->assertNull($this->site());

        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload([
            'office_addresses' => "Westlands Arcade, First Floor, Nairobi\nThird Avenue Beach Apartments, Nyali\n\n",
            'social_links' => ['facebook' => 'https://web.facebook.com/havenholidayhomes', 'tiktok' => 'https://www.tiktok.com/@haven.suitesbnb', 'x' => '', 'youtube' => 'https://www.youtube.com/@havensuitesbnb'],
        ]))->assertSessionHasNoErrors();
        $site = $this->site();
        $this->assertSame(['facebook' => 'https://web.facebook.com/havenholidayhomes', 'tiktok' => 'https://www.tiktok.com/@haven.suitesbnb', 'youtube' => 'https://www.youtube.com/@havensuitesbnb'],
            json_decode($site->social_links, true));
        $this->assertSame(['Facebook' => 'https://web.facebook.com/havenholidayhomes', 'TikTok' => 'https://www.tiktok.com/@haven.suitesbnb', 'YouTube' => 'https://www.youtube.com/@havensuitesbnb'],
            PublicWebsiteLinks::socialLinks($site));
        $this->assertSame(['Westlands Arcade, First Floor, Nairobi', 'Third Avenue Beach Apartments, Nyali'], PublicWebsiteLinks::officeAddresses($site));
    }
}
