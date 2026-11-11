{{-- Private check-in guide from the listing, offered only once the booking is confirmed (lead won). --}}
@if(!empty($checkInGuide?->check_in_guide) && auth()->user()->can('sales.manage'))
    <section class="card" aria-labelledby="check-in-guide-heading">
        <h2 id="check-in-guide-heading">Check-in guide <small class="muted">{{ $checkInGuide->title }}</small></h2>
        @if($lead->stage === 'won')
            @php
                $guestPhone = \App\Support\PhoneNumber::normalize($lead->phone ?? null);
                $guestName = trim(strtok(trim((string) $lead->name), ' ') ?: '');
                $guideMessage = 'Hi '.($guestName ?: 'there').', here is your check-in guide for '.$checkInGuide->title.":\n\n".$checkInGuide->check_in_guide;
            @endphp
            <p class="muted">Private details for this guest. Do not post them publicly.</p>
            <pre class="check-in-guide" id="check-in-guide-text" style="white-space:pre-wrap;font:inherit;background:#f4f6fa;border-radius:10px;padding:12px;margin:0">{{ $guideMessage }}</pre>
            <div class="actions">
                @if($guestPhone)<a class="button" href="https://wa.me/{{ ltrim($guestPhone, '+') }}?text={{ rawurlencode($guideMessage) }}" target="_blank" rel="noopener noreferrer">Send on WhatsApp</a>@else<span class="muted">Add the guest's mobile number to send it on WhatsApp.</span>@endif
                <button type="button" data-copy-guide>Copy guide</button><span class="muted" data-copy-guide-status aria-live="polite"></span>
            </div>
            <script>
            document.querySelector('[data-copy-guide]')?.addEventListener('click', async () => {
                const status = document.querySelector('[data-copy-guide-status]');
                try { await navigator.clipboard.writeText(document.getElementById('check-in-guide-text').textContent); status.textContent = 'Copied.'; }
                catch { status.textContent = 'Select the text and copy it.'; }
            });
            </script>
        @else
            <p class="muted">This listing has a private check-in guide. It becomes available to send here once the booking is confirmed (stage: Won).</p>
        @endif
    </section>
@endif
