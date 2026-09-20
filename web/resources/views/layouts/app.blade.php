<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col">
    @include('layouts.navigation')

    <main class="flex-1 w-full px-4 py-6 sm:px-6 sm:py-8">
        @if (session('status'))
            <div class="sheet mb-4">
                <p class="notice border-0" role="status">{{ session('status') }}</p>
            </div>
        @endif

        {{ $slot }}
    </main>

    <footer class="px-4 py-4 text-xs text-pencil sm:px-6">
        <div class="max-w-sheet mx-auto">HireNote — transkripsi berjalan di komputer ini, tidak ada audio yang dikirim ke layanan luar.</div>
    </footer>
</body>
</html>
