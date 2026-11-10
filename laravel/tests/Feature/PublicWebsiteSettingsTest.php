<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicWebsiteSettingsTest extends TestCase
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
        $this->company = $this->company('website-settings');
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
        $path = tempnam(sys_get_temp_dir(), 'haven_website_');
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
            'id' => 1, 'property_id' => 1, 'unit_number' => 'F5', 'bedrooms' => 2, 'bathrooms' => 2,
            'status' => 'active', 'nightly_rate' => '10000.00', 'owner_phone' => null, 'notes' => null,
        ]);

        return Tenant::factory()->create(['name' => 'Website company', 'slug' => $slug, 'database_name' => $path, 'active' => true, 'plan' => 'legacy', 'subscription_status' => 'legacy']);
    }

    private function sitePayload(array $overrides = []): array
    {
        return array_replace([
            'version' => 0, 'settings_page' => 1, 'enabled' => 0, 'headline' => 'Your next stay',
            'enquiry_assignee_id' => $this->admin->id, 'response_hours' => 24,
            'seo_title' => 'Serviced apartments in Mombasa',
            'seo_description' => 'Browse furnished apartments and send an enquiry for your dates.',
            'whatsapp_number' => '+254712345678',
            'whatsapp_message' => 'Hello! I am interested in {listing_title}: {listing_url}',
            'navigation_links' => [
                ['label' => 'Stays', 'target' => 'stays'],
                ['label' => 'Welcome', 'target' => 'home'],
                ['label' => '', 'target' => ''],
            ],
        ], $overrides);
    }

    private function site(): ?object
    {
        return DB::table('public_sites')->where('tenant_id', $this->company->id)->first();
    }

    public function test_settings_page_saves_search_whatsapp_and_menu_and_returns_to_settings(): void
    {
        $this->get(route('public-listings.settings', $this->company->slug))
            ->assertOk()->assertSee('Search appearance')->assertSee('WhatsApp contact')->assertSee('Website menu');

        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('public-listings.settings', $this->company->slug));

        $site = $this->site();
        $this->assertSame('Serviced apartments in Mombasa', $site->seo_title);
        $this->assertSame('+254712345678', $site->whatsapp_number);
        $this->assertSame(1, (int) $site->version);
        $this->assertSame(
            [['label' => 'Stays', 'target' => 'stays'], ['label' => 'Welcome', 'target' => 'home']],
            json_decode($site->navigation_links, true),
        );
        $this->get(route('public-listings.settings', $this->company->slug))
            ->assertOk()->assertSee('value="Stays"', false)->assertSee('+254712345678');
    }

    public function test_invalid_website_settings_are_rejected_without_saving(): void
    {
        $invalid = [
            'whatsapp_number' => ['whatsapp_number' => '0712345678'],
            'seo_title' => ['seo_title' => '<script>alert(1)</script>'],
            'navigation_links.1.target' => ['navigation_links' => [['label' => 'Stays', 'target' => 'stays'], ['label' => 'More stays', 'target' => 'stays']]],
            'navigation_links.0.target' => ['navigation_links' => [['label' => 'Blog', 'target' => 'https://example.com']]],
            'navigation_links.0.label' => ['navigation_links' => [['label' => '', 'target' => 'home']]],
        ];
        foreach ($invalid as $field => $overrides) {
            $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload($overrides))
                ->assertSessionHasErrors($field);
        }
        $this->assertNull($this->site());
    }

    public function test_json_save_returns_the_redirect_and_message(): void
    {
        $this->postJson(route('public-listings.site.update', $this->company->slug), $this->sitePayload())
            ->assertOk()
            ->assertJson([
                'redirect' => route('public-listings.settings', $this->company->slug),
                'message' => 'Public site settings saved.',
            ]);
        $this->assertSame('+254712345678', $this->site()->whatsapp_number);
    }

    public function test_site_form_without_website_fields_keeps_existing_values(): void
    {
        $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload())->assertSessionHasNoErrors();

        $this->post(route('public-listings.site.update', $this->company->slug), [
            'version' => 1, 'headline' => 'Updated heading', 'enquiry_assignee_id' => $this->admin->id, 'response_hours' => 12,
        ])->assertSessionHasNoErrors()->assertRedirect(route('public-listings.index', $this->company->slug));

        $site = $this->site();
        $this->assertSame('Updated heading', $site->headline);
        $this->assertSame('Serviced apartments in Mombasa', $site->seo_title);
        $this->assertSame('+254712345678', $site->whatsapp_number);
        $this->assertCount(2, json_decode($site->navigation_links, true));
    }

    public function test_listing_saves_its_own_search_and_whatsapp_overrides(): void
    {
        $this->post(route('public-listings.store', $this->company->slug), [
            'unit_id' => 1, 'slug' => 'coral-gables-f5', 'title' => 'Seaview stay at Coral Gables',
            'description' => 'A bright apartment with comfortable bedrooms, a private balcony and a convenient location near the beach.',
            'city' => 'Mombasa', 'max_guests' => 4, 'min_nights' => 2, 'check_in_time' => '14:00', 'check_out_time' => '10:00',
            'starting_price' => '12000.00', 'amenities' => ['wifi'], 'status' => 'draft', 'featured' => 0,
            'seo_title' => 'Coral Gables F5 seaview apartment', 'seo_description' => 'Two-bedroom apartment near Nyali Beach.',
            'whatsapp_number' => '+254700000001', 'whatsapp_message' => 'Hi, is {listing_title} free?',
        ])->assertSessionHasNoErrors();

        $listing = DB::table('public_listings')->where('tenant_id', $this->company->id)->first();
        $this->assertSame('Coral Gables F5 seaview apartment', $listing->seo_title);
        $this->assertSame('+254700000001', $listing->whatsapp_number);
        $this->assertSame('Hi, is {listing_title} free?', $listing->whatsapp_message);
    }

    public function test_only_administrators_can_open_or_save_website_settings(): void
    {
        foreach (['cleaner', 'sales', 'owner', 'manager'] as $role) {
            $user = User::factory()->create(['active' => true]);
            $user->tenants()->attach($this->company, ['roles' => json_encode([$role]), 'legacy_user_id' => $user->id]);
            $this->actingAs($user)->get(route('public-listings.settings', $this->company->slug))->assertForbidden();
            $this->post(route('public-listings.site.update', $this->company->slug), $this->sitePayload())->assertForbidden();
        }
        $this->assertNull($this->site());
    }
}
