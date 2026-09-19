<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($header) ? trim(strip_tags($header)) . ' | ' : '' }}SKILLSYNC</title>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="workspace antialiased">
        <a href="#main-content" class="skip-link">{{ __('Skip to content') }}</a>
        <div class="app-shell">
            @include('layouts.navigation')
            <div class="app-main">
                <header class="topbar">
                    <div class="topbar-heading">
                        <button type="button" class="button button-secondary menu-toggle" aria-controls="workspace-sidebar" aria-expanded="false" data-sidebar-toggle>{{ __('Menu') }}</button>
                        <p class="topbar-workspace">{{ ucfirst(Auth::user()->role) }} <span>{{ __('Workspace') }}</span></p>
                    </div>
                    @include('layouts.account-menu')
                </header>
                <main id="main-content" class="page-content" tabindex="-1">
                    <x-page-header :description="$description ?? null">{{ $header ?? __('Dashboard') }}</x-page-header>
                    @foreach (['success', 'error', 'warning'] as $messageType)
                        @if (session($messageType))<x-alert :type="$messageType" class="mb-6">{{ session($messageType) }}</x-alert>@endif
                    @endforeach
                    <div class="page-section active">{{ $slot }}</div>
                </main>
            </div>
        </div>
    </body>
</html>
