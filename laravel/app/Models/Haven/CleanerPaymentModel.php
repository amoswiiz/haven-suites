<?php

namespace App\Models\Haven;

use App\Support\Migration\Model;

class CleanerPaymentModel extends Model
{
    protected $table = 'cleaner_payments';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'staff_id', 'payment_date', 'amount', 'payment_method',
        'reference', 'notes', 'recorded_by',
    ];
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
}
