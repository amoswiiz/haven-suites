<?php

namespace Tests\Feature;

use App\Console\Commands\ImportWebsiteProperties;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Staff see how complete each listing is, what to fix first, and which listings may be the same apartment. */
class ListingQualityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->databaseFile = (string) tempnam(sys_get_temp_dir(), 'haven_quality_');
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
        $schema->create('partner_apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('apartment_name');
            $table->string('unit_number')->nullable();
            $table->string('location');
            $table->integer('bedrooms');
            $table->integer('bathrooms')->nullable();
            $table->string('status');
            $table->string('owner_name');
            $table->string('owner_phone')->nullable();
            $table->text('notes')->nullable();
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Pinecrest View']);
        DB::connection('tenant')->table('units')->insert([['id' => 1, 'property_id' => 1, 'unit_number' => 'A110', 'bedrooms' => 2, 'bathrooms' => 2, 'status' => 'active', 'nightly_rate' => 9000],
            ['id' => 2, 'property_id' => 1, 'unit_number' => 'B2', 'bedrooms' => 2, 'bathrooms' => 1, 'status' => 'active', 'nightly_rate' => 8000]]);
        DB::connection('tenant')->table('partner_apartments')->insert([
            ['id' => 1, 'apartment_name' => 'Pinecrest 2BR', 'unit_number' => '562', 'location' => 'Kilimani', 'bedrooms' => 2, 'bathrooms' => 2, 'status' => 'active',
                'owner_name' => ImportWebsiteProperties::OWNER_PLACEHOLDER, 'owner_phone' => null, 'notes' => null],
            ['id' => 2, 'apartment_name' => 'Coral Gables', 'unit_number' => '014', 'location' => 'Nyali', 'bedrooms' => 3, 'bathrooms' => 2, 'status' => 'active',
                'owner_name' => 'Amina Otieno', 'owner_phone' => '+254700000000', 'notes' => null],
        ]);
        $this->company = Tenant::factory()->create(['slug' => 'quality-stays', 'database_name' => $this->databaseFile, 'active' => true, 'plan' => 'legacy', 'subscription_status' => 'legacy']);
        $this->admin = User::factory()->create(['active' => true]);
        $this->admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        $this->actingAs($this->admin);
        app()->instance('currentTenant', $this->company);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        @unlink($this->databaseFile);
        parent::tearDown();
    }

    private function listing(array $values): int
    {
        return DB::table('public_listings')->insertGetId($values + [
            'tenant_id' => $this->company->id, 'unit_id' => null, 'partner_apartment_id' => null, 'description' => str_repeat('A calm apartment close to shops. ', 6),
            'neighbourhood' => null, 'max_guests' => 4, 'min_nights' => 1, 'check_in_time' => '12:00', 'check_out_time' => '10:00',
            'status' => 'published', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function event(int $listingId, string $action): void
    {
        DB::table('public_listing_audit_events')->insert(['tenant_id' => $this->company->id, 'listing_id' => $listingId, 'actor_id' => $this->admin->id,
            'action' => $action, 'after_snapshot' => '{}', 'created_at' => now()]);
    }

    public function test_listings_page_scores_listings_and_lists_the_weakest_first_with_duplicates(): void
    {
        $original = $this->listing(['unit_id' => 1, 'slug' => 'pinecrest-view-a110', 'title' => 'Pinecrest View / A110', 'city' => 'Nairobi', 'neighbourhood' => 'Kilimani', 'bedrooms' => 2]);
        $imported = $this->listing(['partner_apartment_id' => 1, 'slug' => 'pinecrest-2br-kilimani-562', 'title' => 'Pinecrest 2BR Kilimani – 562', 'city' => 'Nairobi', 'bedrooms' => 2,
            'description' => 'Near Yaya Centre https://maps.app.goo.gl/abc']);
        $checked = $this->listing(['partner_apartment_id' => 2, 'slug' => 'coral-gables-nyali', 'title' => 'Coral Gables 3-bedroom, Nyali', 'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'bedrooms' => 3]);
        $sameArea = $this->listing(['unit_id' => 2, 'slug' => 'kilimani-garden-loft', 'title' => 'Kilimani Garden Loft, 2 bedrooms', 'city' => 'Nairobi', 'neighbourhood' => 'Kilimani', 'bedrooms' => 2]);
        $this->event($imported, 'listing_imported');
        $this->event($checked, 'listing_imported');
        $this->event($checked, 'listing_updated');

        $page = $this->get(route('public-listings.index', $this->company->slug))->assertOk()->assertSee('Need work')->assertSee('needs_work=1', false);
        $quality = $page->viewData('quality');
        $this->assertSame($original, $quality[$imported]['duplicate_of']->id, 'The imported copy points to the original listing.');
        $this->assertNull($quality[$checked]['duplicate_of']);
        $this->assertNull($quality[$sameArea]['duplicate_of'], 'Sharing only a neighbourhood name is not a duplicate.');
        $this->assertContains('Details checked since import', $quality[$imported]['todo']);
        $this->assertContains('Owner contact recorded', $quality[$imported]['todo']);
        $this->assertContains('Neighbourhood set', $quality[$imported]['todo']);
        $this->assertContains('Clear listing name', $quality[$imported]['todo']);
        $this->assertContains('No web links in descriptions', $quality[$imported]['todo']);
        foreach (['Details checked since import', 'Owner contact recorded', 'Neighbourhood set', 'Clear listing name', 'No web links in descriptions'] as $label) {
            $this->assertNotContains($label, $quality[$checked]['todo'], $label.' is done for the checked listing.');
        }
        $this->assertNotContains('Owner contact recorded', array_column($quality[$original]['checks'], 'label'), 'Managed apartments have no partner owner check.');
        $this->assertNotContains('Details checked since import', array_column($quality[$original]['checks'], 'label'));

        $needsWork = $this->get(route('public-listings.index', $this->company->slug).'?needs_work=1')->assertOk()
            ->assertSee('Showing listings that need work, lowest score first.')->assertSee('Possible duplicate of');
        $this->assertSame($imported, $needsWork->viewData('listings')->first()->id, 'The weakest listing is shown first.');

        $this->get(route('public-listings.edit', ['tenant' => $this->company->slug, 'id' => $imported]))->assertOk()
            ->assertSee('Possible duplicate:')->assertSee('Pinecrest View / A110')->assertSee('Owner contact recorded')->assertSee('Details checked since import');
    }
}
