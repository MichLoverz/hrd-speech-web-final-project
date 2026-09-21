/**
 * HireNote continuous-interview recorder (Alpine component, ADR-007).
 *
 * One stream for the whole interview. The service labels every segment
 * with a speaker (interviewer / candidate) and tracks the active question
 * (question text match, trigger phrase, or manual). Everything is saved as
 * it happens:
 *   - transcript events  → POST live.segments every ~2 s (batched, upsert by client_seq)
 *   - raw PCM            → POST live.audio every ~10 s (appended server-side)
 *   - end                → POST live.finish (wrap WAV, derive answers per question)
 * so a browser crash loses at most a few seconds.
 */

import { openMic, frameWithTimestamp, levelPercent, concatPcm, SAMPLE_RATE } from './capture';

const SEGMENT_FLUSH_MS = 2000;
const AUDIO_FLUSH_MS = 10000;

export default function interview(opts) {
    return {
        wsUrl: opts.ws, httpUrl: opts.http, usePrompt: opts.usePrompt, language: opts.language || 'id',
        triggerPhrases: opts.triggerPhrases || [],
        questions: opts.questions,              // [{id, text, criterion}]
        routes: opts.routes,                    // {segments, audio, finish}
        csrf: opts.csrf,
        seqOffset: opts.lastSeq || 0,           // continue numbering if the page was reopened

        state: 'idle',                          // idle | connecting | live | finishing | done | error
        serviceOk: null, models: '', speakerModel: '',
        errorMessage: '',
        activeQuestionId: null, activeReason: null, activeScore: null,
        lines: [],                              // finals: {segment_id, speaker, text, question_id, latency}
        partial: null,                          // {segment_id, speaker, text}
        lastLatency: null, lastServerLatency: null,
        levelPct: 0, elapsed: 0,
        savedSegments: 0, savedAudioBytes: 0, saveError: '',

        _ws: null, _mic: null, _timer: null, _startedAt: 0,
        _pendingEvents: [], _pendingPcm: [], _segTimer: null, _audioTimer: null, _doneResolve: null,
        _totalSamples: 0, _seqMax: 0, _events: [], relabelled: 0,

        get isLive() { return this.state === 'live'; },
        get activeQuestion() { return this.questions.find(q => q.id === this.activeQuestionId) || null; },
        get activeIndex() { return this.questions.findIndex(q => q.id === this.activeQuestionId); },

        async init() { await this.checkService(); },

        async checkService() {
            try {
                const j = await (await fetch(`${this.httpUrl}/health`, { cache: 'no-store' })).json();
                this.serviceOk = !!j.ok;
                this.models = j.ok ? `${j.partial_model} → ${j.final_model}` : '';
            } catch { this.serviceOk = false; }
        },

        // ------------------------------------------------------------ start

        async start() {
            this.errorMessage = ''; this.saveError = '';
            this.state = 'connecting';
            try {
                this._mic = await openMic((buf) => this.onChunk(buf));
            } catch (e) { this.fail(`Mikrofon tidak bisa dibuka: ${e.message}`); return; }
            try {
                await this.openSocket();
            } catch (e) {
                this.serviceOk = false;
                this.errorMessage = 'Layanan transkripsi tidak tersambung. Audio tetap direkam dan disimpan; transkrip bisa dibuat dari rekaman setelah selesai.';
            }
            this._startedAt = Date.now();
            this._timer = setInterval(() => { this.elapsed = Math.round((Date.now() - this._startedAt) / 1000); }, 500);
            this._segTimer = setInterval(() => this.flushSegments(), SEGMENT_FLUSH_MS);
            this._audioTimer = setInterval(() => this.flushAudio(), AUDIO_FLUSH_MS);
            this.state = 'live';
        },

        openSocket() {
            return new Promise((resolve, reject) => {
                const ws = new WebSocket(this.wsUrl);
                ws.binaryType = 'arraybuffer';
                let ready = false;
                ws.onopen = () => ws.send(JSON.stringify({
                    type: 'start', mode: 'interview', language: this.language, use_prompt: this.usePrompt,
                    questions: this.questions.map(q => ({ id: q.id, text: q.text })),
                    trigger_phrases: this.triggerPhrases,
                }));
                ws.onmessage = (ev) => {
                    const msg = JSON.parse(ev.data);
                    if (msg.type === 'ready') {
                        ready = true;
                        this.models = `${msg.partial_model} → ${msg.final_model}`;
                        this.speakerModel = msg.speaker_model || '';
                        resolve(); return;
                    }
                    this.onEvent(msg);
                };
                ws.onerror = () => { if (!ready) reject(new Error('ws error')); };
                ws.onclose = () => {
                    if (!ready) reject(new Error('ws closed'));
                    else if (this.state === 'live') { this.errorMessage = 'Koneksi ke layanan transkripsi terputus. Audio tetap direkam.'; this.serviceOk = false; }
                    this._ws = null;
                };
                this._ws = ws;
            });
        },

        // ------------------------------------------------------------ streaming

        onChunk(buf) {
            if (this.state !== 'live') return;
            const pcm = new Int16Array(buf);
            this._pendingPcm.push(pcm);
            this._totalSamples += pcm.length;
            this.levelPct = levelPercent(pcm);
            if (this._ws && this._ws.readyState === WebSocket.OPEN) this._ws.send(frameWithTimestamp(buf));
        },

        onEvent(msg) {
            if (msg.type === 'partial' || msg.type === 'final') {
                msg.e2e_latency_ms = msg.last_chunk_client_ms ? Math.max(0, Math.round(Date.now() - msg.last_chunk_client_ms)) : null;
                msg.client_seq = this.seqOffset + msg.seq;
                this._seqMax = Math.max(this._seqMax, msg.client_seq);
                this._pendingEvents.push(msg);
                this._events.push(msg);
                this.lastLatency = msg.e2e_latency_ms; this.lastServerLatency = msg.latency_ms;
                if (msg.type === 'final') {
                    this.lines.push({ segment_id: msg.segment_id, speaker: msg.speaker, text: msg.text, question_id: msg.question_id, latency: msg.e2e_latency_ms });
                    this.lines.sort((a, b) => a.segment_id - b.segment_id);
                    if (this.partial && this.partial.segment_id === msg.segment_id) this.partial = null;
                    this.$nextTick(() => { const el = this.$refs.stream; if (el) el.scrollTop = el.scrollHeight; });
                } else {
                    this.partial = { segment_id: msg.segment_id, speaker: msg.speaker, text: msg.text };
                }
            } else if (msg.type === 'speaker_relabel') {
                // Final re-clustering changed some labels: fix the screen and re-upload those events (upsert by client_seq).
                for (const [sid, label] of Object.entries(msg.labels)) {
                    const id = Number(sid);
                    for (const e of this._events) if (e.segment_id === id && e.speaker !== label) { e.speaker = label; this._pendingEvents.push(e); this.relabelled++; }
                    for (const l of this.lines) if (l.segment_id === id) l.speaker = label;
                }
            } else if (msg.type === 'question_change') {
                this.activeQuestionId = msg.question_id; this.activeReason = msg.reason; this.activeScore = msg.score;
            } else if (msg.type === 'done') {
                if (this._doneResolve) this._doneResolve(msg.stats);
            } else if (msg.type === 'error') {
                this.errorMessage = `Layanan transkripsi: ${msg.message}`;
            }
        },

        setQuestion(id) {
            if (this._ws && this._ws.readyState === WebSocket.OPEN) {
                this._ws.send(JSON.stringify({ type: 'set_question', question_id: id }));
            } else {
                // No service: track locally so segments saved later still carry the question.
                this.activeQuestionId = id; this.activeReason = 'manual'; this.activeScore = null;
            }
        },

        nextQuestion() {
            const i = this.activeIndex;
            const next = this.questions[i + 1] || this.questions[0];
            if (next) this.setQuestion(next.id);
        },

        // ------------------------------------------------------------ incremental save

        async flushSegments() {
            if (!this._pendingEvents.length) return;
            const batch = this._pendingEvents.splice(0, this._pendingEvents.length);
            const body = { segments: batch.map(e => ({
                client_seq: e.client_seq, text: e.text, is_final: e.type === 'final',
                speaker: e.speaker, speaker_similarity: e.speaker_similarity,
                question_id: e.question_id ?? null,      // as recorded at emit time; never backfilled on re-upload
                start_ms: e.start_ms, end_ms: e.end_ms,
                chunk_received_at: e.chunk_received_at, inference_started_at: e.inference_started_at,
                inference_ended_at: e.inference_ended_at, emitted_at: e.emitted_at,
                latency_ms: e.latency_ms, e2e_latency_ms: e.e2e_latency_ms,
                audio_duration_ms: e.audio_duration_ms, real_time_factor: e.real_time_factor,
                confidence: e.avg_logprob == null ? null : Math.exp(e.avg_logprob),
            })) };
            try {
                const r = await fetch(this.routes.segments, { method: 'POST', headers: this.jsonHeaders(), body: JSON.stringify(body) });
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                this.savedSegments += batch.length; this.saveError = '';
            } catch (e) {
                this._pendingEvents.unshift(...batch);            // retry next tick
                this.saveError = `Gagal menyimpan transkrip (${e.message}), mencoba lagi…`;
            }
        },

        async flushAudio() {
            if (!this._pendingPcm.length) return;
            const chunks = this._pendingPcm.splice(0, this._pendingPcm.length);
            const bytes = concatPcm(chunks);
            try {
                const r = await fetch(this.routes.audio, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, 'Content-Type': 'application/octet-stream' }, body: bytes });
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                this.savedAudioBytes += bytes.byteLength; this.saveError = '';
            } catch (e) {
                this._pendingPcm.unshift(...chunks);
                this.saveError = `Gagal menyimpan audio (${e.message}), mencoba lagi…`;
            }
        },

        // ------------------------------------------------------------ finish

        async finish() {
            if (this.state !== 'live') return;
            this.state = 'finishing';
            clearInterval(this._timer); clearInterval(this._segTimer); clearInterval(this._audioTimer);
            this._mic && this._mic.flush();
            await new Promise(r => setTimeout(r, 80));
            this._mic && this._mic.close(); this._mic = null; this.levelPct = 0;

            let stats = null;
            if (this._ws && this._ws.readyState === WebSocket.OPEN) {
                const done = new Promise(r => { this._doneResolve = r; });
                this._ws.send(JSON.stringify({ type: 'stop' }));
                stats = await Promise.race([done, new Promise(r => setTimeout(() => r(null), 60000))]);
                this._ws.close(); this._ws = null;
            }
            this.partial = null;

            await this.flushSegments();
            await this.flushAudio();
            if (this._pendingEvents.length || this._pendingPcm.length) {
                this.state = 'error';
                this.errorMessage = 'Sebagian data belum tersimpan. Periksa koneksi lalu tekan "Akhiri wawancara" lagi.';
                this.state = 'live'; // allow retry of the flush + finish
                return;
            }

            try {
                const r = await fetch(this.routes.finish, { method: 'POST', headers: this.jsonHeaders(), body: JSON.stringify({
                    asr_model: this.models.split('→').pop().trim() || null,
                    speaker_model: this.speakerModel || null,
                    duration_ms: Math.round(this._totalSamples / SAMPLE_RATE * 1000),
                }) });
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const j = await r.json();
                this.state = 'done';
                window.location.href = j.redirect;
            } catch (e) {
                this.fail(`Gagal mengakhiri sesi: ${e.message}`);
            }
        },

        jsonHeaders() {
            return { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' };
        },

        fail(message) {
            this.errorMessage = message; this.state = 'error';
            clearInterval(this._timer); clearInterval(this._segTimer); clearInterval(this._audioTimer);
            this._mic && this._mic.close(); this._mic = null;
            if (this._ws) { try { this._ws.close(); } catch {} this._ws = null; }
        },

        speakerLabel(s) { return { interviewer: 'Pewawancara', candidate: 'Kandidat' }[s] || '?'; },
        statusLabel() {
            return { idle: 'Siap', connecting: 'Menyambung…', live: 'Wawancara berlangsung', finishing: 'Menyelesaikan…', done: 'Selesai', error: 'Gagal' }[this.state];
        },
    };
}
