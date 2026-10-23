<?php

namespace App\Models\Haven;

use App\Support\Migration\Model;

class CleaningRateModel extends Model
{
    protected $table = 'cleaning_rates';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $useAutoIncrement = true;
    protected $useTimestamps = true;

    protected $allowedFields = [
        'bedrooms',
        'amount',
    ];

    protected $validationRules = [
        'bedrooms' => 'required|is_natural_no_zero',
        'amount' => 'required|numeric|greater_than_equal_to[0]',
    ];

    /**
     * Get the configured cleaning rate for a bedroom count.
     */
    public function getRate(int $bedrooms): ?float
    {
        if ($bedrooms < 1) {
            return null;
        }

        $rate = $this->where('bedrooms', $bedrooms)->first();

        return $rate ? (float) $rate['amount'] : null;
    }

    /**
     * Return all configured rates, indexed by bedroom count.
     */
    public function getRateOptions(): array
    {
        $rates = $this->orderBy('bedrooms', 'ASC')->findAll();

        $options = [];

        foreach ($rates as $rate) {
            $options[(int) $rate['bedrooms']] = (float) $rate['amount'];
        }

        return $options;
    }
}
