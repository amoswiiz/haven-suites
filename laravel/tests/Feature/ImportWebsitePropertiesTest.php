<?php

namespace Tests\Feature;

use App\Console\Commands\ImportWebsiteProperties;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ListingAvailability;
use App\Services\PublicEnquiries;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportWebsitePropertiesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->databaseFile = (string) tempnam(sys_get_temp_dir(), 'haven_props_');
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $this->databaseFile, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->create('partner_apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_name');
            $table->string('owner_phone')->nullable();
            $table->string('owner_email')->nullable();
            $table->string('apartment_name');
            $table->string('building_name')->nullable();
            $table->string('unit_number')->nullable();
            $table->string('location');
            $table->integer('bedrooms')->default(1);
            $table->integer('bathrooms')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
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
        });
        $this->company = Tenant::factory()->create(['slug' => 'props-stays', 'name' => 'Haven Suites', 'database_name' => $this->databaseFile, 'active' => true, 'currency' => 'KES']);
        $admin = User::factory()->create(['active' => true]);
        DB::table('public_sites')->insert(['tenant_id' => $this->company->id, 'enabled' => true, 'enquiry_assignee_id' => $admin->id, 'response_hours' => 2,
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('public_listings')->insert(['tenant_id' => $this->company->id, 'unit_id' => 99, 'slug' => 'beachfront-villa-2br-diani-001', 'title' => 'Already here',
            'description' => 'Imported before.', 'city' => 'Diani', 'max_guests' => 2, 'min_nights' => 1, 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->mock(ListingAvailability::class)->shouldReceive('calendar')->andReturn(['blocked' => [], 'fresh' => true, 'last_synced_at' => null, 'requires_confirmation' => true]);
        $this->mock(PublicEnquiries::class)->shouldReceive('formContext')->andReturn([]);

        $property = fn (int $id, string $slug, string $title, array $meta, array $terms = []): array => $terms + ['id' => $id, 'slug' => $slug, 'title' => ['rendered' => $title],
            'content' => ['rendered' => '<p>Wake up to views of the lake from every bedroom.</p><p>[contact-form-7 id="1"]</p>'],
            'property_meta' => $meta + ['fave_property_price_postfix' => ['Day'], 'fave_property_bedrooms' => ['4'], 'fave_property_bathrooms' => ['4'],
                'houzez_geolocation_lat' => ['-0.7612'], 'houzez_geolocation_long' => ['36.4103']],
            'property_city' => [], 'property_area' => [], 'property_feature' => [5, 6], 'property_status' => [], 'property_label' => []];
        $properties = [
            $property(10, 'panoramic-views-4br-naivasha-010', 'Panoramic views 4BR, Naivasha &#8211; 010', ['fave_property_price' => ['18,000'], 'fave_property_images' => ['501', '502']],
                ['property_city' => [1], 'property_area' => [2], 'property_label' => [3]]),
            $property(11, 'beachfront-villa-2br-diani-001', 'BeachFront Villa 2BR, Diani &#8211; 001', ['fave_property_price' => ['9000'], 'fave_property_images' => ['503']]),
            $property(12, 'seaview-4br-for-sale-nyali-13', 'Luxurious Seaview 4BR, Nyali -13', ['fave_property_price' => ['25,000,000'], 'fave_property_price_postfix' => [''], 'fave_property_images' => ['503']],
                ['property_label' => [4]]),
            $property(13, 'quiet-villa-nanyuki_014', 'Quiet Villa 3BR, Nanyuki &#8211; 014', ['fave_property_price' => [''], 'fave_property_bedrooms' => ['3'], 'fave_property_images' => ['504']]),
            $property(14, 'haven-suites-1br-danaff-towers', 'Haven Suites &#8211; 1br Danaff Towers, Kileleshwa', ['fave_property_price' => ['6000'], 'fave_property_bedrooms' => ['1'], 'fave_property_images' => ['504']]),
        ];
        $terms = [
            'property_city' => [['id' => 1, 'name' => 'Naivasha', 'slug' => 'naivasha']],
            'property_area' => [['id' => 2, 'name' => 'Karagita', 'slug' => 'karagita']],
            'property_feature' => [['id' => 5, 'name' => 'WiFi', 'slug' => 'wi-fi'], ['id' => 6, 'name' => 'Balcony', 'slug' => 'balcony']],
            'property_status' => [],
            'property_label' => [['id' => 3, 'name' => 'Guest Favorite', 'slug' => 'guest-favorite'], ['id' => 4, 'name' => 'For Sale', 'slug' => 'for-sale']],
        ];
        Http::fake(function (Request $request) use ($properties, $terms) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_starts_with($path, '/wp-content/uploads/')) {
                return str_contains($path, 'broken') ? Http::response('not an image', 200, ['Content-Type' => 'image/jpeg']) : Http::response($this->png(), 200, ['Content-Type' => 'image/png']);
            }
            if ($path === '/wp-json/wp/v2/media') {
                return Http::response([['id' => 501, 'source_url' => 'https://havensuitesbnb.com/wp-content/uploads/lake.png'], ['id' => 502, 'source_url' => 'https://havensuitesbnb.com/wp-content/uploads/broken.png'],
                    ['id' => 503, 'source_url' => 'https://havensuitesbnb.com/wp-content/uploads/beach.png'], ['id' => 504, 'source_url' => 'https://elsewhere.example/villa.png']]);
            }
            $resource = basename($path);

            return Http::response($resource === 'properties' ? $properties : ($terms[$resource] ?? []), 200, ['X-WP-TotalPages' => '1']);
        });
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        @unlink($this->databaseFile);
        parent::tearDown();
    }

    public function test_dry_run_lists_properties_without_saving(): void
    {
        $this->artisan('haven:import-website-properties', ['tenant' => 'props-stays', '--dry-run' => true, '--publish' => true])
            ->expectsOutputToContain('Skipped (for sale): 1')
            ->expectsOutputToContain('Skipped (already on the new site): 1')
            ->expectsOutputToContain('Ready: Panoramic views 4BR, Naivasha – 010 → /panoramic-views-4br-naivasha-010 (Naivasha, 2 photos, 18,000 a night)')
            ->expectsOutputToContain('Dry run: 3 properties with 4 photos would be imported and published.')
            ->assertSuccessful();
        $this->assertSame(1, DB::table('public_listings')->count());
        $this->assertSame(0, DB::connection('tenant')->table('partner_apartments')->count());
    }

    public function test_properties_become_partner_listings_with_photos_and_show_on_the_site(): void
    {
        $this->artisan('haven:import-website-properties', ['tenant' => 'props-stays', '--publish' => true])
            ->expectsOutputToContain('Imported 3 properties: 1 published, 2 saved as drafts.')->assertSuccessful();

        $listing = DB::table('public_listings')->where('slug', 'panoramic-views-4br-naivasha-010')->first();
        $this->assertSame(['published', 'Naivasha', 'Karagita', '010', 8, 4, 4], [$listing->status, $listing->city, $listing->neighbourhood, $listing->listing_code, (int) $listing->max_guests, (int) $listing->bedrooms, (int) $listing->bathrooms]);
        $this->assertSame(18000.0, (float) $listing->starting_price);
        $this->assertSame(['wifi', 'balcony'], json_decode($listing->amenities, true));
        $this->assertSame(['guest_favourite'], json_decode($listing->collections, true));
        $this->assertSame([-0.7612, 36.4103], [(float) $listing->latitude, (float) $listing->longitude]);
        $this->assertSame('Wake up to views of the lake from every bedroom.', $listing->description, 'Form shortcodes are removed.');
        $photos = DB::table('public_listing_photos')->where('listing_id', $listing->id)->get();
        $this->assertCount(1, $photos, 'Unreadable photos are skipped.');
        Storage::disk('local')->assertExists($photos[0]->storage_key);

        $partner = DB::connection('tenant')->table('partner_apartments')->where('id', $listing->partner_apartment_id)->first();
        $this->assertSame([ImportWebsiteProperties::OWNER_PLACEHOLDER, 'Karagita', 4, 'active'], [$partner->owner_name, $partner->location, (int) $partner->bedrooms, $partner->status]);
        $this->assertStringContainsString('https://havensuitesbnb.com/property/panoramic-views-4br-naivasha-010/', $partner->notes);

        $villa = DB::table('public_listings')->where('slug', 'quiet-villa-nanyuki-014')->first();
        $this->assertSame(['draft', 'Nanyuki', 'villa', null], [$villa->status, $villa->city, $villa->property_type, $villa->starting_price], 'No photos from the own site: kept as a draft, price on request.');

        $this->assertSame('Nairobi', DB::table('public_listings')->where('slug', 'haven-suites-1br-danaff-towers')->value('city'), 'A known area in the title gives the city.');

        $this->get('/stays/props-stays/panoramic-views-4br-naivasha-010')->assertOk()->assertSee('Panoramic views 4BR, Naivasha – 010')->assertSee('KES 18,000');
        $this->get('/stays/props-stays')->assertOk()->assertSee('Panoramic views 4BR, Naivasha – 010');

        $this->artisan('haven:import-website-properties', ['tenant' => 'props-stays', '--publish' => true])->expectsOutputToContain('Nothing new to import.')->assertSuccessful();
        $this->assertSame(3, DB::connection('tenant')->table('partner_apartments')->count(), 'Running again adds nothing.');
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 90, 140, 200));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
