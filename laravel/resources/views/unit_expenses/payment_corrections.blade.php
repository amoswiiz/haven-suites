<section id="payment-corrections" class="card shadow-sm mt-3"><div class="card-body">
    <h5>Payment correction history</h5>
    @forelse($paymentCorrections as $change)
        @php
            $before = json_decode($change->before_snapshot, true) ?? [];
            $after = json_decode($change->after_snapshot, true) ?? [];
            $historicalChange = $change->kind === 'historical';
            $beforeFields = $historicalChange ? ($before['expense'] ?? []) : ($before['payment'] ?? []);
            $afterFields = $historicalChange ? ($after['expense'] ?? []) : ($after['payment'] ?? []);
            $fields = $historicalChange
                ? ['amount_paid' => 'Paid balance', 'paid_at' => 'Payment date', 'payment_method' => 'Method', 'payment_reference' => 'Reference']
                : ['amount' => 'Payment amount', 'payment_date' => 'Payment date', 'payment_method' => 'Method', 'reference' => 'Reference', 'notes' => 'Notes'];
        @endphp
        <article class="border-top pt-3 mt-3">
            <p class="mb-1"><strong>{{ $historicalChange ? 'Historical paid balance' : 'Payment #'.$change->payment_id }}</strong> · {{ $change->actor_name }} · {{ $change->created_at }}</p>
            <p>{{ $change->reason }}</p>
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>
                @foreach($fields as $field => $label)
                    @if((string) ($beforeFields[$field] ?? '') !== (string) ($afterFields[$field] ?? ''))
                        <tr><th>{{ $label }}</th><td style="white-space:pre-wrap">{{ in_array($field, ['amount', 'amount_paid'], true) ? 'KES '.number_format((float) ($beforeFields[$field] ?? 0), 2) : ($beforeFields[$field] ?? 'Not recorded') }}</td><td style="white-space:pre-wrap">{{ in_array($field, ['amount', 'amount_paid'], true) ? 'KES '.number_format((float) ($afterFields[$field] ?? 0), 2) : ($afterFields[$field] ?? 'Not recorded') }}</td></tr>
                    @endif
                @endforeach
            </tbody></table></div>
        </article>
    @empty<p class="text-muted mb-0">No payment corrections recorded.</p>@endforelse
</div></section>
