<x-page :title="'Ubah: '.$opening->title" :back="route('job-openings.show', $opening)" back-label="Kembali ke lowongan">
    <div class="sheet-section max-w-prose">
        <form method="POST" action="{{ route('job-openings.update', $opening) }}">
            @method('PUT')
            @include('job-openings._form')
            <div class="mt-8 flex items-center gap-3">
                <button type="submit" class="btn-primary">Simpan perubahan</button>
                <a href="{{ route('job-openings.show', $opening) }}" class="text-sm">Batal</a>
            </div>
        </form>
    </div>

    <div class="sheet-section">
        <h2 class="text-md">Hapus lowongan</h2>
        <p class="mt-1 mb-4 text-sm text-pencil max-w-prose">Menghapus lowongan juga menghapus semua kriteria, pertanyaan, kandidat, dan sesi wawancara di dalamnya.</p>
        <x-confirm-delete :action="route('job-openings.destroy', $opening)" label="Hapus lowongan ini" confirm="Hapus lowongan beserta semua kriteria, pertanyaan, dan kandidatnya?" />
    </div>
</x-page>
