@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Navigasi halaman" class="flex flex-wrap items-center justify-between gap-3 text-sm">
        <p class="text-pencil">
            Menampilkan {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} dari {{ $paginator->total() }}
        </p>
        <ul class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <li><span class="btn-quiet opacity-50 cursor-not-allowed">Sebelumnya</span></li>
            @else
                <li><a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn-quiet">Sebelumnya</a></li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="px-2 text-pencil">{{ $element }}</li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li><span class="px-3 py-2 font-semibold border-b-2 border-ink" aria-current="page">{{ $page }}</span></li>
                        @else
                            <li><a href="{{ $url }}" class="px-3 py-2 no-underline hover:underline">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li><a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn-quiet">Berikutnya</a></li>
            @else
                <li><span class="btn-quiet opacity-50 cursor-not-allowed">Berikutnya</span></li>
            @endif
        </ul>
    </nav>
@endif
