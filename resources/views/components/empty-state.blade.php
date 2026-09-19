@props(['title', 'description' => null])
<div {{ $attributes->class(['empty-state']) }}>
    <span class="empty-state-mark" aria-hidden="true">&mdash;</span>
    <p class="empty-state-title">{{ $title }}</p>
    @if ($description)<p class="empty-state-description">{{ $description }}</p>@endif
    @unless ($slot->isEmpty())<div class="mt-4">{{ $slot }}</div>@endunless
</div>
