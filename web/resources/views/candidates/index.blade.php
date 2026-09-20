<x-page title="Kandidat" subtitle="Semua pelamar dari lowongan yang bisa Anda akses.">
    <x-slot:actions>
        @can('create', App\Models\Candidate::class)
            <a href="{{ route('candidates.create') }}" class="btn-primary">Tambah kandidat</a>
        @endcan
    </x-slot:actions>

    <div class="sheet-section">
        <form method="GET" class="flex flex-wrap items-end gap-3 mb-6">
            <div class="flex-1 min-w-[200px]">
                <label for="q" class="field-label">Cari</label>
                <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" class="field-input" placeholder="Nama atau email">
            </div>
            <div>
                <label for="job_opening_id" class="field-label">Lowongan</label>
                <select id="job_opening_id" name="job_opening_id" class="field-select">
                    <option value="">Semua</option>
                    @foreach ($openings as $opening)
                        <option value="{{ $opening->id }}" @selected((int) ($filters['job_opening_id'] ?? 0) === $opening->id)>{{ $opening->title }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="status" class="field-label">Status</label>
                <select id="status" name="status" class="field-select">
                    <option value="">Semua</option>
                    @foreach (App\Models\Candidate::STATUSES as $s)
                        <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ status_label($s) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-quiet">Terapkan</button>
            @if (array_filter($filters))
                <a href="{{ route('candidates.index') }}" class="text-sm">Hapus filter</a>
            @endif
        </form>

        @if ($candidates->isEmpty())
            <div class="empty">
                @if (array_filter($filters))
                    Tidak ada kandidat yang cocok dengan filter ini.
                @else
                    Belum ada kandidat. Tambahkan kandidat ke salah satu lowongan.
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="ledger">
                    <thead>
                        <tr>
                            <th>Kandidat</th>
                            <th>Lowongan</th>
                            <th>Ekspektasi gaji</th>
                            <th class="text-right">Sesi</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($candidates as $candidate)
                            <tr>
                                <td>
                                    <a href="{{ route('candidates.show', $candidate) }}" class="font-semibold">{{ $candidate->name }}</a>
                                    @if ($candidate->email)
                                        <div class="text-sm text-pencil">{{ $candidate->email }}</div>
                                    @endif
                                </td>
                                <td><a href="{{ route('job-openings.show', $candidate->jobOpening) }}">{{ $candidate->jobOpening->title }}</a></td>
                                <td class="whitespace-nowrap">{{ rupiah($candidate->expected_salary) }}</td>
                                <td class="text-right tabular-nums">{{ $candidate->interview_sessions_count }}</td>
                                <td><x-status :value="$candidate->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6">{{ $candidates->links() }}</div>
        @endif
    </div>
</x-page>
