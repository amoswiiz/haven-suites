<?php

namespace App\Models;

use CodeIgniter\Model;

class PartnerApartmentModel extends Model
{
    protected $table = 'partner_apartments';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    // Read-only until the actual table schema and partner form are reviewed.
    // This avoids guessing writable columns or timestamp fields.
    protected $allowedFields = [];
    protected $useTimestamps = false;
}
