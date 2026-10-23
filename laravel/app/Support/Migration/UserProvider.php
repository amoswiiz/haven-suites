<?php
namespace App\Support\Migration;
use App\Models\User;
class UserProvider
{
    private ?TenantIdentity $last = null;
    public function findByCredentials(array $credentials): ?User { return User::where('email', $credentials['email'])->first(); }
    public function findById(int $id): ?TenantIdentity {
        $user = app('currentTenant')->users()->wherePivot('legacy_user_id',$id)->first();
        return $user ? new TenantIdentity($user,$id) : null;
    }
    public function find(int $id): ?TenantIdentity { return $this->findById($id); }
    public function save(User|TenantIdentity $identity): bool {
        $tenant = app('currentTenant');
        $user = $identity instanceof TenantIdentity ? $identity->account : $identity;
        if ($user->exists && !$user->tenants()->whereKey($tenant->id)->exists()) abort(403);
        if ($user->exists && $user->isDirty('password') && $user->tenants()->count()>1) abort(403,'Shared account passwords must be managed by the account holder.');
        if (!$user->name) $user->name = $user->username;
        $user->save();
        $membership = $user->tenants()->whereKey($tenant->id)->first();
        if (!$membership) {
            db_connect()->table('users')->insert(['username'=>$user->username,'active'=>1,'created_at'=>now()->format('Y-m-d H:i:s')]);
            $legacyId = db_connect()->insertID();
            $user->tenants()->attach($tenant, ['roles'=>json_encode(['user']),'legacy_user_id'=>$legacyId]);
        } else $legacyId = (int)$membership->pivot->legacy_user_id;
        $this->last = new TenantIdentity($user,$legacyId);
        return true;
    }
    public function getInsertID(): ?int { return $this->last?->id; }
    public function errors(): array { return []; }
}
