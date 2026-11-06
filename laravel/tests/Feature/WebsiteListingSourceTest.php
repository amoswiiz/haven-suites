<?php

namespace Tests\Feature;

use App\Services\WebsiteListingSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WebsiteListingSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function property(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 18365, 'slug' => 'beachfront-nyali-064',
            'title' => ['rendered' => 'Beachfront &amp; airy &#8211; 064'],
            'content' => ['rendered' => '<p>Relax by the beach.</p><p>A bright &amp; comfortable stay.</p>'],
            'property_meta' => [
                'fave_property_price' => ['25,000', '25,000'], 'fave_property_price_postfix' => ['Day', 'Day'],
                'fave_property_bedrooms' => ['4', '4'], 'fave_property_bathrooms' => ['4'],
                'fave_property_images' => ['31', '32', '31'], 'agent_private_phone' => ['SECRET-CONTACT'],
            ],
            'property_city' => [1], 'property_area' => [2], 'property_feature' => [3],
            'property_status' => [], 'property_label' => [4], 'owner_notes' => 'PRIVATE-OWNER-NOTES',
        ], $overrides);
    }

    private function fakeWebsite(array $properties, array $headers = []): void
    {
        Http::fake(function (Request $request) use ($properties, $headers) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/wp-json/wp/v2/properties') {
                return Http::response($properties, 200, $headers);
            }
            $terms = [
                'property_city' => [['id' => 1, 'name' => 'Mombasa', 'slug' => 'mombasa']],
                'property_area' => [['id' => 2, 'name' => 'Nyali', 'slug' => 'nyali']],
                'property_feature' => [['id' => 3, 'name' => 'Swimming Pool', 'slug' => 'swimming-pool']],
                'property_status' => [], 'property_label' => [['id' => 4, 'name' => 'For Sale', 'slug' => 'for-sale']],
            ];
            $resource = basename($path);
            $this->assertArrayHasKey($resource, $terms);

            return Http::response($terms[$resource]);
        });
    }

    public function test_extracts_only_reviewable_public_content_and_flags_conflicting_sale_label(): void
    {
        $this->fakeWebsite([$this->property()]);
        $rows = app(WebsiteListingSource::class)->fetch();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('064', $row['unit_code']);
        $this->assertSame('Beachfront & airy – 064', $row['title']);
        $this->assertSame("Relax by the beach.\n\nA bright & comfortable stay.", $row['description']);
        $this->assertSame('25000', $row['website_price']);
        $this->assertSame('4', $row['website_bedrooms']);
        $this->assertSame('Mombasa', $row['city']);
        $this->assertSame('Nyali', $row['neighbourhood']);
        $this->assertSame(['pool'], $row['amenities']);
        $this->assertSame([31, 32], $row['photo_ids']);
        $this->assertSame('https://havensuitesbnb.com/property/beachfront-nyali-064/', $row['legacy_url']);
        $this->assertContains('Website has both a nightly price and a sale label; confirm rental use.', $row['warnings']);
        $this->assertStringNotContainsString('SECRET', json_encode($rows));
        $this->assertStringNotContainsString('PRIVATE', json_encode($rows));
        Http::assertSent(function (Request $request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/properties')
                && str_contains($query['_fields'], 'property_meta.fave_property_price')
                && ! str_contains($query['_fields'], 'author') && ! str_contains($query['_fields'], 'phone');
        });
        Http::assertSentCount(6);
    }

    public function test_does_not_guess_codes_capacity_or_conflicting_prices_and_removes_active_html(): void
    {
        $property = $this->property();
        $property['title']['rendered'] = 'A new apartment';
        $property['content']['rendered'] = '<p>A welcoming apartment.</p><script>SECRET-SCRIPT</script><form>SECRET-FORM</form><iframe src="http://127.0.0.1/secret">SECRET-FRAME</iframe>[contact-form-7 id="123"]<p>Enjoy your stay.</p>';
        $property['property_meta']['fave_property_price'] = ['15000', '17000'];
        $property['property_meta']['fave_property_price_postfix'] = ['1'];
        $this->fakeWebsite([$property]);
        $row = app(WebsiteListingSource::class)->fetch()[0];
        $this->assertNull($row['unit_code']);
        $this->assertSame('', $row['website_price']);
        $this->assertArrayNotHasKey('max_guests', $row);
        $this->assertArrayNotHasKey('min_nights', $row);
        $this->assertSame("A welcoming apartment.\n\nEnjoy your stay.", $row['description']);
        $this->assertContains('Conflicting website price values.', $row['warnings']);
        $this->assertContains('Website price period is not a verified nightly rate.', $row['warnings']);
    }

    public function test_trailing_annotations_are_not_part_of_the_unit_code(): void
    {
        $this->fakeWebsite([$this->property(['title' => ['rendered' => 'Apartment -072(9)']])]);
        $this->assertSame('072', app(WebsiteListingSource::class)->fetch()[0]['unit_code']);
    }

    public function test_fetches_every_page(): void
    {
        Http::fake(function (Request $request) {
            $resource = basename(parse_url($request->url(), PHP_URL_PATH));
            if ($resource !== 'properties') {
                return Http::response([]);
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $ids = (int) $query['page'] === 1 ? range(1, 100) : [101];

            return Http::response(array_map(fn (int $id): array => $this->property(['id' => $id]), $ids), 200, ['X-WP-Total' => '101', 'X-WP-TotalPages' => '2']);
        });
        $this->assertCount(101, app(WebsiteListingSource::class)->fetch());
        Http::assertSentCount(7);
    }

    public function test_rejects_partial_inventories(): void
    {
        $this->fakeWebsite([$this->property()], ['X-WP-Total' => '108', 'X-WP-TotalPages' => '2']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inventory changed');
        app(WebsiteListingSource::class)->fetch();
    }

    public function test_duplicate_ids_cannot_produce_a_partial_plan(): void
    {
        $this->fakeWebsite([$this->property(), $this->property()]);
        try {
            app(WebsiteListingSource::class)->fetch();
            $this->fail('Duplicate website IDs must fail planning.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('duplicate IDs', $exception->getMessage());
        }
    }

    public function test_redirect_responses_cannot_produce_a_partial_plan(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
        $this->expectException(RuntimeException::class);
        app(WebsiteListingSource::class)->fetch();
    }

    public function test_unknown_taxonomy_values_require_location_review(): void
    {
        $property = $this->property();
        $property['property_city'] = [999];
        $this->fakeWebsite([$property]);
        $row = app(WebsiteListingSource::class)->fetch()[0];
        $this->assertSame('', $row['city']);
        $this->assertContains('Confirm the public city; the website has no single city.', $row['warnings']);
        $this->assertContains('A website property_city term could not be resolved.', $row['warnings']);
    }
}
