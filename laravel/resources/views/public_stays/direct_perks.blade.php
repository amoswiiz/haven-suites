@php
    $ourPrice = (float) ($listing['starting_price'] ?? 0);
    $otaPrice = (float) ($listing['ota_price'] ?? 0);
    $saving = $ourPrice > 0 && $otaPrice > $ourPrice ? $otaPrice - $ourPrice : 0;
    $savingPercent = $saving > 0 ? (int) floor($saving / $otaPrice * 100) : 0;
    $perks = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($site->direct_perks ?? '')) ?: []))), 0, 4);
@endphp
@if($savingPercent >= 3 || $perks !== [])
    <div class="stay-direct" aria-label="Why book direct">
        @if($savingPercent >= 3)
            <p class="stay-direct-saving"><strong>Save {{ $listing['currency'] }} {{ number_format($saving) }} a night</strong> by booking direct <span>({{ $savingPercent }}% less than booking sites)</span></p>
        @else
            <p class="stay-direct-saving"><strong>Book direct with {{ $tenant->name }}</strong></p>
        @endif
        @if($perks !== [])<ul>@foreach($perks as $perk)<li>{{ $perk }}</li>@endforeach</ul>@endif
    </div>
@endif
