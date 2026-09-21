<x-page :title="'Wawancara: '.$session->candidate->name" :subtitle="$session->candidate->jobOpening->title.' — satu rekaman utuh; sistem membedakan suara dan mengikuti pertanyaan yang sedang ditanyakan'" :back="route('interview-sessions.show', $session)" back-label="Kembali ke sesi">
    <div
        x-data="interview({
            ws: @js($asr['ws']),
            http: @js($asr['http']),
            usePrompt: @js($asr['use_prompt']),
            language: @js($asr['language']),
            triggerPhrases: @js($asr['trigger_phrases']),
            questions: @js($questions),
            lastSeq: @js($lastSeq),
            routes: {
                segments: @js(route('live.segments', $session)),
                audio: @js(route('live.audio', $session)),
                finish: @js(route('live.finish', $session)),
            },
            csrf: @js(csrf_token()),
        })"
        class="grid grid-cols-1 lg:grid-cols-[300px_1fr]"
    >
        {{-- Questions: the interviewer reads them from here; the active one is the system's belief --}}
        <aside class="sheet-section lg:border-r border-rule">
            <div class="flex items-baseline justify-between mb-3">
                <h2 class="text-md">Pertanyaan</h2>
                <span class="text-xs text-pencil" x-show="activeQuestion" x-text="'aktif: ' + (activeIndex + 1) + '/' + questions.length"></span>
            </div>
            <ol class="space-y-1">
                <template x-for="(q, i) in questions" :key="q.id">
                    <li>
                        <button type="button" @click="setQuestion(q.id)" :disabled="!isLive"
                                class="w-full text-left px-3 py-2 rounded text-sm border-l-2"
                                :class="q.id === activeQuestionId ? 'border-ink bg-paper font-semibold' : 'border-transparent hover:bg-paper'">
                            <span class="block text-pencil text-xs" x-text="q.criterion"></span>
                            <span x-text="(i + 1) + '. ' + q.text"></span>
                        </button>
                    </li>
                </template>
            </ol>
            <p class="mt-4 text-xs text-pencil">
                Pertanyaan berpindah otomatis saat pewawancara membacakannya atau mengucapkan frasa seperti “lanjut ke pertanyaan berikutnya”. Klik pertanyaan untuk memaksa.
            </p>
            <p class="mt-2 text-xs" x-show="activeReason">
                <span class="text-pencil">Terakhir dipindah:</span>
                <span x-text="{match: 'cocok dengan teks pertanyaan', phrase: 'frasa pemicu', manual: 'manual'}[activeReason] || activeReason"></span>
                <span class="text-pencil" x-show="activeScore !== null" x-text="'(' + activeScore + ')'"></span>
            </p>
        </aside>

        <section class="sheet-section">
            {{-- Status line --}}
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <span class="status" :class="{ 'status-active': isLive, 'status-warn': state === 'error', 'status-done': state === 'done' }"><span x-text="statusLabel()"></span></span>
                <span x-show="isLive" class="tabular-nums text-ink-soft" x-text="Math.floor(elapsed / 60) + ':' + String(elapsed % 60).padStart(2, '0')"></span>
                <span x-show="isLive" class="inline-flex items-center gap-2 text-pencil">
                    <span>mic</span>
                    <span class="inline-block w-24 h-1.5 bg-rule rounded-full overflow-hidden"><span class="block h-full bg-ink" :style="'width:' + levelPct + '%'"></span></span>
                </span>
                <span x-show="lastLatency !== null" class="tabular-nums">
                    Latency <span class="font-semibold" x-text="(lastLatency / 1000).toFixed(1) + ' s'"></span>
                </span>
                <span class="text-pencil" x-show="serviceOk === true" x-text="models + (speakerModel ? ' + diarization' : '')"></span>
                <span class="text-danger" x-show="serviceOk === false">Layanan transkripsi tidak tersedia</span>
                <span class="text-pencil text-xs" x-show="isLive" x-text="'tersimpan: ' + savedSegments + ' segmen, ' + (savedAudioBytes / 32000).toFixed(0) + ' s audio'"></span>
                <span class="text-pencil text-xs" x-show="relabelled" x-text="relabelled + ' label pembicara dikoreksi di akhir'"></span>
            </div>
            <p x-show="errorMessage" class="mt-3 notice border-danger text-danger" x-text="errorMessage"></p>
            <p x-show="saveError" class="mt-3 text-sm text-danger" x-text="saveError"></p>

            {{-- The stream: finals per speaker, current partial in pencil --}}
            <div x-ref="stream" class="mt-4 h-[420px] overflow-y-auto border border-rule rounded p-5 bg-sheet" aria-live="polite">
                <template x-for="line in lines" :key="line.segment_id">
                    <p class="mb-3 grid grid-cols-[92px_1fr] gap-3">
                        <span class="text-xs pt-1" :class="line.speaker === 'candidate' ? 'text-ink font-semibold' : 'text-pencil'" x-text="speakerLabel(line.speaker)"></span>
                        <span class="transcript" :class="line.speaker === 'candidate' ? '' : 'text-ink-soft'" x-text="line.text"></span>
                    </p>
                </template>
                <p x-show="partial" class="mb-3 grid grid-cols-[92px_1fr] gap-3">
                    <span class="text-xs pt-1 text-pencil" x-text="partial ? speakerLabel(partial.speaker) : ''"></span>
                    <span class="transcript transcript-partial" x-text="partial ? partial.text : ''"></span>
                </p>
                <p x-show="!lines.length && !partial" class="text-pencil-light">
                    <span x-show="state === 'idle'">Tekan “Mulai wawancara”, lalu bacakan pertanyaan pertama kepada kandidat.</span>
                    <span x-show="isLive">Mendengarkan…</span>
                </p>
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <button type="button" class="btn-primary" x-show="state === 'idle' || state === 'error'" @click="start()">Mulai wawancara</button>
                <button type="button" class="btn-quiet" x-show="isLive" @click="nextQuestion()">Pertanyaan berikutnya</button>
                <button type="button" class="btn-danger" x-show="isLive" @click="if (confirm('Akhiri wawancara? Rekaman akan ditutup dan jawaban disusun per pertanyaan.')) finish()">Akhiri wawancara</button>
                <span x-show="state === 'finishing'" class="text-sm text-pencil">Menunggu transkrip terakhir dan menyusun jawaban…</span>
                <button type="button" class="btn-link font-normal" x-show="serviceOk === false && !isLive" @click="checkService()">Cek ulang layanan</button>
            </div>

            <p class="mt-6 text-xs text-pencil max-w-prose">
                Audio dan teks disimpan setiap beberapa detik selama wawancara. Suara diproses di komputer ini. Label pembicara dan pembagian per pertanyaan bisa dikoreksi di halaman sesi setelah selesai.
            </p>
        </section>
    </div>
</x-page>
