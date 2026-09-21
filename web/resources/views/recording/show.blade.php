@php
    $questionData = $questions->map(fn ($q) => [
        'id' => $q['id'],
        'text' => $q['text'],
        'criterion' => $q['criterion'],
        'answerText' => $q['answer']?->transcript ?? '',
        'answered' => $q['answer'] !== null && $q['answer']->transcript !== null,
    ])->values();
@endphp

<x-page :title="'Perekaman: '.$session->candidate->name" :subtitle="$session->candidate->jobOpening->title.' — rekam jawaban per pertanyaan; teks muncul selagi kandidat bicara'" :back="route('interview-sessions.show', $session)" back-label="Kembali ke sesi">
    <x-slot:actions>
        <form method="POST" action="{{ route('recording.finish', $session) }}" x-data @submit.prevent="if (confirm('Akhiri sesi? Perekaman tidak bisa dilanjutkan setelah ini.')) $el.submit()">
            @csrf
            <button type="submit" class="btn-quiet">Akhiri sesi</button>
        </form>
    </x-slot:actions>

    <div
        x-data="recorder({
            ws: @js($asr['ws']),
            http: @js($asr['http']),
            usePrompt: @js($asr['use_prompt']),
            language: @js($asr['language']),
            questions: @js($questionData),
            routes: {
                store: @js(route('answers.store', [$session, '__Q__'])),
                audio: @js(route('answers.audio', [$session, '__Q__'])),
            },
            csrf: @js(csrf_token()),
        })"
        class="grid grid-cols-1 lg:grid-cols-[280px_1fr]"
    >
        {{-- Question list --}}
        <aside class="sheet-section lg:border-r border-rule">
            <h2 class="text-md mb-3">Pertanyaan</h2>
            <ol class="space-y-1">
                <template x-for="(q, i) in questions" :key="q.id">
                    <li>
                        <button type="button" @click="select(i)" :disabled="isRecording"
                                class="w-full text-left px-3 py-2 rounded text-sm border-l-2"
                                :class="i === current ? 'border-ink bg-paper font-semibold' : 'border-transparent hover:bg-paper'">
                            <span class="block text-pencil text-xs" x-text="q.criterion"></span>
                            <span x-text="(i + 1) + '. ' + q.text"></span>
                            <span x-show="q.answered" class="status status-done mt-1">Terekam</span>
                        </button>
                    </li>
                </template>
            </ol>
        </aside>

        {{-- Recording surface --}}
        <section class="sheet-section">
            <p class="text-sm text-pencil" x-text="question.criterion"></p>
            <h2 class="mt-1" x-text="question.text"></h2>

            {{-- Transcript: the record. Finals in ink, the live partial in pencil. --}}
            <div class="mt-6 min-h-[220px] border border-rule rounded p-5 bg-sheet" aria-live="polite">
                <p class="transcript">
                    <template x-for="s in finalSegments" :key="s.segment_id"><span x-text="s.text + ' '"></span></template>
                    <span class="transcript-partial" x-text="partialText"></span>
                    <span x-show="!transcript && !partialText" class="text-pencil-light">
                        <span x-show="state === 'idle'">Tekan “Mulai rekam”, lalu bacakan pertanyaan kepada kandidat.</span>
                        <span x-show="state === 'listening'">Mendengarkan…</span>
                    </span>
                </p>
            </div>

            {{-- Status line: what the rubric asks for — status + latency, always visible --}}
            <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <span class="status" :class="{ 'status-active': state === 'listening', 'status-done': state === 'idle' && question.answered, 'status-warn': state === 'error' }">
                    <span x-text="statusLabel()"></span>
                </span>
                <span x-show="state === 'listening'" class="tabular-nums text-ink-soft" x-text="elapsed + ' s'"></span>
                <span x-show="state === 'listening'" class="inline-flex items-center gap-2 text-pencil">
                    <span>mic</span>
                    <span class="inline-block w-24 h-1.5 bg-rule rounded-full overflow-hidden"><span class="block h-full bg-ink" :style="'width:' + levelPct + '%'"></span></span>
                </span>
                <span x-show="lastLatency !== null" class="tabular-nums">
                    Latency <span class="font-semibold" x-text="(lastLatency / 1000).toFixed(1) + ' s'"></span>
                    <span class="text-pencil" x-text="lastServerLatency !== null ? '(inference ' + (lastServerLatency / 1000).toFixed(1) + ' s)' : ''"></span>
                </span>
                <span class="text-pencil" x-show="serviceOk === true" x-text="models"></span>
                <span class="text-danger" x-show="serviceOk === false">Layanan transkripsi tidak tersedia</span>
            </div>

            <p x-show="errorMessage" class="mt-3 notice border-danger text-danger" x-text="errorMessage"></p>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <button type="button" class="btn-primary" x-show="canStart" @click="start()">
                    <span x-text="question.answered ? 'Rekam ulang' : 'Mulai rekam'"></span>
                </button>
                <button type="button" class="btn-danger" x-show="state === 'listening'" @click="stop()">Selesai jawaban</button>
                <span x-show="state === 'stopping' || state === 'saving'" class="text-sm text-pencil" x-text="statusLabel()"></span>
                <button type="button" class="btn-quiet" x-show="state === 'idle' && current < questions.length - 1" @click="select(current + 1)">Pertanyaan berikutnya</button>
                <button type="button" class="btn-link font-normal" x-show="serviceOk === false && state !== 'listening'" @click="checkService()">Cek ulang layanan</button>
            </div>

            <p class="mt-6 text-xs text-pencil max-w-prose">
                Audio diproses di komputer ini. Teks abu-abu bisa berubah sampai kandidat berhenti sejenak; teks hitam adalah hasil akhir yang disimpan. Latency = waktu dari suara terakhir masuk sampai teks muncul, diukur di browser ini.
            </p>
        </section>
    </div>
</x-page>
