<?php

namespace Database\Factories;

use App\Models\CompanyInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyInvitationFactory extends Factory
{
    protected $model = CompanyInvitation::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'invited_by' => User::factory(), 'email' => fake()->safeEmail(), 'role' => 'operations', 'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->addDays(7)];
    }
}
