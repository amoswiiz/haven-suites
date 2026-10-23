<?php

namespace App\Models\Haven;

use App\Support\Migration\Model;

class CleanerPaymentAllocationModel extends Model
{
    protected $table = 'cleaner_payment_allocations';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['payment_id', 'housekeeping_id', 'amount'];
    protected $useTimestamps = false;
}
