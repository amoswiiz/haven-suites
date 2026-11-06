<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\PublicListingCatalog;
use App\Services\PublicListingUnitMatcher;
use App\Services\WebsiteListingSource;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlanPublicListingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Http::preventStrayRequests();
    }

    private function source(array $overrides = []): array
    {
        return array_replace([
            'website_id' => 100, 'unit_code' => '064', 'slug' => 'seaview-apartment-064',
            'title' => 'Seaview apartment - 064', 'description' => 'An airy apartment near the beach with a comfortable living room.',
            'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'website_price' => '25000', 'price_period' => 'Day',
            'website_bedrooms' => 4, 'website_bathrooms' => 4, 'photo_ids' => [101, 102],
            'legacy_url' => 'https://havensuitesbnb.com/property/seaview-apartment-064/',
            'warnings' => ['Website sale label conflicts with daily price.'], 'amenities' => ['wifi', 'pool'],
        ], $overrides);
    }

    private function prepare(array $sources, array $units): Tenant
    {
        $tenant = Tenant::factory()->create(['slug' => 'haven', 'active' => true]);
        $this->mock(WebsiteListingSource::class)->shouldReceive('fetch')->once()->andReturn($sources);
        $this->mock(PublicListingCatalog::class)->shouldReceive('units')->once()
            ->withArgs(fn (Tenant $selected): bool => $selected->id === $tenant->id)
            ->andReturn(collect($units));

        return $tenant;
    }

    private function unit(array $overrides = []): object
    {
        return (object) array_replace([
            'id' => 41, 'unit_number' => '64', 'status' => 'active', 'property_name' => 'Beach apartments',
            'bedrooms' => 2, 'bathrooms' => 2, 'nightly_rate' => '12000',
        ], $overrides);
    }

    private function review(): array
    {
        $files = Storage::disk('local')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('listing-imports/', $files[0]);
        $this->assertStringEndsWith('/review.csv', $files[0]);
        $stream = fopen(Storage::disk('local')->path($files[0]), 'rb');
        try {
            $this->assertSame("\xEF\xBB\xBF", fread($stream, 3));
            $headers = fgetcsv($stream, 0, ',', '"', '');
            $rows = [];
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $rows[] = array_combine($headers, $row);
            }
        } finally {
            fclose($stream);
        }

        return $rows;
    }

    public function test_prepares_a_private_review_without_importing_or_changing_application_records(): void
    {
        $sources = [
            $this->source(),
            $this->source(['website_id' => 200, 'unit_code' => '103', 'slug' => 'apartment-103']),
            $this->source(['website_id' => 300, 'unit_code' => null, 'slug' => 'apartment-no-code']),
        ];
        $this->prepare($sources, [$this->unit(), $this->unit(['id' => 42, 'unit_number' => 'Q 103', 'bedrooms' => 3])]);
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->artisan('haven:plan-public-listings', ['tenant' => 'haven'])
            ->expectsOutput('Prepared 3 website listing review rows.')->assertSuccessful();

        $rows = $this->review();
        $this->assertSame('41', $rows[0]['unit_id']);
        $this->assertSame('matched', $rows[0]['mapping_status']);
        $this->assertSame('064', $rows[0]['unit_code']);
        $this->assertSame('25000', $rows[0]['website_price']);
        $this->assertSame('Day', $rows[0]['website_price_period']);
        $this->assertSame('wifi;pool', $rows[0]['amenities']);
        $this->assertSame('101;102', $rows[0]['website_photo_ids']);
        $this->assertStringContainsString('sale label conflicts', $rows[0]['review_notes']);
        $this->assertStringContainsString('bedroom count differs', $rows[0]['review_notes']);
        $this->assertStringContainsString('website currency', $rows[0]['review_notes']);
        foreach ($rows as $row) {
            foreach (['max_guests', 'min_nights', 'starting_price', 'house_rules'] as $field) {
                $this->assertSame('', $row[$field]);
            }
        }
        $this->assertSame('', $rows[1]['unit_id']);
        $this->assertSame('candidate_only', $rows[1]['mapping_status']);
        $this->assertStringContainsString('#42 Q 103 / Beach apartments', $rows[1]['candidate_units']);
        $this->assertSame('', $rows[2]['unit_id']);
        $this->assertSame('missing_code', $rows[2]['mapping_status']);
        $this->assertSame([], $writes);
        $this->assertDatabaseCount('public_listings', 0);
        $this->assertDatabaseCount('public_sites', 0);
        $this->assertDatabaseCount('public_listing_audit_events', 0);
        $this->assertDatabaseCount('manual_sms_messages', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        Http::assertNothingSent();
    }

    public function test_duplicate_source_codes_remain_unmapped_even_when_the_apartment_exists(): void
    {
        $this->prepare([
            $this->source(['unit_code' => '13']),
            $this->source(['website_id' => 200, 'unit_code' => '013', 'slug' => 'other-apartment-013']),
        ], [$this->unit(['unit_number' => '013'])]);

        $this->artisan('haven:plan-public-listings', ['tenant' => 'haven'])->assertSuccessful();

        foreach ($this->review() as $row) {
            $this->assertSame('', $row['unit_id']);
            $this->assertSame('duplicate_code', $row['mapping_status']);
        }
    }

    public function test_spreadsheet_formulas_are_escaped_even_after_leading_whitespace_or_newlines(): void
    {
        $this->prepare([$this->source([
            'title' => "\t =HYPERLINK(\"https://example.test\")", 'description' => "\r@calc",
            'city' => "\n+cmd", 'neighbourhood' => '-2+3',
        ])], [$this->unit()]);

        $this->artisan('haven:plan-public-listings', ['tenant' => 'haven'])->assertSuccessful();

        $row = $this->review()[0];
        $this->assertSame("'\t =HYPERLINK(\"https://example.test\")", $row['title']);
        $this->assertSame("'\r@calc", $row['description']);
        $this->assertSame("'\n+cmd", $row['city']);
        $this->assertSame("'-2+3", $row['neighbourhood']);
        $this->assertSame('41', $row['unit_id']);
    }

    public function test_missing_website_bedrooms_are_left_for_review_without_a_false_mismatch(): void
    {
        $this->prepare([$this->source(['website_bedrooms' => ''])], [$this->unit()]);

        $this->artisan('haven:plan-public-listings', ['tenant' => 'haven'])->assertSuccessful();

        $row = $this->review()[0];
        $this->assertSame('', $row['website_bedrooms']);
        $this->assertStringNotContainsString('bedroom count differs', $row['review_notes']);
    }

    public function test_source_failure_leaves_no_export_or_application_record(): void
    {
        Tenant::factory()->create(['slug' => 'haven', 'active' => true]);
        $this->mock(WebsiteListingSource::class)->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('Unavailable'));
        $this->mock(PublicListingCatalog::class)->shouldNotReceive('units');

        $this->artisan('haven:plan-public-listings', ['tenant' => 'haven'])->assertFailed();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('public_listings', 0);
        $this->assertDatabaseCount('public_sites', 0);
        Http::assertNothingSent();
    }

    public function test_the_fixed_website_cannot_be_used_to_map_another_company(): void
    {
        Tenant::factory()->create(['slug' => 'another-company', 'active' => true]);
        $this->mock(WebsiteListingSource::class)->shouldNotReceive('fetch');
        $this->mock(PublicListingCatalog::class)->shouldNotReceive('units');

        $this->artisan('haven:plan-public-listings', ['tenant' => 'another-company'])->assertFailed();

        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_an_inactive_company_is_rejected_before_fetching_the_website(): void
    {
        Tenant::factory()->create(['slug' => 'haven', 'active' => false]);
        $this->mock(WebsiteListingSource::class)->shouldNotReceive('fetch');
        $this->mock(PublicListingCatalog::class)->shouldNotReceive('units');

        $this->artisan('haven:plan-public-listings', ['tenant' => 'haven'])->assertFailed();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_invalid_mapping_stops_before_creating_an_export(): void
    {
        $this->prepare([$this->source()], [$this->unit()]);
        $this->mock(PublicListingUnitMatcher::class)->shouldReceive('match')->once()->andReturn([
            100 => ['unit_id' => 999, 'mapping_status' => 'matched', 'candidates' => [], 'warnings' => []],
        ]);

        $this->artisan('haven:plan-public-listings', ['tenant' => 'haven'])->assertFailed();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('public_listings', 0);
    }
}
