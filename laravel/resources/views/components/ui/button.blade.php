{{--
    <x-ui.button>Save</x-ui.button>
    <x-ui.button href="/somewhere" variant="secondary">Cancel</x-ui.button>
    variant: primary (default), secondary, danger
--}}
@props(['href' => null, 'variant' => 'primary', 'type' => 'submit'])
@php($classes = 'ui-button'.($variant !== 'primary' ? ' is-'.$variant : ''))
@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
