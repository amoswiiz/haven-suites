<?php

namespace App\Models;

use App\Support\CompanyModules;
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

    public function sendPasswordResetNotification($token): void
    {
        if (!$this->active) {
            return;
        }

        $companies = $this->is_platform_admin
            ? collect()
            : $this->tenants()->where('tenants.active', true)->limit(2)->get();

        if ($companies->count() !== 1) {
            // A global account must not depend on an arbitrary company's mail server.
            if (in_array(config('mail.default'), ['log', 'array'], true)) {
                throw new \RuntimeException('Platform email delivery is not configured.');
            }
            parent::sendPasswordResetNotification($token);
            return;
        }

        $company = $companies->first();
        $mail = app(\App\Services\CompanyMail::class);
        if (!$mail->sendsExternally($company->id)) {
            throw new \RuntimeException('Company email delivery is disabled.');
        }

        // Use the configured application origin, not the incoming Host header.
        $url = rtrim(config('app.url'), '/').route('password.reset', [
            'token' => $token,
            'email' => $this->getEmailForPasswordReset(),
        ], false);
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $mail->send($company, $this->getEmailForPasswordReset(), 'Set or reset your password',
            "Use this secure link to set or reset your password:\n".$url.
            "\n\nThis link expires in ".$minutes." minutes. If you did not request it, ignore this email.");
    }

    public function hasMultipleCompanies(): bool
    {
        return $this->tenants()->where('active', true)->limit(2)->get(['tenants.id'])->count() > 1;
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
            $module = match (explode('.', $ability)[0]) {
                'tasks' => 'tasks', 'investors', 'investor' => 'investors', 'notifications' => 'notifications',
                'sales' => 'sales', 'agents' => 'agents', 'messaging' => 'messaging',
                'reservations' => 'reservations', 'units', 'properties', 'availability' => 'properties',
                'housekeeping' => 'housekeeping', 'maintenance' => 'maintenance',
                'finance', 'invoices' => 'finance', 'reports' => 'reports', 'staff' => 'team', default => null,
            };
            if ($module !== null && ! CompanyModules::enabled($module)) {
                return false;
            }
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
