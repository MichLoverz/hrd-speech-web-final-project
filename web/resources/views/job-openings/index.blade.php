<x-page title="Lowongan" subtitle="Setiap lowongan punya kriteria dan pertanyaan wawancaranya sendiri.">
    <x-slot:actions>
        @can('create', App\Models\JobOpening::class)
            <a href="{{ route('job-openings.create') }}" class="btn-primary">Buat lowongan</a>
        @endcan
    </x-slot:actions>

    <div class="sheet-section">
        <form method="GET" class="flex flex-wrap items-end gap-3 mb-6">
            <div class="flex-1 min-w-[200px]">
                <label for="q" class="field-label">Cari</label>
                <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" class="field-input" placeholder="Judul atau departemen">
            </div>
            <div>
                <label for="status" class="field-label">Status</label>
                <select id="status" name="status" class="field-select">
                    <option value="">Semua</option>
                    @foreach (App\Models\JobOpening::STATUSES as $s)
                        <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ status_label($s) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-quiet">Terapkan</button>
            @if (array_filter($filters))
                <a href="{{ route('job-openings.index') }}" class="text-sm">Hapus filter</a>
            @endif
        </form>

        @if ($openings->isEmpty())
            <div class="empty">
                @if (array_filter($filters))
                    Tidak ada lowongan yang cocok dengan filter ini.
                @else
                    Belum ada lowongan. Buat lowongan pertama, lalu tambahkan kriteria dan pertanyaannya.
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="ledger">
                    <thead>
                        <tr>
                            <th>Lowongan</th>
                            <th>Rentang gaji</th>
                            <th class="text-right">Kriteria</th>
                            <th class="text-right">Kandidat</th>
                            <th>Status</th>
                            <th>Dibuat oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($openings as $opening)
                            <tr>
                                <td>
                                    <a href="{{ route('job-openings.show', $opening) }}" class="font-semibold">{{ $opening->title }}</a>
                                    @if ($opening->department)
                                        <div class="text-sm text-pencil">{{ $opening->department }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">{{ rupiah($opening->salary_min) }} – {{ rupiah($opening->salary_max) }}</td>
                                <td class="text-right tabular-nums">{{ $opening->criteria_count }}</td>
                                <td class="text-right tabular-nums">{{ $opening->candidates_count }}</td>
                                <td><x-status :value="$opening->status" /></td>
                                <td class="text-sm text-ink-soft">{{ $opening->creator->name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6">{{ $openings->links() }}</div>
        @endif
    </div>
</x-page>
