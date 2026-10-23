<?php
namespace App\Support\Migration;
class Auth
{
    public function user(): ?TenantIdentity {
        $user = auth()->user();
        if (!$user || !app()->bound('currentTenant')) return null;
        $membership = $user->tenants()->whereKey(app('currentTenant')->id)->first();
        return $membership ? new TenantIdentity($user,(int)$membership->pivot->legacy_user_id) : null;
    }
    public function id(): ?int { return $this->user()?->id; }
    public function loggedIn(): bool { return auth()->check(); }
    public function getProvider(): UserProvider { return new UserProvider(); }
}
