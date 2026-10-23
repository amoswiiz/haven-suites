<?php

namespace App\Models\Haven;

use App\Support\Migration\Model;

class UnitExpenseModel extends Model
{
    protected $table = 'unit_expenses';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'unit_id', 'category_id', 'expense_date', 'description', 'amount',
        'payment_status', 'amount_paid', 'paid_at', 'payment_method',
        'payment_reference', 'supplier_name', 'notes', 'source_type',
        'source_id', 'recorded_by',
    ];
}
