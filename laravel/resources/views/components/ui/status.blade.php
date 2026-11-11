{{-- <x-ui.status tone="success">Delivered</x-ui.status>  tone: info (default), success, warning, danger, muted --}}
@props(['tone' => 'info'])
<span {{ $attributes->merge(['class' => 'ui-status'.($tone !== 'info' ? ' is-'.$tone : '')]) }}>{{ $slot }}</span>
