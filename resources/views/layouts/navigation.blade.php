@php
    $role = Auth::user()->role;
    $workspace = config('workspace_navigation.'.$role, ['primary' => [], 'secondary' => [], 'secondary_label' => 'Workspace']);
@endphp
<aside id="workspace-sidebar" class="sidebar" aria-label="{{ __('Workspace navigation') }}" tabindex="-1">
    <div class="sidebar-brand-row">
        <a href="{{ route('dashboard') }}" class="sidebar-brand"><x-skillsync-mark alt="" /> <span>SKILLSYNC</span></a>
        {{-- Desktop only: icon-only collapse/expand. On phones the top bar "Menu" button opens the sidebar. --}}
        <button type="button" class="sidebar-collapse-toggle" aria-controls="workspace-sidebar" aria-expanded="true" aria-label="{{ __('Collapse menu') }}" title="{{ __('Collapse menu') }}" data-sidebar-collapse>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <path d="M11 17l-5-5 5-5M18 17l-5-5 5-5" />
            </svg>
        </button>
        <button type="button" class="button button-secondary sidebar-close" data-sidebar-close>{{ __('Close') }}</button>
    </div>
    <p class="sidebar-caption">{{ ucfirst($role) }} {{ __('Workspace') }}</p>
    <nav class="sidebar-navigation" aria-label="{{ __('Main navigation') }}">
        <div class="nav-group">
            <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard', '*.dashboard')" icon="dashboard">{{ __('Dashboard') }}</x-nav-link>
            @foreach ($workspace['primary'] as $link)
                <x-nav-link :href="route($link['route'])" :active="request()->routeIs(...$link['active'])" :icon="$link['icon']">{{ __($link['label']) }}</x-nav-link>
            @endforeach
        </div>
        <div class="nav-group">
            <p class="nav-group-label">{{ __($workspace['secondary_label']) }}</p>
            @foreach ($workspace['secondary'] as $link)
                <x-nav-link :href="route($link['route'])" :active="request()->routeIs(...$link['active'])" :icon="$link['icon']">{{ __($link['label']) }}</x-nav-link>
            @endforeach
        </div>
        <div class="nav-group nav-account">
            <x-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.*')" icon="cog">{{ __('Account Settings') }}</x-nav-link>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="nav-item nav-logout">
                    <x-nav-icon name="logout" />
                    <span class="nav-label">{{ __('Logout') }}</span>
                </button>
            </form>
        </div>
    </nav>
    <p class="sidebar-footer">{{ __('Research Adviser Recommendation System') }}</p>
</aside>
<div class="sidebar-overlay" data-sidebar-overlay hidden></div>