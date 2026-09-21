@php
    $opening = $session->candidate->jobOpening;
    $answersByQuestion = $session->answers->keyBy('question_id');
    $myScores = $myAssessment?->scores->keyBy('criterion_id') ?? collect();
    $locked = $myAssessment?->isSubmitted() ?? false;
@endphp

<x-page :title="'Wawancara: '.$session->candidate->name" :subtitle="$opening->title.' — pewawancara utama '.$session->interviewer->name" :back="route('interview-sessions.index')" back-label="Semua sesi">
    <x-slot:actions>
        <x-status :value="$session->status" class="mr-2" />
        @can('update', $session)
            @if ($session->status === 'draft')
                <a href="{{ route('recording.consent', $session) }}" class="btn-primary">Mulai sesi</a>
            @elseif ($session->status === 'recording')
                <a href="{{ route('recording.show', $session) }}" class="btn-primary">Lanjutkan perekaman</a>
            @endif
        @endcan
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

    {{-- Questions and answers --}}
    <div class="sheet-section">
        <div class="flex flex-wrap items-baseline justify-between gap-3 mb-2">
            <h2>Pertanyaan dan jawaban</h2>
            @if ($session->answers->isNotEmpty())
                @php $avg = $session->answers->whereNotNull('avg_latency_ms')->avg('avg_latency_ms'); @endphp
                <p class="text-sm text-pencil">
                    {{ $session->answers->count() }} jawaban terekam{{ $avg ? ', rata-rata latency final '.number_format($avg / 1000, 1, ',', '.').' s' : '' }}.
                    Teks yang dikoreksi HRD dipakai untuk penilaian; teks asli ASR tetap disimpan.
                </p>
            @else
                <p class="text-sm text-pencil">Belum ada jawaban terekam.</p>
            @endif
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
                        <li class="pl-1" x-data="{ editing: false }">
                            <p>{{ $question->text }}</p>
                            @if ($answer && ($answer->transcript !== null || $answer->audio_path))
                                <p class="transcript mt-2" x-show="!editing">{{ $answer->transcript ?: '(transkrip kosong)' }}</p>
                                @can('update', $session)
                                    <form method="POST" action="{{ route('answers.transcript', $answer) }}" x-show="editing" x-cloak class="mt-2">
                                        @csrf @method('PATCH')
                                        <textarea name="transcript_edited" rows="4" class="field-textarea transcript">{{ old('transcript_edited', $answer->transcript) }}</textarea>
                                        <div class="mt-2 flex items-center gap-3">
                                            <button type="submit" class="btn-primary">Simpan koreksi</button>
                                            <button type="button" class="text-sm underline" @click="editing = false">Batal</button>
                                        </div>
                                    </form>
                                @endcan
                                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-pencil">
                                    @if ($answer->transcript_edited !== null)
                                        <span class="status status-warn">Dikoreksi HRD</span>
                                    @endif
                                    @if ($answer->model_used)
                                        <span>{{ $answer->model_used }}{{ $answer->asr_mode === 'file' ? ' (dari rekaman)' : '' }}</span>
                                    @endif
                                    @if ($answer->avg_latency_ms !== null)
                                        <span>latency final rata-rata {{ number_format($answer->avg_latency_ms / 1000, 1, ',', '.') }} s</span>
                                    @endif
                                    @if ($answer->duration_ms)
                                        <span>{{ number_format($answer->duration_ms / 1000, 0) }} s audio</span>
                                    @endif
                                    @if ($answer->asr_prompt)
                                        <span title="{{ $answer->asr_prompt }}">dengan prompt</span>
                                    @endif
                                    @can('update', $session)
                                        <button type="button" class="row-action" @click="editing = !editing" x-text="editing ? 'Tutup' : 'Koreksi transkrip'"></button>
                                        @if ($answer->audio_path)
                                            <form method="POST" action="{{ route('answers.retranscribe', [$session, $question]) }}" class="inline" x-data @submit.prevent="if (confirm('Transkripsi ulang dari rekaman? Transkrip ASR saat ini akan diganti (koreksi HRD tetap disimpan).')) $el.submit()">
                                                @csrf
                                                <button type="submit" class="row-action">Transkripsi ulang</button>
                                            </form>
                                        @endif
                                    @endcan
                                </div>
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

    {{-- My manual scorecard --}}
    <div class="sheet-section" id="penilaian">
        <div class="flex flex-wrap items-baseline justify-between gap-3 mb-2">
            <h2>Penilaian saya</h2>
            @if ($locked)
                <span class="status status-done">Dikirim {{ $myAssessment->submitted_at->translatedFormat('d M Y, H:i') }}</span>
            @else
                <p class="text-sm text-pencil">Nilai tiap kriteria 1–5. Setelah dikirim, penilaian terkunci dan penilaian panel lain baru terlihat.</p>
            @endif
        </div>

        @if ($opening->criteria->isEmpty())
            <div class="empty">Lowongan ini belum punya kriteria, jadi belum ada yang bisa dinilai.</div>
        @else
            <form method="POST" action="{{ route('manual-assessments.save', $session) }}" class="max-w-prose">
                @csrf
                <fieldset @disabled($locked) class="space-y-6">
                    @foreach ($opening->criteria as $criterion)
                        @php
                            $current = old("scores.{$criterion->id}", $myScores->get($criterion->id)?->score);
                            $note = old("notes.{$criterion->id}", $myScores->get($criterion->id)?->notes);
                        @endphp
                        <div>
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-semibold">{{ $criterion->name }}</span>
                                <span class="text-sm text-pencil">bobot {{ $criterion->weight }}</span>
                            </div>
                            @if ($criterion->description)
                                <p class="text-sm text-ink-soft">{{ $criterion->description }}</p>
                            @endif
                            <div class="mt-2 flex items-center gap-4" role="radiogroup" aria-label="Skor {{ $criterion->name }}">
                                @for ($i = 1; $i <= 5; $i++)
                                    <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer">
                                        <input type="radio" name="scores[{{ $criterion->id }}]" value="{{ $i }}" class="field-radio" @checked((int) $current === $i) required>
                                        {{ $i }}
                                    </label>
                                @endfor
                            </div>
                            @error("scores.{$criterion->id}")<span class="field-error">{{ $message }}</span>@enderror
                            <input type="text" name="notes[{{ $criterion->id }}]" value="{{ $note }}" class="field-input mt-2 text-sm" placeholder="Catatan singkat (opsional)" maxlength="1000">
                        </div>
                    @endforeach

                    <div class="field">
                        <label for="overall_notes" class="field-label">Kesan keseluruhan</label>
                        <textarea id="overall_notes" name="overall_notes" rows="3" class="field-textarea" maxlength="2000">{{ old('overall_notes', $myAssessment?->overall_notes) }}</textarea>
                    </div>
                </fieldset>

                @unless ($locked)
                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        <button type="submit" name="submit" value="1" class="btn-primary" x-data @click="if (!confirm('Kirim penilaian? Setelah dikirim tidak bisa diubah.')) $event.preventDefault()">Kirim penilaian</button>
                        <button type="submit" name="submit" value="0" class="btn-quiet">Simpan draf</button>
                        @if ($myAssessment)
                            <span class="text-sm text-pencil">Draf terakhir disimpan {{ $myAssessment->updated_at->translatedFormat('d M Y, H:i') }}</span>
                        @endif
                    </div>
                @endunless
            </form>
        @endif
    </div>

    {{-- Other raters (visible after submitting mine, or for admin) --}}
    @if ($otherAssessments->isNotEmpty())
        <div class="sheet-section">
            <h2 class="mb-4">Penilaian panel lain</h2>
            <div class="overflow-x-auto">
                <table class="ledger">
                    <thead>
                        <tr>
                            <th>Kriteria</th>
                            @if ($myAssessment?->isSubmitted())
                                <th class="text-right">Saya</th>
                            @endif
                            @foreach ($otherAssessments as $assessment)
                                <th class="text-right">{{ $assessment->rater->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($opening->criteria as $criterion)
                            <tr>
                                <td>{{ $criterion->name }} <span class="text-sm text-pencil">×{{ $criterion->weight }}</span></td>
                                @if ($myAssessment?->isSubmitted())
                                    <td class="text-right tabular-nums">{{ $myScores->get($criterion->id)?->score ?? '–' }}</td>
                                @endif
                                @foreach ($otherAssessments as $assessment)
                                    <td class="text-right tabular-nums">{{ $assessment->scores->firstWhere('criterion_id', $criterion->id)?->score ?? '–' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr>
                            <td class="font-semibold">Rata-rata berbobot</td>
                            @if ($myAssessment?->isSubmitted())
                                <td class="text-right tabular-nums font-semibold">{{ $myAssessment->weightedScore() ?? '–' }}</td>
                            @endif
                            @foreach ($otherAssessments as $assessment)
                                <td class="text-right tabular-nums font-semibold">{{ $assessment->weightedScore() ?? '–' }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
            @foreach ($otherAssessments->filter(fn ($a) => $a->overall_notes) as $assessment)
                <p class="mt-4 text-sm max-w-prose"><span class="font-semibold">{{ $assessment->rater->name }}:</span> {{ $assessment->overall_notes }}</p>
            @endforeach
        </div>
    @elseif ($myAssessment?->isSubmitted() && $session->manualAssessments->where('submitted_at', '!=', null)->count() === 1)
        <div class="sheet-section">
            <p class="text-sm text-pencil">Belum ada penilaian dari anggota panel lain.</p>
        </div>
    @endif
</x-page>
