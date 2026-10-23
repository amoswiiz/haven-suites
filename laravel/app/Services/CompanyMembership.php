<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyMembership
{
    public function assertCapacity(Tenant $tenant, int $pending = 0): void
    {
        $limit = config('saas.plans.'.$tenant->plan.'.members');
        if ($limit !== null && $tenant->users()->count() + $pending >= $limit) {
            throw ValidationException::withMessages(['email' => 'Your plan has reached its team member limit. Request a larger plan.']);
        }
    }

    public function attach(Tenant $tenant, User $user, string $role): void
    {
        if ($tenant->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This user is already a company member.']);
        }
        $this->assertCapacity($tenant);
        $connection = DB::connection('tenant');
        $connection->transaction(function () use ($connection, $tenant, $user, $role): void {
            $id = $connection->table('users')->insertGetId(['username' => $user->username, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $connection->table('staff')->insert(['user_id' => $id, 'full_name' => $user->name, 'phone' => '', 'email' => $user->email, 'role' => $role, 'location' => '', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $user->tenants()->attach($tenant, ['roles' => json_encode([$role]), 'legacy_user_id' => $id]);
        });
    }
}
