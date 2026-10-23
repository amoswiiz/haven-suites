<?php

namespace App\Models;

use CodeIgniter\Model;

class PropertyModel extends Model
{
    protected $table = 'properties';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'name',
        'location',
        'address',
        'description',
        'status',
    ];

    protected $useTimestamps = true;

    protected $validationRules = [
        'name' => 'required|max_length[150]',
        'location' => 'required|max_length[150]',
        'status' => 'required|in_list[active,inactive]',
    ];
}
