<?php

namespace App\Models\Haven;

use App\Support\Migration\Model;

class StaffModel extends Model
{
    protected $table = 'staff';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps = true;

    protected $allowedFields = [
        'user_id',
        'full_name',
        'phone',
        'email',
        'role',
        'location',
        'status',
        'notes',
    ];

    protected $validationRules = [
        'full_name' => 'required|max_length[150]',
        'phone' => 'required|max_length[30]',
        'email' => 'permit_empty|valid_email|max_length[150]',
        'role' => 'required|max_length[100]',
        'location' => 'required|max_length[100]',
        'status' => 'required|in_list[active,inactive]',
    ];
}
