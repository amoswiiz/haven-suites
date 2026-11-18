<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\ListingAvailability;
use App\Services\PublicEnquiries;
use App\Services\PublicListingCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Guests see why booking direct beats booking sites, and the company can measure visits and enquiries. */
class PublicDirectBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'));
        Http::preventStrayRequests();
        Tenant::factory()->create(['slug' => 'direct-stays', 'name' => 'Haven Suites', 'active' => true]);
        $this->mock(ListingAvailability::class)->shouldReceive('calendar')->andReturn(['blocked' => [], 'fresh' => true, 'last_synced_at' => null, 'requires_confirmation' => true]);
        $this->mock(PublicEnquiries::class)->shouldReceive('formContext')->andReturn([]);
    }

    private function catalogue(array $site, array $listing): void
    {
        $site = (object) ($site + ['headline' => 'Find your Haven', 'tagline' => 'Comfortable apartments.', 'contact_email' => null, 'contact_phone' => null]);
        $listing += [
            'id' => 10, 'unit_id' => 20, 'partner_apartment_id' => null, 'inventory_kind' => 'managed', 'slug' => 'coral-gables-f2', 'title' => 'Coral Gables F2',
            'description' => 'A bright two bedroom apartment near the beach with a balcony and a comfortable living room.', 'city' => 'Mombasa', 'neighbourhood' => 'Nyali',
            'public_location' => null, 'bedrooms' => 2, 'bathrooms' => 2, 'max_guests' => 4, 'min_nights' => 1, 'check_in_time' => '14:00', 'check_out_time' => '10:00',
            'starting_price' => '15000.00', 'currency' => 'KES', 'amenities' => [], 'amenity_labels' => [], 'house_rules' => null, 'featured' => false,
            'updated_at' => '2026-11-17 09:00:00', 'videos' => [], 'listing_code' => 'F2',
            'photos' => [['id' => 1, 'url' => 'http://localhost/stays/direct-stays/coral-gables-f2/photos/1', 'caption' => 'Living room']],
        ];
        $catalogue = $this->mock(PublicListingCatalog::class)->shouldIgnoreMissing();
        $catalogue->shouldReceive('site')->andReturn($site);
        $catalogue->shouldReceive('settings')->andReturn($site);
        $catalogue->shouldReceive('published')->andReturn(collect([$listing]));
        $catalogue->shouldReceive('findPublished')->andReturn($listing);
    }

    public function test_listings_show_the_saving_against_booking_sites_and_the_perks_of_booking_direct(): void
    {
        $this->catalogue(['direct_perks' => "No booking-site service fees\n\nFree late checkout when available"], ['ota_price' => 17500.0]);

        $this->get('/stays/direct-stays/coral-gables-f2')->assertOk()
            ->assertSee('Save KES 2,500 a night')->assertSee('14% less than booking sites')
            ->assertSee('No booking-site service fees')->assertSee('Free late checkout when available');
        $this->get('/stays/direct-stays')->assertOk()->assertSee('Save KES 2,500 a night booking direct');
    }

    public function test_no_saving_is_claimed_when_the_booking_site_price_is_not_higher(): void
    {
        $this->catalogue([], ['ota_price' => 15200.0]);

        $this->get('/stays/direct-stays/coral-gables-f2')->assertOk()->assertDontSee('Save KES')->assertDontSee('Why book direct', false);
        $this->get('/stays/direct-stays')->assertOk()->assertDontSee('booking direct');
    }

    public function test_tracking_is_added_for_guests_only_and_records_the_listing_viewed(): void
    {
        $this->catalogue(['ga_measurement_id' => 'G-TEST12345', 'meta_pixel_id' => '123456789012345'], []);

        $this->get('/stays/direct-stays/coral-gables-f2')->assertOk()
            ->assertSee('googletagmanager.com/gtag/js?id=G-TEST12345', false)->assertSee("fbq('init',\"123456789012345\")", false)
            ->assertSee("havenTrack('view_item'", false)->assertSee('"Coral Gables F2"', false)->assertSee('whatsapp_click', false);

        $this->actingAs(User::factory()->create(['active' => true]));
        $this->get('/stays/direct-stays/coral-gables-f2')->assertOk()->assertDontSee('googletagmanager.com', false)->assertDontSee('fbq(', false);
    }

    public function test_privacy_and_data_deletion_pages_explain_social_messages_and_how_to_ask_for_deletion(): void
    {
        $this->catalogue(['contact_email' => 'hello@example.test'], []);

        $this->get('/stays/direct-stays/privacy')->assertOk()->assertSee('<title>Privacy policy | Haven Suites</title>', false)
            ->assertSee('Messages on social media')->assertSee('Kenya Data Protection Act')->assertSee('/stays/direct-stays/data-deletion', false);
        $this->get('/stays/direct-stays/data-deletion')->assertOk()->assertSee('How to have your data deleted')
            ->assertSee('mailto:hello@example.test', false)->assertSee('within 30 days');
        $this->get('/stays/direct-stays')->assertOk()->assertSee('/stays/direct-stays/privacy', false);
    }

    public function test_no_tracking_code_without_an_id(): void
    {
        $this->catalogue([], []);

        $this->get('/stays/direct-stays/coral-gables-f2')->assertOk()->assertDontSee('googletagmanager.com', false)->assertDontSee('havenTrack', false);
    }
}
