<details class="account-menu" data-dropdown>
    <summary class="account-trigger" aria-label="{{ __('Account menu for :name', ['name' => Auth::user()->name]) }}">
        <span class="account-name">{{ Auth::user()->name }}</span>
        <span class="account-role">{{ __(ucfirst(Auth::user()->role)) }}</span>
        <span class="account-mobile-label">{{ __('Account') }}</span>
    </summary>
    <div class="account-panel">
        <div class="account-panel-header">
            <p class="font-semibold break-words">{{ Auth::user()->name }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ ucfirst(Auth::user()->role) }}</p>
        </div>
        @foreach (config('workspace_navigation.'.Auth::user()->role.'.secondary', []) as $link)
            @if (str_ends_with($link['route'], '.profile.edit'))
                <a href="{{ route($link['route']) }}">{{ __('Profile') }}</a>
            @endif
        @endforeach
        <a href="{{ route('profile.edit') }}">{{ __('Settings') }}</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">{{ __('Logout') }}</button></form>
    </div>
</details>
