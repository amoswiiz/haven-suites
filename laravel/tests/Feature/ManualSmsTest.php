<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualSmsTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug = 'manual-sms'): Tenant
    {
        return Tenant::create(['name' => 'SMS Company', 'slug' => $slug, 'database_name' => 'unused_'.$slug, 'active' => true]);
    }

    private function member(Tenant $tenant, string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['roles' => json_encode([$role]), 'legacy_user_id' => 1]);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.roycetalk.manual_live' => true, 'services.roycetalk.api_key' => 'test-key', 'services.roycetalk.sender_id' => 'RoyceLTD', 'investors.live_delivery' => false]);
    }

    public function test_custom_sms_sends_immediately_without_enabling_automated_alerts_and_cannot_duplicate(): void
    {
        $tenant = $this->company();
        $admin = $this->member($tenant);
        $url = '/t/'.$tenant->slug.'/communications/sms';
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Compose SMS')->assertSee('Communications')->assertSee('Ready for manual sending');
        Http::fake(['https://roycetalk.com/api/v1/sms-api/send/' => Http::response(['success' => true, 'data' => ['message_id' => 'manual-test-123']])]);
        $payload = ['phone' => '+254720602805', 'body' => 'Hello Amos, your apartment is ready.', 'send_token' => (string) Str::uuid()];
        $this->post($url, $payload)->assertRedirect($url)->assertSessionHasNoErrors();
        Http::assertSent(fn ($request) => $request['text_message'] === $payload['body'] && $request['phone_number'] === $payload['phone'] && str_starts_with($request['client_ref'], 'manual-'));
        $this->assertDatabaseHas('manual_sms_messages', ['tenant_id' => $tenant->id, 'sent_by' => $admin->id, 'body' => $payload['body'], 'status' => 'submitted']);
        $this->post($url, $payload)->assertRedirect($url);
        Http::assertSentCount(1);
        $this->assertFalse(config('investors.live_delivery'));
        $this->get($url)->assertOk()->assertSee($payload['body'])->assertSee('manual-test-123');
    }

    public function test_phone_validation_permissions_and_module_controls_block_sends(): void
    {
        Http::fake();
        $tenant = $this->company();
        $admin = $this->member($tenant);
        $url = '/t/'.$tenant->slug.'/communications/sms';
        $this->actingAs($admin)->post($url, ['phone' => '0720602805', 'body' => '', 'send_token' => 'bad'])->assertSessionHasErrors(['phone', 'body', 'send_token']);
        $owner = $this->member($tenant, 'owner');
        $this->actingAs($owner)->get($url)->assertForbidden();
        $this->post($url, [])->assertForbidden();
        $tenant->modules = ['messaging' => false];
        $tenant->save();
        $this->actingAs($admin)->get($url)->assertForbidden();
        $this->post($url, [])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_history_is_isolated_and_unknown_requests_are_not_resent(): void
    {
        $tenant = $this->company();
        $admin = $this->member($tenant);
        $url = '/t/'.$tenant->slug.'/communications/sms';
        Http::fake(fn () => throw new ConnectionException('test-key'));
        $payload = ['phone' => '+254720602805', 'body' => 'Private company message', 'send_token' => (string) Str::uuid()];
        $this->actingAs($admin)->post($url, $payload)->assertSessionHasErrors('sms');
        $this->assertDatabaseHas('manual_sms_messages', ['tenant_id' => $tenant->id, 'status' => 'unknown']);
        $this->post($url, $payload)->assertRedirect();
        $this->assertDatabaseCount('manual_sms_messages', 1);
        $other = $this->company('other-sms');
        $otherAdmin = $this->member($other);
        $this->actingAs($otherAdmin)->get('/t/'.$other->slug.'/communications/sms')->assertOk()->assertDontSee('Private company message');
        $this->actingAs($otherAdmin)->get($url)->assertForbidden();
        $this->assertStringNotContainsString('test-key', DB::table('manual_sms_messages')->value('last_error'));
    }

    public function test_manual_disabled_or_missing_credentials_never_sends(): void
    {
        Http::fake();
        $tenant = $this->company();
        $this->actingAs($this->member($tenant));
        config(['services.roycetalk.manual_live' => false]);
        $this->post('/t/'.$tenant->slug.'/communications/sms', ['phone' => '+254720602805', 'body' => 'Do not send', 'send_token' => (string) Str::uuid()])->assertSessionHasErrors('sms');
        config(['services.roycetalk.manual_live' => true, 'services.roycetalk.api_key' => null]);
        $this->post('/t/'.$tenant->slug.'/communications/sms', ['phone' => '+254720602805', 'body' => 'Do not send', 'send_token' => (string) Str::uuid()])->assertSessionHasErrors('sms');
        Http::assertNothingSent();
        $this->assertDatabaseCount('manual_sms_messages', 0);
    }

    public function test_manual_delivery_status_updates_without_automated_alerts(): void
    {
        $tenant = $this->company();
        $admin = $this->member($tenant);
        $id = DB::table('manual_sms_messages')->insertGetId(['tenant_id' => $tenant->id, 'sent_by' => $admin->id, 'send_token' => (string) Str::uuid(), 'phone' => '+254720602805', 'body' => 'Manual only', 'sender_id' => 'RoyceLTD', 'status' => 'submitted', 'provider_reference' => 'manual-status-123', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['https://roycetalk.com/api/v1/sms-api/messages/manual-status-123/' => Http::response(['success' => true, 'data' => ['id' => 'manual-status-123', 'status' => 'delivered']])]);
        $this->artisan('haven:sync-sms-status')->assertSuccessful();
        $this->assertDatabaseHas('manual_sms_messages', ['id' => $id, 'status' => 'delivered']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_provider_rejection_is_recorded_without_automatic_resending(): void
    {
        $tenant = $this->company();
        $this->actingAs($this->member($tenant));
        Http::fake(['*' => Http::response(['success' => false], 429)]);
        $payload = ['phone' => '+254720602805', 'body' => 'Rate limited test', 'send_token' => (string) Str::uuid()];
        $this->post('/t/'.$tenant->slug.'/communications/sms', $payload)->assertSessionHasErrors('sms');
        $this->assertDatabaseHas('manual_sms_messages', ['tenant_id' => $tenant->id, 'status' => 'rejected']);
        $this->post('/t/'.$tenant->slug.'/communications/sms', $payload)->assertRedirect();
        Http::assertSentCount(1);
    }
}
