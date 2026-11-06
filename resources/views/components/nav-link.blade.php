@props(['active' => false, 'icon' => null])
<a {{ $attributes->class(['nav-item', 'active' => $active])->merge(['aria-current' => $active ? 'page' : null]) }}>
    @if ($icon)
        <x-nav-icon :name="$icon" />
    @endif
    <span class="nav-label">{{ $slot }}</span>
</a>
