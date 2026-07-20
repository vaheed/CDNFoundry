@props([
    'submit' => 'save',
    'label' => 'Save changes',
    'loadingLabel' => 'Saving…',
    'color' => 'primary',
])

<div {{ $attributes->class('cdn-form-actions') }}>
    <x-filament::button
        type="submit"
        :color="$color"
        wire:loading.attr="disabled"
        wire:target="{{ $submit }}"
    >
        <span wire:loading.remove wire:target="{{ $submit }}">{{ $label }}</span>
        <span wire:loading wire:target="{{ $submit }}">{{ $loadingLabel }}</span>
    </x-filament::button>
</div>
