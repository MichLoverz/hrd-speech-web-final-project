<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col items-center justify-center px-4 py-10">
    <a href="/" class="no-underline text-lg font-semibold text-ink mb-6">
        Hire<span class="bg-highlighter px-0.5">Note</span>
    </a>

    <div class="sheet max-w-md">
        <div class="sheet-section">
            {{ $slot }}
        </div>
    </div>

    <p class="mt-6 text-xs text-pencil max-w-md text-center">
        Sistem penilaian wawancara terstruktur. Transkripsi diproses di komputer lokal.
    </p>
</body>
</html>
