<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\ListingAvailability;
use App\Services\PublicEnquiries;
use App\Services\PublicListingCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The company website on its own domain: clean addresses, links on that domain and redirects from old addresses. */
class PublicSiteDomainTest extends TestCase
{
    use RefreshDatabase;

    private const ENV = ['PUBLIC_SITE_DOMAIN' => 'havensuites.test', 'PUBLIC_SITE_COMPANY' => 'domain-stays'];

    private string $databaseFile;

    public function createApplication(): Application
    {
        // Routes for the domain are registered while the app boots, so the setting must exist before then.
        foreach (self::ENV as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['app.url' => 'https://app.havensuites.test']);
        $this->databaseFile = (string) tempnam(sys_get_temp_dir(), 'haven_domain_');
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $this->databaseFile, 'prefix' => '', 'foreign_key_constraints' => true]]);
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
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Coral Gables']);
        DB::connection('tenant')->table('units')->insert(['id' => 1, 'property_id' => 1, 'unit_number' => 'F5', 'bedrooms' => 2, 'bathrooms' => 2, 'status' => 'active', 'nightly_rate' => '10000.00']);
        $company = Tenant::factory()->create(['name' => 'Haven Suites', 'slug' => 'domain-stays', 'database_name' => $this->databaseFile, 'active' => true, 'currency' => 'KES']);
        $admin = User::factory()->create(['active' => true]);
        DB::table('public_sites')->insert(['tenant_id' => $company->id, 'enabled' => true, 'enquiry_assignee_id' => $admin->id, 'response_hours' => 2, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $listingId = DB::table('public_listings')->insertGetId(['tenant_id' => $company->id, 'unit_id' => 1, 'slug' => 'sea-view', 'title' => 'Sea View Retreat',
            'description' => 'A bright two bedroom apartment near the beach.', 'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'max_guests' => 4, 'min_nights' => 1,
            'check_in_time' => '12:00', 'check_out_time' => '10:00', 'starting_price' => '12000.00', 'status' => 'published', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $photo = 'listing-photos/'.$company->id.'/'.$listingId.'/'.str_repeat('a', 48).'.jpg';
        Storage::disk('local')->put($photo, 'jpeg');
        DB::table('public_listing_photos')->insert(['tenant_id' => $company->id, 'listing_id' => $listingId, 'storage_key' => $photo, 'mime_type' => 'image/jpeg', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('public_posts')->insert(['tenant_id' => $company->id, 'slug' => 'business-accommodation', 'title' => 'Business accommodation in Kenya', 'status' => 'published',
            'audience' => 'guests', 'published_at' => now()->subDay(), 'body' => 'Quiet rooms and fast Wi-Fi for business stays.', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->mock(ListingAvailability::class)->shouldReceive('calendar')->andReturn(['blocked' => [], 'fresh' => true, 'last_synced_at' => null, 'requires_confirmation' => true]);
        $this->mock(PublicEnquiries::class)->shouldReceive('formContext')->andReturn([]);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        @unlink($this->databaseFile);
        parent::tearDown();
        foreach (array_keys(self::ENV) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function test_pages_are_served_on_the_domain_with_links_there(): void
    {
        $home = $this->get('http://havensuites.test/')->assertOk()->assertSee('Sea View Retreat')
            ->assertSee('<link rel="canonical" href="https://havensuites.test/">', false)
            ->assertSee('href="https://havensuites.test/sea-view"', false)
            ->assertSee('href="https://havensuites.test/blog"', false);
        $this->assertStringNotContainsString('/stays/domain-stays', $home->getContent(), 'No link points at the old app address.');

        $this->get('http://havensuites.test/sea-view')->assertOk()->assertSee('Sea View Retreat')
            ->assertSee('action="https://havensuites.test/sea-view/enquire"', false);
        $this->get('http://havensuites.test/blog/business-accommodation')->assertOk()->assertSee('Business accommodation in Kenya')
            ->assertSee('<link rel="canonical" href="https://havensuites.test/blog/business-accommodation">', false);
        $this->get('http://havensuites.test/list-your-property')->assertOk()->assertSee('action="https://havensuites.test/list-your-property"', false);
        $this->get('http://havensuites.test/sitemap.xml')->assertOk()->assertSee('<loc>https://havensuites.test/sea-view</loc>', false);
        $this->get('http://havensuites.test/sea-view/photos/1?w=480')->assertOk();

        $this->assertSame('https://app.havensuites.test/stays/other-company', route('public-stays.index', 'other-company'), 'Other companies stay on the app address.');
        $this->assertSame('https://app.havensuites.test/login', route('login'), 'Staff pages are linked on the app address.');
        $this->get('http://havensuites.test/')->assertSee('href="https://app.havensuites.test/login"', false);
    }

    public function test_listing_pages_carry_full_search_details(): void
    {
        $page = $this->get('http://havensuites.test/sea-view')->assertOk()
            ->assertSee('<title>Sea View Retreat – 2 bedrooms in Nyali, Mombasa | Haven Suites</title>', false)
            ->assertSee('<meta name="description" content="2 bedrooms in Nyali, Mombasa for up to 4 guests, from KES 12,000 a night. A bright two bedroom apartment near the beach.">', false)
            ->assertSee('alt="Sea View Retreat – photo 1, Nyali"', false);
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $page->getContent(), $match);
        $graph = json_decode($match[1] ?? '{}', true)['@graph'] ?? [];
        $rental = collect($graph)->firstWhere('@type', 'VacationRental');
        $this->assertNotNull($rental, 'The listing is described as a vacation rental.');
        $this->assertSame(['12:00:00', '10:00:00', 2, 4, 'KE', 'From KES 12,000 per night'], [$rental['checkinTime'], $rental['checkoutTime'],
            $rental['containsPlace']['numberOfBedrooms'], $rental['containsPlace']['occupancy']['value'], $rental['address']['addressCountry'], $rental['priceRange']]);
        $crumbs = collect($graph)->firstWhere('@type', 'BreadcrumbList')['itemListElement'];
        $this->assertSame(['https://havensuites.test/', 'https://havensuites.test/in/mombasa', 'https://havensuites.test/sea-view'], array_column($crumbs, 'item'));

        DB::table('public_listings')->where('slug', 'sea-view')->update(['seo_title' => 'Beach flat', 'seo_description' => 'Steps from the sand https://maps.app.goo.gl/abc']);
        PublicListingCatalog::forget(Tenant::where('slug', 'domain-stays')->firstOrFail()->id);
        $this->get('http://havensuites.test/sea-view')
            ->assertSee('<title>Beach flat – 2 bedrooms in Nyali, Mombasa | Haven Suites</title>', false)
            ->assertSee('from KES 12,000 a night. Steps from the sand">', false);

        $this->get('http://havensuites.test/robots.txt')->assertOk()->assertSee('Sitemap: https://havensuites.test/sitemap.xml');
    }

    public function test_old_app_and_wordpress_addresses_redirect_permanently(): void
    {
        $this->get('https://app.havensuites.test/stays/domain-stays/blog/business-accommodation?utm_source=sms')
            ->assertStatus(301)->assertRedirect('https://havensuites.test/blog/business-accommodation?utm_source=sms');
        $this->get('https://app.havensuites.test/stays/domain-stays')->assertStatus(301)->assertRedirect('https://havensuites.test/');
        $this->get('http://www.havensuites.test/blog?page=2')->assertStatus(301)->assertRedirect('https://havensuites.test/blog?page=2');

        $old = [
            '/business-accommodation/' => '/blog/business-accommodation',
            '/about-haven-suites/' => '/about',
            '/contact/' => '#stay-contact',
            '/travel-tips/' => '/blog',
            '/become-a-host/' => '/list-your-property',
            '/property/sea_view/' => '/sea-view',
            '/property/beachfront-villa-2br-mombasa-001/' => '/in/mombasa',
            '/property/lakeview-4br-naivasha-005/' => '#find-stay',
            '/city/mombasa/' => '/in/mombasa',
            '/label/sea-view/' => '?collection=sea_view#find-stay',
            '/status/new-listing/' => '#find-stay',
            '/agency/haven-suites-bnb/' => '/list-your-property',
            '/category/travel/' => '/blog',
        ];
        foreach ($old as $from => $to) {
            $this->get('http://havensuites.test'.$from)->assertStatus(301)->assertRedirect('https://havensuites.test'.$to);
        }
        $this->get('http://havensuites.test/no-such-page')->assertNotFound();
    }
}
