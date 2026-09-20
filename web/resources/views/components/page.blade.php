@props(['title', 'subtitle' => null, 'back' => null, 'backLabel' => null])

<x-app-layout :title="$title">
    <div class="sheet">
        <header class="sheet-section flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                @if ($back)
                    <a href="{{ $back }}" class="text-sm text-ink-soft">{{ $backLabel ?? 'Kembali' }}</a>
                @endif
                <h1 class="{{ $back ? 'mt-1' : '' }}">{{ $title }}</h1>
                @if ($subtitle)
                    <p class="mt-1 text-sm text-pencil">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>

        {{ $slot }}
    </div>
</x-app-layout>
