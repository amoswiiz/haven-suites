<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $attributes = ['plan' => 'legacy', 'subscription_status' => 'legacy', 'timezone' => 'Africa/Nairobi', 'currency' => 'KES'];

    protected $fillable = ['name', 'slug', 'database_name', 'active', 'contact_email', 'contact_phone', 'timezone', 'currency', 'onboarding_completed_at', 'plan', 'subscription_status', 'trial_ends_at', 'subscription_ends_at', 'requested_plan'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'onboarding_completed_at' => 'datetime', 'trial_ends_at' => 'datetime', 'subscription_ends_at' => 'datetime'];
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('roles', 'legacy_user_id');
    }
}
