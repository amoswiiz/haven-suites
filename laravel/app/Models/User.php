<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'username', 'email', 'password', 'active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean', 'email_verified_at' => 'datetime', 'requires_email_verification' => 'boolean', 'is_platform_admin' => 'boolean'];
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class)->withPivot('roles', 'legacy_user_id');
    }

    public function roles(): array
    {
        if (! app()->bound('currentTenant')) {
            return [];
        }
        $membership = $this->tenants()->whereKey(app('currentTenant')->id)->first();

        return $membership ? json_decode($membership->pivot->roles, true) : [];
    }

    public function inGroup(string ...$groups): bool
    {
        return (bool) array_intersect($groups, $this->roles());
    }

    public function can($abilities, $arguments = []): bool
    {
        foreach ((array) $abilities as $ability) {
            $allowed = false;
            foreach ($this->roles() as $role) {
                foreach (config('haven.roles.'.$role, []) as $permission) {
                    if (Str::is($permission, $ability)) {
                        $allowed = true;
                    }
                }
            }
            if (! $allowed) {
                return false;
            }
        }

        return true;
    }

    public function addGroup(string $group): void
    {
        if (! array_key_exists($group, config('haven.roles'))) {
            throw new \InvalidArgumentException('Invalid role.');
        }
        $roles = array_values(array_unique([...$this->roles(), $group]));
        $this->tenants()->updateExistingPivot(app('currentTenant')->id, ['roles' => json_encode($roles)]);
    }
}
