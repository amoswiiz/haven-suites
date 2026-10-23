<?php

namespace App\Models;

use CodeIgniter\Model;

class UnitModel extends Model
{
    protected $table = 'units';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;

    protected $allowedFields = [
        'property_id',
        'unit_number',
        'bedrooms',
        'bathrooms',
        'floor_number',
        'nightly_rate',
        'owner_name',
        'owner_phone',
        'status',
        'notes',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';

    protected $validationRules = [
        'property_id' => 'required|is_natural_no_zero',
        'unit_number' => 'required|max_length[50]',
        'bedrooms' => 'required|is_natural',
        'bathrooms' => 'required|is_natural',
        'nightly_rate' => 'required|numeric|greater_than_equal_to[0]',
        'status' => 'required|in_list[active,inactive,maintenance]',
    ];
}
