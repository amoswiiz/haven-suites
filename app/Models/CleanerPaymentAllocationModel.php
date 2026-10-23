<?php

namespace App\Models;

use CodeIgniter\Model;

class CleanerPaymentAllocationModel extends Model
{
    protected $table = 'cleaner_payment_allocations';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['payment_id', 'housekeeping_id', 'amount'];
    protected $useTimestamps = false;
}
