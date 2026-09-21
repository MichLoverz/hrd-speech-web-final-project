@php
    $links = [
        ['route' => 'job-openings.index', 'label' => 'Lowongan', 'active' => 'job-openings.*'],
        ['route' => 'candidates.index', 'label' => 'Kandidat', 'active' => 'candidates.*'],
        ['route' => 'interview-sessions.index', 'label' => 'Sesi wawancara', 'active' => 'interview-sessions.*'],
    ];
    if (Auth::user()->isAdmin()) {
        $links[] = ['route' => 'admin.users.index', 'label' => 'Pengguna', 'active' => 'admin.users.*'];
    }
@endphp
<nav x-data="{ open: false }" class="border-b border-rule bg-sheet">
    <div class="max-w-sheet mx-auto px-4 sm:px-6 flex items-center justify-between h-14">
        <div class="flex items-center gap-8">
            <a href="{{ route('job-openings.index') }}" class="no-underline text-md font-semibold text-ink">
                Hire<span class="bg-highlighter px-0.5">Note</span>
            </a>

            <ul class="hidden sm:flex items-center gap-6 text-sm">
                @foreach ($links as $link)
                    <li>
                        <a href="{{ route($link['route']) }}"
                           class="no-underline py-4 border-b-2 {{ request()->routeIs($link['active']) ? 'border-ink text-ink font-semibold' : 'border-transparent text-ink-soft hover:text-ink' }}"
                           @if (request()->routeIs($link['active'])) aria-current="page" @endif>
                            {{ $link['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="hidden sm:flex items-center gap-4 text-sm">
            <a href="{{ route('profile.edit') }}" class="no-underline text-ink-soft hover:text-ink">
                {{ Auth::user()->name }}
                <span class="text-pencil">({{ Auth::user()->role }})</span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn-link font-normal text-ink-soft">Keluar</button>
            </form>
        </div>

        <button @click="open = !open" class="sm:hidden p-2 -mr-2" :aria-expanded="open" aria-controls="mobile-nav" aria-label="Menu">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path x-show="!open" stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                <path x-show="open" x-cloak stroke-linecap="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div id="mobile-nav" x-show="open" x-cloak class="sm:hidden border-t border-rule px-4 py-3 text-sm space-y-2">
        @foreach ($links as $link)
            <a href="{{ route($link['route']) }}" class="block no-underline py-1 {{ request()->routeIs($link['active']) ? 'font-semibold' : '' }}">{{ $link['label'] }}</a>
        @endforeach
        <div class="border-t border-rule pt-3 mt-3 flex items-center justify-between">
            <a href="{{ route('profile.edit') }}" class="no-underline">{{ Auth::user()->name }}</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn-link font-normal">Keluar</button>
            </form>
        </div>
    </div>
</nav>
