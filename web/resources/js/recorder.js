/**
 * HireNote live recorder (Alpine component).
 *
 *   mic ──AudioWorklet──► Int16 16 kHz chunks ──WebSocket──► ASR service
 *                          │                                     │
 *                          └── kept in memory → WAV evidence     └── partial / final events → screen
 *
 * On stop: send {type:"stop"}, wait for "done", then
 *   PUT  answers.store  (transcript + every event with timings)   ← never blocked by audio upload
 *   POST answers.audio  (WAV)                                       ← separate request
 *
 * Latency shown on screen is END-TO-END on the browser clock:
 *   now() - last_chunk_client_ms   (the service echoes the client timestamp we prefixed to the chunk).
 * The service's own latency_ms (its clock) is stored alongside in the database.
 */

import { openMic, frameWithTimestamp, levelPercent, encodeWav, SAMPLE_RATE } from './capture';

export default function recorder(opts) {
    return {
        // --- config from Blade
        wsUrl: opts.ws,
        httpUrl: opts.http,
        usePrompt: opts.usePrompt,
        language: opts.language || 'id',
        questions: opts.questions,           // [{id, text, criterion, answerText, answered}]
        routes: opts.routes,                 // {store: '.../answers/__Q__', audio: '.../answers/__Q__/audio'}
        csrf: opts.csrf,

        // --- state
        current: 0,
        state: 'idle',                       // idle | connecting | listening | stopping | saving | error
        serviceOk: null,                     // null unknown, true/false after health check
        errorMessage: '',
        finalSegments: [],                   // {segment_id, text}
        partialText: '',
        partialSegmentId: null,
        lastLatency: null,                   // e2e ms of the newest event
        lastServerLatency: null,
        events: [],                          // every partial/final message, stored with the answer
        levelPct: 0,                         // input meter
        elapsed: 0,

        // --- internals (not reactive-critical)
        _ws: null, _mic: null, _pcmChunks: [], _timer: null, _startedAt: 0,
        _prompt: '', _doneResolve: null, _partialTimer: null,

        get question() { return this.questions[this.current]; },
        get transcript() { return this.finalSegments.map(s => s.text).filter(Boolean).join(' '); },
        get canStart() { return this.state === 'idle' || this.state === 'error'; },
        get isRecording() { return this.state === 'listening'; },

        async init() {
            await this.checkService();
            this.$watch('current', () => this.resetView());
            this.resetView();
        },

        async checkService() {
            try {
                const r = await fetch(`${this.httpUrl}/health`, { cache: 'no-store' });
                const j = await r.json();
                this.serviceOk = !!j.ok;
                this.models = j.ok ? `${j.partial_model} → ${j.final_model}` : '';
            } catch {
                this.serviceOk = false;
            }
        },

        resetView() {
            const q = this.question;
            this.finalSegments = q.answerText ? [{ segment_id: 0, text: q.answerText }] : [];
            this.partialText = '';
            this.events = [];
            this.lastLatency = null;
            this.lastServerLatency = null;
            this.errorMessage = '';
            this.elapsed = 0;
            if (this.state !== 'listening') this.state = 'idle';
        },

        select(i) {
            if (this.isRecording) return;
            this.current = i;
        },

        // ------------------------------------------------------------ start

        async start() {
            this.errorMessage = '';
            this.finalSegments = [];
            this.partialText = '';
            this.events = [];
            this._pcmChunks = [];
            this.lastLatency = null;
            this.state = 'connecting';
            this._prompt = this.usePrompt ? this.question.text : '';

            try {
                await this.openMic();
            } catch (e) {
                this.fail(`Mikrofon tidak bisa dibuka: ${e.message}`);
                return;
            }

            try {
                await this.openSocket();
            } catch (e) {
                // ASR down: keep recording locally so the answer is not lost; transcript can be made later.
                this.serviceOk = false;
                this.errorMessage = 'Layanan transkripsi tidak tersambung. Audio tetap direkam; transkrip bisa dibuat ulang dari rekaman setelah selesai.';
            }

            this._startedAt = Date.now();
            this._timer = setInterval(() => { this.elapsed = Math.round((Date.now() - this._startedAt) / 1000); }, 500);
            this.state = 'listening';
        },

        async openMic() {
            this._mic = await openMic((buf) => this.onChunk(buf));
            this.contextRate = this._mic.contextRate;
        },

        openSocket() {
            return new Promise((resolve, reject) => {
                const ws = new WebSocket(this.wsUrl);
                ws.binaryType = 'arraybuffer';
                let ready = false;
                ws.onopen = () => {
                    ws.send(JSON.stringify({ type: 'start', language: this.language, prompt: this._prompt, use_prompt: this.usePrompt }));
                };
                ws.onmessage = (ev) => {
                    const msg = JSON.parse(ev.data);
                    if (msg.type === 'ready') { ready = true; this.models = `${msg.partial_model} → ${msg.final_model}`; resolve(); return; }
                    this.onEvent(msg);
                };
                ws.onerror = () => { if (!ready) reject(new Error('ws error')); };
                ws.onclose = () => {
                    if (!ready) reject(new Error('ws closed'));
                    else if (this.state === 'listening') {
                        this.errorMessage = 'Koneksi ke layanan transkripsi terputus. Audio tetap direkam.';
                        this.serviceOk = false;
                    }
                    this._ws = null;
                };
                this._ws = ws;
            });
        },

        // ------------------------------------------------------------ streaming

        onChunk(arrayBuffer) {
            if (this.state !== 'listening') return;
            this._pcmChunks.push(new Int16Array(arrayBuffer));
            this.updateLevel(new Int16Array(arrayBuffer));
            if (this._ws && this._ws.readyState === WebSocket.OPEN) this._ws.send(frameWithTimestamp(arrayBuffer));
        },

        updateLevel(pcm) { this.levelPct = levelPercent(pcm); },

        onEvent(msg) {
            if (msg.type === 'partial' || msg.type === 'final') {
                const e2e = msg.last_chunk_client_ms ? Math.max(0, Math.round(Date.now() - msg.last_chunk_client_ms)) : null;
                msg.e2e_latency_ms = e2e;
                this.events.push(msg);
                this.lastLatency = e2e;
                this.lastServerLatency = msg.latency_ms;

                if (msg.type === 'final') {
                    // A final replaces the partial of its own segment; partials of a newer segment stay.
                    this.finalSegments.push({ segment_id: msg.segment_id, text: msg.text });
                    this.finalSegments.sort((a, b) => a.segment_id - b.segment_id);
                    if (this.partialSegmentId === msg.segment_id) { this.partialText = ''; this.partialSegmentId = null; }
                } else {
                    this.partialText = msg.text;
                    this.partialSegmentId = msg.segment_id;
                }
            } else if (msg.type === 'done') {
                this.stats = msg.stats;
                if (this._doneResolve) this._doneResolve();
            } else if (msg.type === 'error') {
                this.errorMessage = `Layanan transkripsi: ${msg.message}`;
            }
        },

        // ------------------------------------------------------------ stop & save

        async stop() {
            if (this.state !== 'listening') return;
            this.state = 'stopping';
            clearInterval(this._timer);

            // Flush the worklet's last partial chunk, then tear the mic down.
            this._mic && this._mic.flush();
            await new Promise(r => setTimeout(r, 60));
            this.closeMic();

            const durationMs = Math.round(this._pcmChunks.reduce((n, c) => n + c.length, 0) / SAMPLE_RATE * 1000);

            if (this._ws && this._ws.readyState === WebSocket.OPEN) {
                const done = new Promise(r => { this._doneResolve = r; });
                this._ws.send(JSON.stringify({ type: 'stop' }));
                await Promise.race([done, new Promise(r => setTimeout(r, 60000))]);
                this._ws.close();
                this._ws = null;
            }
            this.partialText = '';
            this.partialSegmentId = null;

            this.state = 'saving';
            try {
                await this.saveAnswer(durationMs);
                this.question.answerText = this.transcript;
                this.question.answered = true;
                await this.uploadAudio();
                this.state = 'idle';
            } catch (e) {
                this.fail(`Gagal menyimpan: ${e.message}`);
            }
        },

        closeMic() {
            this._mic && this._mic.close();
            this._mic = null;
            this.levelPct = 0;
        },

        async saveAnswer(durationMs) {
            const finals = this.events.filter(e => e.type === 'final');
            const model = finals.length ? finals[finals.length - 1].model : (this.events[0] && this.events[0].model) || null;
            const body = {
                transcript_raw: this.transcript,
                duration_ms: durationMs,
                model_used: model,
                asr_prompt: this._prompt || null,
                segments: this.events.map(e => ({
                    text: e.text, is_final: e.type === 'final',
                    start_ms: e.start_ms, end_ms: e.end_ms,
                    chunk_received_at: e.chunk_received_at, inference_started_at: e.inference_started_at,
                    inference_ended_at: e.inference_ended_at, emitted_at: e.emitted_at,
                    latency_ms: e.latency_ms, e2e_latency_ms: e.e2e_latency_ms,
                    audio_duration_ms: e.audio_duration_ms, real_time_factor: e.real_time_factor,
                    confidence: e.avg_logprob == null ? null : Math.exp(e.avg_logprob),
                })),
            };
            const r = await fetch(this.routes.store.replace('__Q__', this.question.id), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
            if (!r.ok) throw new Error(`transkrip (HTTP ${r.status})`);
        },

        async uploadAudio() {
            const wav = encodeWav(this._pcmChunks, SAMPLE_RATE);
            const fd = new FormData();
            fd.append('audio', new Blob([wav], { type: 'audio/wav' }), 'answer.wav');
            const r = await fetch(this.routes.audio.replace('__Q__', this.question.id), {
                method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' }, body: fd,
            });
            if (!r.ok) throw new Error(`audio (HTTP ${r.status})`);
        },

        fail(message) {
            this.errorMessage = message;
            this.state = 'error';
            clearInterval(this._timer);
            this.closeMic();
            if (this._ws) { try { this._ws.close(); } catch {} this._ws = null; }
        },

        statusLabel() {
            return {
                idle: 'Siap',
                connecting: 'Menyambung…',
                listening: 'Mendengarkan',
                stopping: 'Menunggu transkrip terakhir…',
                saving: 'Menyimpan…',
                error: 'Gagal',
            }[this.state];
        },
    };
}
