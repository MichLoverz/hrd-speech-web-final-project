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
        @php
            $sortedScores = $result->scores->sortBy(fn ($s) => $s->criterion->id);
            $weighted = $result->weightedScore();
        @endphp

        {{-- Overview first: the whole draft at a glance, details below. --}}
        @if ($result->overall_summary)
            <p class="scorecard-label">Ringkasan</p>
            <p class="max-w-prose mt-1">{{ $result->overall_summary }}</p>
        @endif

        <table class="ledger mt-6 max-w-prose">
            <thead>
                <tr><th>Kriteria</th><th class="text-right">Bobot</th><th class="text-right">Skor</th></tr>
            </thead>
            <tbody>
                @foreach ($sortedScores as $score)
                    <tr>
                        <td><a href="#kriteria-{{ $score->criterion->id }}">{{ $score->criterion->name }}</a></td>
                        <td class="text-right tabular-nums text-pencil">×{{ $score->criterion->weight }}</td>
                        <td class="text-right tabular-nums">
                            @if ($score->score !== null)
                                <span class="font-semibold">{{ \App\Support\ScoreScale::format($score->score) }}</span><span class="text-pencil">/10</span>
                            @else
                                <span class="text-sm text-pencil">tidak dinilai</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="pt-3 font-semibold border-t border-ink">Rata-rata berbobot <span class="font-normal text-pencil text-sm">(kriteria yang dinilai)</span></td>
                    <td class="pt-3 text-right tabular-nums border-t border-ink"><span class="text-lg font-semibold">{{ \App\Support\ScoreScale::format($weighted) }}</span><span class="text-pencil">/10</span></td>
                </tr>
            </tfoot>
        </table>

        <div class="mt-10">
            @foreach ($sortedScores as $score)
                <section class="scorecard-item" id="kriteria-{{ $score->criterion->id }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 max-w-prose">
                        <h3>{{ $score->criterion->name }} <span class="text-sm text-pencil font-normal">×{{ $score->criterion->weight }}</span></h3>
                        @if ($score->score !== null)
                            <span class="tabular-nums"><span class="text-xl font-semibold">{{ \App\Support\ScoreScale::format($score->score) }}</span><span class="text-pencil">/10</span></span>
                        @else
                            <span class="text-sm text-pencil">tidak ada bukti — tidak dinilai</span>
                        @endif
                    </div>
                    @if ($score->rationale)
                        <p class="text-sm text-ink-soft max-w-prose mt-2">{{ $score->rationale }}</p>
                    @endif

                    @if (! empty($score->strengths) || ! empty($score->weaknesses))
                        <div class="grid gap-x-8 gap-y-4 sm:grid-cols-2 mt-5 max-w-prose">
                            @foreach (['strengths' => 'Kekuatan', 'weaknesses' => 'Kelemahan'] as $field => $label)
                                <div>
                                    <p class="scorecard-label">{{ $label }}</p>
                                    @if (! empty($score->$field))
                                        <ul class="list-disc pl-5 text-sm mt-1 space-y-1">
                                            @foreach ($score->$field as $point)
                                                <li>{{ $point }}</li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <p class="text-sm text-pencil mt-1">—</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($score->evidenceQuotes->isNotEmpty())
                        <p class="scorecard-label mt-5">Bukti dari transkrip</p>
                        <ul class="mt-2 space-y-4 max-w-prose">
                            @foreach ($score->evidenceQuotes as $q)
                                @php [$text, $tone] = $badge[$q->match_method] ?? ['belum dicek', 'status-warn']; @endphp
                                <li class="scorecard-quote {{ $q->supports === 'weakness' ? 'scorecard-quote-weak' : '' }}">
                                    <span class="font-transcript {{ $q->is_verified ? 'highlight' : ($q->match_method === 'none' ? 'line-through text-pencil' : '') }}">“{{ $q->quote_text }}”</span>
                                    <span class="block mt-1 text-pencil">
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
                </section>
            @endforeach
        </div>

        <p class="mt-8 pt-4 border-t border-rule text-xs text-pencil">
            {{ $result->model_used }} via {{ $result->provider }} · prompt {{ $result->prompt_version }} · kondisi {{ $result->condition === 'retrieval' ? 'retrieval' : 'transkrip penuh' }}
            · token {{ $result->prompt_tokens ?? '?' }} masuk / {{ $result->completion_tokens ?? '?' }} keluar
            · {{ number_format($result->llm_latency_ms / 1000, 1, ',', '.') }} s
            @if ($result->cost_usd !== null) · ${{ number_format($result->cost_usd, 4) }} @endif
            · dibuat {{ $result->generated_at?->translatedFormat('d M Y, H:i') }}
        </p>
    @endif
</div>
