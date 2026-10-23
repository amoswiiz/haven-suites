<?php

namespace Database\Factories;

use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SubscriptionPaymentFactory extends Factory
{
    protected $model = SubscriptionPayment::class;

    public function definition(): array
    {
        return ['reference' => (string) Str::uuid(), 'tenant_id' => Tenant::factory(), 'user_id' => User::factory(), 'plan' => 'starter', 'provider' => 'manual', 'amount_minor' => 100000, 'currency' => 'KES', 'mode' => 'sandbox', 'status' => 'pending'];
    }
}
