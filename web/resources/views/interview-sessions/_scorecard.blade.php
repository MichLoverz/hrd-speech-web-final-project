{{-- AI draft scorecard (Phase 4). Shown after the manual scorecard so raters score blind first (ADR-004). --}}
@php
    $result = $session->analysisResult;
    $hasScores = $result && $result->scores->isNotEmpty();
    $mmss = fn (?int $ms) => $ms === null ? null : sprintf('%d:%02d', intdiv($ms, 60000), intdiv($ms % 60000, 1000));
    $badge = [
        'exact' => ['terverifikasi', 'status-done'],
        'normalized' => ['terverifikasi', 'status-done'],
        'fuzzy' => ['ejaan berbeda dari transkrip — cek', 'status-warn'],
        'similar' => ['parafrase, bukan kutipan — cek', 'status-warn'],
        'none' => ['tidak ditemukan di transkrip', 'status-warn'],
    ];
@endphp

<div class="sheet-section" id="kartu-skor">
    <div class="flex flex-wrap items-baseline justify-between gap-3 mb-2">
        <h2>Kartu skor AI <span class="text-pencil font-normal">— draf</span></h2>
        @can('update', $session)
            @if ($session->status === 'analyzing')
                <span class="status status-active">Sedang dibuat…</span>
            @elseif ($session->status !== 'approved' && ! $scorecardRefusal)
                <form method="POST" action="{{ route('scorecard.store', $session) }}" class="inline">
                    @csrf
                    <button type="submit" class="{{ $hasScores ? 'btn-quiet' : 'btn-primary' }}">{{ $hasScores ? 'Buat ulang' : 'Buat kartu skor' }}</button>
                </form>
            @endif
        @endcan
    </div>

    @if ($llmTestOnly)
        <p class="notice mb-4"><span class="font-semibold">Mode data uji.</span> Penyedia LLM yang aktif ({{ config('services.llm.provider') }}, {{ config('services.llm.model') }}) adalah tier gratis yang isinya boleh dibaca pihak penyedia. Hanya sesi bertanda data uji yang bisa diproses; wawancara peserta ditolak sampai penyedia berbayar dipasang (ADR-009).</p>
    @endif
    @if ($session->is_test_data)
        <p class="text-sm text-pencil mb-4">Sesi ini ditandai <span class="font-semibold">data uji</span> dan tidak masuk angka evaluasi.</p>
    @endif
    @if ($scorecardRefusal && $session->status !== 'approved')
        <p class="text-sm text-ink-soft mb-4">{{ $scorecardRefusal }}</p>
    @endif
    @if ($result?->error_message)
        <p class="notice mb-4"><span class="font-semibold">Percobaan terakhir gagal:</span> {{ $result->error_message }}</p>
    @endif

    @if (! $hasScores)
        @if (! $scorecardRefusal && $session->status !== 'analyzing')
            <div class="empty">Belum ada kartu skor. Draf dibuat oleh model bahasa dari transkrip, lalu setiap kutipannya dicek ke transkrip asli.</div>
        @endif
    @elseif (! $canSeeAi)
        <div class="empty">Kartu skor AI sudah ada. Kirim penilaian Anda di atas dulu, supaya penilaian manusia tidak terpengaruh draf AI.</div>
    @else
        @if ($result->overall_summary)
            <p class="max-w-prose mb-6">{{ $result->overall_summary }}</p>
        @endif

        <div class="space-y-8">
            @foreach ($result->scores->sortBy(fn ($s) => $s->criterion->id) as $score)
                <div>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="font-semibold">{{ $score->criterion->name }} <span class="text-sm text-pencil font-normal">×{{ $score->criterion->weight }}</span></span>
                        @if ($score->score !== null)
                            <span class="tabular-nums"><span class="text-lg font-semibold">{{ \App\Support\ScoreScale::format($score->score) }}</span><span class="text-pencil">/10</span></span>
                        @else
                            <span class="text-sm text-pencil">tidak ada bukti — tidak dinilai</span>
                        @endif
                    </div>
                    @if ($score->rationale)
                        <p class="text-sm text-ink-soft max-w-prose mt-1">{{ $score->rationale }}</p>
                    @endif

                    @foreach (['strengths' => 'Kekuatan', 'weaknesses' => 'Kelemahan'] as $field => $label)
                        @if (! empty($score->$field))
                            <p class="text-sm font-semibold mt-3">{{ $label }}</p>
                            <ul class="list-disc pl-5 text-sm max-w-prose">
                                @foreach ($score->$field as $point)
                                    <li>{{ $point }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @endforeach

                    @if ($score->evidenceQuotes->isNotEmpty())
                        <p class="text-sm font-semibold mt-3">Bukti dari transkrip</p>
                        <ul class="mt-1 space-y-3 max-w-prose">
                            @foreach ($score->evidenceQuotes as $q)
                                @php [$text, $tone] = $badge[$q->match_method] ?? ['belum dicek', 'status-warn']; @endphp
                                <li class="text-sm">
                                    <span class="font-transcript {{ $q->is_verified ? 'highlight' : ($q->match_method === 'none' ? 'line-through text-pencil' : '') }}">“{{ $q->quote_text }}”</span>
                                    <span class="block mt-0.5 text-pencil">
                                        {{ $q->supports === 'weakness' ? 'kelemahan' : 'kekuatan' }}
                                        · {{ $q->answer->question->text ?? 'jawaban #'.$q->answer_id }}
                                        @if ($mmss($q->start_ms)) · {{ $mmss($q->start_ms) }}–{{ $mmss($q->end_ms) }} @endif
                                        · <span class="status {{ $tone }}">{{ $text }}</span>
                                    </span>
                                    @if ($q->matched_text && in_array($q->match_method, ['fuzzy', 'similar'], true))
                                        <span class="block mt-0.5 text-ink-soft">Di transkrip: <span class="font-transcript">“{{ $q->matched_text }}”</span></span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>

        @php $weighted = $result->weightedScore(); @endphp
        <p class="mt-8 font-semibold">Rata-rata berbobot (kriteria yang dinilai): {{ \App\Support\ScoreScale::format($weighted) }}<span class="text-pencil font-normal">/10</span></p>

        <p class="mt-4 text-xs text-pencil">
            {{ $result->model_used }} via {{ $result->provider }} · prompt {{ $result->prompt_version }} · kondisi {{ $result->condition === 'retrieval' ? 'retrieval' : 'transkrip penuh' }}
            · token {{ $result->prompt_tokens ?? '?' }} masuk / {{ $result->completion_tokens ?? '?' }} keluar
            · {{ number_format($result->llm_latency_ms / 1000, 1, ',', '.') }} s
            @if ($result->cost_usd !== null) · ${{ number_format($result->cost_usd, 4) }} @endif
            · dibuat {{ $result->generated_at?->translatedFormat('d M Y, H:i') }}
        </p>
    @endif
</div>
