<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TaskAccess
{
    public function owners(object $task): Collection
    {
        if (! $task->owner_visible || ! $task->unit_id || ! (app('currentTenant')->modules['investors'] ?? true)) {
            return collect();
        }
        $today = now(app('currentTenant')->timezone)->toDateString();

        return DB::table('investors as i')->join('investor_assignments as a', 'a.investor_id', '=', 'i.id')
            ->where('i.tenant_id', $task->tenant_id)->where('a.tenant_id', $task->tenant_id)->where('i.active', true)->whereNotNull('i.user_id')
            ->where('a.unit_id', $task->unit_id)->where('a.starts_on', '<=', substr($task->created_at, 0, 10))
            ->where(fn ($q) => $q->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', $today))->select('i.*')->distinct()->get();
    }

    public function visible(User $user, object $task): bool
    {
        if ((int) $task->tenant_id !== (int) app('currentTenant')->id || ! $user->active || ! $user->tenants()->whereKey($task->tenant_id)->exists()) {
            return false;
        }
        if ($user->can('tasks.manage')) {
            return true;
        }
        if ($user->can('tasks.view') && in_array($user->id, [$task->assigned_to, $task->created_by, $task->reviewer_id])) {
            return true;
        }

        return $user->can('tasks.owner') && $user->hasVerifiedEmail() && $this->owners($task)->contains('user_id', $user->id);
    }

    public function scope(User $user): Builder
    {
        $q = DB::table('task_items')->where('tenant_id', app('currentTenant')->id);
        if ($user->can('tasks.manage')) {
            return $q;
        }
        if ($user->can('tasks.view')) {
            return $q->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhere('created_by', $user->id)->orWhere('reviewer_id', $user->id));
        }
        abort_unless($user->can('tasks.owner') && $user->hasVerifiedEmail(), 403);
        $today = now(app('currentTenant')->timezone)->toDateString();

        return $q->where('owner_visible', true)->whereExists(function ($q) use ($user, $today): void {
            $q->selectRaw('1')->from('investor_assignments as a')->join('investors as i', 'i.id', '=', 'a.investor_id')
                ->whereColumn('a.unit_id', 'task_items.unit_id')->whereColumn('a.tenant_id', 'task_items.tenant_id')->whereColumn('i.tenant_id', 'task_items.tenant_id')
                ->where('i.user_id', $user->id)->where('i.active', true)->whereRaw('a.starts_on <= DATE(task_items.created_at)')
                ->where(fn ($q) => $q->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', $today));
        })->when(! (app('currentTenant')->modules['investors'] ?? true), fn ($q) => $q->whereRaw('1=0'));
    }
}
