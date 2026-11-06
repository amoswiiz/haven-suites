<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PublicListingCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportPublicListingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $path = $this->temporary('haven_import_db_');
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
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Import property']);
        foreach ([1 => 'active', 2 => 'active', 3 => 'inactive'] as $id => $status) {
            DB::connection('tenant')->table('units')->insert(['id' => $id, 'property_id' => 1, 'unit_number' => 'A'.$id, 'bedrooms' => 2, 'bathrooms' => 1, 'status' => $status, 'nightly_rate' => 10000, 'owner_phone' => 'SECRET-OWNER']);
        }
        $this->company = Tenant::factory()->create(['slug' => 'import-listings', 'database_name' => $path, 'active' => true]);
        $this->admin = User::factory()->create(['active' => true]);
        $this->admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
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

    private function temporary(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $this->files[] = $path;

        return $path;
    }

    private function row(array $overrides = []): array
    {
        return array_replace([
            'unit_id' => 1, 'slug' => 'beach-apartment-a1', 'title' => 'Bright apartment near the beach',
            'description' => 'An airy apartment with comfortable bedrooms and a welcoming lounge close to local restaurants.',
            'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'max_guests' => 4, 'min_nights' => 2,
            'starting_price' => '12000.00', 'amenities' => 'wifi;pool', 'house_rules' => 'No indoor smoking.',
            'legacy_url' => 'https://havensuitesbnb.com/property/old-apartment/', 'status' => 'published',
        ], $overrides);
    }

    private function csv(array $rows, bool $bom = false): string
    {
        $file = $this->temporary('haven_import_csv_');
        $handle = fopen($file, 'wb');
        if ($bom) {
            fwrite($handle, "\xEF\xBB\xBF");
        }
        fputcsv($handle, array_keys($rows[0]), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ',', '"', '');
        }
        fclose($handle);

        return $file;
    }

    private function arguments(string $file, array $overrides = []): array
    {
        return array_replace(['tenant' => $this->company->slug, 'file' => $file, '--actor' => $this->admin->id], $overrides);
    }

    public function test_import_creates_only_unit_mapped_private_drafts_with_truthful_audit_actor(): void
    {
        $file = $this->csv([$this->row(), $this->row(['unit_id' => 2, 'slug' => 'beach-apartment-a2', 'starting_price' => ''])], true);
        $this->artisan('haven:import-public-listings', $this->arguments($file))->expectsOutput('Validated 2 listing rows.')->assertSuccessful();
        $this->assertDatabaseCount('public_listings', 2);
        $this->assertDatabaseCount('public_listing_photos', 0);
        $this->assertDatabaseCount('public_sites', 0);
        $this->assertDatabaseCount('public_listing_audit_events', 2);
        $this->assertSame(['draft'], DB::table('public_listings')->pluck('status')->unique()->all());
        $this->assertSame([1, 2], DB::table('public_listings')->orderBy('id')->pluck('unit_id')->all());
        $this->assertSame($this->admin->id, DB::table('public_listing_audit_events')->value('actor_id'));
        $this->assertSame('listing_imported', DB::table('public_listing_audit_events')->value('action'));
        $this->assertSame(['wifi', 'pool'], json_decode(DB::table('public_listings')->value('amenities'), true));
        $this->assertNull(DB::table('public_listings')->where('unit_id', 2)->value('starting_price'));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_dry_run_validates_without_creating_listings_audits_or_site_settings(): void
    {
        $file = $this->csv([$this->row()]);
        $this->artisan('haven:import-public-listings', $this->arguments($file, ['--dry-run' => true]))->expectsOutput('Validated 1 listing rows.')->assertSuccessful();
        $this->assertDatabaseCount('public_listings', 0);
        $this->assertDatabaseCount('public_listing_audit_events', 0);
        $this->assertDatabaseCount('public_sites', 0);
    }

    public function test_invalid_later_rows_prevent_every_insert_and_report_the_csv_row(): void
    {
        $file = $this->csv([$this->row(), $this->row(['unit_id' => 999, 'slug' => 'missing-apartment'])]);
        $this->artisan('haven:import-public-listings', $this->arguments($file))
            ->expectsOutput('CSV row 3: unit_id must refer to an active apartment in this company.')
            ->expectsOutput('Validation failed. No listings were saved.')->assertFailed();
        $this->assertDatabaseCount('public_listings', 0);
        $this->assertDatabaseCount('public_listing_audit_events', 0);
        $inactive = $this->csv([$this->row(['unit_id' => 3])]);
        $this->artisan('haven:import-public-listings', $this->arguments($inactive))->assertFailed();
        $this->assertDatabaseCount('public_listings', 0);
    }

    public function test_duplicate_unit_slug_and_existing_records_are_never_overwritten(): void
    {
        $first = $this->csv([$this->row(), $this->row(['slug' => 'another-address'])]);
        $this->artisan('haven:import-public-listings', $this->arguments($first))->assertFailed();
        $second = $this->csv([$this->row(), $this->row(['unit_id' => 2])]);
        $this->artisan('haven:import-public-listings', $this->arguments($second))->assertFailed();
        $this->assertDatabaseCount('public_listings', 0);
        $valid = $this->csv([$this->row()]);
        $this->artisan('haven:import-public-listings', $this->arguments($valid))->assertSuccessful();
        $this->artisan('haven:import-public-listings', $this->arguments($valid))->assertFailed();
        $this->assertDatabaseCount('public_listings', 1);
        $this->assertSame(1, DB::table('public_listings')->value('version'));
    }

    public function test_actor_must_be_active_authorized_and_a_member_of_the_selected_company(): void
    {
        $file = $this->csv([$this->row()]);
        $this->artisan('haven:import-public-listings', $this->arguments($file, ['--actor' => null]))->assertFailed();
        $outsider = User::factory()->create(['active' => true]);
        $this->artisan('haven:import-public-listings', $this->arguments($file, ['--actor' => $outsider->id]))->assertFailed();
        $outsider->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 2]);
        $this->artisan('haven:import-public-listings', $this->arguments($file, ['--actor' => $outsider->id]))->assertFailed();
        $this->admin->active = false;
        $this->admin->save();
        $this->artisan('haven:import-public-listings', $this->arguments($file))->assertFailed();
        $this->assertDatabaseCount('public_listings', 0);
    }

    public function test_import_does_not_change_bound_tenant_context_or_operational_units(): void
    {
        $other = Tenant::factory()->create(['slug' => 'unrelated-company', 'database_name' => 'not_connected']);
        app()->instance('currentTenant', $other);
        $config = config('database.connections.tenant');
        $file = $this->csv([$this->row()]);
        $this->artisan('haven:import-public-listings', $this->arguments($file))->assertSuccessful();
        $this->assertSame($other, app('currentTenant'));
        $this->assertSame($config, config('database.connections.tenant'));
        $this->assertSame('SECRET-OWNER', DB::connection('tenant')->table('units')->where('id', 1)->value('owner_phone'));
        $this->assertSame($this->company->id, DB::table('public_listings')->value('tenant_id'));
    }

    public function test_malformed_csv_and_invalid_public_content_fail_before_writing(): void
    {
        $missing = $this->temporary('haven_import_bad_');
        file_put_contents($missing, "unit_id,title\n1,Incomplete\n");
        $this->artisan('haven:import-public-listings', $this->arguments($missing))->assertFailed();
        $file = $this->csv([$this->row(['starting_price' => '1e6', 'amenities' => 'wifi;secret_feature'])]);
        $this->artisan('haven:import-public-listings', $this->arguments($file))->assertFailed();
        $this->assertDatabaseCount('public_listings', 0);
        $this->assertDatabaseCount('public_listing_audit_events', 0);
    }

    public function test_audit_failure_rolls_back_the_complete_batch(): void
    {
        DB::statement("CREATE TRIGGER reject_import_audit BEFORE INSERT ON public_listing_audit_events BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
        $file = $this->csv([$this->row(), $this->row(['unit_id' => 2, 'slug' => 'beach-apartment-a2'])]);
        $this->artisan('haven:import-public-listings', $this->arguments($file))->assertFailed();
        $this->assertDatabaseCount('public_listings', 0);
        $this->assertDatabaseCount('public_listing_audit_events', 0);
    }

    public function test_public_photo_authorization_looks_up_only_the_requested_listing_and_unit(): void
    {
        $file = $this->csv([$this->row(), $this->row(['unit_id' => 2, 'slug' => 'beach-apartment-a2'])]);
        $this->artisan('haven:import-public-listings', $this->arguments($file))->assertSuccessful();
        DB::table('public_sites')->insert(['tenant_id' => $this->company->id, 'enabled' => true, 'enquiry_assignee_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $listing = DB::table('public_listings')->where('unit_id', 1)->first();
        DB::table('public_listings')->where('id', $listing->id)->update(['status' => 'published']);
        $path = 'listing-photos/'.$this->company->id.'/'.$listing->id.'/'.str_repeat('a', 48).'.jpg';
        Storage::disk('local')->put($path, 'not-decoded-for-this-test');
        $photo = DB::table('public_listing_photos')->insertGetId(['tenant_id' => $this->company->id, 'listing_id' => $listing->id, 'storage_key' => $path, 'mime_type' => 'image/jpeg', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $image = app(PublicListingCatalog::class)->image($this->company, $listing->slug, $photo);
        $this->assertSame('image/jpeg', $image['mime_type']);
        $listingQueries = array_values(array_filter($queries, fn (string $query): bool => str_contains($query, 'public_listings')));
        $unitQueries = array_values(array_filter($queries, fn (string $query): bool => str_contains($query, '"units" as "u"')));
        $photoQueries = array_values(array_filter($queries, fn (string $query): bool => str_contains($query, 'public_listing_photos')));
        $this->assertCount(1, $listingQueries);
        $this->assertStringContainsString('"slug" = ?', $listingQueries[0]);
        $this->assertCount(1, $unitQueries);
        $this->assertStringContainsString('"u"."id" = ?', $unitQueries[0]);
        $this->assertCount(1, $photoQueries);
        $this->assertStringContainsString('"id" = ?', $photoQueries[0]);
    }
}
