<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\ListingAvailability;
use App\Services\PublicEnquiries;
use App\Services\PublicListingCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Pages for the kind of stay guests search for in a city, such as sea view or 2-bedroom apartments. */
class PublicStayTopicsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'));
        Http::preventStrayRequests();
        config(['app.url' => 'http://localhost']);
        Tenant::factory()->create(['slug' => 'topic-stays', 'name' => 'Haven Suites', 'active' => true]);
        $this->mock(ListingAvailability::class)->shouldReceive('calendar')->andReturn(['blocked' => [], 'fresh' => true, 'last_synced_at' => null, 'requires_confirmation' => true]);
        $this->mock(PublicEnquiries::class)->shouldReceive('formContext')->andReturn([]);
        $site = (object) ['headline' => 'Find your Haven', 'tagline' => 'Comfortable apartments.', 'contact_email' => null, 'contact_phone' => null];
        $catalogue = $this->mock(PublicListingCatalog::class)->shouldIgnoreMissing();
        $catalogue->shouldReceive('site')->andReturn($site);
        $catalogue->shouldReceive('settings')->andReturn($site);
        $catalogue->shouldReceive('published')->andReturn(collect([
            $this->listing(1, 'Coral Gables F2', 'Mombasa', 'Nyali', 2, 15000, ['sea_view'], []),
            $this->listing(2, 'Third Avenue Q103', 'Mombasa', 'Nyali', 3, 18000, [], ['sea_view', 'pool']),
            $this->listing(3, 'Bamburi Garden Flat', 'Mombasa', 'Bamburi', 2, 9000, [], []),
            $this->listing(4, 'Pinecrest View', 'Nairobi', 'Kilimani', 2, 8000, ['sea_view'], []),
        ]));
    }

    private function listing(int $id, string $title, string $city, string $area, int $bedrooms, int $price, array $collections, array $amenities): array
    {
        return [
            'id' => $id, 'unit_id' => $id, 'partner_apartment_id' => null, 'inventory_kind' => 'managed', 'slug' => Str::slug($title), 'title' => $title,
            'description' => 'A comfortable apartment.', 'city' => $city, 'neighbourhood' => $area, 'public_location' => null, 'bedrooms' => $bedrooms, 'bathrooms' => 1,
            'max_guests' => 4, 'min_nights' => 1, 'check_in_time' => '14:00', 'check_out_time' => '10:00', 'starting_price' => (string) $price, 'currency' => 'KES',
            'amenities' => $amenities, 'amenity_labels' => [], 'collections' => $collections, 'collection_labels' => [], 'house_rules' => null, 'featured' => false,
            'updated_at' => '2026-11-17 09:00:00', 'videos' => [], 'photos' => [['id' => $id, 'url' => 'http://localhost/photos/'.$id, 'caption' => 'Living room']],
        ];
    }

    public function test_a_city_topic_page_lists_matching_stays_cheapest_first_with_its_own_title(): void
    {
        $html = $this->get('/stays/topic-stays/in/mombasa/sea-view-apartments')->assertOk()
            ->assertSee('<title>Sea view apartments in Mombasa | Haven Suites</title>', false)
            ->assertSee('<h1>Sea view apartments in Mombasa</h1>', false)
            ->assertSee('2 sea view apartments in Mombasa from KES 15,000 a night', false)
            ->assertSee('rel="canonical" href="http://localhost/stays/topic-stays/in/mombasa/sea-view-apartments"', false)
            ->assertSee('"name":"Sea view apartments"', false)
            ->assertDontSee('Bamburi Garden Flat')->assertDontSee('Pinecrest View')
            ->assertSee('2-bedroom apartments <span>2</span>', false)
            ->getContent();
        $this->assertLessThan(strpos($html, 'Third Avenue Q103'), strpos($html, 'Coral Gables F2'), 'Cheapest first.');

        $this->get('/stays/topic-stays/in/mombasa/2-bedroom-apartments')->assertOk()->assertSee('Coral Gables F2')->assertSee('Bamburi Garden Flat')->assertDontSee('Third Avenue Q103');
    }

    public function test_topics_with_too_few_stays_do_not_get_a_page_and_city_pages_link_to_the_rest(): void
    {
        $this->get('/stays/topic-stays/in/mombasa/apartments-with-pool')->assertNotFound();
        $this->get('/stays/topic-stays/in/nairobi/sea-view-apartments')->assertNotFound();
        $this->get('/stays/topic-stays/in/mombasa')->assertOk()->assertSee('Popular in Mombasa')
            ->assertSee('/in/mombasa/sea-view-apartments', false)->assertDontSee('/in/mombasa/apartments-with-pool', false);
        $this->get('/stays/topic-stays/in/mombasa/nyali')->assertOk()->assertSee('Stays in Nyali, Mombasa');

        $sitemap = $this->get('/stays/topic-stays/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/in/mombasa/sea-view-apartments</loc>', $sitemap);
        $this->assertStringContainsString('/in/mombasa/2-bedroom-apartments</loc>', $sitemap);
        $this->assertStringNotContainsString('apartments-with-pool', $sitemap);
    }
}
