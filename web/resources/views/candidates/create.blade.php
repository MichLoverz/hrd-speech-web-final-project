<x-page title="Tambah kandidat" :back="route('candidates.index')" back-label="Semua kandidat">
    <div class="sheet-section max-w-prose">
        @if ($openings->isEmpty())
            <div class="empty">Anda belum punya lowongan. <a href="{{ route('job-openings.create') }}">Buat lowongan</a> dulu sebelum menambahkan kandidat.</div>
        @else
            <form method="POST" action="{{ route('candidates.store') }}" enctype="multipart/form-data">
                @include('candidates._form')
                <div class="mt-8 flex items-center gap-3">
                    <button type="submit" class="btn-primary">Simpan kandidat</button>
                    <a href="{{ route('candidates.index') }}" class="text-sm">Batal</a>
                </div>
            </form>
        @endif
    </div>
</x-page>
