<div class="flex items-center gap-3">

    @php
        $currentUser = Auth::user();

        $unreadCount = $currentUser->unreadNotifications()->count();

        $latestNotifications = $currentUser->notifications()
            ->latest()
            ->take(5)
            ->get();
    @endphp

    {{-- Notification Bell --}}
    <details class="relative" data-dropdown>

        <summary
            class="relative flex cursor-pointer list-none items-center justify-center rounded-lg p-2 hover:bg-gray-100"
            aria-label="Notifications"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                width="22"
                height="22"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
                <path d="M10 21h4" />
            </svg>

            @if ($unreadCount > 0)
                <span class="absolute -right-1 -top-1 flex min-w-[19px] h-[19px] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">
                    {{ $unreadCount > 99 ? '99+' : $unreadCount }}
                </span>
            @endif
        </summary>

        {{-- Notification Dropdown --}}
        <div class="absolute right-0 z-50 mt-3 w-80 max-w-[90vw] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl">

            {{-- Header --}}
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">

                <h3 class="font-semibold text-gray-800">
                    Notifications
                </h3>

                @if ($unreadCount > 0)
                    <form
                        method="POST"
                        action="{{ route('notifications.read-all') }}"
                    >
                        @csrf
                        @method('PATCH')

                        <button
                            type="submit"
                            class="text-xs font-medium text-blue-600 hover:text-blue-800"
                        >
                            Mark all as read
                        </button>
                    </form>
                @endif

            </div>

            {{-- Notification List --}}
            <div class="max-h-96 overflow-y-auto">

                @forelse ($latestNotifications as $notification)

                    <div class="border-b border-gray-100 px-4 py-3 {{ $notification->read_at ? 'bg-white' : 'bg-blue-50' }}">

                        <div class="flex items-start justify-between gap-2">

                            <div class="min-w-0 flex-1">

                                <p class="text-sm font-semibold text-gray-800">
                                    {{ $notification->data['title'] ?? 'Notification' }}
                                </p>

                                <p class="mt-1 text-xs leading-relaxed text-gray-600">
                                    {{ $notification->data['message'] ?? '' }}
                                </p>

                                <p class="mt-2 text-[11px] text-gray-400">
                                    {{ $notification->created_at->diffForHumans() }}
                                </p>

                            </div>

                            @if (! $notification->read_at)
                                <span
                                    class="mt-1 h-2 w-2 shrink-0 rounded-full bg-blue-600"
                                    title="Unread"
                                ></span>
                            @endif

                        </div>

                        <div class="mt-3 flex items-center gap-3">

                            @if (! $notification->read_at)
                                <form
                                    method="POST"
                                    action="{{ route('notifications.read', $notification->id) }}"
                                >
                                    @csrf
                                    @method('PATCH')

                                    <button
                                        type="submit"
                                        class="text-xs font-medium text-blue-600 hover:text-blue-800"
                                    >
                                        Mark as read
                                    </button>
                                </form>
                            @endif

                            @if (! empty($notification->data['url']))
                                <a
                                    href="{{ $notification->data['url'] }}"
                                    class="text-xs font-medium text-gray-600 hover:text-blue-700"
                                >
                                    View details
                                </a>
                            @endif

                        </div>

                    </div>

                @empty

                    <div class="px-4 py-8 text-center">

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="mx-auto h-8 w-8 text-gray-300"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.5"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"
                            />
                        </svg>

                        <p class="mt-2 text-sm text-gray-500">
                            No notifications yet.
                        </p>

                    </div>

                @endforelse

            </div>

        </div>

    </details>


    {{-- Existing Account Menu --}}
    <details class="account-menu" data-dropdown>

        <summary
            class="account-trigger"
            aria-label="{{ __('Account menu for :name', ['name' => Auth::user()->name]) }}"
        >

            <x-user-avatar class="account-avatar" />

            <span class="account-name">
                {{ Auth::user()->name }}
            </span>

            <span class="account-role">
                {{ __(ucfirst(Auth::user()->role)) }}
            </span>

            <span class="account-mobile-label">
                {{ __('Account') }}
            </span>

            <svg
                class="account-chevron"
                viewBox="0 0 24 24"
                width="16"
                height="16"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
                focusable="false"
            >
                <path d="m6 9 6 6 6-6" />
            </svg>

        </summary>

        <div class="account-panel">

            <div class="account-panel-header">

                <p class="font-semibold break-words">
                    {{ Auth::user()->name }}
                </p>

                <p class="mt-1 text-sm text-gray-500">
                    {{ ucfirst(Auth::user()->role) }}
                </p>

            </div>

            @foreach (config('workspace_navigation.'.Auth::user()->role.'.secondary', []) as $link)

                @if (str_ends_with($link['route'], '.profile.edit'))

                    <a href="{{ route($link['route']) }}">
                        {{ __('Profile') }}
                    </a>

                @endif

            @endforeach

            <a href="{{ route('profile.edit') }}">
                {{ __('Settings') }}
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf

                <button type="submit">
                    {{ __('Logout') }}
                </button>
            </form>

        </div>

    </details>

</div>
