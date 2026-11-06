<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PublicListingCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPartnerListingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->company = $this->company('pilot-listings');
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
        $path = tempnam(sys_get_temp_dir(), 'haven_partner_listing_');
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
            $table->string('notes')->nullable();
        });
        $schema->create('partner_apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('apartment_name');
            $table->string('building_name')->nullable();
            $table->string('unit_number')->nullable();
            $table->string('location');
            $table->integer('bedrooms');
            $table->integer('bathrooms')->nullable();
            $table->string('status');
            $table->string('owner_name');
            $table->string('owner_phone');
            $table->text('notes');
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Coral Gables']);
        foreach (range(1, 9) as $id) {
            DB::connection('tenant')->table('units')->insert([
                'id' => $id, 'property_id' => 1, 'unit_number' => 'F'.$id, 'bedrooms' => 2, 'bathrooms' => 2,
                'status' => $id === 9 ? 'inactive' : 'active', 'nightly_rate' => 10000, 'notes' => 'PRIVATE-MANAGED-NOTE',
            ]);
        }
        foreach (range(1, 4) as $id) {
            DB::connection('tenant')->table('partner_apartments')->insert([
                'id' => $id, 'apartment_name' => 'Partner Stay '.$id, 'building_name' => 'Private Building', 'unit_number' => 'P'.$id,
                'location' => 'PRIVATE-PARTNER-LOCATION', 'bedrooms' => 1, 'bathrooms' => null, 'status' => $id === 4 ? 'inactive' : 'active',
                'owner_name' => 'PRIVATE-OWNER', 'owner_phone' => 'PRIVATE-OWNER-PHONE', 'notes' => 'PRIVATE-PARTNER-NOTE',
            ]);
        }

        return Tenant::factory()->create(['slug' => $slug, 'database_name' => $path, 'active' => true, 'plan' => 'legacy', 'subscription_status' => 'legacy']);
    }

    private function arguments(array $overrides = []): array
    {
        return array_replace(['tenant' => $this->company->slug, '--actor' => $this->admin->id], $overrides);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'apartment_ref' => 'unit:1', 'slug' => 'managed-stay', 'title' => 'Managed apartment near the beach',
            'description' => 'A welcoming apartment with a comfortable lounge and bedrooms, close to restaurants and the beach.',
            'city' => 'Mombasa', 'max_guests' => 4, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00',
            'starting_price' => null, 'amenities' => ['wifi'], 'status' => 'draft',
        ], $overrides);
    }

    private function save(array $overrides = []): object
    {
        $data = $this->payload($overrides);
        $this->post(route('public-listings.store', $this->company->slug), $data)->assertSessionHasNoErrors();

        return DB::table('public_listings')->where('tenant_id', $this->company->id)->where('slug', $data['slug'])->first();
    }

    private function enableSite(Tenant $tenant): void
    {
        DB::table('public_sites')->insert(['tenant_id' => $tenant->id, 'enabled' => true, 'enquiry_assignee_id' => $this->admin->id, 'response_hours' => 24, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_catalogue_keeps_colliding_managed_and_partner_ids_distinct_without_private_fields(): void
    {
        $catalog = app(PublicListingCatalog::class);
        $this->assertCount(9, $catalog->units($this->company));
        $apartments = $catalog->apartments($this->company)->where('status', 'active')->keyBy('reference');
        $this->assertCount(11, $apartments);
        $this->assertSame(1, $apartments['unit:1']->unit_id);
        $this->assertNull($apartments['unit:1']->partner_apartment_id);
        $this->assertSame(1, $apartments['partner:1']->partner_apartment_id);
        $this->assertNull($apartments['partner:1']->unit_id);
        $this->assertNull($apartments['partner:1']->nightly_rate);
        $this->assertNull($apartments['partner:1']->bathrooms);
        $encoded = json_encode($apartments, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-OWNER', 'PRIVATE-PARTNER', 'PRIVATE-MANAGED', 'owner_phone', 'notes', 'database_name'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_preparation_creates_eleven_incomplete_private_drafts_once_with_a_truthful_actor(): void
    {
        $configuration = config('database.connections.tenant');
        $this->artisan('haven:prepare-public-catalogue', $this->arguments())->expectsOutput('Prepared 11 private drafts (8 managed, 3 partner). Existing listings were unchanged.')->assertSuccessful();
        $this->assertDatabaseCount('public_listings', 11);
        $this->assertDatabaseCount('public_listing_audit_events', 11);
        $this->assertSame(8, DB::table('public_listings')->whereNotNull('unit_id')->count());
        $this->assertSame(3, DB::table('public_listings')->whereNotNull('partner_apartment_id')->count());
        $this->assertSame(['draft'], DB::table('public_listings')->pluck('status')->unique()->all());
        $this->assertSame([''], DB::table('public_listings')->pluck('description')->unique()->all());
        $this->assertSame([''], DB::table('public_listings')->pluck('city')->unique()->all());
        foreach (['max_guests', 'min_nights', 'starting_price'] as $column) {
            $this->assertSame(0, DB::table('public_listings')->whereNotNull($column)->count());
        }
        $this->assertSame([$this->admin->id], DB::table('public_listing_audit_events')->pluck('actor_id')->unique()->all());
        $this->assertDatabaseCount('public_listing_photos', 0);
        $this->assertDatabaseCount('public_sites', 0);
        $this->assertDatabaseCount('manual_sms_messages', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        DB::table('public_listings')->where('unit_id', 1)->update(['title' => 'Reviewed title']);
        $before = DB::table('public_listings')->orderBy('id')->get()->toJson();
        $this->artisan('haven:prepare-public-catalogue', $this->arguments())->expectsOutput('Prepared 0 private drafts (0 managed, 0 partner). Existing listings were unchanged.')->assertSuccessful();
        $this->assertSame($before, DB::table('public_listings')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('public_listing_audit_events', 11);
        $this->assertSame($configuration, config('database.connections.tenant'));
        $this->assertSame($this->company, app('currentTenant'));
        $this->assertSame(9, DB::connection('tenant')->table('units')->count());
        $this->assertSame(4, DB::connection('tenant')->table('partner_apartments')->count());
    }

    public function test_dry_run_and_unauthorized_actors_never_prepare_drafts(): void
    {
        $this->artisan('haven:prepare-public-catalogue', $this->arguments(['--dry-run' => true]))->expectsOutput('Dry run: 11 missing private drafts (8 managed, 3 partner). Nothing was changed.')->assertSuccessful();
        $outsider = User::factory()->create(['active' => true]);
        $cleaner = User::factory()->create(['active' => true]);
        $cleaner->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => $cleaner->id]);
        foreach ([null, $outsider->id, $cleaner->id] as $actor) {
            $this->artisan('haven:prepare-public-catalogue', $this->arguments(['--actor' => $actor]))->assertFailed();
        }
        $this->admin->update(['active' => false]);
        $this->artisan('haven:prepare-public-catalogue', $this->arguments())->assertFailed();
        $this->assertDatabaseCount('public_listings', 0);
        $this->assertDatabaseCount('public_listing_audit_events', 0);
        $this->assertDatabaseCount('public_sites', 0);
    }

    public function test_prepare_button_uses_logged_in_actor_and_cleaners_cannot_prepare(): void
    {
        $this->get(route('public-listings.index', $this->company->slug))->assertOk()->assertSee('Prepare listings from existing apartments')->assertSee('8 active managed apartments and 3 active partner apartments');
        $this->post(route('public-listings.prepare', $this->company->slug), ['actor' => 9999])->assertRedirect(route('public-listings.index', $this->company->slug))->assertSessionHasNoErrors();
        $this->assertSame([$this->admin->id], DB::table('public_listing_audit_events')->pluck('actor_id')->unique()->all());
        $cleaner = User::factory()->create(['active' => true]);
        $cleaner->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => $cleaner->id]);
        $this->actingAs($cleaner)->post(route('public-listings.prepare', $this->company->slug))->assertForbidden();
        $this->assertDatabaseCount('public_listings', 11);
    }

    public function test_partner_can_publish_with_price_on_request_while_managed_zero_rate_is_rejected(): void
    {
        $this->enableSite($this->company);
        $managed = $this->save(['status' => 'published', 'photos' => [UploadedFile::fake()->image('managed.jpg', 20, 20)]]);
        $partner = $this->save(['apartment_ref' => 'partner:1', 'slug' => 'partner-stay', 'status' => 'published', 'photos' => [UploadedFile::fake()->image('partner.jpg', 20, 20)]]);
        $this->assertNull($partner->unit_id);
        $this->assertEquals(1, $partner->partner_apartment_id);
        $catalog = app(PublicListingCatalog::class);
        $this->assertCount(2, $catalog->published($this->company));
        $this->assertSame('managed', $catalog->findPublished($this->company, $managed->slug)['inventory_kind']);
        $projection = $catalog->findPublished($this->company, $partner->slug);
        $this->assertSame('partner', $projection['inventory_kind']);
        $this->assertNull($projection['unit_id']);
        $this->assertSame(1, $projection['partner_apartment_id']);
        $this->assertNull($projection['starting_price']);
        $this->assertNull($projection['bathrooms']);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($projection, JSON_THROW_ON_ERROR));
        DB::connection('tenant')->table('units')->where('id', 2)->update(['nightly_rate' => 0]);
        $this->post(route('public-listings.store', $this->company->slug), $this->payload(['apartment_ref' => 'unit:2', 'slug' => 'zero-rate', 'status' => 'published', 'photos' => [UploadedFile::fake()->image('zero.jpg', 20, 20)]]))->assertSessionHasErrors('starting_price');
        $this->assertDatabaseCount('public_listings', 2);
    }

    public function test_typed_selector_rejects_conflicting_references_duplicate_partner_and_inactive_partner(): void
    {
        $url = route('public-listings.store', $this->company->slug);
        $this->post($url, $this->payload(['unit_id' => 1, 'partner_apartment_id' => 1]))->assertSessionHasErrors('apartment_ref');
        $this->post($url, $this->payload(['apartment_ref' => '', 'unit_id' => 1, 'partner_apartment_id' => 1]))->assertSessionHasErrors('unit_id');
        $this->post($url, $this->payload(['apartment_ref' => 'partner:4']))->assertSessionHasErrors('apartment_ref');
        $this->post($url, $this->payload(['apartment_ref' => 'partner:999']))->assertSessionHasErrors('apartment_ref');
        $this->save(['apartment_ref' => 'partner:1', 'slug' => 'partner-stay']);
        $this->post($url, $this->payload(['apartment_ref' => 'partner:1', 'slug' => 'duplicate']))->assertSessionHasErrors('apartment_ref');
        $this->get(route('public-listings.create', $this->company->slug))->assertOk()->assertSee('value="unit:1"', false)->assertSee('value="partner:1"', false)->assertDontSee('PRIVATE-OWNER');
        $this->assertDatabaseCount('public_listings', 1);
    }

    public function test_prepared_drafts_keep_unknown_guest_fields_blank_and_cannot_be_exposed_by_status_only(): void
    {
        $this->artisan('haven:prepare-public-catalogue', $this->arguments())->assertSuccessful();
        $this->enableSite($this->company);
        $listing = DB::table('public_listings')->where('partner_apartment_id', 1)->first();
        $photo = $this->photo($listing->id);
        $this->get(route('public-listings.edit', ['tenant' => $this->company->slug, 'id' => $listing->id]))->assertOk()->assertSee('name="max_guests" required type="number" min="1" max="100" value=""', false)->assertSee('name="min_nights" required type="number" min="1" max="365" value=""', false);
        $catalog = app(PublicListingCatalog::class);
        $preview = $catalog->preview($this->company, $listing->id);
        $this->assertNull($preview['max_guests']);
        $this->assertNull($preview['min_nights']);
        DB::table('public_listings')->where('id', $listing->id)->update(['status' => 'published']);
        $this->assertCount(0, $catalog->published($this->company));
        $this->get(route('public-stays.photo', ['company' => $this->company->slug, 'slug' => $listing->slug, 'photo' => $photo]))->assertNotFound();
        $this->post(route('public-listings.update', ['tenant' => $this->company->slug, 'id' => $listing->id]), $this->payload(['apartment_ref' => 'partner:1', 'slug' => $listing->slug, 'version' => 1, 'max_guests' => null, 'min_nights' => null, 'description' => '', 'city' => '']))->assertSessionHasErrors(['max_guests', 'min_nights', 'description', 'city']);
    }

    private function photo(int $listingId): int
    {
        $key = 'listing-photos/'.$this->company->id.'/'.$listingId.'/'.str_repeat('a', 48).'.jpg';
        $file = UploadedFile::fake()->image('photo.jpg', 20, 20);
        Storage::disk('local')->put($key, file_get_contents($file->getPathname()));

        return DB::table('public_listing_photos')->insertGetId(['tenant_id' => $this->company->id, 'listing_id' => $listingId, 'storage_key' => $key, 'mime_type' => 'image/jpeg', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_partner_photo_authorization_queries_only_that_partner_and_hides_inactive_or_other_company_sources(): void
    {
        $this->enableSite($this->company);
        $listing = $this->save(['apartment_ref' => 'partner:1', 'slug' => 'partner-stay', 'status' => 'published', 'photos' => [UploadedFile::fake()->image('partner.jpg', 20, 20)]]);
        $photo = DB::table('public_listing_photos')->value('id');
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $image = app(PublicListingCatalog::class)->image($this->company, $listing->slug, $photo);
        $this->assertSame('image/jpeg', $image['mime_type']);
        $partnerQueries = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "partner_apartments"')));
        $this->assertCount(1, $partnerQueries);
        $this->assertStringContainsString('where "id" = ?', $partnerQueries[0]);
        $this->assertCount(0, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "units"')));
        DB::connection('tenant')->table('partner_apartments')->where('id', 1)->update(['status' => 'inactive']);
        $this->get(route('public-stays.photo', ['company' => $this->company->slug, 'slug' => $listing->slug, 'photo' => $photo]))->assertNotFound();
        $other = $this->company('other-pilot');
        $this->admin->tenants()->attach($other, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        $this->enableSite($other);
        $this->get(route('public-listings.photo', ['tenant' => $other->slug, 'id' => $listing->id, 'photo' => $photo]))->assertNotFound();
        $this->get(route('public-stays.photo', ['company' => $other->slug, 'slug' => $listing->slug, 'photo' => $photo]))->assertNotFound();
    }

    public function test_audit_failure_rolls_back_all_prepared_drafts(): void
    {
        DB::statement("CREATE TRIGGER reject_preparation_audit BEFORE INSERT ON public_listing_audit_events BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
        $this->artisan('haven:prepare-public-catalogue', $this->arguments())->assertFailed();
        $this->assertDatabaseCount('public_listings', 0);
        $this->assertDatabaseCount('public_listing_audit_events', 0);
        $this->assertDatabaseCount('public_sites', 0);
    }

    public function test_migration_rollback_preserves_partner_enquiry_identity_even_without_a_listing(): void
    {
        DB::table('sales_leads')->insert(['tenant_id' => $this->company->id, 'name' => 'Guest enquiry', 'phone' => '+254700000001', 'source' => 'website', 'channel' => 'website', 'assigned_to' => $this->admin->id, 'next_action' => 'Respond to guest', 'public_partner_apartment_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $migration = require database_path('migrations/2026_11_06_142451_extend_public_listings_for_partner_apartments.php');
        try {
            $migration->down();
            $this->fail('Partner enquiry identity must be preserved when rolling back.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('preserve partner enquiry sources', $exception->getMessage());
            $this->assertEquals(1, DB::table('sales_leads')->value('public_partner_apartment_id'));
        }
    }
}
