<x-page :title="$candidate->name" :subtitle="'Melamar '.$candidate->jobOpening->title" :back="route('candidates.index')" back-label="Semua kandidat">
    <x-slot:actions>
        <x-status :value="$candidate->status" class="mr-2" />
        @can('update', $candidate)
            <a href="{{ route('candidates.edit', $candidate) }}" class="btn-quiet">Ubah data</a>
        @endcan
    </x-slot:actions>

    <div class="sheet-section">
        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 text-sm">
            <div>
                <dt class="text-pencil">Email</dt>
                <dd>{{ $candidate->email ?: '–' }}</dd>
            </div>
            <div>
                <dt class="text-pencil">Telepon</dt>
                <dd>{{ $candidate->phone ?: '–' }}</dd>
            </div>
            <div>
                <dt class="text-pencil">Ekspektasi gaji</dt>
                <dd>
                    {{ rupiah($candidate->expected_salary) }}
                    <span class="block text-pencil">anggaran {{ rupiah($candidate->jobOpening->salary_min) }} – {{ rupiah($candidate->jobOpening->salary_max) }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-pencil">CV</dt>
                <dd>
                    @if ($candidate->cv_path)
                        <a href="{{ route('candidates.cv', $candidate) }}">Unduh CV (PDF)</a>
                    @else
                        Belum diunggah
                    @endif
                </dd>
            </div>
        </dl>
    </div>

    <div class="sheet-section">
        <div class="flex flex-wrap items-baseline justify-between gap-3 mb-4">
            <h2>Sesi wawancara</h2>
            @can('create', App\Models\InterviewSession::class)
                @can('update', $candidate)
                    <a href="{{ route('interview-sessions.create', ['candidate_id' => $candidate->id]) }}" class="btn-quiet">Buat sesi</a>
                @endcan
            @endcan
        </div>

        @if ($candidate->interviewSessions->isEmpty())
            <div class="empty">Belum ada sesi wawancara untuk kandidat ini.</div>
        @else
            <div class="overflow-x-auto">
                <table class="ledger">
                    <thead>
                        <tr>
                            <th>Sesi</th>
                            <th>Pewawancara</th>
                            <th>Status</th>
                            <th>Kartu skor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($candidate->interviewSessions as $session)
                            <tr>
                                <td>
                                    <a href="{{ route('interview-sessions.show', $session) }}" class="font-semibold">Sesi #{{ $session->id }}</a>
                                    <div class="text-sm text-pencil">{{ $session->started_at?->translatedFormat('d M Y, H:i') ?? 'belum dimulai' }}</div>
                                </td>
                                <td>{{ $session->interviewer->name }}</td>
                                <td><x-status :value="$session->status" /></td>
                                <td>{{ $session->analysisResult ? status_label($session->analysisResult->status) : '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-page>
