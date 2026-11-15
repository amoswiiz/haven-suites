<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\GuestMessageTemplates;
use App\Services\HomepageLayout;
use App\Services\ListingAvailability;
use App\Services\ListingDetails;
use App\Services\ListingPhotoVariants;
use App\Services\PublicEnquiries;
use App\Services\PublicListingCatalog;
use App\Services\PublicReviews;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GuestTrustTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    private int $listingId;

    private ?string $databaseFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => 'http://localhost']);
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'haven_trust_');
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
        $this->company = Tenant::factory()->create(['name' => 'Haven Suites', 'slug' => 'trust-stays', 'database_name' => $this->databaseFile, 'active' => true, 'plan' => 'legacy', 'subscription_status' => 'legacy', 'currency' => 'KES']);
        $this->admin = User::factory()->create(['active' => true, 'name' => 'Amos Mwaura']);
        $this->admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        DB::table('public_sites')->insert(['tenant_id' => $this->company->id, 'enabled' => true, 'enquiry_assignee_id' => $this->admin->id, 'response_hours' => 2, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->listingId = DB::table('public_listings')->insertGetId([
            'tenant_id' => $this->company->id, 'unit_id' => 1, 'slug' => 'sea-view', 'title' => 'Sea View Retreat',
            'description' => 'A bright two bedroom apartment near the beach with a balcony.', 'city' => 'Mombasa', 'neighbourhood' => 'Nyali',
            'max_guests' => 4, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00', 'starting_price' => '12000.00',
            'cleaning_fee' => '1500.00', 'weekly_discount_percent' => 10, 'monthly_discount_percent' => 25,
            'amenities' => json_encode(['wifi', 'backup_power']), 'house_rules' => 'No indoor smoking.', 'status' => 'published', 'featured' => true,
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('public_listing_photos')->insert(['tenant_id' => $this->company->id, 'listing_id' => $this->listingId, 'storage_key' => 'listing-photos/'.$this->company->id.'/'.$this->listingId.'/'.str_repeat('a', 48).'.jpg', 'mime_type' => 'image/jpeg', 'caption' => 'Balcony', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->mock(ListingAvailability::class)->shouldReceive('calendar')->andReturn(['blocked' => [], 'fresh' => true, 'last_synced_at' => null, 'requires_confirmation' => true]);
        $this->mock(PublicEnquiries::class)->shouldReceive('formContext')->andReturn([]);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        if ($this->databaseFile && is_file($this->databaseFile)) {
            unlink($this->databaseFile);
        }
        parent::tearDown();
    }

    private function staff(): static
    {
        app()->instance('currentTenant', $this->company);

        return $this->actingAs($this->admin);
    }

    public function test_guest_review_link_is_single_use_and_shows_only_after_approval(): void
    {
        $this->staff()->post(route('public-listings.reviews.request', $this->company->slug), [
            'listing_id' => $this->listingId, 'guest_name' => 'grace achieng', 'guest_phone' => '0712 345 678', 'stayed_on' => now()->subDays(3)->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $link = session('review_link');
        $this->assertStringStartsWith('http://localhost/stays/trust-stays/review/', $link['url']);
        $this->assertStringStartsWith('https://wa.me/254712345678?text=', $link['whatsapp_url']);
        $this->assertStringContainsString('Hi grace, thank you for staying with Haven Suites', $link['message']);
        $this->assertDatabaseHas('public_reviews', ['tenant_id' => $this->company->id, 'status' => 'requested', 'guest_phone' => '+254712345678']);
        $this->assertNull(DB::table('public_reviews')->where('token_hash', basename($link['url']))->first(), 'Only the hash of the link is stored.');

        auth()->logout();
        $path = parse_url($link['url'], PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('How was your stay at Sea View Retreat?')->assertSee('Grace A.')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->post($path, ['rating' => 5, 'comment' => 'Spotless apartment <script>alert(1)</script> and a lovely sea breeze.', 'display_name' => 'Grace Achieng', 'consent' => 1])
            ->assertOk()->assertSee('Thanks for your review');
        $this->get($path)->assertNotFound()->assertSee('expired or was already used');
        $this->post($path, ['rating' => 1, 'comment' => 'Trying to change my review.', 'display_name' => 'Grace', 'consent' => 1])->assertNotFound();

        $this->get('/stays/trust-stays/sea-view')->assertOk()->assertDontSee('Spotless apartment');
        $review = DB::table('public_reviews')->first();
        $this->assertSame('pending', $review->status);
        $this->assertSame(5, (int) $review->rating);

        $this->staff()->get(route('public-listings.reviews', $this->company->slug))->assertOk()->assertSee('Spotless apartment')->assertSee('Waiting for approval');
        $this->post(route('public-listings.reviews.moderate', [$this->company->slug, $review->id]), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->post(route('public-listings.reviews.moderate', [$this->company->slug, $review->id]), ['action' => 'reply', 'reply' => 'Thank you, Grace!'])->assertSessionHasNoErrors();

        $page = $this->get('/stays/trust-stays/sea-view')->assertOk();
        $page->assertSee('Spotless apartment')->assertSee('Grace A.')->assertSee('Verified stay')->assertSee('Thank you, Grace!')
            ->assertSee('Rated 5 out of 5')->assertSee('1 review')->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/stays/trust-stays')->assertOk()->assertSee('What our guests say')->assertSee('Spotless apartment')->assertSee('(1)');

        $this->post(route('public-listings.reviews.moderate', [$this->company->slug, $review->id]), ['action' => 'hide'])->assertSessionHasNoErrors();
        $this->get('/stays/trust-stays/sea-view')->assertOk()->assertDontSee('Spotless apartment');
    }

    public function test_review_requests_send_guests_to_google_once_the_link_is_saved(): void
    {
        $settings = route('company.settings.update', $this->company->slug);
        $this->staff()->post($settings, ['name' => 'Haven Suites', 'google_review_url' => 'https://evil.example/review'])->assertSessionHasErrors('google_review_url');
        $this->post($settings, ['name' => 'Haven Suites', 'google_review_url' => 'http://g.page/r/haven/review'])->assertSessionHasErrors('google_review_url');
        $this->assertNull($this->company->fresh()->google_review_url);
        $this->post($settings, ['name' => 'Haven Suites', 'google_review_url' => ' https://share.google/gIhA3wxXBRoPNO1Ll '])->assertSessionHasNoErrors();
        $this->assertSame('https://share.google/gIhA3wxXBRoPNO1Ll', $this->company->fresh()->google_review_url);
        $this->company->refresh();
        app()->instance('currentTenant', $this->company);

        $this->get(route('public-listings.reviews', $this->company->slug))->assertOk()->assertSee('Send Google review link');
        $this->post(route('public-listings.reviews.request', $this->company->slug), ['guest_name' => 'Grace Achieng', 'guest_phone' => '+254712345678', 'listing_id' => $this->listingId])
            ->assertSessionHasNoErrors();
        $link = session('review_link');
        $this->assertSame('https://share.google/gIhA3wxXBRoPNO1Ll', $link['url']);
        $this->assertTrue($link['google']);
        $this->assertStringContainsString('review us on Google: https://share.google/gIhA3wxXBRoPNO1Ll', $link['message']);
        $request = DB::table('public_reviews')->where('tenant_id', $this->company->id)->first();
        $this->assertSame(['google', 'requested'], [$request->source, $request->status]);
        $this->assertNull($request->token_hash, 'No website review form is opened by a Google request.');
        $this->get(route('public-listings.reviews', [$this->company->slug, 'status' => 'requested']))->assertOk()->assertSee('Google review link sent');

        $templates = new GuestMessageTemplates;
        $this->assertSame('Review us: https://share.google/x', $templates->render('Review us: {{review_link}}', ['review_link' => 'https://share.google/x']));
        try {
            $templates->render('Review us: {{review_link}}', ['review_link' => null]);
            $this->fail('A missing review link must stop the SMS.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Company settings', $exception->errors()['body'][0]);
        }
    }

    public function test_expired_links_imported_reviews_and_staff_permissions(): void
    {
        $this->staff()->post(route('public-listings.reviews.request', $this->company->slug), ['guest_name' => 'Peter Otieno', 'guest_phone' => '+254 '])->assertSessionHasNoErrors();
        $url = session('review_link')['url'];
        $this->staff()->post(route('public-listings.reviews.request', $this->company->slug), ['guest_name' => 'No Phone', 'guest_phone' => '+254 ', 'send_sms' => 1])->assertSessionHasErrors('guest_phone');
        $this->travel(PublicReviews::LINK_DAYS + 1)->days();
        auth()->logout();
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();
        $this->get('/stays/trust-stays/review/'.str_repeat('A', 40))->assertNotFound();

        $this->staff()->post(route('public-listings.reviews.store', $this->company->slug), [
            'listing_id' => $this->listingId, 'source' => 'airbnb', 'guest_name' => 'Lena Fischer', 'rating' => 4, 'comment' => 'Great host and quick replies.',
        ])->assertSessionHasNoErrors();
        $this->post(route('public-listings.reviews.store', $this->company->slug), ['source' => 'direct', 'guest_name' => 'Fake', 'rating' => 5, 'comment' => 'Pretending to be verified.'])
            ->assertSessionHasErrors('source');
        $this->get('/stays/trust-stays/sea-view')->assertOk()->assertSee('From Airbnb')->assertSee('Lena F.')->assertDontSee('Verified stay');

        $viewer = User::factory()->create(['active' => true]);
        $viewer->tenants()->attach($this->company, ['roles' => json_encode(['housekeeping']), 'legacy_user_id' => 9]);
        $this->actingAs($viewer)->get(route('public-listings.reviews', $this->company->slug))->assertForbidden();
        $other = Tenant::factory()->create(['slug' => 'other-company', 'active' => true]);
        $otherReview = DB::table('public_reviews')->insertGetId(['tenant_id' => $other->id, 'source' => 'google', 'status' => 'pending', 'guest_name' => 'Other', 'rating' => 1, 'comment' => 'Other company review', 'created_at' => now(), 'updated_at' => now()]);
        $this->staff()->post(route('public-listings.reviews.moderate', [$this->company->slug, $otherReview]), ['action' => 'approve'])->assertNotFound();
        $this->assertSame('pending', DB::table('public_reviews')->where('id', $otherReview)->value('status'));
    }

    public function test_listing_shows_price_estimate_inputs_policies_host_and_kenyan_amenities(): void
    {
        $page = $this->get('/stays/trust-stays/sea-view')->assertOk();
        $page->assertSee('data-nightly="12000"', false)->assertSee('data-cleaning="1500"', false)->assertSee('data-weekly="10"', false)->assertSee('data-monthly="25"', false)
            ->assertSee('10% off weekly stays')->assertSee('25% off monthly stays')
            ->assertSee('Good to know')->assertSee('Cancellations')->assertSee('Deposit and payment')->assertSee('Only pay to the account named on your quote')
            ->assertSee('Hosted by the Haven Suites team')->assertSee('Usually replies within 2 hours')->assertSee('Speaks English, Swahili')
            ->assertSee('Backup power')->assertSee('https://wa.me/?text=', false);
        $this->get('/stays/trust-stays')->assertOk()->assertSee('25% off long stays')->assertSee('Pay the company, safely')->assertSee(route('public-stays.faq', 'trust-stays'), false);
    }

    public function test_faq_page_uses_defaults_then_saved_questions_and_policies(): void
    {
        $this->get('/stays/trust-stays/faq')->assertOk()->assertSee('How do I book a stay?')->assertSee('"@type":"FAQPage"', false)
            ->assertSee('<link rel="canonical" href="http://localhost/stays/trust-stays/faq">', false);

        $this->staff()->post(route('public-listings.site.update', $this->company->slug), [
            'version' => 1, 'settings_page' => 1, 'enabled' => 1, 'enquiry_assignee_id' => $this->admin->id, 'response_hours' => 2,
            'faq' => "Is there parking?\nYes, one secure bay per apartment.\n\nQ: Do you allow pets?\nA: Only in listings marked Pets allowed.",
            'cancellation_policy' => 'Full refund up to 14 days before arrival.', 'payment_policy' => 'Pay to M-Pesa Paybill 123456, account HAVEN.',
            'host_intro' => 'Wanjiku and the team look after every guest.', 'host_reply_time' => 'Replies in minutes', 'host_languages' => 'English, Swahili, French',
        ])->assertSessionHasNoErrors();
        $this->get(route('public-listings.settings', $this->company->slug))->assertOk()->assertSee('Policies and FAQ')->assertSee('Your team on the website');

        auth()->logout();
        $this->get('/stays/trust-stays/faq')->assertOk()->assertSee('Is there parking?')->assertSee('Do you allow pets?')->assertSee('Only in listings marked Pets allowed.')
            ->assertDontSee('Q: Do you allow pets?')->assertDontSee('How do I book a stay?')->assertSee('Full refund up to 14 days before arrival.')->assertSee('Paybill 123456');
        $this->get('/stays/trust-stays/sea-view')->assertOk()->assertSee('Wanjiku and the team look after every guest.')->assertSee('Replies in minutes')->assertSee('English, Swahili, French')
            ->assertSee('Full refund up to 14 days before arrival.');
    }

    public function test_listing_editor_saves_cleaning_fee_and_discounts(): void
    {
        $this->staff()->get(route('public-listings.edit', [$this->company->slug, $this->listingId]))->assertOk()->assertSee('Weekly discount')->assertSee('Cleaning fee per stay');
        $payload = ['version' => 1, 'unit_id' => 1, 'slug' => 'sea-view', 'title' => 'Sea View Retreat', 'description' => 'A bright two bedroom apartment near the beach with a balcony.',
            'city' => 'Mombasa', 'max_guests' => 4, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00', 'starting_price' => '12000', 'status' => 'published',
            'cleaning_fee' => '2000', 'weekly_discount_percent' => 15, 'monthly_discount_percent' => ''];
        $this->post(route('public-listings.update', [$this->company->slug, $this->listingId]), $payload)->assertSessionHasNoErrors();
        $listing = DB::table('public_listings')->find($this->listingId);
        $this->assertEquals(2000, $listing->cleaning_fee);
        $this->assertSame(15, (int) $listing->weekly_discount_percent);
        $this->assertNull($listing->monthly_discount_percent);
        $this->post(route('public-listings.update', [$this->company->slug, $this->listingId]), ['version' => 2, 'weekly_discount_percent' => 95] + $payload)->assertSessionHasErrors('weekly_discount_percent');
    }

    public function test_listing_layout_beds_and_villa_override_the_apartment_record(): void
    {
        $this->get('/stays/trust-stays/sea-view')->assertOk()->assertSee('2 bedrooms');
        $this->staff()->get(route('public-listings.edit', [$this->company->slug, $this->listingId]))->assertOk()
            ->assertSee('Layout, bathrooms and beds')->assertSee('Use apartment record')->assertSee('>Studio</option>', false)->assertSee('5 bedrooms');
        $payload = ['version' => 1, 'unit_id' => 1, 'slug' => 'sea-view', 'title' => 'Sea View Villa', 'description' => 'A bright four bedroom villa near the beach with a private garden.',
            'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'max_guests' => 9, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00', 'starting_price' => '30000', 'status' => 'published',
            'property_type' => 'villa', 'bedrooms' => 4, 'bathrooms' => 3, 'beds' => 5, 'bed_setup' => '2 king, 2 queen, 1 sofa bed'];
        $this->post(route('public-listings.update', [$this->company->slug, $this->listingId]), $payload)->assertSessionHasNoErrors();
        $listing = DB::table('public_listings')->find($this->listingId);
        $this->assertSame([4, 3, 5], [(int) $listing->bedrooms, (int) $listing->bathrooms, (int) $listing->beds]);

        auth()->logout();
        $this->get('/stays/trust-stays/sea-view')->assertOk()->assertSee('4-bedroom villa')->assertSee('5 beds')->assertSee('2 king, 2 queen, 1 sofa bed')->assertSee('3 bathrooms');
        $this->get('/stays/trust-stays?bedrooms=4')->assertOk()->assertSee('Sea View Villa');
        $this->get('/stays/trust-stays?bedrooms=2')->assertOk()->assertDontSee('Sea View Villa');

        $this->staff()->post(route('public-listings.update', [$this->company->slug, $this->listingId]), ['version' => 2, 'bedrooms' => 0, 'property_type' => 'studio', 'bathrooms' => '', 'beds' => 1, 'bed_setup' => ''] + $payload)->assertSessionHasNoErrors();
        auth()->logout();
        $this->get('/stays/trust-stays/sea-view')->assertOk()->assertSee('Studio')->assertSee('1 bed ', false)->assertSee('2 bathrooms');
        $this->staff()->post(route('public-listings.update', [$this->company->slug, $this->listingId]), ['version' => 3, 'bedrooms' => 11, 'beds' => 0] + $payload)->assertSessionHasErrors(['bedrooms', 'beds']);
    }

    public function test_new_homepage_keeps_search_fields_addresses_enquiry_links_and_whatsapp_codes(): void
    {
        DB::table('public_sites')->where('tenant_id', $this->company->id)->update(['whatsapp_number' => '+254712345678']);
        DB::table('public_listings')->where('id', $this->listingId)->update(['listing_code' => 'F5', 'collections' => json_encode(['sea_view', 'long_stay'])]);
        $classic = $this->get('/stays/trust-stays')->assertOk()->assertSee('stay-hero', false)->assertDontSee('stay-pill', false)->getContent();

        app(ListingAvailability::class)->shouldReceive('availabilityForListings')->andReturn([$this->listingId => ['available' => true, 'fresh' => false]]);
        $query = '?layout=new&city=Mombasa&check_in='.now()->addDays(10)->toDateString().'&check_out='.now()->addDays(12)->toDateString().'&guests=2';
        $new = $this->get('/stays/trust-stays'.$query)->assertOk();
        $html = $new->getContent();
        $new->assertSee('stay-pill', false)->assertSee('data-filter-drawer', false)->assertDontSee('class="stay-hero"', false)
            ->assertSee('<link rel="canonical" href="http://localhost/stays/trust-stays">', false);
        foreach (['name="city"', 'name="check_in"', 'name="check_out"', 'name="guests"', 'name="q"', 'name="area"', 'name="bedrooms"', 'name="min_price"', 'name="max_price"', 'name="amenities[]"', 'name="available_only"', 'name="collection"', 'id="stay-search-form"', '/stays/trust-stays#find-stay" method="get"'] as $field) {
            $this->assertStringContainsString($field, $html, 'New search keeps '.$field);
            $this->assertStringContainsString($field, $classic, 'Classic search has '.$field);
        }
        $this->assertStringContainsString('<input type="hidden" name="layout" value="new">', $html, 'Searching while previewing keeps the preview.');
        // Cards still carry the dates to the listing, keep the WhatsApp message with the listing code, and show an estimate.
        $this->assertMatchesRegularExpression('#class="stay-card-link" href="https?://[^/"]+/stays/trust-stays/sea-view\?(?=[^"]*check_in=[0-9-]+)(?=[^"]*check_out=[0-9-]+)(?=[^"]*guests=2)[^"]*"#', $html);
        $this->assertSame(1, preg_match('#class="stay-card-wa" href="(https://wa\.me/254712345678\?text=[^"]+)"#', $html, $whatsapp), 'The card keeps its WhatsApp link.');
        $this->assertStringContainsString('F5', urldecode(html_entity_decode($whatsapp[1])), 'The WhatsApp message still names the listing code.');
        $new->assertSee('KES 25,500')->assertSee('est. total · 2 nights')->assertSee('We confirm your final price')->assertSee('Ask us to confirm dates');
        $this->assertSame(['nights' => 2, 'total' => 25500.0], ListingDetails::estimate(['starting_price' => '12000', 'cleaning_fee' => 1500, 'weekly_discount' => 10],
            now()->addDays(10)->toDateString(), now()->addDays(12)->toDateString()), 'Same estimate as the listing page: 2 × 12,000 + 1,500 cleaning.');
        $this->get('/stays/trust-stays?layout=new&collection=long_stay')->assertOk()->assertSee('Long stays <small>1</small>', false);
        $this->get('/stays/trust-stays?collection=long_stay')->assertOk()->assertSeeInOrder(['<span class="stay-tag">Long stays</span>', '<span class="stay-tag">Sea view</span>'], false);
    }

    public function test_homepage_steps_switch_on_independently_and_saved_stays_need_no_account(): void
    {
        DB::table('public_listings')->where('id', $this->listingId)->update(['latitude' => -4.043512, 'longitude' => 39.712345]);
        DB::table('public_sites')->where('tenant_id', $this->company->id)->update(['homepage_layout' => HomepageLayout::encode(['search' => '1'])]);
        $this->get('/stays/trust-stays')->assertOk()->assertSee('stay-pill', false)->assertDontSee('stay-card is-v2', false)->assertDontSee('data-save-stay', false);
        $this->get('/stays/trust-stays?layout=classic')->assertOk()->assertDontSee('stay-pill', false);

        DB::table('public_sites')->where('tenant_id', $this->company->id)->update(['homepage_layout' => HomepageLayout::encode(['cards' => true, 'explore' => true])]);
        $page = $this->get('/stays/trust-stays')->assertOk()->assertDontSee('stay-pill', false)->assertSee('stay-card is-v2', false)
            ->assertSee('data-save-stay="sea-view"', false)->assertSee('data-map-lat="-4.045"', false)->assertSee('data-map-lng="39.71"', false)->assertSee('data-map-view', false);
        $this->assertStringNotContainsString('43512', $page->getContent(), 'The map pin is rounded to the area.');
        $this->get('/stays/trust-stays/sea-view')->assertOk()->assertSee('data-save-stay="sea-view"', false);

        $this->get('/stays/trust-stays/saved?stays=sea-view,not-a-listing,BAD!')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow')
            ->assertSee('Saved stays')->assertSee('Sea View Retreat')->assertDontSee('not-a-listing');
        $this->get('/stays/trust-stays/saved')->assertOk()->assertSee('No saved stays yet');
        $this->assertContains('saved', PublicListingCatalog::RESERVED_SLUGS);

        $this->staff()->get(route('public-listings.settings', $this->company->slug))->assertOk()->assertSee('Homepage layout')->assertSee('?layout=new', false)
            ->assertSee('name="homepage_layout[explore]" value="1" style="width:auto" checked', false);
    }

    /** @return array<string, mixed> */
    private function detailsPayload(int $photoId): array
    {
        return ['version' => 1, 'unit_id' => 1, 'slug' => 'sea-view', 'title' => 'Sea View Retreat', 'description' => 'A bright two bedroom apartment near the beach with a balcony.',
            'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'public_location' => 'Near Nyali Beach', 'max_guests' => 4, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00',
            'starting_price' => '12000', 'status' => 'published', 'amenities' => ['wifi', 'elevator', 'gated'],
            'location' => 'https://www.google.com/maps/place/Nyali/@-4.043512,39.712345,17z/data=!3m1!4b1',
            'nearby' => [['name' => 'Nyali Beach', 'minutes' => 5, 'mode' => 'walk'], ['name' => 'City Mall', 'minutes' => 8, 'mode' => 'drive'], ['name' => '', 'minutes' => '', 'mode' => 'drive']],
            'rooms' => [['name' => 'Master bedroom', 'beds' => '1 king bed', 'photo_id' => $photoId, 'ensuite' => 1], ['name' => 'Second bedroom', 'beds' => '2 single beds', 'photo_id' => '', 'ensuite' => 0], ['name' => '', 'beds' => '']],
            'size_sqm' => 95, 'floor_level' => '5th floor', 'parking_spaces' => 1, 'virtual_tour_url' => 'https://kuula.co/share/7abcD',
            'photos_checked' => 1, 'check_in_guide' => 'Gate code 4512. Wi-Fi password HavenGuest2026.', 'weekend_price' => '15000',
            'seasonal_rates' => [['name' => 'December holidays', 'from' => '2099-12-15', 'to' => '2100-01-05', 'price' => '20000'], ['name' => 'Old season', 'from' => '2020-01-01', 'to' => '2020-01-10', 'price' => '9000']],
            'photo_rooms' => [$photoId => 'bedroom']];
    }

    public function test_listing_details_show_rooms_area_map_nearby_facts_prices_and_tour_without_private_data(): void
    {
        $photo = (int) DB::table('public_listing_photos')->where('listing_id', $this->listingId)->value('id');
        $this->staff()->get(route('public-listings.edit', [$this->company->slug, $this->listingId]))->assertOk()
            ->assertSee('Listing quality:')->assertSee('Where guests sleep')->assertSee('Map and nearby places')->assertSee('Check-in guide')->assertSee('name="photo_rooms['.$photo.']"', false);
        $this->post(route('public-listings.update', [$this->company->slug, $this->listingId]), $this->detailsPayload($photo))->assertSessionHasNoErrors();
        $listing = DB::table('public_listings')->find($this->listingId);
        $this->assertEquals(-4.043512, (float) $listing->latitude);
        $this->assertSame(now()->toDateString(), $listing->photos_verified_at);
        $this->assertSame('bedroom', DB::table('public_listing_photos')->where('id', $photo)->value('room'));
        $this->assertCount(2, json_decode($listing->rooms, true));
        $this->get(route('public-listings.edit', [$this->company->slug, $this->listingId]))->assertSee('value="-4.043512, 39.712345"', false);

        auth()->logout();
        $page = $this->get('/stays/trust-stays/sea-view')->assertOk();
        $page->assertSee("Where you'll sleep", false)->assertSee('Master bedroom')->assertSee('1 king bed')->assertSee('Ensuite bathroom')
            ->assertSee('openstreetmap.org/export/embed.html', false)->assertSee('Nyali Beach')->assertSee('5 min walk')->assertSee('8 min drive')
            ->assertSee('95 m²')->assertSee('5th floor')->assertSee('Lift available')->assertSee('1 parking space')->assertSee('Gated compound')
            ->assertSee('Photos checked '.now()->format('F Y'))->assertSee('Prices by date')->assertSee('December holidays')->assertDontSee('Old season')
            ->assertSee('data-weekend="15000"', false)->assertSee('2099-12-15', false)
            ->assertSee('data-tour-url="https://kuula.co/share/7abcD?fs=1', false)
            ->assertDontSee('Gate code')->assertDontSee('HavenGuest2026')->assertDontSee('43512')->assertDontSee('712345');

        $this->staff()->post(route('public-listings.update', [$this->company->slug, $this->listingId]), ['version' => 2, 'location' => 'somewhere nice'] + $this->detailsPayload($photo))->assertSessionHasErrors('location');
        $this->post(route('public-listings.update', [$this->company->slug, $this->listingId]), ['version' => 2, 'virtual_tour_url' => 'https://evil.example/share/abc'] + $this->detailsPayload($photo))->assertSessionHasErrors('virtual_tour_url');
        $this->post(route('public-listings.update', [$this->company->slug, $this->listingId]), ['version' => 2, 'seasonal_rates' => [['name' => 'Bad', 'from' => '2099-02-10', 'to' => '2099-02-01', 'price' => 100]]] + $this->detailsPayload($photo))->assertSessionHasErrors('seasonal_rates.0.to');
    }

    public function test_check_in_guide_is_offered_on_the_enquiry_only_after_the_booking_is_won(): void
    {
        $photo = (int) DB::table('public_listing_photos')->where('listing_id', $this->listingId)->value('id');
        $this->staff()->post(route('public-listings.update', [$this->company->slug, $this->listingId]), $this->detailsPayload($photo))->assertSessionHasNoErrors();
        // The enquiry page passes the listing's guide to this partial (SalesController::show); rendered here directly
        // because the full enquiry page needs company tables this fixture does not create.
        $guide = DB::table('public_listings')->where('id', $this->listingId)->first(['title', 'check_in_guide']);
        $lead = (object) ['name' => 'Grace Achieng', 'phone' => '0712 345 678', 'stage' => 'new'];
        $this->staff();
        $this->view('sales.check_in_guide', ['checkInGuide' => $guide, 'lead' => $lead])
            ->assertSee('becomes available to send here once the booking is confirmed')->assertDontSee('Gate code 4512');
        $lead->stage = 'won';
        $this->view('sales.check_in_guide', ['checkInGuide' => $guide, 'lead' => $lead])->assertSee('Hi Grace, here is your check-in guide for Sea View Retreat')
            ->assertSee('Gate code 4512')->assertSee('https://wa.me/254712345678?text=', false);
        $this->assertStringContainsString("'checkInGuide' => \$checkInGuide", file_get_contents(app_path('Http/Controllers/SalesController.php')));
    }

    public function test_staff_choose_the_cover_photo_and_it_leads_the_slider_and_cards(): void
    {
        $base = ['tenant_id' => $this->company->id, 'listing_id' => $this->listingId, 'mime_type' => 'image/jpeg', 'created_at' => now(), 'updated_at' => now()];
        $kitchen = DB::table('public_listing_photos')->insertGetId($base + ['storage_key' => 'listing-photos/'.$this->company->id.'/'.$this->listingId.'/'.str_repeat('b', 48).'.jpg', 'caption' => 'Kitchen', 'sort_order' => 1]);
        $bedroom = DB::table('public_listing_photos')->insertGetId($base + ['storage_key' => 'listing-photos/'.$this->company->id.'/'.$this->listingId.'/'.str_repeat('c', 48).'.jpg', 'caption' => 'Bedroom', 'sort_order' => 2]);
        $balcony = (int) DB::table('public_listing_photos')->where('caption', 'Balcony')->value('id');

        $this->staff()->get(route('public-listings.edit', [$this->company->slug, $this->listingId]))->assertOk()
            ->assertSee('data-photo-sorter', false)->assertSee('name="cover_photo" value="'.$balcony.'" checked', false)->assertSee('Cover photo');
        $payload = ['version' => 1, 'unit_id' => 1, 'slug' => 'sea-view', 'title' => 'Sea View Retreat', 'description' => 'A bright two bedroom apartment near the beach with a balcony.',
            'city' => 'Mombasa', 'max_guests' => 4, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00', 'status' => 'published',
            'photo_order' => [$balcony => 0, $kitchen => 1, $bedroom => 2], 'cover_photo' => $bedroom];
        $this->post(route('public-listings.update', [$this->company->slug, $this->listingId]), $payload)->assertSessionHasNoErrors();
        $this->assertSame([$bedroom, $balcony, $kitchen], DB::table('public_listing_photos')->where('listing_id', $this->listingId)->orderBy('sort_order')->pluck('id')->map(fn ($id) => (int) $id)->all());

        auth()->logout();
        $page = $this->get('/stays/trust-stays/sea-view')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-slider-track[^>]*>\s*<a class="stay-slide" href="[^"]+/photos/'.$bedroom.'\?w=1200"#', $page, 'The cover photo is the first slide.');
        $this->assertStringContainsString('aria-label="Photo 1 of 3: Bedroom"', $page);
        $this->get('/stays/trust-stays')->assertOk()->assertSee('/sea-view/photos/'.$bedroom.'?w=480', false);

        $this->staff()->post(route('public-listings.update', [$this->company->slug, $this->listingId]), ['version' => 2, 'cover_photo' => 999999, 'photo_order' => [$bedroom => 0, $balcony => 1, $kitchen => 2]] + $payload)->assertSessionHasNoErrors();
        $this->assertSame($bedroom, (int) DB::table('public_listing_photos')->where('listing_id', $this->listingId)->orderBy('sort_order')->value('id'), 'An unknown cover is ignored.');
    }

    public function test_blog_editor_uploads_images_that_render_in_published_posts(): void
    {
        Storage::fake('local');
        $this->staff()->get(route('public-listings.blog.create', $this->company->slug))->assertOk()
            ->assertSee('toastui-editor-all.min.js', false)->assertSee('data-post-editor', false)->assertSee('data-upload-url="'.route('public-listings.blog.image', $this->company->slug).'"', false);
        $url = $this->postJson(route('public-listings.blog.image', $this->company->slug), ['image' => UploadedFile::fake()->image('beach.png', 900, 600)])
            ->assertOk()->json('url');
        $this->assertMatchesRegularExpression('#/stays/trust-stays/blog/images/[A-Za-z0-9]{32}$#', $url);
        $this->postJson(route('public-listings.blog.image', $this->company->slug), ['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('image');

        $body = "Our favourite beach walk.\n\n![Nyali beach at sunrise]({$url})\n\n| Area | Distance |\n| --- | --- |\n| Nyali | 5 min |";
        $this->post(route('public-listings.blog.store', $this->company->slug), ['title' => 'Walks near Nyali', 'body' => $body, 'status' => 'published'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get('/stays/trust-stays/blog/walks-near-nyali')->assertOk()->assertSee('<table>', false)
            ->assertSee('<img src="'.$url.'?w=1200" alt="Nyali beach at sunrise" srcset="'.$url.'?w=480 480w, '.$url.'?w=1200 1200w" sizes="(max-width: 800px) 100vw, 760px" loading="lazy" decoding="async">', false);
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $inline = 'public-posts/'.$this->company->id.'/inline/'.basename($url).'.jpg';
        Storage::disk('local')->assertExists([ListingPhotoVariants::key($inline, 480), ListingPhotoVariants::key($inline, 1200)]);
        $small = $this->get(parse_url($url, PHP_URL_PATH).'?w=480')->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->assertSame(480, getimagesizefromstring((string) file_get_contents($small->baseResponse->getFile()->getPathname()))[0]);
        Storage::disk('local')->delete(ListingPhotoVariants::key($inline, 1200));
        $this->get(parse_url($url, PHP_URL_PATH).'?w=1200')->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'max-age=3600, public');
        $this->artisan('haven:listing-photo-variants')->assertSuccessful();
        Storage::disk('local')->assertExists(ListingPhotoVariants::key($inline, 1200));

        $this->staff()->post(route('public-listings.blog.store', $this->company->slug), ['title' => 'Sunset at Nyali', 'body' => 'An evening walk along the beach with the family, then dinner by the water.', 'status' => 'published',
            'cover' => UploadedFile::fake()->image('cover.jpg', 1600, 900)])->assertSessionHasNoErrors();
        $cover = DB::table('public_posts')->where('slug', 'sunset-at-nyali')->value('cover_path');
        Storage::disk('local')->assertExists(ListingPhotoVariants::key($cover, 480));
        auth()->logout();
        $this->get('/stays/trust-stays/blog')->assertOk()->assertSee('&amp;w=480 480w', false);
        $coverPage = $this->get('/stays/trust-stays/blog/sunset-at-nyali')->assertOk()->assertSee('&amp;w=1200" srcset=', false);
        preg_match('#src="([^"]+/cover\?v=\d+)&amp;w=1200"#', $coverPage->getContent(), $coverUrl);
        $this->get(parse_url($coverUrl[1], PHP_URL_PATH).'?w=480')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get('/stays/trust-stays/blog/images/'.str_repeat('a', 32))->assertNotFound();
        $this->get('/stays/other-company/blog/images/'.basename($url))->assertNotFound();
    }

    public function test_blog_search_checklist_scores_posts_live_and_shows_progress(): void
    {
        $slug = $this->company->slug;
        $paragraph = 'Nyali beach apartments put you minutes from the ocean. Families enjoy quiet mornings. Business guests like the fast WiFi. ';
        $body = $paragraph."\n\n## Why Nyali beach apartments suit families\n\n".str_repeat($paragraph, 4)
            ."\n\n## Getting around\n\nRead our [restaurant guide](".url('/stays/'.$slug.'/blog/nyali-restaurants').') and the [county rules](https://www.mombasa.go.ke).'
            ."\n\n## Where to eat\n\n".str_repeat($paragraph, 3);
        $this->staff()->get(route('public-listings.blog.create', $slug))->assertOk()->assertSee('Search optimisation')->assertSee('Search score')->assertSee('Add the phrase guests would type');

        $check = $this->postJson(route('public-listings.blog.check', $slug), ['title' => 'Nyali beach apartments: a family guide', 'body' => $body,
            'focus_keyword' => 'Nyali beach apartments', 'seo_description' => 'Compare Nyali beach apartments for families: what to expect, getting around and where to eat near the ocean.'])
            ->assertOk();
        $this->assertGreaterThan(40, $check->json('score'));
        $this->assertStringContainsString('Keyword in title', $check->json('html'));
        $this->assertStringContainsString('nyali-beach-apartments-a-family-guide', $check->json('html'), 'The Google preview shows the web address.');
        $this->assertSame(0, DB::table('public_posts')->count(), 'Checking saves nothing.');

        $this->post(route('public-listings.blog.store', $slug), ['title' => 'Nyali restaurants near the beach', 'slug' => 'nyali-restaurants',
            'body' => 'Our favourite places to eat in Nyali, from seafood by the beach to coffee shops for remote work.', 'status' => 'published', 'published_at' => '2026-01-01 09:00'])->assertSessionHasNoErrors();
        $this->post(route('public-listings.blog.store', $slug), ['title' => 'Business travel in Westlands', 'body' => 'Where to stay in Westlands for meetings, with fast WiFi and quiet rooms for calls.',
            'status' => 'published', 'published_at' => '2026-02-01 09:00'])->assertSessionHasNoErrors();
        $this->post(route('public-listings.blog.store', $slug), ['title' => 'Nyali beach apartments: a family guide', 'body' => $body, 'focus_keyword' => 'Nyali beach apartments',
            'status' => 'published', 'published_at' => '2026-03-01 09:00'])->assertSessionHasNoErrors();
        $this->assertSame('Nyali beach apartments', DB::table('public_posts')->where('slug', 'nyali-beach-apartments-a-family-guide')->value('focus_keyword'));

        $this->get(route('public-listings.blog', $slug))->assertOk()->assertSee('Search progress')->assertSee('Keyword: Nyali beach apartments')->assertSee('linked from 1 post');

        $page = $this->get('/stays/'.$slug.'/blog/nyali-beach-apartments-a-family-guide')->assertOk()
            ->assertSee('<meta property="og:type" content="article">', false)->assertSee('article:published_time', false)->assertSee('twitter:label2', false)
            ->assertSee('In this guide')->assertSee('<h2 id="why-nyali-beach-apartments-suit-families">', false)->assertSee('href="#getting-around"', false)
            ->assertSee('Edit this article')->assertSee('Search score')->assertSee('All articles')
            ->assertSee('Latest guides');
        $html = $page->getContent();
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"keywords":"Nyali beach apartments"', $html);
        $this->assertMatchesRegularExpression('/"wordCount":\d{3}/', $html);
        $this->assertLessThan(strpos($html, 'Business travel in Westlands', strpos($html, 'More guides')), strpos($html, 'Nyali restaurants near the beach', strpos($html, 'More guides')), 'Related guides on the same topic come first.');

        auth()->logout();
        $this->get('/stays/'.$slug.'/blog/nyali-beach-apartments-a-family-guide')->assertOk()->assertDontSee('Edit this article')->assertDontSee('Search score');
    }

    public function test_cached_catalogue_is_cleared_by_staff_saves_and_photos_use_smaller_copies(): void
    {
        Storage::fake('local');
        config(['haven.public_catalogue_cache_seconds' => 60]);
        $photo = DB::table('public_listing_photos')->first();
        Storage::disk('local')->put($photo->storage_key, UploadedFile::fake()->image('balcony.jpg', 2000, 1200)->getContent());

        $this->get('/stays/trust-stays')->assertOk()->assertSee('Sea View Retreat');
        DB::table('public_listings')->where('id', $this->listingId)->update(['title' => 'Renamed Behind The Scenes']);
        $this->get('/stays/trust-stays')->assertOk()->assertSee('Sea View Retreat', false)->assertDontSee('Renamed Behind The Scenes');
        $this->assertTrue(Cache::has('public-catalogue:'.$this->company->id));
        $this->staff()->post(route('public-listings.update', [$this->company->slug, $this->listingId]), ['version' => 99])->assertSessionHasErrors();
        $this->assertFalse(Cache::has('public-catalogue:'.$this->company->id), 'Staff saves clear the cached catalogue.');
        auth()->logout();
        $this->get('/stays/trust-stays')->assertOk()->assertSee('Renamed Behind The Scenes');

        $photoUrl = '/stays/trust-stays/sea-view/photos/'.$photo->id;
        $this->get('/stays/trust-stays')->assertSee($photoUrl.'?w=480 480w', false);
        $this->get($photoUrl.'?w=480')->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'max-age=3600, public');
        $this->artisan('haven:listing-photo-variants')->assertSuccessful();
        Storage::disk('local')->assertExists(ListingPhotoVariants::key($photo->storage_key, 480));
        $small = $this->get($photoUrl.'?w=480')->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->assertSame(480, getimagesizefromstring($small->streamedContent() ?: file_get_contents($small->baseResponse->getFile()->getPathname()))[0]);
        $this->get($photoUrl.'?w=999')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        DB::table('public_listings')->where('id', $this->listingId)->update(['status' => 'paused']);
        PublicListingCatalog::forget($this->company->id);
        $this->get($photoUrl)->assertNotFound();
    }

    public function test_blog_drafts_stay_private_and_published_posts_render_safely(): void
    {
        Storage::fake('local');
        $this->get('/stays/trust-stays')->assertOk()->assertDontSee('/stays/trust-stays/blog"', false);
        $this->get('/stays/trust-stays/blog')->assertOk()->assertSee('New guides are on the way');

        $body = "Kilimani is close to Yaya Centre.\n\n## Getting around\n\n- Take a taxi from JKIA\n- **Avoid** rush hour\n\n<script>alert('x')</script>\n\n[Unsafe](javascript:alert(1)) and [our stays](https://example.com/stays).";
        $this->staff()->post(route('public-listings.blog.store', $this->company->slug), [
            'title' => 'Best areas to stay in Nairobi', 'body' => $body, 'status' => 'draft',
            'cover' => UploadedFile::fake()->image('kilimani.jpg', 1200, 700), 'cover_caption' => 'Kilimani at sunset',
        ])->assertSessionHasNoErrors();
        $post = DB::table('public_posts')->first();
        $this->assertSame('best-areas-to-stay-in-nairobi', $post->slug);
        $this->assertMatchesRegularExpression('#^public-posts/'.$this->company->id.'/[A-Za-z0-9]{32}\.jpg$#', $post->cover_path);
        Storage::disk('local')->assertExists($post->cover_path);
        $this->get(route('public-listings.blog', $this->company->slug))->assertOk()->assertSee('Best areas to stay in Nairobi')->assertSee('Draft');

        auth()->logout();
        $this->get('/stays/trust-stays/blog/best-areas-to-stay-in-nairobi')->assertNotFound();
        $this->get('/stays/trust-stays/blog/best-areas-to-stay-in-nairobi/cover')->assertNotFound();

        $this->staff()->post(route('public-listings.blog.update', [$this->company->slug, $post->id]), [
            'version' => 1, 'title' => 'Best areas to stay in Nairobi', 'slug' => $post->slug, 'body' => $body, 'status' => 'published', 'cover_caption' => 'Kilimani at sunset',
        ])->assertSessionHasNoErrors();
        auth()->logout();
        $page = $this->get('/stays/trust-stays/blog/best-areas-to-stay-in-nairobi')->assertOk();
        $page->assertSee('<h2 id="getting-around">Getting around</h2>', false)->assertSee('<strong>Avoid</strong>', false)->assertSee('href="https://example.com/stays"', false)
            ->assertDontSee("<script>alert('x')</script>", false)->assertDontSee('javascript:alert', false)
            ->assertSee('"@type":"BlogPosting"', false)->assertSee('By Amos M.')->assertSee('Kilimani at sunset');
        $this->get('/stays/trust-stays/blog/best-areas-to-stay-in-nairobi/cover')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/stays/trust-stays/blog')->assertOk()->assertSee('Best areas to stay in Nairobi')->assertSee('min read');
        $this->get('/stays/trust-stays')->assertOk()->assertSee('http://localhost/stays/trust-stays/blog', false);
        $this->get('/stays/trust-stays/sitemap.xml')->assertOk()->assertSee('/stays/trust-stays/blog/best-areas-to-stay-in-nairobi', false);

        $this->staff()->post(route('public-listings.blog.update', [$this->company->slug, $post->id]), [
            'version' => 2, 'title' => 'Best areas to stay in Nairobi', 'slug' => $post->slug, 'body' => $body, 'status' => 'published', 'published_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();
        $this->get(route('public-listings.blog', $this->company->slug))->assertSee('Scheduled');
        auth()->logout();
        $this->get('/stays/trust-stays/blog/best-areas-to-stay-in-nairobi')->assertNotFound();

        $this->staff()->post(route('public-listings.blog.update', [$this->company->slug, $post->id]), ['version' => 1, 'title' => 'Stale edit', 'slug' => $post->slug, 'body' => $body, 'status' => 'draft'])
            ->assertSessionHasErrors('post');
        $this->post(route('public-listings.blog.destroy', [$this->company->slug, $post->id]))->assertRedirect();
        Storage::disk('local')->assertMissing($post->cover_path);
        $this->assertDatabaseCount('public_posts', 0);
    }

    public function test_area_pages_list_local_stays_with_editable_introductions(): void
    {
        $this->get('/stays/trust-stays/in/mombasa')->assertOk()->assertSee('Stays in Mombasa')->assertSee('Sea View Retreat')->assertSee('1 stay in Mombasa');
        $this->get('/stays/trust-stays/in/mombasa/nyali')->assertOk()->assertSee('Stays in Nyali, Mombasa')->assertSee('Sea View Retreat')
            ->assertSee('<link rel="canonical" href="http://localhost/stays/trust-stays/in/mombasa/nyali">', false)->assertSee('"@type":"BreadcrumbList"', false);
        $this->get('/stays/trust-stays/in/nairobi')->assertNotFound();
        $this->get('/stays/trust-stays/in/mombasa/kilimani')->assertNotFound();
        $this->get('/stays/trust-stays/sitemap.xml')->assertOk()->assertSee('http://localhost/stays/trust-stays/in/mombasa/nyali', false);

        $this->staff()->get(route('public-listings.areas', $this->company->slug))->assertOk()->assertSee('Nyali, Mombasa')->assertSee('Standard introduction');
        $this->post(route('public-listings.areas.save', $this->company->slug), ['city' => 'Mombasa', 'area' => 'Nyali', 'intro' => 'Nyali is a calm beach suburb north of Mombasa island.', 'highlights' => "5 minutes to Nyali Beach\nCity Mall nearby"])
            ->assertSessionHasNoErrors();
        $this->post(route('public-listings.areas.save', $this->company->slug), ['city' => 'Atlantis', 'intro' => 'Made-up place.'])->assertSessionHasErrors('city');
        auth()->logout();
        $this->get('/stays/trust-stays/in/mombasa/nyali')->assertOk()->assertSee('Nyali is a calm beach suburb north of Mombasa island.')->assertSee('City Mall nearby');
    }
}
