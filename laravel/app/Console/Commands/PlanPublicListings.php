<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\PublicListingCatalog;
use App\Services\PublicListingUnitMatcher;
use App\Services\WebsiteListingSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Signature('haven:plan-public-listings {tenant : Company slug}')]
#[Description('Prepare a private website listing review CSV without changing application records or publishing')]
class PlanPublicListings extends Command
{
    private const HEADERS = [
        'unit_id', 'slug', 'title', 'description', 'city', 'max_guests', 'min_nights',
        'neighbourhood', 'starting_price', 'amenities', 'house_rules', 'legacy_url',
        'website_id', 'unit_code', 'mapping_status', 'candidate_units', 'website_price',
        'website_price_period', 'website_bedrooms', 'website_bathrooms', 'website_photo_ids', 'review_notes',
    ];

    public function handle(PublicListingCatalog $catalog, WebsiteListingSource $source, PublicListingUnitMatcher $matcher): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->where('active', true)->first();
        if (! $tenant || $tenant->slug !== 'haven') {
            $this->error('This website source belongs to the active haven company. No export was created.');

            return self::FAILURE;
        }

        $path = null;
        try {
            $sources = $source->fetch();
            if ($sources === [] || ! array_is_list($sources) || count($sources) > 2000) {
                throw new \UnexpectedValueException('Invalid source inventory.');
            }
            $units = $catalog->units($tenant);
            $mappings = $matcher->match($sources, $units);
            $unitsById = $units->keyBy('id');
            $rows = [];
            $counts = [];
            $seen = [];
            foreach ($sources as $listing) {
                if (! is_array($listing) || ! is_int($listing['website_id'] ?? null) || $listing['website_id'] < 1
                    || isset($seen[$listing['website_id']])) {
                    throw new \UnexpectedValueException('Invalid source identifiers.');
                }
                $seen[$listing['website_id']] = true;
                $mapping = $mappings[$listing['website_id']] ?? null;
                if (! is_array($mapping) || ! is_string($mapping['mapping_status'] ?? null)
                    || ! is_array($mapping['candidates'] ?? null) || ! is_array($mapping['warnings'] ?? null)) {
                    throw new \UnexpectedValueException('Invalid mapping result.');
                }
                $status = $mapping['mapping_status'];
                $unitId = $status === 'matched' ? ($mapping['unit_id'] ?? null) : null;
                $unit = $unitId === null ? null : $unitsById->get($unitId);
                if ($status === 'matched' && (! is_int($unitId) || ! $unit || $unit->status !== 'active')) {
                    throw new \UnexpectedValueException('Invalid matched apartment.');
                }
                $notes = array_merge($listing['warnings'], $mapping['warnings']);
                if ($unit && is_numeric($listing['website_bedrooms']) && (float) $unit->bedrooms !== (float) $listing['website_bedrooms']) {
                    $notes[] = 'Website bedroom count differs from the app. Verify the listing content; the app bedroom count is unchanged.';
                }
                $notes[] = 'Verify maximum guests, minimum nights, city, pricing, website currency and correct apartment photos before importing.';
                $rows[] = [
                    $unitId ?? '', $listing['slug'], $listing['title'], $listing['description'], $listing['city'], '', '',
                    $listing['neighbourhood'], '', implode(';', $listing['amenities']), '', $listing['legacy_url'],
                    $listing['website_id'], $listing['unit_code'] ?? '', $status,
                    implode(' | ', array_map(fn (array $candidate): string => '#'.$candidate['unit_id'].' '.$candidate['unit_number'].' / '.$candidate['property_name'].' ('.$candidate['status'].')', $mapping['candidates'])),
                    $listing['website_price'] ?? '', $listing['price_period'] ?? '', $listing['website_bedrooms'] ?? '',
                    $listing['website_bathrooms'] ?? '', implode(';', $listing['photo_ids']), implode(' ', array_unique($notes)),
                ];
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
            $csv = $this->csv($rows);
            $path = 'listing-imports/'.$tenant->id.'/'.now()->format('Ymd-His').'-'.Str::random(8).'/review.csv';
            if (! Storage::disk('local')->put($path, $csv, ['visibility' => 'private'])) {
                throw new \RuntimeException('Private export storage is unavailable.');
            }
        } catch (\Throwable) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            $this->error('Planning stopped: website data, apartment mappings or private export storage could not be validated. No application records were changed.');

            return self::FAILURE;
        }

        $this->info('Prepared '.count($rows).' website listing review rows.');
        foreach ($counts as $status => $count) {
            $this->line($status.': '.$count);
        }
        $this->line('Private review CSV: '.Storage::disk('local')->path($path));
        $this->warn('Review unresolved mappings and fill in maximum guests and minimum nights. Website prices are reference values only; starting_price is blank to retain the app rate.');
        $this->info('No listings were imported or published, no rates changed and no messages sent.');

        return self::SUCCESS;
    }

    /**
     * @param  list<list<string|int|null>>  $rows
     */
    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Export stream is unavailable.');
        }
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ([self::HEADERS, ...$rows] as $row) {
                $cells = array_map(function (string|int|null $value): string {
                    $value = (string) $value;
                    if (! mb_check_encoding($value, 'UTF-8')) {
                        throw new \UnexpectedValueException('Export text must be UTF-8.');
                    }

                    return preg_match('/^[\s\x00-\x1f\x7f]*[=+\-@]/u', $value) ? "'".$value : $value;
                }, $row);
                if (fputcsv($stream, $cells, ',', '"', '') === false) {
                    throw new \RuntimeException('Export could not be encoded.');
                }
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) {
                throw new \RuntimeException('Export could not be read.');
            }

            return $csv;
        } finally {
            fclose($stream);
        }
    }
}
