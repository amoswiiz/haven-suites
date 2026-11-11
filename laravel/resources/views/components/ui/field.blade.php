{{--
    Labelled input with hint and validation error, keeping old input after a failed save.
    <x-ui.field name="phone" label="Phone" :value="$agent->phone" hint="Kenyan or international number" />
    <x-ui.field name="notes" label="Notes" type="textarea" rows="4" />
    <x-ui.field name="kind" label="Type" type="select" :options="['a' => 'Option A']" :value="$current" />
    <x-ui.field name="active" label="Active" type="checkbox" :checked="true" />
--}}
@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'options' => [], 'checked' => false, 'id' => null])
@php
    $fieldId = $id ?? 'field-'.\Illuminate\Support\Str::slug(str_replace(['[', ']'], '-', $name));
    $errorKey = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $error = $errors->first($errorKey);
    $current = old($errorKey, $value);
    $describedBy = trim(($hint ? $fieldId.'-hint ' : '').($error ? $fieldId.'-error' : ''));
@endphp
<div class="ui-field{{ $error ? ' has-error' : '' }}">
    @if($type === 'checkbox')
        <input type="hidden" name="{{ $name }}" value="0">
        <label class="ui-check" for="{{ $fieldId }}"><input type="checkbox" id="{{ $fieldId }}" name="{{ $name }}" value="1" @checked(old($errorKey, $checked)) @if($describedBy) aria-describedby="{{ $describedBy }}" @endif {{ $attributes }}> <span>{{ $label }}</span></label>
    @else
        <label for="{{ $fieldId }}">{{ $label }}</label>
        @if($type === 'textarea')
            <textarea id="{{ $fieldId }}" name="{{ $name }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($error) aria-invalid="true" @endif {{ $attributes }}>{{ $current }}</textarea>
        @elseif($type === 'select')
            <select id="{{ $fieldId }}" name="{{ $name }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($error) aria-invalid="true" @endif {{ $attributes }}>
                @foreach($options as $optionValue => $optionLabel)<option value="{{ $optionValue }}" @selected((string) $current === (string) $optionValue)>{{ $optionLabel }}</option>@endforeach
            </select>
        @else
            <input type="{{ $type }}" id="{{ $fieldId }}" name="{{ $name }}" value="{{ $current }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($error) aria-invalid="true" @endif {{ $attributes }}>
        @endif
    @endif
    @if($hint)<small class="ui-hint" id="{{ $fieldId }}-hint">{{ $hint }}</small>@endif
    @if($error)<small class="ui-error" id="{{ $fieldId }}-error">{{ $error }}</small>@endif
</div>
