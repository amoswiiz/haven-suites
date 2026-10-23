<?php

namespace App\Models;

use CodeIgniter\Model;

class MaintenanceModel extends Model
{
    protected $table = 'maintenance';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps = true;

    protected $allowedFields = [
        'unit_id',
        'assigned_staff_id',
        'title',
        'category',
        'description',
        'priority',
        'status',
        'provider_name',
        'provider_phone',
        'work_notes',
        'cost',
        'reported_at',
        'completed_at',
    ];

    protected $validationRules = [
        'unit_id' => 'required|is_natural_no_zero',
        'title' => 'required|max_length[180]',
        'category' => 'required|max_length[50]',
        'description' => 'required',
        'priority' => 'required|in_list[low,medium,high,urgent]',
        'status' => 'required|in_list[open,assigned,in_progress,on_hold,completed]',
        'cost' => 'required|numeric|greater_than_equal_to[0]',
    ];
}
