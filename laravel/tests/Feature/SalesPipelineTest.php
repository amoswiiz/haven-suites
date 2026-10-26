<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\CompanyModules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'manager', string $slug = 'sales-test'): array
    {
        $tenant = Tenant::create(['name' => 'Sales test company', 'slug' => $slug, 'database_name' => $slug === 'sales-test' ? 'haven_suites_laravel13' : 'not_connected_'.$slug, 'active' => true]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['roles' => json_encode([$role]), 'legacy_user_id' => 1]);

        return [$tenant, $user];
    }

    private function payload(User $user): array
    {
        return ['name' => 'Pipeline Guest', 'phone' => '0712345678', 'email' => 'pipeline@example.test', 'source' => 'instagram', 'channel' => 'whatsapp', 'campaign' => 'Beach weekend', 'assigned_to' => $user->id, 'check_in' => date('Y-m-d', strtotime('+30 days')), 'check_out' => date('Y-m-d', strtotime('+33 days')), 'guests' => 2, 'budget' => 12000, 'next_action' => 'Share apartment options', 'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s')];
    }

    public function test_capture_pipeline_quote_conversation_and_duplicate_review(): void
    {
        [$tenant,$user] = $this->member();
        $this->actingAs($user);
        $url = '/t/'.$tenant->slug.'/sales';
        $this->get($url)->assertOk()->assertSee('Sales pipeline')->assertSee('id="company-navigation"', false)->assertSee('Main navigation');
        $this->get($url.'/new')->assertOk();
        $this->post($url, $this->payload($user))->assertSessionHasNoErrors()->assertRedirect();
        $lead = DB::table('sales_leads')->first();
        $this->get($url)->assertOk()->assertSee('draggable="true"', false)->assertSee('id="sales-move-dialog"', false)->assertSee('data-version="1"', false);
        $this->get($url.'?view=list')->assertOk()->assertDontSee('id="sales-move-dialog"', false);
        $this->assertSame('254712345678', $lead->phone_normalized);
        $this->get($url.'/'.$lead->id)->assertOk()->assertSee('Pipeline Guest')->assertSee('Booking invoice')->assertDontSee('Prepare quotation');
        $this->post($url, $this->payload($user))->assertSessionHasErrors('duplicate');
        $this->assertSame(1, DB::table('sales_leads')->count());
        $this->post($url, [...$this->payload($user), 'duplicate_acknowledged' => 1])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('sales_leads')->count());
        $this->post($url.'/'.$lead->id.'/quotes', ['amount' => 12000, 'valid_until' => date('Y-m-d'), 'description' => 'Three nights, beach apartment.'])->assertSessionHasErrors('quotation');
        DB::table('sales_quotes')->insert(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'amount' => 12000, 'valid_until' => date('Y-m-d'), 'description' => 'Historical quote', 'snapshot' => json_encode(['guest' => $lead->name, 'check_in' => $lead->check_in, 'check_out' => $lead->check_out, 'guests' => $lead->guests, 'currency' => 'KES']), 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $quote = DB::table('sales_quotes')->first();
        $this->get($url.'/'.$lead->id.'/quotes/'.$quote->id)->assertOk()->assertSee('12,000.00')->assertSee('Pipeline Guest');
        $this->post($url.'/'.$lead->id, ['stage' => 'options_shared', 'assigned_to' => $user->id, 'next_action' => 'Prepare booking invoice', 'follow_up_at' => now()->addDay(), 'version' => 1])->assertSessionHasNoErrors();
        $this->post($url.'/'.$lead->id.'/messages', ['channel' => 'whatsapp', 'direction' => 'inbound', 'body' => 'Guest prefers the beach unit', 'external_id' => '254712345678', 'consent' => 1])->assertSessionHasNoErrors();
        $this->get($url.'/'.$lead->id)->assertOk()->assertSee('Guest prefers the beach unit');
        $this->post($url.'/'.$lead->id.'/details', ['name' => 'Pipeline Guest', 'phone' => '0712345678', 'guests' => 3, 'location' => 'Nyali', 'version' => 2])->assertSessionHasNoErrors();
        $this->assertSame(3, DB::table('sales_leads')->where('id', $lead->id)->value('guests'));
        $this->assertSame('instagram', DB::table('sales_leads')->where('id', $lead->id)->value('source'));
        $this->get($url.'?view=list&source=instagram')->assertOk()->assertSee('Pipeline Guest');
        $this->get('/t/'.$tenant->slug.'/reservations/create?lead_id='.$lead->id)->assertOk()->assertSee('Pipeline Guest');
    }

    public function test_leads_quotes_agents_and_messages_cannot_cross_companies(): void
    {
        [$tenant,$user] = $this->member();
        $this->actingAs($user)->post('/t/'.$tenant->slug.'/sales', $this->payload($user));
        $id = DB::table('sales_leads')->value('id');
        [$other,$outsider] = $this->member('manager', 'other-sales');
        $this->actingAs($outsider);
        $this->get('/t/'.$other->slug.'/sales/'.$id)->assertNotFound();
        $this->post('/t/'.$other->slug.'/sales/'.$id.'/notes', ['body' => 'Intrusion'])->assertNotFound();
        $this->post('/t/'.$other->slug.'/sales/'.$id.'/booking', ['reservation_id' => 1, 'version' => 1])->assertNotFound();
        $this->post('/t/'.$other->slug.'/sales/'.$id.'/messages', ['body' => 'Intrusion', 'channel' => 'whatsapp', 'direction' => 'inbound'])->assertNotFound();
        $this->get('/t/'.$other->slug.'/sales')->assertOk()->assertDontSee('Pipeline Guest');
        $this->post('/t/'.$other->slug.'/sales', [...$this->payload($outsider), 'assigned_to' => $user->id])->assertSessionHasErrors('assigned_to');
    }

    public function test_only_platform_admin_can_toggle_modules_with_audit_and_preserve_data(): void
    {
        [$tenant,$user] = $this->member('superadmin');
        $this->actingAs($user)->post('/t/'.$tenant->slug.'/sales', $this->payload($user));
        $this->post('/platform/companies/'.$tenant->id.'/modules', ['modules' => [], 'reason' => 'Restricted package'])->assertForbidden();
        $platform = User::factory()->create();
        $platform->is_platform_admin = true;
        $platform->save();
        $this->actingAs($platform);
        $this->get('/platform')->assertOk()->assertSee('Company modules');
        $this->post('/platform/companies/'.$tenant->id.'/modules', ['modules' => ['reservations', 'properties'], 'reason' => 'Sales not included'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('platform_audit_events', ['tenant_id' => $tenant->id, 'actor_id' => $platform->id, 'action' => 'company.modules.updated']);
        $this->actingAs($user)->get('/t/'.$tenant->slug.'/sales')->assertForbidden();
        $this->post('/t/'.$tenant->slug.'/sales', $this->payload($user))->assertForbidden();
        $this->get('/t/'.$tenant->slug.'/housekeeping')->assertForbidden();
        $this->get('/t/'.$tenant->slug.'/company/team')->assertForbidden();
        $this->get('/t/'.$tenant->slug.'/dashboard')->assertOk()->assertDontSee('href="http://127.0.0.1:8001/t/'.$tenant->slug.'/sales"', false)->assertDontSee('Financial summary');
        $this->assertSame(1, DB::table('sales_leads')->count());
        $this->actingAs($platform)->post('/platform/companies/'.$tenant->id.'/modules', ['modules' => array_keys(CompanyModules::LABELS), 'reason' => 'Restore access'])->assertSessionHasNoErrors();
        $this->actingAs($user)->get('/t/'.$tenant->slug.'/sales')->assertOk()->assertSee('Pipeline Guest');
    }

    public function test_stage_guards_concurrent_updates_and_lost_reason(): void
    {
        [$tenant,$user] = $this->member();
        $this->actingAs($user)->post('/t/'.$tenant->slug.'/sales', $this->payload($user));
        $id = DB::table('sales_leads')->value('id');
        $url = '/t/'.$tenant->slug.'/sales/'.$id;
        $data = ['stage' => 'invoice_issued', 'assigned_to' => $user->id, 'next_action' => 'Follow up', 'follow_up_at' => now()->addDay(), 'version' => 1];
        $this->post($url, $data)->assertSessionHasErrors('stage');
        $this->post($url, [...$data, 'stage' => 'won'])->assertSessionHasErrors('stage');
        $this->post($url, [...$data, 'stage' => 'lost'])->assertSessionHasErrors('lost_reason');
        $this->post($url, [...$data, 'stage' => 'lost', 'lost_reason' => 'Dates unavailable'])->assertSessionHasNoErrors();
        $this->post($url, [...$data, 'stage' => 'qualifying'])->assertSessionHasErrors('version');
        $this->assertSame('lost', DB::table('sales_leads')->value('stage'));
    }

    public function test_sales_role_lands_on_pipeline_and_cannot_access_finance_or_confirm_unpaid_bookings(): void
    {
        [$tenant,$user] = $this->member('sales');
        $this->actingAs($user)->get('/')->assertRedirect('/t/'.$tenant->slug.'/sales');
        $this->post('/t/'.$tenant->slug.'/sales', $this->payload($user))->assertSessionHasNoErrors();
        $id = DB::table('sales_leads')->value('id');
        $this->get('/t/'.$tenant->slug.'/sales/'.$id)->assertOk();
        $this->get('/t/'.$tenant->slug.'/invoices')->assertForbidden();
        $this->post('/t/'.$tenant->slug.'/sales/'.$id.'/win', ['reservation_id' => 1, 'required_deposit' => 100])->assertForbidden();
        $this->post('/t/'.$tenant->slug.'/sales/'.$id.'/booking', ['reservation_id' => 1, 'version' => 1])->assertForbidden();
        $this->get('/t/'.$tenant->slug.'/sales/connections')->assertOk()->assertSee('Disconnected');
    }

    public function test_agent_attribution_and_messaging_module_restrictions(): void
    {
        [$tenant, $user] = $this->member();
        $this->actingAs($user)->post('/t/'.$tenant->slug.'/sales/agents', ['name' => 'Referral partner', 'commission_terms' => '5% after completed stay, refunded stays excluded'])->assertSessionHasNoErrors();
        $agentId = DB::table('sales_agents')->value('id');
        $this->get('/t/'.$tenant->slug.'/sales/agents')->assertOk()->assertSee('Referral partner');
        $this->post('/t/'.$tenant->slug.'/sales', [...$this->payload($user), 'agent_id' => $agentId])->assertSessionHasNoErrors();
        $id = DB::table('sales_leads')->value('id');
        $this->assertSame($agentId, DB::table('sales_leads')->value('agent_id'));
        $this->post('/t/'.$tenant->slug.'/sales/'.$id.'/messages', ['channel' => 'whatsapp', 'direction' => 'inbound', 'body' => 'Private conversation'])->assertSessionHasNoErrors();
        $tenant->modules = ['messaging' => false, 'agents' => false];
        $tenant->save();
        $this->get('/t/'.$tenant->slug.'/sales/'.$id)->assertOk()->assertDontSee('Private conversation')->assertDontSee('Conversation history');
        $this->post('/t/'.$tenant->slug.'/sales/'.$id.'/messages', ['channel' => 'whatsapp', 'direction' => 'outbound', 'body' => 'Blocked'])->assertForbidden();
        $this->get('/t/'.$tenant->slug.'/sales/agents')->assertForbidden();
        $this->get('/t/'.$tenant->slug.'/sales/connections')->assertForbidden();
        $this->assertSame(1, DB::table('sales_messages')->count());
    }

    public function test_read_only_sales_user_cannot_drag_or_change_stages(): void
    {
        config(['haven.roles.sales_viewer' => ['sales.view']]);
        [$tenant, $user] = $this->member('sales_viewer');
        $url = '/t/'.$tenant->slug.'/sales';
        $this->actingAs($user)->get($url)->assertOk()->assertDontSee('sales-pipeline.js')->assertDontSee('id="sales-move-dialog"', false);
        $this->post($url.'/1', ['stage' => 'qualifying'])->assertForbidden();
    }

    public function test_legacy_quotation_stage_migrates_without_claiming_an_invoice_was_issued(): void
    {
        [$tenant, $user] = $this->member();
        $this->actingAs($user)->post('/t/'.$tenant->slug.'/sales', $this->payload($user));
        $lead = DB::table('sales_leads')->first();
        DB::table('sales_leads')->where('id', $lead->id)->update(['stage' => 'quotation_sent']);
        DB::table('sales_quotes')->insert(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'amount' => 12000, 'valid_until' => date('Y-m-d'), 'description' => 'Keep historical document', 'snapshot' => '{}', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $migration = require database_path('migrations/2026_10_24_212232_replace_sales_quotation_stage_with_invoice_flow.php');
        $migration->up();
        $this->assertDatabaseHas('sales_leads', ['id' => $lead->id, 'stage' => 'options_shared', 'version' => 2]);
        $this->assertDatabaseCount('sales_quotes', 1);
        $this->assertDatabaseHas('sales_activities', ['lead_id' => $lead->id, 'type' => 'workflow_migration']);
        $migration->up();
        $this->assertSame(1, DB::table('sales_activities')->where('type', 'workflow_migration')->count());
    }
}
