<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\ListingAvailability;
use App\Services\PublicEnquiries;
use App\Services\PublicListingCatalog;
use App\Services\PublicWebsiteLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicWebsiteIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private object $site;

    private array $listing;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 11, 7)->startOfDay());
        config(['app.url' => 'https://app.havensuitesbnb.com']);
        $this->tenant = Tenant::factory()->create(['name' => 'Haven Suites', 'slug' => 'website-integration', 'active' => true,
            'timezone' => 'Africa/Nairobi', 'currency' => 'KES']);
        $this->site = (object) [
            'tenant_id' => $this->tenant->id,
            'enabled' => true, 'headline' => 'Find your next stay', 'tagline' => 'A thoughtful welcome.',
            'contact_email' => 'public@example.test', 'contact_phone' => '+254700000001',
            'seo_title' => 'Haven holiday apartments', 'seo_description' => 'Explore comfortable apartments and enquire directly with our team.',
            'whatsapp_number' => '+254700000002', 'whatsapp_message' => 'Hello, I would like to enquire.',
            'navigation_links' => json_encode([['label' => 'Home', 'target' => 'home'], ['label' => 'Our apartments', 'target' => 'stays'], ['label' => 'Contact', 'target' => 'contact']]),
        ];
        $this->listing = [
            'id' => 17, 'unit_id' => 557, 'partner_apartment_id' => null, 'inventory_kind' => 'managed',
            'slug' => 'coral-gables-f2', 'title' => 'Coral Gables / F2', 'description' => 'A bright seaside apartment with comfortable bedrooms and a balcony close to the beach.',
            'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'public_location' => 'Near Nyali Beach',
            'bedrooms' => 2, 'bathrooms' => 2, 'max_guests' => 4, 'min_nights' => 1, 'starting_price' => 8500,
            'currency' => 'KES', 'check_in_time' => '14:00', 'check_out_time' => '10:00', 'house_rules' => 'No smoking indoors.',
            'amenities' => ['wifi'], 'amenity_labels' => ['Wi-Fi'], 'featured' => false, 'updated_at' => now()->toDateTimeString(),
            'seo_title' => 'Seaview apartment F2 | Haven', 'seo_description' => 'Plan your seaside stay in this comfortable Nyali apartment.',
            'whatsapp_number' => null, 'whatsapp_message' => null, 'videos' => [],
            'photos' => [['id' => 1, 'url' => 'https://app.havensuitesbnb.com/photo.jpg', 'caption' => 'Living room and balcony']],
        ];
        $this->mock(PublicListingCatalog::class, function ($mock): void {
            $mock->shouldReceive('site')->andReturnUsing(fn (): object => $this->site);
            $mock->shouldReceive('published')->andReturnUsing(fn () => collect([$this->listing]));
            $mock->shouldReceive('findPublished')->andReturnUsing(fn (): array => $this->listing);
            $mock->shouldReceive('destinations')->andReturnUsing(fn () => [['city' => 'Mombasa', 'count' => 1, 'areas' => [['name' => 'Nyali', 'count' => 1]]]]);
            $mock->shouldReceive('settings')->andReturnUsing(fn (): object => $this->site);
        });
        $this->mock(ListingAvailability::class, function ($mock): void {
            $mock->shouldReceive('calendar')->andReturn(['blocked' => [], 'fresh' => true, 'last_synced_at' => null, 'requires_confirmation' => true]);
            $mock->shouldReceive('availabilityForListings')->andReturn([17 => ['available' => true, 'fresh' => true]]);
        });
        $this->mock(PublicEnquiries::class)->shouldReceive('formContext')->andReturn([]);
    }

    public function test_saved_site_metadata_and_menu_are_used_on_the_public_catalogue(): void
    {
        $response = $this->get('/stays/'.$this->tenant->slug);
        $response->assertOk()->assertSee('<title>Haven holiday apartments</title>', false)
            ->assertSee('content="Explore comfortable apartments and enquire directly with our team."', false)
            ->assertSee('Our apartments');
        $response->assertViewHas('websiteMenu', fn (array $menu): bool => $menu[1]['label'] === 'Our apartments'
            && str_ends_with($menu[1]['url'], '/stays/'.$this->tenant->slug.'#find-stay'));
        $response->assertViewHas('websiteContactLinks', fn (array $links): bool => str_starts_with($links['whatsapp_url'], 'https://wa.me/254700000002?'));
        $this->assertDatabaseCount('sales_leads', 0);
        $this->assertDatabaseCount('manual_sms_messages', 0);
        Http::assertNothingSent();
    }

    public function test_listing_enquiry_links_preserve_dates_and_carry_listing_identity_to_whatsapp(): void
    {
        $response = $this->get('/stays/'.$this->tenant->slug.'/coral-gables-f2?check_in=2026-11-10&check_out=2026-11-12&guests=3&utm_source=campaign');
        $response->assertOk()->assertSee('<title>Seaview apartment F2 | Haven</title>', false)
            ->assertSee('content="Plan your seaside stay in this comfortable Nyali apartment."', false);
        $links = $response->viewData('publicLinks');
        $this->assertSame('https://app.havensuitesbnb.com/stays/'.$this->tenant->slug.'/coral-gables-f2?check_in=2026-11-10&check_out=2026-11-12&guests=3#enquire', $links['enquiry_url']);
        $this->assertStringNotContainsString('utm_source', $links['public_url']);
        parse_str(parse_url($links['whatsapp_url'], PHP_URL_QUERY), $query);
        $this->assertStringContainsString($this->listing['title'], $query['text']);
        $this->assertStringContainsString($links['public_url'], $query['text']);
        $this->assertStringContainsString("Dates: Tue 10 Nov 2026 – Thu 12 Nov 2026 (2 nights)\nGuests: 3", $query['text']);
        $this->assertSame('254700000002', $links['whatsapp_number']);
        $response->assertSee('Enquire on WhatsApp instead', false)->assertSee('data-whatsapp-enquiry', false)->assertSee('data-wa-number="254700000002"', false);
        $this->assertSame(['check_in' => '2026-11-10', 'check_out' => '2026-11-12', 'guests' => 3], $response->viewData('enquiryDefaults'));
        $response->assertSee('id="enquiry-phone"', false)->assertSee('value="+254 "', false);
        $this->assertDatabaseCount('sales_leads', 0);
        Http::assertNothingSent();
    }

    public function test_catalogue_card_enquiry_links_keep_the_selected_stay_preferences(): void
    {
        $response = $this->get('/stays/'.$this->tenant->slug.'?check_in=2026-11-10&check_out=2026-11-12&guests=3');
        $response->assertOk();
        $stay = $response->viewData('stays')->first();
        $this->assertStringEndsWith('?check_in=2026-11-10&check_out=2026-11-12&guests=3#enquire', $stay['links']['enquiry_url']);
        $this->assertSame(17, $stay['id']);
    }

    public function test_malformed_query_prefills_cannot_crash_the_listing_or_enter_its_enquiry_link(): void
    {
        $response = $this->get('/stays/'.$this->tenant->slug.'/coral-gables-f2?check_in[]=bad&check_out=2026-02-31&guests[]=3');
        $response->assertOk()->assertViewHas('enquiryDefaults', []);
        $this->assertSame('https://app.havensuitesbnb.com/stays/'.$this->tenant->slug.'/coral-gables-f2#enquire', $response->viewData('publicLinks')['enquiry_url']);
    }

    public function test_empty_metadata_overrides_use_the_existing_descriptive_defaults(): void
    {
        $this->site->seo_title = null;
        $this->site->seo_description = null;
        $this->listing['seo_title'] = null;
        $this->listing['seo_description'] = null;
        $this->get('/stays/'.$this->tenant->slug)->assertOk()->assertSee('<title>Haven Suites | Find your next stay</title>', false);
        $response = $this->get('/stays/'.$this->tenant->slug.'/coral-gables-f2');
        $response->assertOk()->assertSee('Coral Gables / F2 | Nyali, Mombasa | Haven Suites');
        $this->assertDatabaseCount('sales_leads', 0);
    }

    public function test_whatsapp_stay_lines_include_code_and_ignore_invalid_dates(): void
    {
        $this->assertSame(['Code: A19', 'Dates: Sat 12 Dec 2026 – Tue 15 Dec 2026 (3 nights)', 'Guests: 2'],
            PublicWebsiteLinks::stayLines('A19', ['check_in' => '2026-12-12', 'check_out' => '2026-12-15', 'guests' => 2]));
        $this->assertSame(['Check-in: Sat 12 Dec 2026'], PublicWebsiteLinks::stayLines(null, ['check_in' => '2026-12-12', 'check_out' => '2026-12-10']));
        $this->assertSame([], PublicWebsiteLinks::stayLines('', ['check_in' => '2026-02-31', 'check_out' => 'tomorrow', 'guests' => 'many']));
    }
}
