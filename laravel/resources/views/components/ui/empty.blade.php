{{-- <x-ui.empty title="No agents yet" text="Add your first referral agent." :action-url="..." action-label="Add agent" /> --}}
@props(['title', 'text' => null, 'actionUrl' => null, 'actionLabel' => null])
<div {{ $attributes->merge(['class' => 'ui-empty']) }}>
    <strong>{{ $title }}</strong>
    @if($text)<p>{{ $text }}</p>@endif
    @if($actionUrl && $actionLabel)<div class="ui-actions"><x-ui.button :href="$actionUrl">{{ $actionLabel }}</x-ui.button></div>@endif
</div>
