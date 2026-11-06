<?php

namespace Tests\Unit;

use App\Services\PublicListingUnitMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class PublicListingUnitMatcherTest extends TestCase
{
    private function units(array $numbers): Collection
    {
        return collect($numbers)->map(fn (string|array $number, int $index): object => (object) [
            'id' => $index + 1,
            'unit_number' => is_array($number) ? $number[0] : $number,
            'status' => is_array($number) ? $number[1] : 'active',
            'property_name' => 'Property '.($index + 1),
            'owner_phone' => 'PRIVATE',
        ]);
    }

    private function sources(array $codes): array
    {
        return array_map(fn (?string $code, int $index): array => [
            'website_id' => 100 + $index,
            'unit_code' => $code,
        ], $codes, array_keys($codes));
    }

    public function test_numeric_unit_numbers_ignore_leading_zeroes_and_preserve_the_real_id(): void
    {
        $matches = (new PublicListingUnitMatcher)->match($this->sources(['064', '000']), $this->units(['64', '0']));

        $this->assertSame('matched', $matches[100]['mapping_status']);
        $this->assertSame(1, $matches[100]['unit_id']);
        $this->assertSame(2, $matches[101]['unit_id']);
        $this->assertSame([], $matches[100]['warnings']);
        $this->assertSame([
            'unit_id' => 1, 'unit_number' => '64', 'property_name' => 'Property 1', 'status' => 'active',
        ], $matches[100]['candidates'][0]);
    }

    public function test_full_unit_codes_ignore_case_and_whitespace_without_removing_prefixes(): void
    {
        $matches = (new PublicListingUnitMatcher)->match($this->sources([' a 064 ', '64']), $this->units(['A064', '064', 'A64']));

        $this->assertSame(1, $matches[100]['unit_id']);
        $this->assertSame(2, $matches[101]['unit_id']);
        $this->assertCount(1, $matches[100]['candidates']);
        $this->assertCount(1, $matches[101]['candidates']);
    }

    public function test_duplicate_website_codes_block_every_listing_even_when_a_single_unit_matches(): void
    {
        $matches = (new PublicListingUnitMatcher)->match($this->sources(['13', '013']), $this->units(['13']));

        foreach ($matches as $match) {
            $this->assertSame('duplicate_code', $match['mapping_status']);
            $this->assertNull($match['unit_id']);
            $this->assertNotEmpty($match['warnings']);
            $this->assertCount(1, $match['candidates']);
        }
    }

    public function test_duplicate_unit_numbers_across_properties_are_never_chosen_automatically(): void
    {
        $matches = (new PublicListingUnitMatcher)->match($this->sources(['64']), $this->units(['064', '64']));

        $this->assertSame('ambiguous_unit', $matches[100]['mapping_status']);
        $this->assertNull($matches[100]['unit_id']);
        $this->assertCount(2, $matches[100]['candidates']);
    }

    public function test_an_inactive_duplicate_does_not_make_the_remaining_active_unit_unambiguous(): void
    {
        $matches = (new PublicListingUnitMatcher)->match($this->sources(['64']), $this->units(['064', ['64', 'inactive']]));

        $this->assertSame('ambiguous_unit', $matches[100]['mapping_status']);
        $this->assertNull($matches[100]['unit_id']);
        $this->assertCount(2, $matches[100]['candidates']);
    }

    public function test_an_inactive_exact_match_remains_unselected(): void
    {
        $matches = (new PublicListingUnitMatcher)->match($this->sources(['64']), $this->units([['064', 'inactive']]));

        $this->assertSame('inactive_unit', $matches[100]['mapping_status']);
        $this->assertNull($matches[100]['unit_id']);
        $this->assertSame('inactive', $matches[100]['candidates'][0]['status']);
    }

    public function test_numeric_suffixes_only_produce_candidates_and_do_not_strip_unit_prefixes(): void
    {
        $matches = (new PublicListingUnitMatcher)->match($this->sources(['103']), $this->units(['Q 103', 'A0103', 'Q103A', 'B104']));

        $this->assertSame('candidate_only', $matches[100]['mapping_status']);
        $this->assertNull($matches[100]['unit_id']);
        $this->assertSame([1, 2], array_column($matches[100]['candidates'], 'unit_id'));
        $this->assertNotEmpty($matches[100]['warnings']);
    }

    public function test_missing_and_unmatched_codes_cannot_map_to_an_apartment(): void
    {
        $matches = (new PublicListingUnitMatcher)->match($this->sources([null, ' ', '900']), $this->units(['F5', '']));

        $this->assertSame('missing_code', $matches[100]['mapping_status']);
        $this->assertSame('missing_code', $matches[101]['mapping_status']);
        $this->assertSame('unmatched', $matches[102]['mapping_status']);
        foreach ($matches as $match) {
            $this->assertNull($match['unit_id']);
            $this->assertSame([], $match['candidates']);
            $this->assertNotEmpty($match['warnings']);
        }
    }

    public function test_prefixed_codes_are_not_numerically_normalized_or_matched_to_other_prefixes(): void
    {
        $matches = (new PublicListingUnitMatcher)->match($this->sources(['A064']), $this->units(['A64', 'B064', '64']));

        $this->assertSame('unmatched', $matches[100]['mapping_status']);
        $this->assertNull($matches[100]['unit_id']);
        $this->assertSame([], $matches[100]['candidates']);
    }
}
