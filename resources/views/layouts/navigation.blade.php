@php
    $role = Auth::user()->role;
    $workspace = config('workspace_navigation.'.$role, ['primary' => [], 'secondary' => [], 'secondary_label' => 'Workspace']);
@endphp
<aside id="workspace-sidebar" class="sidebar" aria-label="{{ __('Workspace navigation') }}" tabindex="-1">
    <div class="sidebar-brand-row">
        <a href="{{ route('dashboard') }}" class="sidebar-brand"><x-skillsync-mark alt="" /> <span>SKILLSYNC</span></a>
        <button type="button" class="button button-secondary sidebar-close" data-sidebar-close>{{ __('Close') }}</button>
    </div>
    <p class="sidebar-caption">{{ ucfirst($role) }} {{ __('Workspace') }}</p>
    <nav class="sidebar-navigation" aria-label="{{ __('Main navigation') }}">
        <div class="nav-group">
            <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard', '*.dashboard')">{{ __('Dashboard') }}</x-nav-link>
            @foreach ($workspace['primary'] as $link)
                <x-nav-link :href="route($link['route'])" :active="request()->routeIs(...$link['active'])">{{ __($link['label']) }}</x-nav-link>
            @endforeach
        </div>
        <div class="nav-group">
            <p class="nav-group-label">{{ __($workspace['secondary_label']) }}</p>
            @foreach ($workspace['secondary'] as $link)
                <x-nav-link :href="route($link['route'])" :active="request()->routeIs(...$link['active'])">{{ __($link['label']) }}</x-nav-link>
            @endforeach
        </div>
        <div class="nav-group nav-account">
            <x-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.*')">{{ __('Account Settings') }}</x-nav-link>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="nav-item nav-logout">{{ __('Logout') }}</button>
            </form>
        </div>
    </nav>
    <p class="sidebar-footer">{{ __('Research Adviser Recommendation System') }}</p>
</aside>
<div class="sidebar-overlay" data-sidebar-overlay hidden></div>
