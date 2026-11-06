<?php

namespace App\Services;

use Illuminate\Support\Collection;

class PublicListingUnitMatcher
{
    /**
     * @param  array<int, array{website_id: int|string, unit_code: ?string}>  $sources
     * @param  Collection<int, object{id: int, unit_number: string, status: string, property_name: ?string}>  $units
     * @return array<int|string, array{unit_id: ?int, mapping_status: string, candidates: array<int, array{unit_id: int, unit_number: string, property_name: ?string, status: string}>, warnings: array<int, string>}>
     */
    public function match(array $sources, Collection $units): array
    {
        $sourceCodes = [];
        foreach ($sources as $source) {
            $code = $this->normalize($source['unit_code']);
            if ($code !== '') {
                $sourceCodes[$code] = ($sourceCodes[$code] ?? 0) + 1;
            }
        }

        $indexedUnits = $units->groupBy(fn (object $unit): string => $this->normalize($unit->unit_number));
        $matches = [];
        foreach ($sources as $source) {
            $code = $this->normalize($source['unit_code']);
            $exact = $code === '' ? collect() : $indexedUnits->get($code, collect());
            $candidates = $exact->isNotEmpty() ? $exact : $this->suffixCandidates($code, $units);
            $match = [
                'unit_id' => null,
                'mapping_status' => 'unmatched',
                'candidates' => $candidates->map(fn (object $unit): array => [
                    'unit_id' => (int) $unit->id,
                    'unit_number' => $unit->unit_number,
                    'property_name' => $unit->property_name ?? null,
                    'status' => $unit->status,
                ])->values()->all(),
                'warnings' => [],
            ];

            if ($code === '') {
                $match['mapping_status'] = 'missing_code';
                $match['warnings'][] = 'The website listing has no unit code. Select the apartment manually.';
            } elseif ($sourceCodes[$code] > 1) {
                $match['mapping_status'] = 'duplicate_code';
                $match['warnings'][] = 'Multiple website listings use this code. Review all of them before choosing an apartment.';
            } elseif ($exact->count() > 1) {
                $match['mapping_status'] = 'ambiguous_unit';
                $match['warnings'][] = 'Multiple apartments have this unit number. Confirm the correct property and apartment.';
            } elseif ($exact->count() === 1) {
                $unit = $exact->first();
                if ($unit->status === 'active') {
                    $match['unit_id'] = (int) $unit->id;
                    $match['mapping_status'] = 'matched';
                } else {
                    $match['mapping_status'] = 'inactive_unit';
                    $match['warnings'][] = 'The matching apartment is inactive and cannot be imported as an active listing.';
                }
            } elseif ($candidates->isNotEmpty()) {
                $match['mapping_status'] = 'candidate_only';
                $match['warnings'][] = 'Only the numeric suffix matches. Confirm the complete unit number before importing.';
            } else {
                $match['warnings'][] = 'No apartment has this complete unit number.';
            }

            $matches[$source['website_id']] = $match;
        }

        return $matches;
    }

    private function normalize(?string $code): string
    {
        $normalized = mb_strtoupper(preg_replace('/\s+/u', '', $code ?? '') ?? '', 'UTF-8');
        if (preg_match('/^[0-9]+$/D', $normalized)) {
            return ltrim($normalized, '0') ?: '0';
        }

        return $normalized;
    }

    /**
     * @param  Collection<int, object{id: int, unit_number: string, status: string, property_name: ?string}>  $units
     * @return Collection<int, object{id: int, unit_number: string, status: string, property_name: ?string}>
     */
    private function suffixCandidates(string $code, Collection $units): Collection
    {
        if (! preg_match('/^[0-9]+$/D', $code)) {
            return collect();
        }

        return $units->filter(function (object $unit) use ($code): bool {
            return preg_match('/^[A-Z]+([0-9]+)$/D', $this->normalize($unit->unit_number), $matches)
                && $this->normalize($matches[1]) === $code;
        });
    }
}
