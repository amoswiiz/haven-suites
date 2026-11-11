<?php

namespace Tests\Unit;

use App\Services\ListingDetails;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListingDetailsTest extends TestCase
{
    /** @return array<string, array{string, ?array{0: float, 1: float}}> */
    public static function locations(): array
    {
        return [
            'coordinates' => ['-4.0435, 39.7123', [-4.0435, 39.7123]],
            'google place link' => ['https://www.google.com/maps/place/Nyali/@-4.043512,39.712345,17z/data=x', [-4.043512, 39.712345]],
            'google pin data' => ['https://www.google.com/maps/place/X/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d-1.2921!4d36.8219', [-1.2921, 36.8219]],
            'query link' => ['https://maps.google.com/?q=-1.2864,36.8172', [-1.2864, 36.8172]],
            'openstreetmap' => ['https://www.openstreetmap.org/#map=17/-4.04351/39.71234', [-4.04351, 39.71234]],
            'text' => ['Nyali, Mombasa', null],
            'out of range' => ['95.1, 39.7', null],
            'zero' => ['0, 0', null],
        ];
    }

    #[DataProvider('locations')]
    public function test_locations_are_read_from_links_and_coordinates(string $input, ?array $expected): void
    {
        $this->assertSame($expected, ListingDetails::parseLocation($input));
    }

    public function test_area_map_rounds_the_location_so_the_building_cannot_be_found(): void
    {
        $map = ListingDetails::areaMap(-4.043512, 39.712345);
        $this->assertStringStartsWith('https://www.openstreetmap.org/export/embed.html?bbox=', $map['embed_url']);
        $this->assertStringNotContainsString('4.0435', $map['embed_url']);
        $this->assertStringNotContainsString('39.7123', $map['embed_url']);
        $this->assertSame($map, ListingDetails::areaMap(-4.044, 39.711), 'Nearby buildings share the same map.');
        $this->assertNull(ListingDetails::areaMap(null, 39.7));
    }

    public function test_only_kuula_and_matterport_tours_are_embedded(): void
    {
        $this->assertSame('https://kuula.co/share/7abcD?fs=1&vr=1&thumbs=1&info=0&logo=0', ListingDetails::tourEmbed('https://kuula.co/share/7abcD'));
        $this->assertSame('https://kuula.co/share/collection/7Abc1?fs=1&vr=1&thumbs=1&info=0&logo=0', ListingDetails::tourEmbed('https://kuula.co/share/collection/7Abc1'));
        $this->assertSame('https://my.matterport.com/show/?m=SxQL3iGyoDo&play=1&qs=1', ListingDetails::tourEmbed('https://my.matterport.com/show/?m=SxQL3iGyoDo'));
        foreach (['http://kuula.co/share/7abcD', 'https://kuula.co.evil.example/share/7abcD', 'https://user@kuula.co/share/7abcD', 'https://kuula.co/share/7abcD/../x', 'https://my.matterport.com/show/?m=<script>', 'javascript:alert(1)'] as $url) {
            $this->assertNull(ListingDetails::tourEmbed($url), $url);
        }
    }

    public function test_nightly_price_uses_season_then_weekend_then_starting_price(): void
    {
        $listing = ['starting_price' => '10000', 'weekend_price' => 13000, 'seasonal_rates' => ListingDetails::seasons([
            ['name' => 'Christmas', 'from' => '2026-12-20', 'to' => '2026-12-31', 'price' => '18000'],
            ['name' => 'Broken', 'from' => '2026-12-10', 'to' => '2026-12-01', 'price' => '1'],
        ])];
        $this->assertCount(1, $listing['seasonal_rates']);
        $this->assertSame(10000.0, ListingDetails::nightlyPrice($listing, CarbonImmutable::parse('2026-12-14')), 'Monday');
        $this->assertSame(13000.0, ListingDetails::nightlyPrice($listing, CarbonImmutable::parse('2026-12-18')), 'Friday night');
        $this->assertSame(18000.0, ListingDetails::nightlyPrice($listing, CarbonImmutable::parse('2026-12-26')), 'Season beats weekend');
        $this->assertSame(10000.0, ListingDetails::nightlyPrice($listing, CarbonImmutable::parse('2027-01-04')), 'Monday after the season');
        $this->assertSame(13000.0, ListingDetails::nightlyPrice($listing, CarbonImmutable::parse('2027-01-01')), 'Friday after the season');
    }

    public function test_rooms_and_nearby_rows_are_cleaned(): void
    {
        $rooms = ListingDetails::rooms([['name' => 'Master', 'beds' => '1 king', 'photo_id' => '5', 'ensuite' => '1'], ['name' => '', 'beds' => ''], ['name' => 'Other', 'beds' => 'Sofa bed', 'photo_id' => 99]], [5, 6]);
        $this->assertSame([['name' => 'Master', 'beds' => '1 king', 'photo_id' => 5, 'ensuite' => true], ['name' => 'Other', 'beds' => 'Sofa bed', 'photo_id' => null, 'ensuite' => false]], $rooms);
        $this->assertSame([['name' => 'Beach', 'minutes' => 5, 'mode' => 'walk']], ListingDetails::nearby([['name' => 'Beach', 'minutes' => '5', 'mode' => 'walk'], ['name' => 'Mall', 'minutes' => ''], ['name' => '', 'minutes' => 3]]));
    }
}
