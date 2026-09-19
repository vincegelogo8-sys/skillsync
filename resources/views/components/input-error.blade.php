@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'input-errors text-sm text-red-700 space-y-1', 'role' => 'alert']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
