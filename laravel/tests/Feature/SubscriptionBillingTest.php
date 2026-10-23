<?php

namespace Tests\Feature;

use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['billing.sandbox_enabled' => true, 'billing.prices_minor.starter' => 100000, 'billing.stripe.secret' => 'sk_test_fake', 'billing.stripe.webhook_secret' => 'whsec_test', 'billing.mpesa.key' => 'sandbox-key', 'billing.mpesa.secret' => 'sandbox-secret']);
    }

    private function companyUser(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $user->tenants()->attach($tenant, ['roles' => json_encode(['superadmin']), 'legacy_user_id' => 1]);
        $this->actingAs($user);

        return [$user, $tenant];
    }

    public function test_manual_test_invoice_does_not_activate_until_platform_review(): void
    {
        [$user, $tenant] = $this->companyUser();
        $this->post('/t/'.$tenant->slug.'/company/subscription/checkout', ['plan' => 'starter', 'provider' => 'manual', 'amount_minor' => 1])->assertSessionHas('success');
        $payment = SubscriptionPayment::firstOrFail();
        $this->assertSame(100000, $payment->amount_minor);
        $this->assertSame('legacy', $tenant->fresh()->subscription_status);
        $this->get('/t/'.$tenant->slug.'/company/subscription/payments/'.$payment->reference.'/invoice')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->post('/platform/payments/'.$payment->id.'/approve', ['transaction_reference' => 'TEST-REF'])->assertForbidden();
        $user->forceFill(['is_platform_admin' => true])->save();
        $this->post('/platform/payments/'.$payment->id.'/approve', ['transaction_reference' => 'TEST-REF'])->assertSessionHas('success');
        $end = $tenant->fresh()->subscription_ends_at;
        $this->assertSame('active', $tenant->fresh()->subscription_status);
        $this->post('/platform/payments/'.$payment->id.'/approve', ['transaction_reference' => 'TEST-REF'])->assertSessionHas('success');
        $this->assertTrue($tenant->fresh()->subscription_ends_at->equalTo($end));
        $this->assertSame(1, DB::table('platform_audit_events')->count());
    }

    public function test_disabled_sandbox_and_live_keys_are_rejected_without_network_calls(): void
    {
        [, $tenant] = $this->companyUser();
        Http::fake();
        config(['billing.sandbox_enabled' => false]);
        $this->post('/t/'.$tenant->slug.'/company/subscription/checkout', ['plan' => 'starter', 'provider' => 'manual'])->assertSessionHasErrors('billing');
        config(['billing.sandbox_enabled' => true, 'billing.stripe.secret' => 'sk_live_not_allowed']);
        $this->post('/t/'.$tenant->slug.'/company/subscription/checkout', ['plan' => 'starter', 'provider' => 'stripe'])->assertSessionHasErrors('provider');
        Http::assertNothingSent();
        $this->assertDatabaseCount('subscription_payments', 0);
    }

    public function test_sandbox_cannot_run_in_production_even_when_enabled(): void
    {
        [$user, $tenant] = $this->companyUser();
        $this->app['env'] = 'production';
        $this->expectException(ValidationException::class);
        app(SubscriptionBilling::class)->start($tenant, $user, 'starter', 'manual');
    }

    public function test_stripe_test_checkout_uses_server_price_and_does_not_settle_on_redirect(): void
    {
        [, $tenant] = $this->companyUser();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_123', 'livemode' => false, 'url' => 'https://checkout.stripe.com/c/pay/cs_test_123'])]);
        $this->post('/t/'.$tenant->slug.'/company/subscription/checkout', ['plan' => 'starter', 'provider' => 'stripe', 'amount_minor' => 1])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');
        Http::assertSent(fn ($request): bool => $request['line_items'][0]['price_data']['unit_amount'] === 100000);
        $this->assertDatabaseHas('subscription_payments', ['gateway_id' => 'cs_test_123', 'status' => 'pending', 'mode' => 'sandbox']);
        $this->assertSame('legacy', $tenant->fresh()->subscription_status);
    }

    private function stripeEvent(SubscriptionPayment $payment, array $overrides = []): array
    {
        return ['type' => 'checkout.session.completed', 'livemode' => false, 'data' => ['object' => [...['id' => $payment->gateway_id, 'payment_status' => 'paid', 'amount_total' => $payment->amount_minor, 'currency' => 'kes', 'client_reference_id' => $payment->reference, 'metadata' => ['tenant_id' => (string) $payment->tenant_id]], ...$overrides]]];
    }

    private function webhook(array $event, ?string $signature = null): TestResponse
    {
        $body = json_encode($event);
        $signature ??= 't='.time().',v1='.hash_hmac('sha256', time().'.'.$body, 'whsec_test');

        return $this->call('POST', '/billing/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $body);
    }

    public function test_stripe_signature_amount_live_mode_and_replays_are_checked(): void
    {
        $payment = SubscriptionPayment::factory()->create(['provider' => 'stripe', 'gateway_id' => 'cs_test_verify']);
        $this->webhook($this->stripeEvent($payment), 't=1,v1=invalid')->assertStatus(400);
        $this->webhook($this->stripeEvent($payment, ['amount_total' => 1]))->assertStatus(400);
        $event = $this->stripeEvent($payment);
        $event['livemode'] = true;
        $this->webhook($event)->assertStatus(400);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->webhook($this->stripeEvent($payment))->assertOk();
        $end = $payment->tenant->fresh()->subscription_ends_at;
        $this->webhook($this->stripeEvent($payment))->assertOk();
        $this->assertTrue($payment->tenant->fresh()->subscription_ends_at->equalTo($end));
    }

    public function test_mpesa_sandbox_callback_requires_token_amount_and_provider_confirmation(): void
    {
        $token = bin2hex(random_bytes(32));
        $payment = SubscriptionPayment::factory()->create(['provider' => 'mpesa', 'gateway_id' => 'checkout-test', 'callback_token_hash' => hash('sha256', $token), 'phone' => '254700000000', 'metadata' => ['merchant_request_id' => 'merchant-test']]);
        $callback = ['Body' => ['stkCallback' => ['CheckoutRequestID' => 'checkout-test', 'MerchantRequestID' => 'merchant-test', 'ResultCode' => 0, 'CallbackMetadata' => ['Item' => [['Name' => 'Amount', 'Value' => 1000], ['Name' => 'PhoneNumber', 'Value' => '254700000000'], ['Name' => 'MpesaReceiptNumber', 'Value' => 'TEST1234567']]]]]];
        $this->postJson('/billing/webhooks/mpesa/'.str_repeat('a', 64), $callback)->assertNotFound();
        Http::fake(['*/oauth/*' => Http::response(['access_token' => 'sandbox-token']), '*/stkpushquery/*' => Http::sequence()->push(['CheckoutRequestID' => 'checkout-test', 'ResultCode' => '1032'])->push(['CheckoutRequestID' => 'checkout-test', 'ResultCode' => '0'])]);
        $this->postJson('/billing/webhooks/mpesa/'.$token, $callback)->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);
        $this->postJson('/billing/webhooks/mpesa/'.$token, $callback)->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('mpesa-test:TEST1234567', $payment->fresh()->transaction_id);
    }

    public function test_other_company_cannot_download_subscription_invoice(): void
    {
        [, $tenant] = $this->companyUser();
        $payment = SubscriptionPayment::factory()->create();
        $this->get('/t/'.$tenant->slug.'/company/subscription/payments/'.$payment->reference.'/invoice')->assertNotFound();
    }
}
