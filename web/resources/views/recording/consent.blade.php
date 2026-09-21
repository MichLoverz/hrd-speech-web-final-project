<x-page title="Persetujuan perekaman" :subtitle="'Sesi wawancara '.$session->candidate->name" :back="route('interview-sessions.show', $session)" back-label="Kembali ke sesi">
    <div class="sheet-section max-w-prose">
        @if ($already)
            <p class="status status-done mb-4">Persetujuan sudah tercatat untuk sesi ini.</p>
            <a href="{{ route('recording.show', $session) }}" class="btn-primary">Lanjut ke perekaman</a>
        @else
            <p class="mb-4">Bacakan atau tunjukkan teks berikut kepada kandidat sebelum wawancara dimulai. Versi teks: <span class="text-pencil">{{ App\Models\Consent::CURRENT_TEXT_VERSION }}</span>.</p>

            <blockquote class="border-l-2 border-ink pl-4 my-6 space-y-3">
                <p>Wawancara ini akan direkam dan ditranskripsikan secara otomatis untuk membantu penilaian. Pemrosesan suara dilakukan di komputer pewawancara; tidak ada audio yang dikirim ke layanan pihak ketiga.</p>
                <p>Rekaman suara disimpan paling lama {{ (int) env('RECORDING_RETENTION_DAYS', 30) }} hari lalu dihapus otomatis. Transkrip dan kartu skor disimpan sebagai bagian dari catatan rekrutmen.</p>
                <p>Kandidat berhak menolak perekaman; dalam hal itu wawancara tetap dapat dilakukan tanpa sistem ini.</p>
            </blockquote>

            <form method="POST" action="{{ route('recording.consent.store', $session) }}">
                @csrf
                <label class="flex items-start gap-3 text-base">
                    <input type="checkbox" name="agree" value="1" class="field-checkbox mt-1" required>
                    <span>Kandidat <span class="font-semibold">{{ $session->candidate->name }}</span> telah mendengar penjelasan di atas dan menyetujui perekaman.</span>
                </label>
                @error('agree')<span class="field-error">{{ $message }}</span>@enderror

                <p class="mt-3 text-sm text-pencil">Yang dicatat: waktu persetujuan, alamat IP perangkat ini, dan versi teks persetujuan.</p>

                <div class="mt-6 flex items-center gap-3">
                    <button type="submit" class="btn-primary">Catat persetujuan dan mulai</button>
                    <a href="{{ route('interview-sessions.show', $session) }}" class="text-sm">Batal</a>
                </div>
            </form>
        @endif
    </div>
</x-page>
