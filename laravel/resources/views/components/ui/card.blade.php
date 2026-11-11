{{--
    <x-ui.card title="Agents" description="Optional line under the title">
        <x-slot:actions><x-ui.button href="...">Add</x-ui.button></x-slot:actions>
        ...content...
    </x-ui.card>
--}}
@props(['title' => null, 'description' => null, 'actions' => null])
<section {{ $attributes->merge(['class' => 'ui-card']) }}>
    @if($title || $actions)
        <div class="ui-card-head">
            <div>@if($title)<h2>{{ $title }}</h2>@endif @if($description)<p>{{ $description }}</p>@endif</div>
            @if($actions)<div class="ui-actions" style="margin:0">{{ $actions }}</div>@endif
        </div>
    @endif
    {{ $slot }}
</section>
