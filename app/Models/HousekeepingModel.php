<?php

namespace App\Models;

use CodeIgniter\Model;

class HousekeepingModel extends Model
{
    protected $table = 'housekeeping';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps = true;
    

    protected $allowedFields = [
        'unit_id',
        'reservation_id',
        'assigned_staff_id',
        'verified_by_staff_id',
        'cleaning_type',
        'scheduled_at',
        'status',
        'notes',
        'completed_at',
        'verified_at',
        'bedrooms_cleaned',
        'cleaning_charge',
        'charge_adjusted_by',
    ];

    protected $validationRules = [
        'unit_id' => 'required|is_natural_no_zero',
        'cleaning_type' =>
            'required|in_list[checkout,pre_checkin,routine,deep]',
        'scheduled_at' => 'required',
        'status' =>
            'required|in_list[pending,assigned,in_progress,completed,verified,cancelled]',
    ];
}
