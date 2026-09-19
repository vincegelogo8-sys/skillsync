@props(['status'])

@if ($status)
    <div role="status" {{ $attributes->merge(['class' => 'ui-alert alert-success']) }}>
        {{ $status }}
    </div>
@endif
