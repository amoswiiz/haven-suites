<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class TaskNotifications
{
    public function record(object $task, string $key, string $message, ?int $actor = null, bool $owners = false, array $extra = []): void
    {
        $recipients = array_unique(array_filter([$task->assigned_to, $task->created_by, $task->reviewer_id, ...$extra]));
        foreach ($recipients as $id) {
            if ($id != $actor) {
                $this->insert($task, (int) $id, $key, $message, false);
            }
        }
        if ($owners) {
            foreach (app(TaskAccess::class)->owners($task) as $owner) {
                if ($owner->user_id != $actor && ! in_array($owner->user_id, $recipients)) {
                    $this->insert($task, (int) $owner->user_id, $key, 'An update is available for your property task.', true);
                }
            }
        }
    }

    private function insert(object $task, int $user, string $key, string $message, bool $owner): void
    {
        DB::table('task_notices')->insertOrIgnore(['tenant_id' => $task->tenant_id, 'task_id' => $task->id, 'user_id' => $user, 'event_key' => $key, 'message' => $message, 'owner' => $owner, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function process(): int
    {
        $count = 0;
        foreach (Tenant::where('active', true)->cursor() as $tenant) {
            if (! ($tenant->modules['tasks'] ?? true)) {
                continue;
            }
            app()->instance('currentTenant', $tenant);
            try {
                $today = now($tenant->timezone)->toDateString();
                $tomorrow = now($tenant->timezone)->addDay()->toDateString();
                DB::table('task_items')->where('tenant_id', $tenant->id)->whereNotIn('status', ['done', 'cancelled'])->where('due_date', '<=', $tomorrow)->orderBy('id')->chunkById(100, function ($tasks) use ($today): void {
                    foreach ($tasks as $task) {
                        $overdue = $task->due_date < $today;
                        $this->record($task, ($overdue ? 'overdue:' : 'due:').$task->due_date, $overdue ? 'Task overdue: please review its progress.' : 'A task is due soon.');
                    }
                });
                $ids = DB::table('task_notices')->where('tenant_id', $tenant->id)->where(fn ($q) => $q->where('email_status', 'pending')->orWhere('sms_status', 'pending'))->orderBy('id')->limit(200)->pluck('id');
                foreach ($ids as $id) {
                    $this->deliver((int) $id);
                    $count++;
                }
            } finally {
                app()->forgetInstance('currentTenant');
            }
        }

        return $count;
    }

    public function deliver(int $id): void
    {
        $notice = DB::table('task_notices')->where('tenant_id', app('currentTenant')->id)->where('id', $id)->firstOrFail();
        $task = DB::table('task_items')->where('id', $notice->task_id)->where('tenant_id', $notice->tenant_id)->firstOrFail();
        $user = User::find($notice->user_id);
        $tenant = app('currentTenant');
        $eligible = $tenant->active && ($tenant->modules['tasks'] ?? true) && ($tenant->modules['notifications'] ?? true) && $user && app(TaskAccess::class)->visible($user, $task);
        // A former assignee receives only a generic assignment-change message.
        if (! $notice->owner && str_starts_with($notice->event_key, 'reassigned:') && $user?->active && $user->can('tasks.view') && $user->tenants()->whereKey($tenant->id)->exists()) {
            $eligible = $tenant->active && ($tenant->modules['tasks'] ?? true) && ($tenant->modules['notifications'] ?? true);
        }
        if ((str_starts_with($notice->event_key, 'due:') || str_starts_with($notice->event_key, 'overdue:')) && (in_array($task->status, ['done', 'cancelled']) || ! str_ends_with($notice->event_key, $task->due_date))) {
            $eligible = false;
        }
        $prefs = DB::table('task_preferences')->where('tenant_id', $tenant->id)->where('user_id', $notice->user_id)->first();
        $profile = $notice->owner ? app(TaskAccess::class)->owners($task)->firstWhere('user_id', $notice->user_id) : null;
        $settings = DB::table('investor_notification_settings')->where('tenant_id', $tenant->id)->first();
        foreach (['email', 'sms'] as $channel) {
            $column = $channel.'_status';
            if ($notice->$column !== 'pending') {
                continue;
            }
            $enabled = false;
            $recipient = null;
            if ($notice->owner && $profile) {
                $enabled = $channel === 'email' ? ($settings->email_enabled ?? true) && $profile->email_notifications && (! $profile->communication_email || $profile->communication_email_verified_at) : ($settings->sms_enabled ?? false) && $profile->sms_notifications && $profile->sms_consented_at && $profile->phone_verified_at;
                $recipient = $channel === 'email' ? ($profile->communication_email ?: $profile->email) : $profile->phone;
            } elseif (! $notice->owner && $prefs) {
                $enabled = $channel === 'email' ? ($settings->email_enabled ?? true) && $prefs->email_enabled : ($settings->sms_enabled ?? false) && $prefs->sms_enabled && $prefs->sms_consented_at;
                $recipient = $channel === 'email' ? $user?->email : $prefs->phone;
            }
            $enabled = $enabled && $recipient && ($channel !== 'email' || $user?->hasVerifiedEmail());
            $status = ! $eligible ? 'blocked' : (! $enabled ? 'skipped' : (! config('tasks.live_delivery') ? 'simulated' : 'processing'));
            if (! DB::table('task_notices')->where('id', $id)->where($column, 'pending')->update([$column => $status, 'updated_at' => now()]) || $status !== 'processing') {
                continue;
            }
            $url = route('tasks.show', [$tenant->slug, $task->id]);
            $message = $tenant->name.': A task update is available. Sign in to view it: '.$url;
            try {
                if ($channel === 'email') {
                    if (in_array(config('mail.default'), ['log', 'array'], true)) {
                        $status = 'simulated';
                    } else {
                        Mail::raw($message, fn ($mail) => $mail->to($recipient)->subject('Task update'));
                        $status = 'submitted';
                    }
                } else {
                    $sms = app(RoyceTalkSms::class);
                    if (! $sms->configured()) {
                        $status = 'blocked';
                    } else {
                        $response = $sms->send($recipient, $message, 'task-'.$id);
                        $status = $response->successful() && $response->json('success') === true && is_string($response->json('data.message_id')) ? 'submitted' : ($response->clientError() ? 'failed' : 'unknown');
                    }
                }
            } catch (\Throwable $e) {
                report($e);
                $status = 'unknown';
            }
            DB::table('task_notices')->where('id',$id)->update([$column => $status, 'updated_at' => now()]);
        }
    }
}
