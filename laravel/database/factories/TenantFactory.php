<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return ['name' => fake()->company(), 'slug' => 'company-'.fake()->unique()->numerify('########'), 'database_name' => 'haven_test_'.fake()->unique()->numerify('########'), 'active' => true];
    }
}
