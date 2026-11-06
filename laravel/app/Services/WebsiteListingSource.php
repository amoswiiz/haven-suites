<?php

namespace App\Services;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WebsiteListingSource
{
    private const ORIGIN = 'https://havensuitesbnb.com';

    private const FIELDS = 'id,slug,title.rendered,content.rendered,property_meta.fave_property_price,property_meta.fave_property_price_postfix,property_meta.fave_property_bedrooms,property_meta.fave_property_bathrooms,property_meta.fave_property_images,property_city,property_area,property_feature,property_status,property_label';

    /** @return list<array<string, mixed>> */
    public function fetch(): array
    {
        $properties = $this->pages('properties', self::FIELDS);
        $terms = [];
        foreach (['property_city', 'property_area', 'property_feature', 'property_status', 'property_label'] as $taxonomy) {
            $terms[$taxonomy] = array_column($this->pages($taxonomy, 'id,name,slug'), null, 'id');
        }

        return array_map(fn (array $property): array => $this->project($property, $terms), $properties);
    }

    /** @return list<array<string, mixed>> */
    private function pages(string $resource, string $fields): array
    {
        $records = [];
        $ids = [];
        for ($page = 1; $page <= 20; $page++) {
            $response = Http::acceptJson()->connectTimeout(10)->timeout(25)
                ->withOptions(['allow_redirects' => false])->retry(2, 200)
                ->get(self::ORIGIN.'/wp-json/wp/v2/'.$resource, [
                    'per_page' => 100, 'page' => $page, '_fields' => $fields,
                ]);
            if (! $response->successful() || strlen($response->body()) > 8 * 1024 * 1024) {
                throw new RuntimeException('The public website could not be read completely. No import plan was created.');
            }
            $rows = $response->json();
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
                throw new RuntimeException('The public website returned an unexpected listing format.');
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] < 1 || isset($ids[$row['id']])) {
                    throw new RuntimeException('The website inventory changed or contains duplicate IDs. Run the plan again.');
                }
                $ids[$row['id']] = true;
                $records[] = $row;
            }
            $totalPages = $response->header('X-WP-TotalPages');
            $total = $response->header('X-WP-Total');
            if (($totalPages !== '' && $totalPages !== null && ! ctype_digit((string) $totalPages))
                || ($total !== '' && $total !== null && ! ctype_digit((string) $total))) {
                throw new RuntimeException('The website pagination information is invalid.');
            }
            if (count($rows) < 100 || ($totalPages !== null && $totalPages !== '' && $page >= (int) $totalPages)) {
                if ($total !== null && $total !== '' && count($records) !== (int) $total) {
                    throw new RuntimeException('The website inventory changed while it was being read. Run the plan again.');
                }

                return $records;
            }
        }

        throw new RuntimeException('The website inventory exceeds the planning limit. No partial plan was created.');
    }

    /** @param array<string, mixed> $property
     * @param  array<string, array<int, array<string, mixed>>>  $terms
     * @return array<string, mixed>
     */
    private function project(array $property, array $terms): array
    {
        $warnings = [];
        $title = $this->text((string) ($property['title']['rendered'] ?? ''), false);
        $slug = (string) ($property['slug'] ?? '');
        if (! preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/D', $slug)) {
            throw new RuntimeException('A website listing has an unsupported page address. No partial plan was created.');
        }
        $code = preg_match('/[-\x{2013}\x{2014}]\s*([a-zA-Z]{0,5}\s*[0-9]{1,6})(?:\s*\([0-9]+\))?\s*$/u', $title, $match)
            ? preg_replace('/\s+/u', '', $match[1]) : null;
        $meta = is_array($property['property_meta'] ?? null) ? $property['property_meta'] : [];
        $price = $this->value($meta['fave_property_price'] ?? [], 'price', $warnings);
        if (preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/D', $price)) {
            $price = str_replace(',', '', $price);
        }
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $price) || (float) $price <= 0) {
            $price = '';
            $warnings[] = 'Website price is missing or needs review.';
        }
        $period = $this->value($meta['fave_property_price_postfix'] ?? [], 'price period', $warnings);
        $daily = in_array(mb_strtolower(trim($period, " \t\r\n/")), ['day', 'night', 'per day', 'per night'], true);
        if (! $daily) {
            $warnings[] = 'Website price period is not a verified nightly rate.';
        }
        $labels = array_merge($this->termNames($property, 'property_status', $terms, $warnings), $this->termNames($property, 'property_label', $terms, $warnings));
        if (preg_grep('/\bsale\b/i', $labels)) {
            $warnings[] = $daily ? 'Website has both a nightly price and a sale label; confirm rental use.' : 'Website has a sale label; confirm this is a short-stay rental.';
        }
        $cities = $this->termNames($property, 'property_city', $terms, $warnings);
        if (count($cities) !== 1) {
            $warnings[] = 'Confirm the public city; the website has no single city.';
        }
        $photos = [];
        foreach ((array) ($meta['fave_property_images'] ?? []) as $id) {
            if ((is_string($id) && ctype_digit($id)) || is_int($id)) {
                if ((int) $id > 0) {
                    $photos[] = (int) $id;
                }
            }
        }
        if ($photos === []) {
            $warnings[] = 'Website photo references are missing.';
        }
        $amenities = [];
        $aliases = ['wi-fi' => 'wifi', 'air-conditioning' => 'air_conditioning', 'swimming-pool' => 'pool', 'sea-view' => 'sea_view', 'washing-machine' => 'washer', 'lift' => 'elevator', 'pets-allowed' => 'pets_allowed'];
        foreach ((array) ($property['property_feature'] ?? []) as $id) {
            $term = $terms['property_feature'][$id] ?? null;
            if (! $term) {
                $warnings[] = 'A website feature could not be resolved.';

                continue;
            }
            $key = $aliases[$term['slug'] ?? ''] ?? str_replace('-', '_', $term['slug'] ?? '');
            if (array_key_exists($key, PublicListingCatalog::AMENITIES)) {
                $amenities[] = $key;
            }
        }

        return [
            'website_id' => $property['id'], 'unit_code' => $code, 'slug' => $slug, 'title' => $title,
            'description' => $this->text((string) ($property['content']['rendered'] ?? '')),
            'city' => count($cities) === 1 ? $cities[0] : '',
            'neighbourhood' => implode(', ', $this->termNames($property, 'property_area', $terms, $warnings)),
            'website_price' => $price, 'price_period' => $period,
            'website_bedrooms' => $this->value($meta['fave_property_bedrooms'] ?? [], 'bedrooms', $warnings),
            'website_bathrooms' => $this->value($meta['fave_property_bathrooms'] ?? [], 'bathrooms', $warnings),
            'photo_ids' => array_values(array_unique($photos)), 'amenities' => array_values(array_unique($amenities)),
            'legacy_url' => self::ORIGIN.'/property/'.$slug.'/', 'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** @param list<string> $warnings */
    private function value(mixed $raw, string $label, array &$warnings): string
    {
        $values = [];
        foreach ((array) $raw as $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $value = $this->text((string) $value, false);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }
        $values = array_values(array_unique($values));
        if (count($values) > 1) {
            $warnings[] = 'Conflicting website '.$label.' values.';

            return '';
        }

        return $values[0] ?? '';
    }

    /** @param array<string, mixed> $property
     * @param  array<string, array<int, array<string, mixed>>>  $terms
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function termNames(array $property, string $taxonomy, array $terms, array &$warnings): array
    {
        $names = [];
        foreach ((array) ($property[$taxonomy] ?? []) as $id) {
            $term = $terms[$taxonomy][$id] ?? null;
            if (! $term) {
                $warnings[] = 'A website '.$taxonomy.' term could not be resolved.';

                continue;
            }
            $names[] = $this->text((string) ($term['name'] ?? ''), false);
        }

        return array_values(array_unique(array_filter($names)));
    }

    private function text(string $html, bool $paragraphs = true): string
    {
        $document = new DOMDocument;
        $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ((new DOMXPath($document))->query('//script|//style|//form|//iframe|//object|//embed|//noscript|//template|//svg') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $walk = function (DOMNode $node) use (&$walk): string {
            if ($node->nodeType === XML_TEXT_NODE) {
                return $node->textContent;
            }
            $text = '';
            foreach ($node->childNodes as $child) {
                $text .= $walk($child);
            }

            return in_array(strtolower($node->nodeName), ['p', 'div', 'li', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $text."\n\n" : $text;
        };
        $body = $document->getElementsByTagName('body')->item(0);
        $text = $body ? $walk($body) : '';
        $text = preg_replace('/\[\/?(?:contact-form-7|houzez[a-z_-]*|vc_[a-z_-]*)(?:\s[^\]]*)?\]/i', '', $text);
        $text = preg_replace('/[^\S\r\n]+/u', ' ', $text ?? '');
        $text = preg_replace('/ *\R */u', "\n", $text ?? '');
        $text = preg_replace('/\n{3,}/', "\n\n", $text ?? '');

        return trim($paragraphs ? ($text ?? '') : preg_replace('/\s+/u', ' ', $text ?? ''));
    }
}
