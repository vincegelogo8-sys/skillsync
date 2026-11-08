@props(['badge' => null])
@php
    $initials = collect(preg_split('/\s+/', trim((string) Auth::user()->name)))
        ->filter()->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp
<span {{ $attributes->class(['user-avatar']) }} aria-hidden="true">
    <span class="user-avatar-initials">{{ $initials !== '' ? $initials : '?' }}</span>
    @if ($photoUrl = Auth::user()->profilePhotoUrl())
        <img src="{{ $photoUrl }}" alt="" class="user-avatar-photo" loading="lazy" onerror="this.remove()">
    @endif
    @if ($badge)
        <span class="user-avatar-badge">
            <x-nav-icon :name="$badge === 'admin' ? 'cog' : 'graduation-cap'" />
        </span>
    @endif
</span>