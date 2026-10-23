<?php

namespace App\Models\Haven;

use App\Support\Migration\Model;

class ReservationModel extends Model
{
    protected $table = 'reservations';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps = true;

    protected $allowedFields = [
        'unit_id',
        'booking_type',
        'partner_apartment_id',
        'owner_net_amount',
        'guest_name',
        'guest_phone',
        'guest_email',
        'check_in',
        'check_out',
        'adults',
        'children',
        'booking_source',
        'nightly_rate',
        'total_amount',
        'amount_paid',
        'status',
        'notes',
    ];

    protected $validationRules = [
        // The controller validates the apartment ID against the selected booking type.
        'booking_type' => 'required|in_list[managed,partner]',
        'unit_id' => 'permit_empty|is_natural_no_zero',
        'partner_apartment_id' => 'permit_empty|is_natural_no_zero',
        'owner_net_amount' => 'permit_empty|numeric|greater_than_equal_to[0]',
        'guest_name' => 'required|max_length[150]',
        'guest_phone' => 'required|max_length[40]',
        'guest_email' => 'permit_empty|valid_email',
        'check_in' => 'required|valid_date[Y-m-d]',
        'check_out' => 'required|valid_date[Y-m-d]',
        'nightly_rate' => 'required|numeric',
        'total_amount' => 'required|numeric',
        'amount_paid' => 'required|numeric',
        'status' => 'required|in_list[pending,confirmed,checked_in,checked_out,cancelled]',
    ];

    public function hasOverlap(
        int $unitId,
        string $checkIn,
        string $checkOut,
        ?int $excludeId = null
    ): bool {
        $query = $this->where('unit_id', $unitId)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->where('check_in <', $checkOut)
            ->where('check_out >', $checkIn);

        if ($excludeId !== null) {
            $query->where('id !=', $excludeId);
        }

        return $query->countAllResults() > 0;
    }
}
