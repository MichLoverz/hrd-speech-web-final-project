<x-page title="Sesi wawancara" subtitle="Satu sesi = satu kandidat, satu panel, satu rangkaian pertanyaan.">
    <x-slot:actions>
        @can('create', App\Models\InterviewSession::class)
            <a href="{{ route('interview-sessions.create') }}" class="btn-primary">Buat sesi</a>
        @endcan
    </x-slot:actions>

    <div class="sheet-section">
        <form method="GET" class="flex flex-wrap items-end gap-3 mb-6">
            <div>
                <label for="status" class="field-label">Status</label>
                <select id="status" name="status" class="field-select">
                    <option value="">Semua</option>
                    @foreach (App\Models\InterviewSession::STATUSES as $s)
                        <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ status_label($s) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-quiet">Terapkan</button>
            @if (array_filter($filters))
                <a href="{{ route('interview-sessions.index') }}" class="text-sm">Hapus filter</a>
            @endif
        </form>

        @if ($sessions->isEmpty())
            <div class="empty">
                @if (array_filter($filters))
                    Tidak ada sesi dengan status ini.
                @else
                    Belum ada sesi wawancara. Buat sesi dari halaman kandidat atau dari tombol di atas.
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="ledger">
                    <thead>
                        <tr>
                            <th>Kandidat</th>
                            <th>Lowongan</th>
                            <th>Pewawancara utama</th>
                            <th>Waktu</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions as $session)
                            <tr>
                                <td><a href="{{ route('interview-sessions.show', $session) }}" class="font-semibold">{{ $session->candidate->name }}</a></td>
                                <td>{{ $session->candidate->jobOpening->title }}</td>
                                <td>{{ $session->interviewer->name }}</td>
                                <td class="text-sm text-ink-soft whitespace-nowrap">{{ $session->started_at?->translatedFormat('d M Y, H:i') ?? 'belum dimulai' }}</td>
                                <td><x-status :value="$session->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6">{{ $sessions->links() }}</div>
        @endif
    </div>
</x-page>
