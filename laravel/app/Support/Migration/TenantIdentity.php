<?php
namespace App\Support\Migration;
class TenantIdentity
{
    public function __construct(public \App\Models\User $account, public int $id) {}
    public function __get(string $key): mixed { return $this->account->$key; }
    public function __set(string $key, mixed $value): void { $this->account->$key = $value; }
    public function can(string $permission): bool { return $this->account->can($permission); }
    public function inGroup(string ...$groups): bool { return $this->account->inGroup(...$groups); }
    public function addGroup(string $group): void { $this->account->addGroup($group); }
}
