<x-page :title="'Ubah: '.$candidate->name" :back="route('candidates.show', $candidate)" back-label="Kembali ke kandidat">
    <div class="sheet-section max-w-prose">
        <form method="POST" action="{{ route('candidates.update', $candidate) }}" enctype="multipart/form-data">
            @method('PUT')
            @include('candidates._form')
            <div class="mt-8 flex items-center gap-3">
                <button type="submit" class="btn-primary">Simpan perubahan</button>
                <a href="{{ route('candidates.show', $candidate) }}" class="text-sm">Batal</a>
            </div>
        </form>
    </div>

    <div class="sheet-section">
        <h2 class="text-md">Hapus kandidat</h2>
        <p class="mt-1 mb-4 text-sm text-pencil max-w-prose">Semua sesi wawancara, rekaman, transkrip, dan kartu skor kandidat ini ikut terhapus.</p>
        <x-confirm-delete :action="route('candidates.destroy', $candidate)" label="Hapus kandidat ini" confirm="Hapus kandidat beserta semua sesi wawancaranya?" />
    </div>
</x-page>
