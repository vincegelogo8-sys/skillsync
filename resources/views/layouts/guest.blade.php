<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>SKILLSYNC | Portal</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="guest-page antialiased">
        <main class="login-card">
            <a href="{{ url('/') }}" class="guest-brand"><x-application-logo /> <span>SKILLSYNC</span></a>
            {{ $slot }}
        </main>
    </body>
</html>
