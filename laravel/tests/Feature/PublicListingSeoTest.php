<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ListingAvailability;
use App\Services\PublicEnquiries;
use App\Services\PublicListingCatalog;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicListingSeoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00:00', 'UTC'));
        Http::preventStrayRequests();
        config(['app.url' => 'http://localhost']);
        $this->company = Tenant::factory()->create(['slug' => 'seo-stays', 'name' => 'Haven Suites', 'active' => true]);
        $this->mock(ListingAvailability::class)->shouldReceive('calendar')->andReturn([
            'blocked' => [], 'fresh' => true, 'last_synced_at' => null, 'requires_confirmation' => true,
        ]);
        $this->mock(PublicEnquiries::class)->shouldReceive('formContext')->andReturn([]);
    }

    private function listing(array $overrides = []): array
    {
        return array_replace([
            'id' => 10, 'unit_id' => 20, 'partner_apartment_id' => null, 'inventory_kind' => 'managed',
            'slug' => 'coral-gables-f5', 'title' => 'Sea View Retreat', 'description' => 'A peaceful two bedroom apartment near the beach with a bright balcony and comfortable living room.',
            'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'public_location' => 'Near Nyali Beach',
            'bedrooms' => 2, 'bathrooms' => 2, 'max_guests' => 4, 'min_nights' => 2,
            'check_in_time' => '14:00', 'check_out_time' => '10:00', 'starting_price' => '12000.00', 'currency' => 'KES',
            'amenities' => ['wifi'], 'amenity_labels' => ['Wi-Fi'], 'house_rules' => 'No indoor smoking.', 'featured' => true,
            'updated_at' => '2026-11-06 09:00:00', 'videos' => [],
            'photos' => [
                ['id' => 1, 'url' => 'http://localhost/stays/seo-stays/coral-gables-f5/photos/1', 'caption' => 'Sunny apartment balcony'],
                ['id' => 2, 'url' => 'http://localhost/stays/seo-stays/coral-gables-f5/photos/2', 'caption' => 'Comfortable living room'],
            ],
        ], $overrides);
    }

    private function catalogue(array $listings): void
    {
        $site = (object) ['headline' => 'Find your Haven', 'tagline' => 'Comfortable apartments, thoughtful hosting.', 'contact_email' => null, 'contact_phone' => null];
        $catalogue = $this->mock(PublicListingCatalog::class);
        $catalogue->shouldReceive('site')->andReturn($site);
        $catalogue->shouldReceive('settings')->andReturn($site);
        $catalogue->shouldReceive('published')->andReturn(collect($listings));
        if ($listings !== []) {
            $catalogue->shouldReceive('findPublished')->andReturn($listings[0]);
            $catalogue->shouldReceive('preview')->andReturn($listings[0]);
        }
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }

    private function meta(DOMXPath $document, string $name): ?string
    {
        $node = $document->query('//head/meta[@name="'.$name.'" or @property="'.$name.'"]')->item(0);

        return $node?->getAttribute('content');
    }

    public function test_listing_metadata_is_unique_plain_text_and_social_links_use_the_clean_canonical(): void
    {
        $this->catalogue([$this->listing([
            'title' => 'Sea View <b>Retreat</b>',
            'description' => '<script>PRIVATE-TRACKER()</script><p>A bright &amp; peaceful apartment.</p><p>Enjoy the balcony.</p>',
        ])]);
        $response = $this->get('/stays/seo-stays/coral-gables-f5?utm_source=social&check_in=2026-11-20')->assertOk();
        $document = $this->document($response->getContent());
        $canonical = 'http://localhost/stays/seo-stays/coral-gables-f5';
        $this->assertSame($canonical, $document->query('//head/link[@rel="canonical"]')->item(0)->getAttribute('href'));
        $this->assertSame('Sea View Retreat | Nyali, Mombasa | Haven Suites', $document->query('//head/title')->item(0)->textContent);
        $this->assertSame('A bright & peaceful apartment. Enjoy the balcony.', $this->meta($document, 'description'));
        $this->assertSame($canonical, $this->meta($document, 'og:url'));
        $this->assertSame('website', $this->meta($document, 'og:type'));
        $this->assertSame('Haven Suites', $this->meta($document, 'og:site_name'));
        $this->assertSame('summary_large_image', $this->meta($document, 'twitter:card'));
        $this->assertSame('Sunny apartment balcony', $this->meta($document, 'og:image:alt'));
        $this->assertSame($this->meta($document, 'og:image'), $this->meta($document, 'twitter:image'));
        $this->assertSame('index, follow, max-image-preview:large', $this->meta($document, 'robots'));
        $this->assertNull($this->meta($document, 'og:image:width'));
        Http::assertNothingSent();
    }

    public function test_structured_data_describes_visible_accommodation_breadcrumbs_and_images_without_fabricated_video_or_reviews(): void
    {
        $this->catalogue([$this->listing(['videos' => [[
            'video_id' => 'abcdefghijk', 'title' => 'Apartment walkthrough',
            'watch_url' => 'https://www.youtube.com/watch?v=abcdefghijk',
            'embed_url' => 'https://www.youtube-nocookie.com/embed/abcdefghijk',
            'thumbnail_url' => 'https://i.ytimg.com/vi/abcdefghijk/hqdefault.jpg',
        ]]])]);
        $response = $this->get('/stays/seo-stays/coral-gables-f5')->assertOk();
        $document = $this->document($response->getContent());
        $json = $document->query('//head/script[@type="application/ld+json"]')->item(0)->textContent;
        $schema = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://schema.org', $schema['@context']);
        $nodes = collect($schema['@graph'])->groupBy('@type');
        $this->assertSame('Sea View Retreat', $nodes['LodgingBusiness'][0]['name']);
        $this->assertSame('Mombasa', $nodes['LodgingBusiness'][0]['address']['addressLocality']);
        $this->assertSame('Wi-Fi', $nodes['LodgingBusiness'][0]['amenityFeature'][0]['name']);
        $this->assertCount(2, $nodes['ImageObject']);
        $this->assertSame('Sunny apartment balcony', $nodes['ImageObject'][0]['caption']);
        $this->assertSame('http://localhost/stays/seo-stays', $nodes['BreadcrumbList'][0]['itemListElement'][0]['item']);
        $this->assertStringNotContainsString('VideoObject', $json);
        $this->assertStringNotContainsString('aggregateRating', $json);
        $this->assertStringNotContainsString('Offer', $json);
        $this->assertStringNotContainsString('uploadDate', $json);
        Http::assertNothingSent();
    }

    public function test_filter_search_pages_are_noindex_and_tracking_only_catalogue_requests_remain_indexable(): void
    {
        $this->catalogue([$this->listing()]);
        $response = $this->get('/stays/seo-stays?q=Nyali&utm_source=social')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
        $document = $this->document($response->getContent());
        $this->assertSame('noindex, follow', $this->meta($document, 'robots'));
        $this->assertSame('http://localhost/stays/seo-stays', $document->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'));
        $this->get('/stays/seo-stays?bedrooms=0')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');

        $response = $this->get('/stays/seo-stays?utm_source=social')->assertOk()->assertHeaderMissing('X-Robots-Tag');
        $this->assertSame('index, follow, max-image-preview:large', $this->meta($this->document($response->getContent()), 'robots'));
    }

    public function test_unfiltered_pagination_has_its_own_canonical_and_missing_pages_return_not_found(): void
    {
        $listings = array_map(fn (int $id): array => $this->listing(['id' => $id, 'slug' => 'apartment-'.$id, 'title' => 'Apartment '.$id]), range(1, 14));
        $this->catalogue($listings);
        $response = $this->get('/stays/seo-stays?page=2&utm_campaign=stay')->assertOk();
        $document = $this->document($response->getContent());
        $this->assertSame('http://localhost/stays/seo-stays?page=2', $document->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'));
        $this->assertSame('index, follow, max-image-preview:large', $this->meta($document, 'robots'));
        $this->get('/stays/seo-stays?page=3')->assertNotFound();
    }

    public function test_private_previews_do_not_advertise_staff_photo_urls_or_structured_data(): void
    {
        $this->catalogue([$this->listing(['photos' => [[
            'id' => 1, 'url' => 'http://localhost/t/seo-stays/public-listings/10/photos/1', 'caption' => 'Private preview photo',
        ]]])]);
        $admin = User::factory()->create(['active' => true]);
        $admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($admin);

        $response = $this->get('/t/seo-stays/public-listings/10/preview')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $document = $this->document($response->getContent());
        $this->assertSame('noindex, nofollow', $this->meta($document, 'robots'));
        $this->assertNull($this->meta($document, 'og:image'));
        $this->assertNull($this->meta($document, 'twitter:image'));
        $this->assertSame(0, $document->query('//head/script[@type="application/ld+json"]')->length);
    }

    public function test_sitemap_uses_actual_modification_dates_and_public_photo_urls(): void
    {
        $this->catalogue([
            $this->listing(),
            $this->listing(['id' => 11, 'slug' => 'unknown-date', 'updated_at' => 'invalid date', 'photos' => []]),
            $this->listing(['id' => 12, 'slug' => 'future-date', 'updated_at' => '2099-01-01', 'photos' => []]),
        ]);
        $response = $this->get('/stays/seo-stays/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($response->getContent()));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xpath->registerNamespace('image', 'http://www.google.com/schemas/sitemap-image/1.1');
        $this->assertSame(6, $xpath->query('//s:url')->length, 'Catalogue, About us, FAQ and the published listings.');
        $this->assertSame(1, $xpath->query('//s:url/s:loc[contains(., "/about")]')->length);
        $this->assertSame(1, $xpath->query('//s:url/s:loc[contains(., "/faq")]')->length);
        $this->assertSame(1, $xpath->query('//s:lastmod')->length);
        $this->assertSame(2, $xpath->query('//image:image/image:loc')->length);
        $this->assertStringContainsString('2026-11-06', $xpath->query('//s:lastmod')->item(0)->textContent);
        $this->assertStringNotContainsString('/t/', $response->getContent());

    }
}
