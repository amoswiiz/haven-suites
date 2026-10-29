<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TaskNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug = 'task-test'): Tenant
    {
        return Tenant::create(['name' => 'Task company', 'slug' => $slug, 'database_name' => $slug === 'task-test' ? 'haven_suites_laravel13' : 'unused_'.$slug, 'active' => true]);
    }

    private function member(Tenant $tenant, string $role = 'operations'): User
    {
        $u = User::factory()->create();
        $u->tenants()->attach($tenant, ['roles' => json_encode([$role]), 'legacy_user_id' => 1]);

        return $u;
    }

    private function data(User $assignee, array $extra = []): array
    {
        return [...['title' => 'Inspect apartment', 'description' => 'Private staff instructions', 'assigned_to' => $assignee->id, 'due_date' => now()->addDay()->toDateString(), 'priority' => 'normal', 'kind' => 'routine', 'owner_visible' => 0, 'share_cost' => 0], ...$extra];
    }

    private function createTask(Tenant $tenant, User $creator, User $assignee, array $extra = []): object
    {
        $this->actingAs($creator)->post('/t/'.$tenant->slug.'/tasks', $this->data($assignee, $extra))->assertSessionHasNoErrors()->assertRedirect();

        return DB::table('task_items')->latest('id')->first();
    }

    private function act(Tenant $tenant, object $task, User $user, string $action, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->post('/t/'.$tenant->slug.'/tasks/'.$task->id.'/action', ['version' => DB::table('task_items')->find($task->id)->version, 'action' => $action, ...$extra]);
    }

    public function test_assignment_review_and_notifications_are_scoped_and_audited(): void
    {
        $t = $this->company();
        $manager = $this->member($t, 'manager');
        $staff = $this->member($t);
        $reviewer = $this->member($t, 'admin');
        $task = $this->createTask($t, $manager, $staff, ['reviewer_id' => $reviewer->id]);
        $this->assertDatabaseHas('task_notices', ['task_id' => $task->id, 'user_id' => $staff->id, 'event_key' => 'created']);
        $this->assertDatabaseMissing('task_notices', ['task_id' => $task->id, 'user_id' => $manager->id, 'event_key' => 'created']);
        $this->actingAs($staff)->get('/t/'.$t->slug.'/tasks')->assertOk()->assertSee('Inspect apartment');
        $this->get('/t/'.$t->slug.'/tasks?scope=team')->assertForbidden();
        $this->act($t, $task, $staff, 'start')->assertSessionHasNoErrors();
        $this->act($t, $task, $staff, 'complete', ['note' => 'Completed the inspection'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('task_items', ['id' => $task->id, 'status' => 'review']);
        $this->act($t, $task, $staff, 'approve')->assertForbidden();
        $this->act($t, $task, $reviewer, 'return', ['note' => 'Please check the window'])->assertSessionHasNoErrors();
        $this->act($t, $task, $staff, 'complete', ['note' => 'Window checked'])->assertSessionHasNoErrors();
        $this->act($t, $task, $reviewer, 'approve')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('task_items', ['id' => $task->id, 'status' => 'done']);
        $this->assertGreaterThan(4, DB::table('task_events')->where('task_id', $task->id)->count());
        $this->actingAs($staff)->get('/t/'.$t->slug.'/tasks?status=&view=board')->assertOk()->assertSee('Inspect apartment');
    }

    public function test_unauthorised_members_tenants_and_stale_edits_are_rejected(): void
    {
        $t = $this->company();
        $admin = $this->member($t, 'admin');
        $staff = $this->member($t);
        $other = $this->member($t);
        $foreign = $this->company('other-tasks');
        $outsider = $this->member($foreign, 'admin');
        $task = $this->createTask($t, $admin, $staff);
        $this->actingAs($other)->get('/t/'.$t->slug.'/tasks/'.$task->id)->assertNotFound();
        $this->actingAs($outsider)->get('/t/'.$foreign->slug.'/tasks/'.$task->id)->assertNotFound();
        $this->actingAs($admin)->post('/t/'.$t->slug.'/tasks', $this->data($outsider))->assertSessionHasErrors('assigned_to');
        $this->actingAs($staff)->post('/t/'.$t->slug.'/tasks', $this->data($staff))->assertForbidden();
        $this->actingAs($admin)->post('/t/'.$t->slug.'/tasks/'.$task->id, [...$this->data($other), 'version' => 1])->assertSessionHasNoErrors();
        $this->post('/t/'.$t->slug.'/tasks/'.$task->id, [...$this->data($staff), 'version' => 1])->assertSessionHasErrors('version');
        $this->assertDatabaseHas('task_notices', ['task_id' => $task->id, 'user_id' => $staff->id, 'event_key' => 'reassigned:2']);
        $t->forceFill(['modules' => ['tasks' => false]])->save();
        $this->get('/t/'.$t->slug.'/tasks')->assertForbidden();
    }

    public function test_cost_approval_does_not_record_expenses_or_payments(): void
    {
        $t = $this->company();
        $admin = $this->member($t, 'admin');
        $manager = $this->member($t, 'manager');
        $staff = $this->member($t);
        $task = $this->createTask($t, $manager, $staff, ['kind' => 'cost', 'estimate' => '5000.50']);
        $this->assertSame(500050, (int) $task->estimate_minor);
        $this->act($t, $task, $staff, 'start')->assertSessionHasErrors('cost');
        $this->act($t, $task, $manager, 'approve_cost')->assertForbidden();
        $this->act($t, $task, $admin, 'approve_cost')->assertSessionHasNoErrors();
        $this->act($t, $task, $staff, 'complete', ['note' => 'Work finished, receipt to follow'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('task_items', ['id' => $task->id, 'status' => 'done', 'expense_id' => null]);
        $this->actingAs($admin)->get('/t/'.$t->slug.'/tasks/'.$task->id)->assertOk()->assertSee('Not recorded');
        $this->act($t, $task, $admin, 'reopen')->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/t/'.$t->slug.'/tasks/'.$task->id, [...$this->data($staff, ['kind' => 'cost', 'estimate' => '6000']), 'version' => DB::table('task_items')->find($task->id)->version])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('task_items', ['id' => $task->id, 'cost_status' => 'requested', 'cost_approved_by' => null]);
    }

    private function owner(Tenant $t, int $unit = 1): User
    {
        $owner = $this->member($t, 'owner');
        $id = DB::table('investors')->insertGetId(['tenant_id' => $t->id, 'user_id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'active' => true, 'email_notifications' => true, 'sms_notifications' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('investor_assignments')->insert(['tenant_id' => $t->id, 'investor_id' => $id, 'unit_id' => $unit, 'share_basis_points' => 10000, 'starts_on' => now()->subMonth()->toDateString(), 'agreement_reference' => 'TASK-TEST', 'created_at' => now(), 'updated_at' => now()]);

        return $owner;
    }

    public function test_owner_visibility_protects_internal_notes_costs_and_files(): void
    {
        Storage::fake('local');
        $t = $this->company();
        $admin = $this->member($t, 'admin');
        $staff = $this->member($t);
        $owner = $this->owner($t);
        $other = $this->owner($t, 999999);
        $task = $this->createTask($t, $admin, $staff, ['unit_id' => 1, 'owner_visible' => 1, 'owner_description' => 'We are checking your apartment.', 'kind' => 'cost', 'estimate' => '1234.56']);
        $url = '/t/'.$t->slug.'/tasks/'.$task->id;
        $this->actingAs($staff)->post($url.'/comments', ['body' => 'Secret supplier note', 'shared' => 0, 'attachment' => UploadedFile::fake()->create('bill.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $private = DB::table('task_updates')->latest('id')->first();
        $this->post($url.'/comments', ['body' => 'Unauthorised publication', 'shared' => 1])->assertForbidden();
        $this->actingAs($admin)->post($url.'/comments', ['body' => 'Inspection update for owner', 'shared' => 1, 'attachment' => UploadedFile::fake()->image('photo.jpg')])->assertSessionHasNoErrors();
        $shared = DB::table('task_updates')->latest('id')->first();
        $this->actingAs($owner)->get($url)->assertOk()->assertSee('We are checking your apartment.')->assertSee('Inspection update for owner')->assertDontSee('Secret supplier note')->assertDontSee('Private staff instructions')->assertDontSee('1,234.56')->assertDontSee('bill.pdf');
        $this->get($url.'/attachments/'.$private->id)->assertNotFound();
        $this->get($url.'/attachments/'.$shared->id)->assertOk();
        $this->post($url.'/comments', ['body' => 'Owner write attempt', 'shared' => 0])->assertForbidden();
        $this->actingAs($other)->get($url)->assertNotFound();
        DB::table('investor_assignments')->where('unit_id', 1)->update(['ends_on' => now()->subDay()->toDateString()]);
        $this->actingAs($owner)->get($url)->assertNotFound();
    }

    public function test_owner_completion_notifications_wait_for_review_and_internal_updates_stay_private(): void
    {
        $t = $this->company();
        $admin = $this->member($t, 'admin');
        $staff = $this->member($t);
        $owner = $this->owner($t);
        $task = $this->createTask($t, $admin, $staff, ['unit_id' => 1, 'owner_visible' => 1, 'owner_description' => 'Owner progress', 'reviewer_id' => $admin->id]);
        $before = DB::table('task_notices')->where('user_id', $owner->id)->count();
        $this->act($t, $task, $staff, 'complete', ['note' => 'Internal completion'])->assertSessionHasNoErrors();
        $this->assertSame($before, DB::table('task_notices')->where('user_id', $owner->id)->count());
        $this->act($t, $task, $admin, 'approve')->assertSessionHasNoErrors();
        $this->assertSame($before + 1, DB::table('task_notices')->where('user_id', $owner->id)->count());
    }

    public function test_reminders_are_deduplicated_and_delivery_rechecks_membership(): void
    {
        Http::preventStrayRequests();
        $t = $this->company();
        $admin = $this->member($t, 'admin');
        $staff = $this->member($t);
        $task = $this->createTask($t, $admin, $staff, ['due_date' => now()->subDay()->toDateString()]);
        $this->actingAs($staff)->post('/t/'.$t->slug.'/tasks/preferences', ['email_enabled' => 1, 'sms_enabled' => 0, 'sms_consent' => 0])->assertSessionHasNoErrors();
        $service = app(TaskNotifications::class);
        $service->process();
        $count = DB::table('task_notices')->count();
        $service->process();
        $this->assertSame($count, DB::table('task_notices')->count());
        $this->assertDatabaseHas('task_notices', ['task_id' => $task->id, 'user_id' => $staff->id, 'email_status' => 'simulated']);
        $notice = DB::table('task_notices')->where('user_id',$staff->id)->first();
        DB::table('task_notices')->where('id',$notice->id)->update(['email_status' => 'pending']);
        $staff->tenants()->detach($t->id);
        app()->instance('currentTenant',$t);
        $service->deliver($notice->id);
        app()->forgetInstance('currentTenant');
        $this->assertDatabaseHas('task_notices',['id' => $notice->id, 'email_status' => 'blocked']);
    }
    public function test_linked_expense_uses_finance_payment_status_without_creating_a_second_expense(): void
    {
        $t=$this->company(); $admin=$this->member($t,'admin'); $staff=$this->member($t);
        config(['database.connections.tenant.database'=>$t->database_name]); DB::purge('tenant');
        $db=DB::connection('tenant'); $db->beginTransaction();
        $this->withoutMiddleware(\App\Http\Middleware\ResolveTenant::class); app()->instance('currentTenant',$t);
        try {
            $unit=$db->table('units')->first(); $category=$db->table('expense_categories')->first();
            $expense=$db->table('unit_expenses')->insertGetId(['unit_id'=>$unit->id,'category_id'=>$category->id,'expense_date'=>now()->toDateString(),'description'=>'Task repair test expense','amount'=>5000,'amount_paid'=>2000,'payment_status'=>'partial','created_at'=>now(),'updated_at'=>now()]);
            $count=$db->table('unit_expenses')->count();
            $task=$this->createTask($t,$admin,$staff,['kind'=>'cost','estimate'=>'5000','unit_id'=>$unit->id]);
            $this->act($t,$task,$admin,'approve_cost')->assertSessionHasNoErrors();
            $this->act($t,$task,$staff,'link_expense',['expense_id'=>$expense])->assertForbidden();
            $this->act($t,$task,$admin,'link_expense',['expense_id'=>$expense])->assertSessionHasNoErrors();
            $this->actingAs($admin)->get('/t/'.$t->slug.'/tasks/'.$task->id)->assertOk()->assertSee('Part-paid')->assertSee('2,000.00');
            $this->act($t,$task,$staff,'complete',['note'=>'Repair complete'])->assertSessionHasNoErrors();
            $this->assertSame($count,$db->table('unit_expenses')->count());
            $this->assertEquals(2000,$db->table('unit_expenses')->where('id',$expense)->value('amount_paid'));
        } finally { $db->rollBack(); DB::purge('tenant'); app()->forgetInstance('currentTenant'); }
    }

    public function test_sms_is_opt_in_generic_and_submitted_only_once(): void
    {
        config(['tasks.live_delivery'=>true,'services.roycetalk.api_key'=>'fake-key','services.roycetalk.sender_id'=>'TEST']);
        Http::fake(['roycetalk.com/*'=>Http::response(['success'=>true,'data'=>['message_id'=>'task-test-message']],200)]);
        $t=$this->company(); $admin=$this->member($t,'admin'); $staff=$this->member($t);
        DB::table('investor_notification_settings')->insert(['tenant_id'=>$t->id,'email_enabled'=>false,'sms_enabled'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $task=$this->createTask($t,$admin,$staff);
        $this->actingAs($staff)->post('/t/'.$t->slug.'/tasks/preferences',['email_enabled'=>0,'sms_enabled'=>1,'phone'=>'+254712345678','sms_consent'=>0])->assertSessionHasErrors('sms_consent');
        $this->post('/t/'.$t->slug.'/tasks/preferences',['email_enabled'=>0,'sms_enabled'=>1,'phone'=>'+254712345678','sms_consent'=>1])->assertSessionHasNoErrors();
        app()->instance('currentTenant',$t);
        try {
            $notice=DB::table('task_notices')->where('user_id',$staff->id)->first();
            $service=app(TaskNotifications::class); $service->deliver($notice->id); $service->deliver($notice->id);
            $this->assertDatabaseHas('task_notices',['id'=>$notice->id,'sms_status'=>'submitted','email_status'=>'skipped']);
            Http::assertSentCount(1);
            Http::assertSent(fn($request)=>$request['phone_number']==='+254712345678' && !str_contains($request['text_message'],'Private staff instructions') && str_contains($request['text_message'],'/tasks/'.$task->id));
        } finally { app()->forgetInstance('currentTenant'); }
    }

}
