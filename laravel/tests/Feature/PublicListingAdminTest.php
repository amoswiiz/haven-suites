<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PublicListingCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicListingAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    private array $databaseFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->company = $this->company('public-admin');
        $this->admin = User::factory()->create(['active' => true]);
        $this->admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        $this->actingAs($this->admin);
        app()->instance('currentTenant', $this->company);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        foreach ($this->databaseFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function company(string $slug): Tenant
    {
        $path = tempnam(sys_get_temp_dir(), 'haven_listing_');
        $this->databaseFiles[] = $path;
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
            $table->string('owner_phone')->nullable();
            $table->string('notes')->nullable();
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Coral Gables']);
        DB::connection('tenant')->table('units')->insert([
            ['id' => 1, 'property_id' => 1, 'unit_number' => 'F5', 'bedrooms' => 2, 'bathrooms' => 2, 'status' => 'active', 'nightly_rate' => '10000.00', 'owner_phone' => 'PRIVATE-OWNER-PHONE', 'notes' => 'CONFIDENTIAL-OWNER-TERMS'],
            ['id' => 2, 'property_id' => 1, 'unit_number' => 'F6', 'bedrooms' => 1, 'bathrooms' => 1, 'status' => 'inactive', 'nightly_rate' => '6000.00', 'owner_phone' => null, 'notes' => null],
        ]);

        return Tenant::factory()->create(['name' => 'Listing company', 'slug' => $slug, 'database_name' => $path, 'active' => true, 'plan' => 'legacy', 'subscription_status' => 'legacy']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'unit_id' => 1, 'slug' => 'coral-gables-f5', 'title' => 'Seaview stay at Coral Gables',
            'description' => 'A bright apartment with comfortable bedrooms, a private balcony and a convenient location near the beach.',
            'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'public_location' => 'Near Nyali Beach',
            'max_guests' => 4, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00',
            'starting_price' => '12000.00', 'amenities' => ['wifi', 'pool'], 'house_rules' => 'No smoking indoors.',
            'status' => 'draft', 'featured' => 0,
        ], $overrides);
    }

    private function createListing(array $overrides = []): object
    {
        $this->post(route('public-listings.store', $this->company->slug), $this->payload($overrides))->assertSessionHasNoErrors();

        return DB::table('public_listings')->where('tenant_id', $this->company->id)->first();
    }

    private function enableSite(): void
    {
        $this->post(route('public-listings.site.update', $this->company->slug), [
            'version' => 0, 'enabled' => 1, 'headline' => 'Your next stay', 'enquiry_assignee_id' => $this->admin->id, 'response_hours' => 24,
        ])->assertSessionHasNoErrors();
    }

    public function test_management_forms_render_and_only_administrators_can_publish(): void
    {
        $this->get(route('public-listings.index', $this->company->slug))->assertOk()->assertSee('Public listings')->assertSee('Website settings');
        $this->get(route('public-listings.settings', $this->company->slug))->assertOk()->assertSee('Assign website enquiries to');
        $this->get(route('public-listings.create', $this->company->slug))->assertOk()->assertSee('Existing apartment')->assertDontSee('PRIVATE-OWNER-PHONE');
        foreach (['cleaner', 'sales', 'owner', 'manager'] as $role) {
            $user = User::factory()->create(['active' => true]);
            $user->tenants()->attach($this->company, ['roles' => json_encode([$role]), 'legacy_user_id' => $user->id]);
            $this->actingAs($user)->get(route('public-listings.index', $this->company->slug))->assertForbidden();
            $this->post(route('public-listings.store', $this->company->slug), $this->payload())->assertForbidden();
        }
        $this->assertDatabaseCount('public_listings', 0);
    }

    public function test_new_listing_is_a_private_draft_linked_to_the_existing_unit(): void
    {
        $listing = $this->createListing();
        $this->assertEquals(1, $listing->unit_id);
        $this->assertSame('draft', $listing->status);
        $this->assertDatabaseCount('public_listing_audit_events', 1);
        $this->assertCount(0, app(PublicListingCatalog::class)->published($this->company));
        $this->assertSame(2, DB::connection('tenant')->table('units')->count());
        $this->assertSame('CONFIDENTIAL-OWNER-TERMS', DB::connection('tenant')->table('units')->where('id', 1)->value('notes'));
    }

    public function test_publication_requires_a_photo_and_active_unit_without_overwriting_existing_listing(): void
    {
        $this->post(route('public-listings.store', $this->company->slug), $this->payload(['status' => 'published']))->assertSessionHasErrors('photos');
        $this->assertDatabaseCount('public_listings', 0);
        $this->post(route('public-listings.store', $this->company->slug), $this->payload(['unit_id' => 2]))->assertSessionHasErrors('unit_id');
        $this->createListing();
        $this->post(route('public-listings.store', $this->company->slug), $this->payload(['slug' => 'different-slug']))->assertSessionHasErrors('unit_id');
        $this->assertDatabaseCount('public_listings', 1);
    }

    public function test_published_projection_exposes_safe_fields_and_reencoded_photos_only(): void
    {
        $this->enableSite();
        $listing = $this->createListing(['status' => 'published', 'photos' => [UploadedFile::fake()->image('private-original.jpg', 100, 80)]]);
        $photo = DB::table('public_listing_photos')->first();
        Storage::disk('local')->assertExists($photo->storage_key);
        Storage::disk('public')->assertMissing($photo->storage_key);
        $this->assertStringNotContainsString('private-original', $photo->storage_key);
        $public = app(PublicListingCatalog::class)->findPublished($this->company, $listing->slug);
        $this->assertSame(2, $public['bedrooms']);
        $this->assertSame(2, $public['bathrooms']);
        $this->assertSame(['Wi-Fi', 'Swimming pool'], $public['amenity_labels']);
        $json = json_encode($public, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-OWNER-PHONE', $json);
        $this->assertStringNotContainsString('CONFIDENTIAL-OWNER-TERMS', $json);
        $this->assertStringNotContainsString('storage_key', $json);
        $this->assertStringNotContainsString('database_name', $json);
        $this->assertSame('image/jpeg', getimagesize(Storage::disk('local')->path($photo->storage_key))['mime']);
        $this->get(route('public-listings.photo', ['tenant' => $this->company->slug, 'id' => $listing->id, 'photo' => $photo->id]))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_site_enable_requires_an_active_sales_assignee_from_the_same_company(): void
    {
        $this->post(route('public-listings.site.update', $this->company->slug), ['version' => 0, 'enabled' => 1, 'response_hours' => 24])->assertSessionHasErrors('enquiry_assignee_id');
        $other = User::factory()->create(['active' => true]);
        $this->post(route('public-listings.site.update', $this->company->slug), ['version' => 0, 'enabled' => 1, 'enquiry_assignee_id' => $other->id, 'response_hours' => 24])->assertSessionHasErrors('enquiry_assignee_id');
        $this->enableSite();
        $this->assertNotNull(app(PublicListingCatalog::class)->site($this->company));
        $this->post(route('public-listings.site.update', $this->company->slug), ['version' => 0, 'enabled' => 0, 'response_hours' => 24])->assertSessionHasErrors('site');
        $this->assertTrue((bool) DB::table('public_sites')->value('enabled'));
    }

    public function test_uploaded_photos_are_resized_and_trailing_private_metadata_is_not_published(): void
    {
        $file = UploadedFile::fake()->image('location.jpg', 3200, 1800);
        file_put_contents($file->getPathname(), 'PRIVATE-GPS-METADATA', FILE_APPEND);
        $this->createListing(['photos' => [$file]]);
        $photo = DB::table('public_listing_photos')->first();
        $dimensions = getimagesize(Storage::disk('local')->path($photo->storage_key));
        $this->assertSame(2400, $dimensions[0]);
        $this->assertSame(1350, $dimensions[1]);
        $this->assertStringNotContainsString('PRIVATE-GPS-METADATA', Storage::disk('local')->get($photo->storage_key));
    }

    public function test_zero_unit_rate_can_remain_a_draft_but_publication_requires_a_positive_starting_price(): void
    {
        DB::connection('tenant')->table('units')->where('id', 1)->update(['nightly_rate' => 0]);
        $listing = $this->createListing(['starting_price' => null]);
        $this->assertSame('draft', $listing->status);
        $url = route('public-listings.update', ['tenant' => $this->company->slug, 'id' => $listing->id]);
        $this->post($url, $this->payload([
            'version' => 1, 'status' => 'published', 'starting_price' => null,
            'photos' => [UploadedFile::fake()->image('stay.jpg', 20, 20)],
        ]))->assertSessionHasErrors('starting_price');
        $this->assertSame('draft', DB::table('public_listings')->value('status'));
        $this->assertDatabaseCount('public_listing_photos', 0);
        $this->post($url, $this->payload([
            'version' => 1, 'status' => 'published', 'starting_price' => '4500.00',
            'photos' => [UploadedFile::fake()->image('stay.jpg', 20, 20)],
        ]))->assertSessionHasNoErrors();
        $this->assertSame('published', DB::table('public_listings')->value('status'));
        $this->assertEquals(4500, DB::table('public_listings')->value('starting_price'));
    }

    public function test_photo_removal_cannot_publish_an_empty_gallery_and_stale_updates_are_rejected(): void
    {
        $listing = $this->createListing(['status' => 'published', 'photos' => [UploadedFile::fake()->image('stay.png', 20, 20)]]);
        $photo = DB::table('public_listing_photos')->first();
        $url = route('public-listings.update', ['tenant' => $this->company->slug, 'id' => $listing->id]);
        $this->post($url, $this->payload(['version' => 1, 'status' => 'published', 'remove_photos' => [$photo->id]]))->assertSessionHasErrors('photos');
        Storage::disk('local')->assertExists($photo->storage_key);
        $this->post($url, $this->payload(['version' => 99]))->assertSessionHasErrors('listing');
        $this->assertSame(1, DB::table('public_listings')->value('version'));
        $this->post($url, $this->payload(['version' => 1, 'status' => 'paused', 'remove_photos' => [$photo->id]]))->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($photo->storage_key);
        $this->assertSame('paused', DB::table('public_listings')->value('status'));
    }

    public function test_photo_order_captions_and_id_scope_are_checked(): void
    {
        $listing = $this->createListing(['photos' => [UploadedFile::fake()->image('one.png', 20, 20), UploadedFile::fake()->image('two.png', 20, 20)]]);
        $photos = DB::table('public_listing_photos')->orderBy('id')->get();
        $url = route('public-listings.update', ['tenant' => $this->company->slug, 'id' => $listing->id]);
        $this->post($url, $this->payload(['version' => 1, 'photo_order' => [999999 => 0]]))->assertSessionHasErrors('photos');
        $this->post($url, $this->payload(['version' => 1, 'photo_order' => [$photos[1]->id => 0, $photos[0]->id => 10], 'photo_captions' => [$photos[1]->id => '<script>not executable</script>']]))->assertSessionHasNoErrors();
        $preview = app(PublicListingCatalog::class)->preview($this->company, $listing->id);
        $this->assertSame($photos[1]->id, $preview['photos'][0]['id']);
        $this->get(route('public-listings.edit', ['tenant' => $this->company->slug, 'id' => $listing->id]))->assertOk()->assertDontSee('<script>not executable</script>', false)->assertSee('&lt;script&gt;not executable&lt;/script&gt;', false);
    }

    public function test_invalid_photo_types_and_cross_company_records_are_rejected(): void
    {
        $this->post(route('public-listings.store', $this->company->slug), $this->payload(['photos' => [UploadedFile::fake()->create('code.svg', 1, 'image/svg+xml')]]))->assertSessionHasErrors('photos.0');
        $listing = $this->createListing();
        $other = $this->company('other-listings');
        $this->admin->tenants()->attach($other, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        $this->get(route('public-listings.edit', ['tenant' => $other->slug, 'id' => $listing->id]))->assertNotFound();
        $this->post(route('public-listings.update', ['tenant' => $other->slug, 'id' => $listing->id]), $this->payload(['version' => 1]))->assertNotFound();
        $this->assertSame($this->company->id, DB::table('public_listings')->value('tenant_id'));
    }

    public function test_published_listings_disappear_when_paused_unit_inactive_site_disabled_or_company_inactive(): void
    {
        $this->enableSite();
        $listing = $this->createListing(['status' => 'published', 'photos' => [UploadedFile::fake()->image('stay.png', 20, 20)]]);
        $catalog = app(PublicListingCatalog::class);
        $this->assertCount(1, $catalog->published($this->company));
        DB::connection('tenant')->table('units')->where('id', 1)->update(['status' => 'maintenance']);
        $this->assertCount(0, $catalog->published($this->company));
        DB::connection('tenant')->table('units')->where('id', 1)->update(['status' => 'active']);
        DB::table('public_listings')->where('id', $listing->id)->update(['status' => 'paused']);
        $this->assertCount(0, $catalog->published($this->company));
        DB::table('public_listings')->where('id', $listing->id)->update(['status' => 'published']);
        DB::table('public_sites')->update(['enabled' => false]);
        $this->assertCount(0, $catalog->published($this->company));
        DB::table('public_sites')->update(['enabled' => true]);
        $this->company->active = false;
        $this->assertCount(0, $catalog->published($this->company));
        $this->assertSame('coral-gables-f5', $listing->slug);
    }

    public function test_audit_failure_rolls_back_listing_and_cleans_uploaded_files(): void
    {
        DB::statement("CREATE TRIGGER reject_listing_audit BEFORE INSERT ON public_listing_audit_events BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
        $this->post(route('public-listings.store', $this->company->slug), $this->payload(['photos' => [UploadedFile::fake()->image('stay.jpg', 20, 20)]]))->assertServerError();
        $this->assertDatabaseCount('public_listings', 0);
        $this->assertDatabaseCount('public_listing_photos', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_listings_can_be_archived_restored_and_hidden_from_the_website(): void
    {
        $this->enableSite();
        $listing = $this->createListing();
        $tenant = $this->company->slug;

        $this->post(route('public-listings.archive', [$tenant, $listing->id]))->assertRedirect(route('public-listings.index', $tenant));
        $this->assertSame('archived', DB::table('public_listings')->find($listing->id)->status);
        $this->get(route('public-listings.index', $tenant))->assertOk()->assertDontSee($listing->title)->assertSee('Show archived listings (1)');
        $this->get(route('public-listings.index', $tenant).'?archived=1')->assertOk()->assertSee($listing->title)->assertSee('Restore listing');
        $this->get(route('public-stays.show', [$tenant, $listing->slug]))->assertNotFound();
        $this->get(route('public-listings.edit', [$tenant, $listing->id]))->assertOk()->assertSee('This listing is archived')->assertDontSee('Archive or delete this listing');

        $this->post(route('public-listings.restore', [$tenant, $listing->id]))->assertRedirect(route('public-listings.edit', ['tenant' => $tenant, 'id' => $listing->id]));
        $this->assertSame('draft', DB::table('public_listings')->find($listing->id)->status);
        $this->assertSame(['archived', 'restored'], DB::table('public_listing_audit_events')->where('listing_id', $listing->id)->whereIn('action', ['archived', 'restored'])->orderBy('id')->pluck('action')->all());
    }

    public function test_deleting_needs_confirmation_removes_photos_and_is_refused_when_enquiries_refer_to_the_listing(): void
    {
        $listing = $this->createListing();
        $tenant = $this->company->slug;
        $path = 'listing-photos/'.$this->company->id.'/'.$listing->id.'/photo.jpg';
        Storage::disk('local')->put($path, 'jpeg');
        DB::table('public_listing_photos')->insert(['tenant_id' => $this->company->id, 'listing_id' => $listing->id, 'storage_key' => $path, 'mime_type' => 'image/jpeg', 'sort_order' => 0]);

        $this->post(route('public-listings.destroy', [$tenant, $listing->id]))->assertSessionHasErrors('confirm_delete');
        DB::table('sales_leads')->insert(['tenant_id' => $this->company->id, 'name' => 'Guest', 'source' => 'website', 'channel' => 'phone', 'assigned_to' => $this->admin->id,
            'guests' => 1, 'stage' => 'new', 'next_action' => 'Call', 'public_listing_id' => $listing->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->post(route('public-listings.destroy', [$tenant, $listing->id]), ['confirm_delete' => 1])->assertSessionHasErrors('confirm_delete');
        $this->assertDatabaseHas('public_listings', ['id' => $listing->id]);

        DB::table('sales_leads')->where('public_listing_id', $listing->id)->delete();
        $this->post(route('public-listings.destroy', [$tenant, $listing->id]), ['confirm_delete' => 1])->assertRedirect(route('public-listings.index', $tenant));
        $this->assertDatabaseMissing('public_listings', ['id' => $listing->id]);
        $this->assertDatabaseMissing('public_listing_photos', ['listing_id' => $listing->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('public_listing_audit_events', ['listing_id' => $listing->id, 'action' => 'deleted']);
    }

    public function test_editor_shows_the_owner_contact_to_staff_only(): void
    {
        $listing = $this->createListing();
        $this->get(route('public-listings.edit', [$this->company->slug, $listing->id]))->assertOk()
            ->assertSee('Owner contact')->assertSee('Staff only')->assertSee('PRIVATE-OWNER-PHONE');
    }

    public function test_create_form_can_preselect_an_apartment_from_the_partner_page(): void
    {
        $this->get(route('public-listings.create', $this->company->slug).'?apartment=unit:1')->assertOk()->assertSee('value="unit:1" selected', false);
        $this->get(route('public-listings.create', $this->company->slug).'?apartment=unit:1;drop')->assertOk()->assertDontSee('value="unit:1" selected', false);
    }
}
