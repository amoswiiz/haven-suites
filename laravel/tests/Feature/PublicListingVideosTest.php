<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PublicListingCatalog;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicListingVideosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake();
        $this->company = $this->company('video-listings');
        $this->admin = User::factory()->create(['active' => true]);
        $this->admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        $this->actingAs($this->admin);
        app()->instance('currentTenant', $this->company);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function company(string $slug): Tenant
    {
        $path = tempnam(sys_get_temp_dir(), 'haven_listing_videos_');
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
            $table->unsignedInteger('property_id');
            $table->string('unit_number');
            $table->integer('bedrooms');
            $table->integer('bathrooms');
            $table->string('status');
            $table->decimal('nightly_rate', 12, 2);
        });
        $schema->create('partner_apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('apartment_name');
            $table->string('unit_number')->nullable();
            $table->integer('bedrooms');
            $table->integer('bathrooms')->nullable();
            $table->string('status');
        });
        $schema->create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('unit_id')->nullable();
            $table->unsignedInteger('partner_apartment_id')->nullable();
            $table->string('booking_type')->default('managed');
            $table->string('status');
            $table->date('check_in');
            $table->date('check_out');
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Coral Gables']);
        DB::connection('tenant')->table('units')->insert(['id' => 1, 'property_id' => 1, 'unit_number' => 'F5', 'bedrooms' => 2, 'bathrooms' => 2, 'status' => 'active', 'nightly_rate' => 10000]);
        DB::connection('tenant')->table('partner_apartments')->insert(['id' => 1, 'apartment_name' => 'Partner apartment', 'unit_number' => 'P1', 'bedrooms' => 1, 'bathrooms' => 1, 'status' => 'active']);

        return Tenant::factory()->create(['slug' => $slug, 'database_name' => $path, 'active' => true, 'plan' => 'legacy', 'subscription_status' => 'legacy']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'apartment_ref' => 'unit:1', 'slug' => 'coral-gables-f5', 'title' => 'Seaview stay at Coral Gables',
            'description' => 'A welcoming apartment with comfortable bedrooms, a private balcony and a convenient location near the beach.',
            'city' => 'Mombasa', 'max_guests' => 4, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00',
            'starting_price' => null, 'amenities' => [], 'status' => 'draft',
        ], $overrides);
    }

    private function videos(): array
    {
        return [['url' => 'https://youtu.be/abCDef_1234?si=remove-tracking', 'title' => 'Inside the apartment'], ['url' => 'https://www.youtube.com/shorts/Z9-xY8wV7u6', 'title' => 'Balcony and sea view']];
    }

    private function create(array $overrides = []): object
    {
        $data = $this->payload($overrides);
        $this->post(route('public-listings.store', $this->company->slug), $data)->assertSessionHasNoErrors();

        return DB::table('public_listings')->where('tenant_id', $this->company->id)->where('slug', $data['slug'])->first();
    }

    public function test_staff_can_save_two_videos_and_get_only_canonical_safe_metadata_in_forms_and_audits(): void
    {
        $listing = $this->create(['youtube_videos' => $this->videos()]);
        $stored = json_decode($listing->youtube_videos, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['video_id' => 'abCDef_1234', 'title' => 'Inside the apartment'], ['video_id' => 'Z9-xY8wV7u6', 'title' => 'Balcony and sea view']], $stored);
        $audit = json_decode(DB::table('public_listing_audit_events')->value('after_snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($listing->youtube_videos, $audit['listing']['youtube_videos']);
        $projection = app(PublicListingCatalog::class)->preview($this->company, $listing->id);
        $this->assertCount(2, $projection['videos']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/abCDef_1234?rel=0', $projection['videos'][0]['embed_url']);
        $this->assertStringNotContainsString('remove-tracking', json_encode($projection['videos'], JSON_THROW_ON_ERROR));
        $this->get(route('public-listings.edit', ['tenant' => $this->company->slug, 'id' => $listing->id]))->assertOk()->assertSee('Video tours')->assertSee('youtube_videos[0][url]', false)->assertSee('youtube_videos[1][url]', false)->assertSee('Inside the apartment')->assertSee('https://www.youtube.com/watch?v=abCDef_1234', false);
        Http::assertNothingSent();
    }

    public function test_older_updates_preserve_videos_and_explicit_empty_slots_remove_them_with_history(): void
    {
        $listing = $this->create(['youtube_videos' => $this->videos()]);
        $url = route('public-listings.update', ['tenant' => $this->company->slug, 'id' => $listing->id]);
        $this->post($url, $this->payload(['version' => 1, 'title' => 'Updated apartment title']))->assertSessionHasNoErrors();
        $this->assertSame($listing->youtube_videos, DB::table('public_listings')->value('youtube_videos'));
        $this->post($url, $this->payload(['version' => 2, 'youtube_videos' => [['url' => '', 'title' => ''], ['url' => '', 'title' => '']]]))->assertSessionHasNoErrors();
        $this->assertSame('[]', DB::table('public_listings')->value('youtube_videos'));
        $audit = DB::table('public_listing_audit_events')->latest('id')->first();
        $this->assertSame($listing->youtube_videos, json_decode($audit->before_snapshot, true, 512, JSON_THROW_ON_ERROR)['listing']['youtube_videos']);
        $this->assertSame('[]', json_decode($audit->after_snapshot, true, 512, JSON_THROW_ON_ERROR)['listing']['youtube_videos']);
    }

    public function test_invalid_or_duplicate_videos_cannot_overwrite_valid_saved_videos(): void
    {
        $listing = $this->create(['youtube_videos' => $this->videos()]);
        $url = route('public-listings.update', ['tenant' => $this->company->slug, 'id' => $listing->id]);
        foreach ([
            [[['url' => 'https://evil.example/watch?v=abCDef_1234']], 'youtube_videos.0.url'],
            [[['url' => 'https://youtu.be/abCDef_1234'], ['url' => 'https://www.youtube.com/watch?v=abCDef_1234']], 'youtube_videos.1.url'],
            [[['url' => 'https://youtu.be/abCDef_1234', 'title' => '<b>Tour</b>']], 'youtube_videos.0.title'],
            [[['url' => '', 'title' => 'Tour']], 'youtube_videos.0.url'],
            [[['url' => ''], ['url' => ''], ['url' => '']], 'youtube_videos'],
        ] as [$input, $error]) {
            $this->post($url, $this->payload(['version' => 1, 'youtube_videos' => $input]))->assertSessionHasErrors($error);
            $this->assertSame($listing->youtube_videos, DB::table('public_listings')->value('youtube_videos'));
        }
        $this->assertDatabaseCount('public_listing_audit_events', 1);
        $this->assertSame(1, DB::table('public_listings')->value('version'));
        Http::assertNothingSent();
    }

    public function test_partner_listings_support_videos_and_older_listings_have_an_empty_video_projection(): void
    {
        $managed = $this->create();
        $this->assertNull($managed->youtube_videos);
        $this->assertSame([], app(PublicListingCatalog::class)->preview($this->company, $managed->id)['videos']);
        $partner = $this->create(['apartment_ref' => 'partner:1', 'slug' => 'partner-tour', 'youtube_videos' => [$this->videos()[0]]]);
        $projection = app(PublicListingCatalog::class)->preview($this->company, $partner->id);
        $this->assertSame('partner', $projection['inventory_kind']);
        $this->assertCount(1, $projection['videos']);
        $this->assertSame('https://www.youtube.com/watch?v=abCDef_1234', $projection['videos'][0]['watch_url']);
        $this->assertNull($projection['starting_price']);
    }

    public function test_invalid_legacy_video_json_never_crashes_preview_or_admin_forms(): void
    {
        $listing = $this->create();
        foreach (['{invalid', json_encode([['video_id' => 'abCDef_1234', 'embed_url' => 'https://evil.example']]), json_encode([['video_id' => 'abCDef_1234', 'title' => '<script>bad()</script>']])] as $legacy) {
            DB::table('public_listings')->where('id', $listing->id)->update(['youtube_videos' => $legacy]);
            $this->assertSame([], app(PublicListingCatalog::class)->preview($this->company, $listing->id)['videos']);
            $this->get(route('public-listings.edit', ['tenant' => $this->company->slug, 'id' => $listing->id]))->assertOk()->assertDontSee('https://evil.example')->assertDontSee('<script>bad()</script>', false);
        }
    }

    public function test_cross_company_and_unprivileged_updates_cannot_change_videos(): void
    {
        $listing = $this->create(['youtube_videos' => $this->videos()]);
        $other = $this->company('other-video-company');
        $this->admin->tenants()->attach($other, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        $this->post(route('public-listings.update', ['tenant' => $other->slug, 'id' => $listing->id]), $this->payload(['version' => 1, 'youtube_videos' => []]))->assertNotFound();
        $cleaner = User::factory()->create(['active' => true]);
        $cleaner->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 99]);
        $this->actingAs($cleaner)->post(route('public-listings.update', ['tenant' => $this->company->slug, 'id' => $listing->id]), $this->payload(['version' => 1, 'youtube_videos' => []]))->assertForbidden();
        $this->assertSame($listing->youtube_videos, DB::table('public_listings')->value('youtube_videos'));
        $this->assertDatabaseCount('public_listing_audit_events', 1);
    }

    private function enablePublicSite(): void
    {
        DB::table('public_sites')->insert(['tenant_id' => $this->company->id, 'enabled' => true, 'enquiry_assignee_id' => $this->admin->id, 'response_hours' => 24]);
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    public function test_published_media_has_two_safe_video_previews_and_every_photo_is_reachable_before_javascript(): void
    {
        $this->enablePublicSite();
        $videos = $this->videos();
        $videos[0]['title'] = 'Inside "our home" & balcony';
        $photos = array_map(fn (int $index): UploadedFile => UploadedFile::fake()->image('apartment-'.$index.'.jpg', 20, 20), range(1, 5));
        $listing = $this->create(['status' => 'published', 'youtube_videos' => $videos, 'photos' => $photos]);
        $response = $this->get(route('public-stays.show', [$this->company->slug, $listing->slug]))->assertOk();
        $html = $response->getContent();
        $document = $this->document($html);
        $this->assertSame(2, $document->query('//a[@data-play-video]')->length);
        $this->assertSame(0, $document->query('//iframe')->length);
        $this->assertStringNotContainsString('<iframe', strtolower($html));
        $this->assertStringContainsString('Inside &quot;our home&quot; &amp; balcony', $html);
        $this->assertSame($videos[0]['title'], $document->query('//a[@data-play-video]')->item(0)->getAttribute('data-video-title'));
        foreach (['abCDef_1234', 'Z9-xY8wV7u6'] as $id) {
            $watch = 'https://www.youtube.com/watch?v='.$id;
            $this->assertSame(2, $document->query('//a[@href="'.$watch.'"]')->length);
            $this->assertSame('https://www.youtube-nocookie.com/embed/'.$id.'?rel=0', $document->query('//a[@data-play-video and @href="'.$watch.'"]')->item(0)->getAttribute('data-video-url'));
        }
        $slides = $document->query('//section[@id="stay-photos"]//div[@data-slider-track]/a[@data-gallery-photo]');
        $this->assertSame(5, $slides->length, 'Every photo is in the slider and reachable without JavaScript.');
        $this->assertSame('high', $slides->item(0)->getElementsByTagName('img')->item(0)->getAttribute('fetchpriority'));
        $this->assertSame('lazy', $slides->item(1)->getElementsByTagName('img')->item(0)->getAttribute('loading'));
        $this->assertSame(5, $document->query('//*[@data-slider-thumbs]//*[@data-slide-go]')->length);
        $gridPhotos = $document->query('//*[@id="photo-grid"]/a[@data-gallery-photo]');
        $this->assertSame(5, $gridPhotos->length);
        $projection = app(PublicListingCatalog::class)->findPublished($this->company, $listing->slug);
        foreach ($gridPhotos as $index => $photo) {
            $this->assertSame((string) $index, $photo->getAttribute('data-photo-index'));
            // Gallery links ask for the 1200px WebP copy made at upload; the original stays available.
            $this->assertSame($projection['photos'][$index]['url'].'?w=1200', $photo->getAttribute('href'));
            $this->get($photo->getAttribute('href'))->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
            $this->get($projection['photos'][$index]['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        }
        $this->assertSame(5, $document->query('//*[@data-photo-dialog]//*[@data-photo-go]')->length);
        Http::assertNothingSent();
    }

    public function test_published_video_section_survives_older_updates_and_disappears_after_explicit_removal(): void
    {
        $this->enablePublicSite();
        $listing = $this->create(['status' => 'published', 'youtube_videos' => $this->videos(), 'photos' => [UploadedFile::fake()->image('apartment.jpg', 20, 20)]]);
        $page = route('public-stays.show', [$this->company->slug, $listing->slug]);
        $update = route('public-listings.update', [$this->company->slug, $listing->id]);
        $initial = $this->get($page)->assertOk();
        $this->assertSame(2, $this->document($initial->getContent())->query('//*[@id="stay-videos"]//a[@data-play-video]')->length);
        $this->post($update, $this->payload(['version' => 1, 'status' => 'published']))->assertSessionHasNoErrors();
        $preserved = $this->get($page)->assertOk();
        $this->assertSame(2, $this->document($preserved->getContent())->query('//*[@id="stay-videos"]//a[@data-play-video]')->length);
        $this->post($update, $this->payload(['version' => 2, 'status' => 'published', 'youtube_videos' => [['url' => '', 'title' => ''], ['url' => '', 'title' => '']]]))->assertSessionHasNoErrors();
        $removed = $this->get($page)->assertOk();
        $document = $this->document($removed->getContent());
        $this->assertSame(0, $document->query('//*[@id="stay-videos"]')->length);
        $this->assertSame(0, $document->query('//a[@data-play-video]')->length);
        $this->assertSame(0, $document->query('//a[@href="#stay-videos"]')->length);
        $this->assertSame(1, $document->query('//*[@id="photo-grid"]/a[@data-gallery-photo]')->length);
        Http::assertNothingSent();
    }
}
