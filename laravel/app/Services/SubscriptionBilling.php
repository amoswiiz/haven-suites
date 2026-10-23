<?php

namespace App\Services;

use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionBilling
{
    public function assertSandbox(): void
    {
        if (! app()->environment('local', 'testing') || ! config('billing.sandbox_enabled')) {
            throw ValidationException::withMessages(['billing' => 'Payment collection is disabled. Sandbox billing must be explicitly enabled in a local or testing environment.']);
        }
    }

    public function start(Tenant $tenant, User $user, string $plan, string $provider, ?string $phone = null): array
    {
        $this->assertSandbox();
        $price = (int) config('billing.prices_minor.'.$plan);
        if ($price <= 0 || ! array_key_exists($plan, config('saas.plans')) || ! in_array($provider, ['stripe', 'mpesa', 'manual'], true)) {
            throw ValidationException::withMessages(['plan' => 'Set a sandbox plan price before testing a payment.']);
        }
        if ($provider === 'stripe' && ! str_starts_with((string) config('billing.stripe.secret'), 'sk_test_')) {
            throw ValidationException::withMessages(['provider' => 'Only a Stripe test secret key is accepted. Live keys are blocked.']);
        }
        if ($provider === 'mpesa') {
            foreach (['key', 'secret', 'shortcode', 'passkey', 'callback_base_url'] as $key) {
                if (! config('billing.mpesa.'.$key)) {
                    throw ValidationException::withMessages(['provider' => 'M-Pesa sandbox credentials and callback URL are required.']);
                }
            }
            if ($price % 100 !== 0 || ! preg_match('/^254[17]\d{8}$/D', $phone ?? '') || ! str_starts_with(config('billing.mpesa.callback_base_url'), 'https://')) {
                throw ValidationException::withMessages(['phone' => 'Use a sandbox test phone beginning with 254, a whole-shilling price, and an HTTPS sandbox callback URL.']);
            }
        }
        $payment = SubscriptionPayment::create(['reference' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $user->id, 'plan' => $plan, 'provider' => $provider, 'amount_minor' => $price, 'currency' => 'KES', 'mode' => 'sandbox', 'phone' => $phone]);
        try {
            $url = null;
            if ($provider === 'stripe') {
                $session = Http::asForm()->withBasicAuth(config('billing.stripe.secret'), '')->withHeaders(['Idempotency-Key' => $payment->reference])->timeout(20)
                    ->post('https://api.stripe.com/v1/checkout/sessions', [
                        'mode' => 'payment', 'payment_method_types' => ['card'], 'client_reference_id' => $payment->reference,
                        'customer_email' => $user->email, 'metadata' => ['tenant_id' => (string) $tenant->id],
                        'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'kes', 'unit_amount' => $price, 'product_data' => ['name' => 'SANDBOX '.config('saas.plans.'.$plan.'.name').' — 1 month']]]],
                        'success_url' => route('company.subscription', $tenant->slug).'?payment=processing', 'cancel_url' => route('company.subscription', $tenant->slug),
                    ])->throw()->json();
                if (empty($session['id']) || ($session['livemode'] ?? true) !== false || ! str_starts_with($session['url'] ?? '', 'https://checkout.stripe.com/')) {
                    throw new \RuntimeException('Expected a Stripe test-mode checkout.');
                }
                $payment->update(['gateway_id' => $session['id']]);
                $url = $session['url'];
            } elseif ($provider === 'mpesa') {
                $token = bin2hex(random_bytes(32));
                $payment->update(['callback_token_hash' => hash('sha256', $token)]);
                $result = Http::withToken($this->mpesaToken())->timeout(20)->post('https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest', [
                    ...$this->mpesaCredentials(), 'TransactionType' => 'CustomerPayBillOnline', 'Amount' => intdiv($price, 100),
                    'PartyA' => $phone, 'PartyB' => config('billing.mpesa.shortcode'), 'PhoneNumber' => $phone,
                    'CallBackURL' => rtrim(config('billing.mpesa.callback_base_url'), '/').'/billing/webhooks/mpesa/'.$token,
                    'AccountReference' => 'TEST'.$payment->id, 'TransactionDesc' => 'Sandbox subscription',
                ])->throw()->json();
                if ((string) ($result['ResponseCode'] ?? '') !== '0' || empty($result['CheckoutRequestID'])) {
                    throw new \RuntimeException('M-Pesa sandbox checkout was not accepted.');
                }
                $payment->update(['gateway_id' => $result['CheckoutRequestID'], 'metadata' => ['merchant_request_id' => $result['MerchantRequestID'] ?? null]]);
            }

            return ['payment' => $payment, 'url' => $url];
        } catch (\Throwable $exception) {
            $payment->update(['status' => 'failed']);
            report($exception);
            throw ValidationException::withMessages(['payment' => 'Sandbox initiation failed. No subscription was activated.']);
        }
    }

    public function settle(SubscriptionPayment $payment, string $transactionId, ?int $reviewer = null): void
    {
        $this->assertSandbox();
        abort_unless($payment->mode === 'sandbox', 403);
        DB::transaction(function () use ($payment, $transactionId, $reviewer): void {
            $payment = SubscriptionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'paid') {
                return;
            }
            abort_unless($payment->status === 'pending', 409);
            $tenant = Tenant::whereKey($payment->tenant_id)->lockForUpdate()->firstOrFail();
            $end = $tenant->subscription_ends_at?->isFuture() && $tenant->subscription_status === 'active' ? $tenant->subscription_ends_at->copy() : now();
            $tenant->update(['plan' => $payment->plan, 'subscription_status' => 'active', 'subscription_ends_at' => $end->addMonthNoOverflow(), 'requested_plan' => null]);
            $payment->update(['status' => 'paid', 'transaction_id' => $transactionId, 'paid_at' => now(), 'reviewed_by' => $reviewer]);
            if ($reviewer) {
                DB::table('platform_audit_events')->insert(['actor_id' => $reviewer, 'tenant_id' => $tenant->id, 'action' => 'sandbox.payment.approved', 'details' => json_encode(['reference' => $payment->reference, 'amount_minor' => $payment->amount_minor]), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function stripeWebhook(string $body, string $signature): void
    {
        $this->assertSandbox();
        $secret = config('billing.stripe.webhook_secret');
        abort_unless($secret, 503);
        $parts = [];
        foreach (explode(',', $signature) as $item) {
            $pair = explode('=', $item, 2);
            if (count($pair) === 2) {
                $parts[$pair[0]][] = $pair[1];
            }
        }
        $timestamp = $parts['t'][0] ?? '';
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 400);
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        abort_unless(collect($parts['v1'] ?? [])->contains(fn (string $value): bool => hash_equals($expected, $value)), 400);
        $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        abort_unless(($event['livemode'] ?? null) === false, 400);
        if (! in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return;
        }
        $session = $event['data']['object'] ?? [];
        $payment = SubscriptionPayment::where('provider', 'stripe')->where('gateway_id', $session['id'] ?? '')->firstOrFail();
        abort_unless(($session['payment_status'] ?? '') === 'paid'
            && (int) ($session['amount_total'] ?? 0) === $payment->amount_minor
            && strtolower($session['currency'] ?? '') === 'kes'
            && ($session['client_reference_id'] ?? '') === $payment->reference
            && (string) ($session['metadata']['tenant_id'] ?? '') === (string) $payment->tenant_id, 400);
        $this->settle($payment, 'stripe-test:'.$session['id']);
    }

    public function mpesaWebhook(string $token, array $body): void
    {
        $this->assertSandbox();
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $payment = SubscriptionPayment::where('provider', 'mpesa')->where('callback_token_hash', hash('sha256', $token))->firstOrFail();
        if ($payment->status === 'paid') {
            return;
        }
        $callback = $body['Body']['stkCallback'] ?? [];
        abort_unless(($callback['CheckoutRequestID'] ?? '') === $payment->gateway_id && ($callback['MerchantRequestID'] ?? '') === ($payment->metadata['merchant_request_id'] ?? null), 400);
        if ((int) ($callback['ResultCode'] ?? -1) !== 0) {
            return;
        }
        $items = collect($callback['CallbackMetadata']['Item'] ?? [])->mapWithKeys(fn (array $item): array => [$item['Name'] => $item['Value'] ?? null]);
        abort_unless((int) round((float) $items->get('Amount', 0) * 100) === $payment->amount_minor && (string) $items->get('PhoneNumber', '') === $payment->phone, 400);
        $result = Http::withToken($this->mpesaToken())->timeout(20)->post('https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query', [...$this->mpesaCredentials(), 'CheckoutRequestID' => $payment->gateway_id])->throw()->json();
        if ((string) ($result['ResultCode'] ?? '') !== '0') {
            return;
        }
        abort_unless(($result['CheckoutRequestID'] ?? '') === $payment->gateway_id, 400);
        $receipt = (string) $items->get('MpesaReceiptNumber', '');
        abort_unless(preg_match('/^[A-Z0-9]{8,20}$/D', $receipt), 400);
        $this->settle($payment, 'mpesa-test:'.$receipt);
    }

    private function mpesaToken(): string
    {
        $response = Http::withBasicAuth(config('billing.mpesa.key'), config('billing.mpesa.secret'))->timeout(15)->get('https://sandbox.safaricom.co.ke/oauth/v1/generate', ['grant_type' => 'client_credentials'])->throw()->json();
        if (empty($response['access_token'])) {
            throw new \RuntimeException('M-Pesa sandbox authentication failed.');
        }

        return $response['access_token'];
    }

    private function mpesaCredentials(): array
    {
        $timestamp = now('Africa/Nairobi')->format('YmdHis');

        return ['BusinessShortCode' => config('billing.mpesa.shortcode'), 'Timestamp' => $timestamp, 'Password' => base64_encode(config('billing.mpesa.shortcode').config('billing.mpesa.passkey').$timestamp)];
    }
}
