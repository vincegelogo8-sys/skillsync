@props(['name', 'show' => false, 'maxWidth' => '2xl'])

@php
    $widthClass = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-lg',
        'xl' => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl',
    ][$maxWidth] ?? 'sm:max-w-2xl';
@endphp

<dialog id="{{ $name }}" data-show="{{ $show ? 'true' : 'false' }}"
    {{ $attributes->except('focusable')->class(['w-full rounded-lg bg-white p-0 shadow-xl backdrop:bg-gray-900/60', $widthClass]) }}>
    {{ $slot }}
</dialog>
