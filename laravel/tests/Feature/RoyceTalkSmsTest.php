<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\InvestorNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoyceTalkSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function delivery(): int
    {
        config(['investors.live_delivery' => true, 'investors.sms_driver' => 'roycetalk', 'services.roycetalk.api_key' => 'private-test-key', 'services.roycetalk.sender_id' => 'HavenSuites']);
        $slug = 'sms-'.bin2hex(random_bytes(4));
        $tenant = Tenant::create(['name' => 'Haven Test', 'slug' => $slug, 'database_name' => 'unused_'.$slug, 'active' => true]);
        $owner = User::factory()->create();
        $owner->tenants()->attach($tenant, ['roles' => json_encode(['owner']), 'legacy_user_id' => 1]);
        $id = DB::table('investors')->insertGetId(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'phone' => '+254712345678', 'active' => true, 'email_notifications' => false, 'sms_notifications' => true, 'phone_verified_at' => now(), 'sms_consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('investor_notification_settings')->insert(['tenant_id' => $tenant->id, 'email_enabled' => false, 'sms_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $investor = DB::table('investors')->where('id', $id)->first();
        $event = app(InvestorNotifications::class)->record($investor, 'sms:test', 'statement_ready', 'Statement ready', 'Private financial amount 999999.00');

        return DB::table('investor_deliveries')->where('event_id', $event)->where('channel', 'sms')->value('id');
    }

    public function test_sends_documented_payload_and_does_not_resend_accepted_messages(): void
    {
        $id = $this->delivery();
        Http::fake(['https://roycetalk.com/api/v1/sms-api/send/' => Http::response(['success' => true, 'data' => ['message_id' => 'test-message-123', 'status' => 'pending']], 200)]);
        app(InvestorNotifications::class)->deliver($id);
        $this->assertDatabaseHas('investor_deliveries', ['id' => $id, 'status' => 'submitted', 'sms_provider' => 'roycetalk', 'provider_reference' => 'test-message-123', 'provider_status' => 'pending']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://roycetalk.com/api/v1/sms-api/send/' && $request->hasHeader('Authorization', 'Bearer private-test-key') && $request['phone_number'] === '+254712345678' && $request['sender_id'] === 'HavenSuites' && str_starts_with($request['client_ref'], 'investor-') && str_contains($request['text_message'], 'investor-portal') && ! str_contains($request['text_message'], '999999.00'));
        app(InvestorNotifications::class)->deliver($id);
        Http::assertSentCount(1);
    }

    public function test_rejections_throttling_and_uncertain_responses_are_distinguished(): void
    {
        foreach ([[400, [], 'rejected'], [403, [], 'rejected'], [429, [], 'failed'], [503, [], 'unknown'], [200, ['success' => true, 'data' => []], 'unknown'], [200, ['success' => false, 'data' => ['message_id' => 'unconfirmed']], 'unknown']] as [$code, $body, $status]) {
            $id = $this->delivery();
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response($body, $code)]);
            app(InvestorNotifications::class)->deliver($id);
            $this->assertDatabaseHas('investor_deliveries', ['id' => $id, 'status' => $status, 'provider_reference' => null]);
            if ($code === 429) {
                $this->assertNotNull(DB::table('investor_deliveries')->where('id', $id)->value('next_attempt_at'));
            }
            app(InvestorNotifications::class)->deliver($id);
            Http::assertSentCount(1);
        }
    }

    public function test_timeout_is_not_automatically_retried(): void
    {
        $id = $this->delivery();
        Http::fake(fn () => throw new ConnectionException('Contains private-test-key'));
        app(InvestorNotifications::class)->deliver($id);
        app(InvestorNotifications::class)->deliver($id);
        $this->assertDatabaseHas('investor_deliveries', ['id' => $id, 'status' => 'unknown', 'attempts' => 1]);
        $this->assertStringNotContainsString('private-test-key', DB::table('investor_deliveries')->where('id', $id)->value('last_error'));
    }

    public function test_missing_credentials_simulation_and_consent_prevent_requests(): void
    {
        Http::fake();
        $missing = $this->delivery();
        config(['services.roycetalk.api_key' => null]);
        app(InvestorNotifications::class)->deliver($missing);
        $this->assertDatabaseHas('investor_deliveries', ['id' => $missing, 'status' => 'blocked']);
        $simulated = $this->delivery();
        config(['investors.live_delivery' => false]);
        app(InvestorNotifications::class)->deliver($simulated);
        $this->assertDatabaseHas('investor_deliveries', ['id' => $simulated, 'status' => 'simulated']);
        $noConsent = $this->delivery();
        DB::table('investors')->update(['sms_consented_at' => null]);
        app(InvestorNotifications::class)->deliver($noConsent);
        $this->assertDatabaseHas('investor_deliveries', ['id' => $noConsent, 'status' => 'skipped']);
        Http::assertNothingSent();
    }

    public function test_delivery_status_progresses_without_resending_and_respects_poll_interval(): void
    {
        $id = $this->delivery();
        DB::table('investor_deliveries')->where('id', $id)->update(['status' => 'submitted', 'sms_provider' => 'roycetalk', 'provider_reference' => 'message-123', 'submitted_at' => now()]);
        Http::fake(['https://roycetalk.com/api/v1/sms-api/messages/message-123/' => Http::sequence()->push(['success' => true, 'data' => ['id' => 'message-123', 'status' => 'sent']])->push(['success' => true, 'data' => ['id' => 'message-123', 'status' => 'delivered']])]);
        $this->artisan('haven:sync-sms-status')->assertSuccessful();
        $this->assertDatabaseHas('investor_deliveries', ['id' => $id, 'status' => 'submitted', 'provider_status' => 'sent']);
        $this->artisan('haven:sync-sms-status')->assertSuccessful();
        Http::assertSentCount(1);
        $this->travel(6)->minutes();
        $this->artisan('haven:sync-sms-status')->assertSuccessful();
        $this->assertDatabaseHas('investor_deliveries', ['id' => $id, 'status' => 'delivered']);
        $this->assertNotNull(DB::table('investor_deliveries')->where('id', $id)->value('delivered_at'));
        $this->artisan('haven:sync-sms-status')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->travelBack();
    }

    public function test_provider_delivery_failure_is_terminal_and_other_transports_are_not_polled(): void
    {
        $id = $this->delivery();
        DB::table('investor_deliveries')->where('id', $id)->update(['status' => 'submitted', 'sms_provider' => 'roycetalk', 'provider_reference' => 'message-456']);
        $other = $this->delivery();
        DB::table('investor_deliveries')->where('id', $other)->update(['status' => 'submitted', 'sms_provider' => 'twilio', 'provider_reference' => 'other-message']);
        Http::fake(['https://roycetalk.com/api/v1/sms-api/messages/message-456/' => Http::response(['success' => true, 'data' => ['id' => 'message-456', 'status' => 'rejected']])]);
        $this->artisan('haven:sync-sms-status')->assertSuccessful();
        app(InvestorNotifications::class)->deliver($id);
        $this->assertDatabaseHas('investor_deliveries', ['id' => $id, 'status' => 'undelivered', 'provider_status' => 'rejected']);
        Http::assertSentCount(1);
    }

    public function test_status_response_must_match_the_recorded_message(): void
    {
        $id = $this->delivery();
        DB::table('investor_deliveries')->where('id', $id)->update(['status' => 'submitted', 'sms_provider' => 'roycetalk', 'provider_reference' => 'right-message']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'wrong-message', 'status' => 'delivered']])]);
        $this->artisan('haven:sync-sms-status')->assertSuccessful();
        $this->assertDatabaseHas('investor_deliveries', ['id' => $id, 'status' => 'submitted', 'delivered_at' => null]);
        config(['investors.live_delivery' => false]);
        $this->artisan('haven:sync-sms-status')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_balance_command_never_sends_sms_or_exposes_provider_errors(): void
    {
        config(['services.roycetalk.api_key' => 'private-test-key']);
        Http::fake(['https://roycetalk.com/api/v1/sms-api/balance/' => Http::sequence()->push(['success' => true, 'data' => ['current_balance' => 9910]])->push(['success' => false, 'message' => 'private-test-key'], 403)]);
        $this->artisan('haven:sms-balance')->expectsOutputToContain('9910')->assertSuccessful();
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer private-test-key'));
        $this->artisan('haven:sms-balance')->expectsOutputToContain('balance unavailable')->assertFailed();
    }

    public function test_provider_status_outage_preserves_acceptance_and_stops_the_batch(): void
    {
        $first = $this->delivery();
        $second = $this->delivery();
        DB::table('investor_deliveries')->whereIn('id', [$first, $second])->update(['status' => 'submitted', 'sms_provider' => 'roycetalk', 'provider_reference' => 'accepted-message']);
        Http::fake(['*' => Http::response(['success' => false], 503)]);
        $this->artisan('haven:sync-sms-status')->assertSuccessful();
        $this->assertDatabaseHas('investor_deliveries', ['id' => $first, 'status' => 'submitted', 'provider_reference' => 'accepted-message']);
        $this->assertDatabaseHas('investor_deliveries', ['id' => $second, 'status_checked_at' => null]);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_notification_history_and_manual_retry_are_company_scoped(): void
    {
        $id = $this->delivery();
        $delivery = DB::table('investor_deliveries')->where('id', $id)->first();
        $tenant = Tenant::findOrFail($delivery->tenant_id);
        $admin = User::factory()->create();
        $admin->tenants()->attach($tenant, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        DB::table('investor_deliveries')->where('id', $id)->update(['status' => 'undelivered', 'sms_provider' => 'roycetalk', 'provider_reference' => 'review-reference', 'provider_status' => 'rejected']);
        $this->actingAs($admin)->get('/t/'.$tenant->slug.'/investor-notifications')->assertOk()->assertSee('review-reference')->assertSee('Not delivered');
        $this->post('/t/'.$tenant->slug.'/investor-notifications/'.$id.'/retry')->assertRedirect();
        $this->assertDatabaseHas('investor_deliveries', ['id' => $id, 'status' => 'pending', 'provider_reference' => null, 'provider_status' => null]);
        $this->assertStringContainsString('review-reference', DB::table('investor_audit_events')->where('action', 'notification.retry')->value('details'));
        $otherId = $this->delivery();
        $this->post('/t/'.$tenant->slug.'/investor-notifications/'.$otherId.'/retry')->assertNotFound();
    }
}
