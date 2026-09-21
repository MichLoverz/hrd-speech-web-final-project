/**
 * AudioWorklet processor: turns the microphone's Float32 stream into
 * 16 kHz mono Int16 chunks of a fixed length and posts them to the page.
 *
 * Why AudioWorklet and not MediaRecorder: MediaRecorder produces compressed
 * containers (webm/opus) meant for saving files; the ASR service needs raw
 * PCM it can run VAD and inference on as it arrives. The worklet runs on the
 * audio thread and hands us raw samples every 128 frames.
 *
 * Resampling: we ask for an AudioContext at 16 kHz. Browsers that honour it
 * give us 16 kHz directly; otherwise we decimate here with linear
 * interpolation (fine for speech, and it keeps the worklet dependency-free).
 *
 * Loaded from /audio/pcm-processor.js (must be a plain file, not bundled).
 */
class PcmProcessor extends AudioWorkletProcessor {
    constructor(options) {
        super();
        const o = (options && options.processorOptions) || {};
        this.targetRate = o.targetRate || 16000;
        this.chunkSamples = o.chunkSamples || Math.round(this.targetRate * 0.2); // 200 ms
        this.ratio = sampleRate / this.targetRate; // `sampleRate` is the worklet global (context rate)
        this.buffer = new Int16Array(this.chunkSamples);
        this.fill = 0;
        this.pos = 0; // fractional read position for resampling
        this.carry = new Float32Array(0);
        this.port.onmessage = (e) => { if (e.data === 'flush') this.flush(); };
    }

    process(inputs) {
        const input = inputs[0];
        if (!input || !input[0]) return true;
        const mono = input[0]; // channel 0; getUserMedia gives us mono anyway

        if (this.ratio === 1) {
            for (let i = 0; i < mono.length; i++) this.push(mono[i]);
            return true;
        }

        // Concatenate the leftover from last call so interpolation can cross block boundaries.
        const data = new Float32Array(this.carry.length + mono.length);
        data.set(this.carry, 0);
        data.set(mono, this.carry.length);

        let i = this.pos;
        while (i + 1 < data.length) {
            const i0 = Math.floor(i);
            const frac = i - i0;
            this.push(data[i0] * (1 - frac) + data[i0 + 1] * frac);
            i += this.ratio;
        }
        // Keep the last sample (needed for interpolation) and the fractional position.
        const consumed = Math.floor(i);
        this.carry = data.slice(Math.max(0, consumed - 1));
        this.pos = i - Math.max(0, consumed - 1);
        return true;
    }

    push(sample) {
        const s = Math.max(-1, Math.min(1, sample));
        this.buffer[this.fill++] = s < 0 ? s * 0x8000 : s * 0x7fff;
        if (this.fill === this.chunkSamples) {
            this.port.postMessage(this.buffer.buffer.slice(0));
            this.fill = 0;
        }
    }

    flush() {
        if (this.fill > 0) {
            this.port.postMessage(this.buffer.buffer.slice(0, this.fill * 2));
            this.fill = 0;
        }
    }
}

registerProcessor('pcm-processor', PcmProcessor);
