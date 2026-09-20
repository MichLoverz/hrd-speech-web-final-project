@php
    $opening = $session->candidate->jobOpening;
    $answersByQuestion = $session->answers->keyBy('question_id');
@endphp

<x-page :title="'Wawancara: '.$session->candidate->name" :subtitle="$opening->title.' — pewawancara utama '.$session->interviewer->name" :back="route('interview-sessions.index')" back-label="Semua sesi">
    <x-slot:actions>
        <x-status :value="$session->status" class="mr-2" />
        @can('delete', $session)
            @if ($session->status === 'draft')
                <x-confirm-delete :action="route('interview-sessions.destroy', $session)" label="Hapus draf" confirm="Hapus sesi draf ini?" />
            @endif
        @endcan
    </x-slot:actions>

    <div class="sheet-section grid grid-cols-1 md:grid-cols-3 gap-6 text-sm">
        <div>
            <div class="text-pencil">Panel</div>
            <ul class="mt-1">
                @foreach ($session->panel as $member)
                    <li>{{ $member->name }}{{ $member->id === $session->interviewer_id ? ' (utama)' : '' }}</li>
                @endforeach
            </ul>
        </div>
        <div>
            <div class="text-pencil">Persetujuan perekaman</div>
            @if ($session->consents->isNotEmpty())
                <div class="mt-1 status status-done">Disetujui {{ $session->consents->first()->agreed_at->translatedFormat('d M Y, H:i') }}</div>
            @else
                <div class="mt-1">Belum diminta. Persetujuan dicatat saat sesi dimulai.</div>
            @endif
        </div>
        <div>
            <div class="text-pencil">Catatan</div>
            <p class="mt-1 whitespace-pre-line">{{ $session->notes ?: '–' }}</p>
        </div>
    </div>

    <div class="sheet-section">
        <div class="flex flex-wrap items-baseline justify-between gap-3 mb-2">
            <h2>Pertanyaan dan jawaban</h2>
            <p class="text-sm text-pencil">Perekaman per pertanyaan dan transkrip langsung tersedia di tahap berikutnya.</p>
        </div>

        @foreach ($opening->criteria as $criterion)
            <section class="py-5 border-t border-rule first:border-t-0">
                <h3 class="flex items-baseline gap-3">
                    {{ $criterion->name }}
                    <span class="text-sm font-normal text-pencil">bobot {{ $criterion->weight }}</span>
                </h3>
                <ol class="mt-3 space-y-4 list-decimal pl-6 max-w-prose">
                    @forelse ($criterion->questions as $question)
                        @php $answer = $answersByQuestion->get($question->id); @endphp
                        <li class="pl-1">
                            <p>{{ $question->text }}</p>
                            @if ($answer && $answer->transcript)
                                <p class="transcript mt-2">{{ $answer->transcript }}</p>
                            @else
                                <p class="mt-1 text-sm text-pencil">Belum ada jawaban terekam.</p>
                            @endif
                        </li>
                    @empty
                        <li class="list-none -ml-6 text-sm text-pencil">Kriteria ini belum punya pertanyaan.</li>
                    @endforelse
                </ol>
            </section>
        @endforeach
    </div>
</x-page>
