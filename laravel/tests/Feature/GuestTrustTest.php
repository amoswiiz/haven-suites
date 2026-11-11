<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\ListingAvailability;
use App\Services\PublicEnquiries;
use App\Services\PublicReviews;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
        $page->assertSee('<h2>Getting around</h2>', false)->assertSee('<strong>Avoid</strong>', false)->assertSee('href="https://example.com/stays"', false)
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
