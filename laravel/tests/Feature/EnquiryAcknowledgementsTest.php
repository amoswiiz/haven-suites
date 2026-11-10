<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EnquiryAcknowledgements;
use App\Services\EnquiryTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnquiryAcknowledgementsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $salesperson;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.roycetalk.manual_live' => true, 'services.roycetalk.api_key' => 'test-key', 'services.roycetalk.sender_id' => 'RoyceLTD', 'investors.live_delivery' => false]);
        $this->company = Tenant::create(['name' => 'Haven Suites', 'slug' => 'enquiry-ack', 'database_name' => 'unused_enquiry_ack', 'active' => true]);
        $this->salesperson = User::factory()->create(['active' => true]);
        $this->salesperson->tenants()->attach($this->company, ['roles' => json_encode(['sales']), 'legacy_user_id' => 22]);
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($this->salesperson);
    }

    private function url(string $suffix = ''): string
    {
        return '/t/'.$this->company->slug.'/sales'.$suffix;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Amina Enquiry', 'phone' => '0712345678', 'source' => 'phone', 'channel' => 'phone',
            'assigned_to' => $this->salesperson->id, 'guests' => 2, 'next_action' => 'Confirm stay requirements',
            'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'), 'capture_token' => (string) Str::uuid(),
        ], $overrides);
    }

    private function accepted(): void
    {
        Http::fake(['https://roycetalk.com/api/v1/sms-api/send/' => Http::response(['success' => true, 'data' => ['message_id' => 'enquiry-test-123']])]);
    }

    public function test_new_enquiry_enters_pipeline_and_receives_a_warm_sms_after_creation(): void
    {
        $this->accepted();
        $this->get($this->url('/new'))->assertOk()->assertSee('name="capture_token"', false)->assertSee('acknowledgement SMS');
        $this->post($this->url(), $this->payload(['stage' => 'won']))->assertSessionHasNoErrors()->assertRedirect();
        $lead = DB::table('sales_leads')->first();
        $this->assertSame('new', $lead->stage);
        $this->assertSame('254712345678', $lead->phone_normalized);
        $this->assertDatabaseHas('sales_activities', ['lead_id' => $lead->id, 'type' => 'created']);
        $this->assertDatabaseHas('manual_sms_messages', [
            'tenant_id' => $this->company->id, 'enquiry_id' => $lead->id, 'communication_stage' => 'enquiry_acknowledgement',
            'sent_by' => $this->salesperson->id, 'phone' => '+254712345678', 'status' => 'submitted', 'provider_status' => 'pending',
        ]);
        $tracking = app(EnquiryTracking::class)->forLead($this->company, (int) $lead->id);
        $this->assertMatchesRegularExpression('/^HS-[0-9]{4}-[0-9]{3}$/', $tracking['reference']);
        Http::assertSent(fn ($request) => $request['phone_number'] === '+254712345678'
            && $request['text_message'] === 'Hi Amina, thanks for contacting Haven Suites! Your enquiry ref is '.$tracking['reference'].'. Track its progress here: '.$tracking['url']
            && str_starts_with($request['client_ref'], 'enquiry-'));
        Http::assertSentCount(1);
        $this->get($this->url('/'.$lead->id))->assertOk()->assertSee('Submitted to SMS provider')->assertSee('delivery pending')->assertDontSee('>Delivered<', false)
            ->assertSee($tracking['reference'])->assertSee($tracking['url']);
        $this->get($this->url('?q='.strtolower($tracking['reference'])))->assertOk()->assertSee('Amina Enquiry');
    }

    public function test_form_retries_and_repeated_acknowledgement_calls_never_duplicate_the_lead_or_sms(): void
    {
        $this->accepted();
        $payload = $this->payload();
        $first = $this->post($this->url(), $payload)->assertRedirect();
        $second = $this->post($this->url(), [...$payload, 'duplicate_acknowledged' => 1])->assertRedirect();
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $lead = DB::table('sales_leads')->first();
        app(EnquiryAcknowledgements::class)->send($this->company, $lead->id, $this->salesperson);
        $this->assertDatabaseCount('sales_leads', 1);
        $this->assertDatabaseCount('manual_sms_messages', 1);
        $this->assertDatabaseCount('sales_activities', 2);
        Http::assertSentCount(1);
    }

    public function test_a_separate_enquiry_needs_duplicate_review_and_gets_its_own_acknowledgement(): void
    {
        $this->accepted();
        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $payload = $this->payload();
        $this->post($this->url(), $payload)->assertSessionHasErrors('duplicate');
        Http::assertSentCount(1);
        $this->post($this->url(), [...$payload, 'duplicate_acknowledged' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales_leads', 2);
        $this->assertDatabaseCount('manual_sms_messages', 2);
        Http::assertSentCount(2);
    }

    public function test_email_only_or_invalid_phone_enquiries_are_saved_with_an_explicit_not_sent_status(): void
    {
        Http::fake();
        $this->post($this->url(), $this->payload(['phone' => '', 'email' => 'email-only@example.test']))->assertSessionHasNoErrors();
        $first = DB::table('sales_leads')->first();
        $this->assertDatabaseHas('manual_sms_messages', ['enquiry_id' => $first->id, 'status' => 'skipped', 'phone' => '']);
        $this->get($this->url('/'.$first->id))->assertOk()->assertSee('Not sent')->assertSee('No phone number was supplied');
        $this->post($this->url(), $this->payload(['phone' => 'ask the guest', 'email' => 'invalid-phone@example.test']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales_leads', 2);
        $this->assertSame(2, DB::table('manual_sms_messages')->where('status', 'skipped')->count());
        Http::assertNothingSent();
    }

    public function test_sms_disabled_or_missing_provider_configuration_preserves_the_enquiry(): void
    {
        Http::fake();
        config(['services.roycetalk.manual_live' => false]);
        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $lead = DB::table('sales_leads')->first();
        $this->assertDatabaseHas('manual_sms_messages', ['enquiry_id' => $lead->id, 'status' => 'skipped']);
        config(['services.roycetalk.manual_live' => true, 'services.roycetalk.api_key' => null]);
        $this->post($this->url(), $this->payload(['phone' => '0112345678']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales_leads', 2);
        $this->assertSame(2, DB::table('manual_sms_messages')->where('status', 'skipped')->count());
        Http::assertNothingSent();
    }

    public function test_messaging_module_disabled_prevents_sms_but_does_not_block_sales_capture(): void
    {
        Http::fake();
        $this->company->modules = ['messaging' => false];
        $this->company->save();
        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $lead = DB::table('sales_leads')->first();
        $this->get($this->url('/'.$lead->id))->assertOk()->assertSee('messaging is disabled');
        $this->assertDatabaseHas('manual_sms_messages', ['enquiry_id' => $lead->id, 'status' => 'skipped']);
        Http::assertNothingSent();
    }

    public function test_provider_rejection_does_not_lose_the_enquiry_or_retry(): void
    {
        Http::fake(['*' => Http::response(['success' => false], 429)]);
        $payload = $this->payload();
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        $lead = DB::table('sales_leads')->first();
        $this->assertDatabaseHas('manual_sms_messages', ['enquiry_id' => $lead->id, 'status' => 'rejected']);
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        app(EnquiryAcknowledgements::class)->send($this->company, $lead->id, $this->salesperson);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('sales_leads', 1);
    }

    public function test_interrupted_or_unconfirmed_delivery_is_not_automatically_resent(): void
    {
        Http::fake(fn () => throw new ConnectionException('test-key should never be recorded'));
        $payload = $this->payload();
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        $lead = DB::table('sales_leads')->first();
        $record = DB::table('manual_sms_messages')->first();
        $this->assertSame('unknown', $record->status);
        $this->assertStringNotContainsString('test-key', $record->last_error);
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        app(EnquiryAcknowledgements::class)->send($this->company, $lead->id, $this->salesperson);
        $this->assertDatabaseCount('manual_sms_messages', 1);
        $this->assertDatabaseCount('sales_activities', 2);
    }

    public function test_success_http_without_a_valid_provider_reference_is_unknown(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['message_id' => '../invalid-reference']])]);
        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('manual_sms_messages', ['status' => 'unknown', 'provider_reference' => null]);
    }

    public function test_phone_normalization_accepts_kenyan_and_explicit_international_formats(): void
    {
        $service = app(EnquiryAcknowledgements::class);
        foreach (['0712345678' => '+254712345678', '0112345678' => '+254112345678', '254712345678' => '+254712345678', '+254 712 345 678' => '+254712345678', '+44 (7700) 900-123' => '+447700900123', '00447700900123' => '+447700900123'] as $input => $expected) {
            $this->assertSame($expected, $service->phone((string) $input));
        }
        foreach (['', 'email@example.test', '+2547123 extension 5', '712345678', '++254712345678'] as $input) {
            $this->assertNull($service->phone($input));
        }
    }

    public function test_acknowledgement_waits_for_commit_and_never_sends_when_the_enquiry_is_rolled_back(): void
    {
        $this->accepted();
        DB::beginTransaction();
        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales_leads', 1);
        $this->assertDatabaseCount('sales_activities', 1);
        $this->assertDatabaseHas('manual_sms_messages', ['status' => 'pending']);
        Http::assertNothingSent();
        DB::rollBack();
        $this->assertDatabaseCount('sales_leads', 0);
        $this->assertDatabaseCount('sales_activities', 0);
        $this->assertDatabaseCount('manual_sms_messages', 0);
        Http::assertNothingSent();
    }

    public function test_sms_intent_and_activity_exist_before_commit_and_submission_claims_it_after_commit(): void
    {
        $observed = null;
        Http::fake(function () use (&$observed) {
            $observed = [
                'stage' => DB::table('sales_leads')->value('stage'),
                'intent_status' => DB::table('manual_sms_messages')->value('status'),
                'created_activity' => DB::table('sales_activities')->where('type', 'created')->exists(),
            ];

            return Http::response(['success' => true, 'data' => ['message_id' => 'durable-enquiry-123']]);
        });
        DB::beginTransaction();
        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('manual_sms_messages', ['status' => 'pending']);
        $this->assertNull($observed);
        DB::commit();
        $this->assertSame(['stage' => 'new', 'intent_status' => 'processing', 'created_activity' => true], $observed);
        $this->assertDatabaseHas('manual_sms_messages', ['status' => 'submitted', 'provider_reference' => 'durable-enquiry-123']);
        Http::assertSentCount(1);
    }

    public function test_same_capture_retry_recovers_a_pending_intent_only_once(): void
    {
        $this->partialMock(EnquiryAcknowledgements::class, function ($mock): void {
            $mock->shouldReceive('send')->once()->andReturnNull();
        });
        $payload = $this->payload();
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('manual_sms_messages', ['status' => 'pending']);
        $this->app->forgetInstance(EnquiryAcknowledgements::class);
        $this->accepted();
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('manual_sms_messages', ['status' => 'submitted']);
        $this->assertDatabaseCount('sales_leads', 1);
        $this->assertDatabaseCount('manual_sms_messages', 1);
        Http::assertSentCount(1);
    }

    public function test_an_in_flight_processing_intent_is_not_retried_by_capture_replay(): void
    {
        $this->partialMock(EnquiryAcknowledgements::class, function ($mock): void {
            $mock->shouldReceive('send')->once()->andReturnNull();
        });
        $payload = $this->payload();
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        DB::table('manual_sms_messages')->update(['status' => 'processing']);
        $this->app->forgetInstance(EnquiryAcknowledgements::class);
        Http::fake();
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        $lead = DB::table('sales_leads')->first();
        app(EnquiryAcknowledgements::class)->send($this->company, $lead->id, $this->salesperson);
        $this->assertDatabaseHas('manual_sms_messages', ['status' => 'processing']);
        $this->assertDatabaseCount('sales_leads', 1);
        Http::assertNothingSent();
    }

    public function test_sms_intent_preparation_failure_rolls_back_the_enquiry_and_created_activity(): void
    {
        Http::fake();
        $this->partialMock(EnquiryAcknowledgements::class, function ($mock): void {
            $mock->shouldReceive('prepare')->once()->andThrow(new \RuntimeException('Intent storage unavailable'));
        });
        $this->post($this->url(), $this->payload())->assertServerError();
        $this->assertDatabaseCount('sales_leads', 0);
        $this->assertDatabaseCount('sales_activities', 0);
        $this->assertDatabaseCount('manual_sms_messages', 0);
        Http::assertNothingSent();
    }

    public function test_international_and_local_formatting_uses_one_canonical_duplicate_key(): void
    {
        $this->accepted();
        $this->post($this->url(), $this->payload(['phone' => '00447700900123']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales_leads', ['phone_normalized' => '447700900123']);
        $this->post($this->url(), $this->payload(['phone' => '+44 (7700) 900-123']))->assertSessionHasErrors('duplicate');
        $this->post($this->url(), $this->payload(['phone' => '0(712) 345-678']))->assertSessionHasNoErrors();
        $this->post($this->url(), $this->payload(['phone' => '+254712345678']))->assertSessionHasErrors('duplicate');
        $this->assertDatabaseCount('sales_leads', 2);
        Http::assertSentCount(2);
    }

    public function test_validation_and_permission_errors_do_not_create_pipeline_entries_or_sms(): void
    {
        Http::fake();
        $this->post($this->url(), $this->payload(['capture_token' => 'invalid']))->assertSessionHasErrors('capture_token');
        $this->post($this->url(), $this->payload(['phone' => '', 'email' => '']))->assertSessionHasErrors('phone');
        $cleaner = User::factory()->create(['active' => true]);
        $cleaner->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 23]);
        $this->actingAs($cleaner)->post($this->url(), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('sales_leads', 0);
        $this->assertDatabaseCount('manual_sms_messages', 0);
        Http::assertNothingSent();
    }

    public function test_enquiry_acknowledgements_are_company_scoped_and_capture_tokens_can_be_reused_in_another_company(): void
    {
        $this->accepted();
        $payload = $this->payload();
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        $first = DB::table('sales_leads')->first();
        $other = Tenant::create(['name' => 'Other Suites', 'slug' => 'other-enquiry', 'database_name' => 'unused_other_enquiry', 'active' => true]);
        $this->salesperson->tenants()->attach($other, ['roles' => json_encode(['sales']), 'legacy_user_id' => 22]);
        app()->instance('currentTenant', $other);
        $this->get('/t/other-enquiry/sales/'.$first->id)->assertNotFound();
        $this->post('/t/other-enquiry/sales', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales_leads', 2);
        $this->assertDatabaseCount('manual_sms_messages', 2);
        $this->assertNull(app(EnquiryAcknowledgements::class)->status($other->id, $first->id));
        Http::assertSentCount(2);
    }

    public function test_delivery_status_poller_updates_enquiry_acknowledgements_without_resending(): void
    {
        $this->accepted();
        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        Http::fake(['https://roycetalk.com/api/v1/sms-api/messages/enquiry-test-123/' => Http::response(['success' => true, 'data' => ['id' => 'enquiry-test-123', 'status' => 'delivered']])]);
        $this->artisan('haven:sync-sms-status')->assertSuccessful();
        $this->assertDatabaseHas('manual_sms_messages', ['status' => 'delivered']);
        $lead = DB::table('sales_leads')->first();
        $this->get($this->url('/'.$lead->id))->assertOk()->assertSee('Delivered');
    }

    public function test_legacy_capture_requests_without_a_form_token_still_save(): void
    {
        $this->accepted();
        $payload = $this->payload();
        unset($payload['capture_token']);
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales_leads', ['capture_token' => null, 'stage' => 'new']);
        Http::assertSentCount(1);
    }

    public function test_editing_existing_enquiry_details_does_not_send_another_acknowledgement(): void
    {
        $this->accepted();
        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $lead = DB::table('sales_leads')->first();
        $this->post($this->url('/'.$lead->id.'/details'), ['name' => 'Amina Updated', 'phone' => '0112345678', 'guests' => 2, 'version' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales_leads', ['id' => $lead->id, 'name' => 'Amina Updated']);
        $this->assertDatabaseCount('manual_sms_messages', 1);
        Http::assertSentCount(1);
    }
}
