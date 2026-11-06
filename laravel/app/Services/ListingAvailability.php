<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

class ListingAvailability
{
    public function __construct(private UnitAvailability $units, private PartnerAvailability $partners) {}

    public function calendar(Tenant $tenant, array $listing, string $from, string $to): array
    {
        if (! empty($listing['unit_id'])) {
            return $this->units->calendar($tenant, (int) $listing['unit_id'], $from, $to);
        }
        abort_unless(! empty($listing['partner_apartment_id']), 404);

        return $this->partners->calendar($tenant, (int) $listing['partner_apartment_id'], $from, $to);
    }

    /** @return array<int, array{available: bool, fresh: bool}> */
    public function availabilityForListings(Tenant $tenant, array $listings, string $from, string $to): array
    {
        if (count($listings) > 500) {
            throw ValidationException::withMessages(['apartments' => 'Search up to 500 apartments at a time.']);
        }
        $unitIds = $partnerIds = [];
        foreach ($listings as $listing) {
            if (! empty($listing['unit_id'])) {
                $unitIds[] = (int) $listing['unit_id'];
            } elseif (! empty($listing['partner_apartment_id'])) {
                $partnerIds[] = (int) $listing['partner_apartment_id'];
            }
        }
        $units = $unitIds === [] ? [] : $this->units->availabilityForUnits($tenant, $unitIds, $from, $to);
        $partners = $partnerIds === [] ? [] : $this->partners->availabilityForPartners($tenant, $partnerIds, $from, $to);
        $result = [];
        foreach ($listings as $listing) {
            $result[(int) $listing['id']] = ! empty($listing['unit_id'])
                ? ($units[(int) $listing['unit_id']] ?? ['available' => false, 'fresh' => false])
                : ($partners[(int) ($listing['partner_apartment_id'] ?? 0)] ?? ['available' => false, 'fresh' => false]);
        }

        return $result;
    }
}
