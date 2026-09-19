@props(['active' => false])
<a {{ $attributes->class(['nav-item', 'active' => $active])->merge(['aria-current' => $active ? 'page' : null]) }}>{{ $slot }}</a>
