<x-page title="Buat lowongan" :back="route('job-openings.index')" back-label="Semua lowongan">
    <div class="sheet-section max-w-prose">
        <form method="POST" action="{{ route('job-openings.store') }}">
            @include('job-openings._form')
            <div class="mt-8 flex items-center gap-3">
                <button type="submit" class="btn-primary">Simpan lowongan</button>
                <a href="{{ route('job-openings.index') }}" class="text-sm">Batal</a>
            </div>
        </form>
    </div>
</x-page>
