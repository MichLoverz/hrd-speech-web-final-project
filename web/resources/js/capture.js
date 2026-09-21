/**
 * Shared microphone capture for both recording modes.
 *
 *   mic ──AudioWorklet(/audio/pcm-processor.js)──► Int16 16 kHz chunks ──► onChunk(ArrayBuffer)
 *
 * The worklet resamples if the browser refuses a 16 kHz AudioContext.
 */
export const SAMPLE_RATE = 16000;
export const CHUNK_MS = 200;

export async function openMic(onChunk) {
    const stream = await navigator.mediaDevices.getUserMedia({
        audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true },
    });
    const ctx = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: SAMPLE_RATE });
    await ctx.audioWorklet.addModule('/audio/pcm-processor.js');
    const source = ctx.createMediaStreamSource(stream);
    const node = new AudioWorkletNode(ctx, 'pcm-processor', {
        numberOfInputs: 1, numberOfOutputs: 0, channelCount: 1,
        processorOptions: { targetRate: SAMPLE_RATE, chunkSamples: Math.round(SAMPLE_RATE * CHUNK_MS / 1000) },
    });
    node.port.onmessage = (e) => onChunk(e.data);
    source.connect(node);

    return {
        contextRate: ctx.sampleRate,
        flush() { node.port.postMessage('flush'); },
        close() {
            try { node.disconnect(); } catch {}
            try { stream.getTracks().forEach(t => t.stop()); } catch {}
            try { ctx.close(); } catch {}
        },
    };
}

/** Prefix a PCM chunk with an 8-byte little-endian float64 client timestamp (ms). The service echoes it. */
export function frameWithTimestamp(arrayBuffer) {
    const frame = new ArrayBuffer(8 + arrayBuffer.byteLength);
    new DataView(frame).setFloat64(0, Date.now(), true);
    new Uint8Array(frame, 8).set(new Uint8Array(arrayBuffer));
    return frame;
}

/** Crude input meter: mean |sample| over a decimated chunk, 0..100. */
export function levelPercent(pcm) {
    let sum = 0;
    for (let i = 0; i < pcm.length; i += 8) sum += Math.abs(pcm[i]);
    const avg = sum / (pcm.length / 8) / 32768;
    return Math.min(100, Math.round(avg * 400));
}

/** PCM Int16 chunks → 16-bit mono WAV (RIFF) ArrayBuffer. */
export function encodeWav(chunks, sampleRate = SAMPLE_RATE) {
    const total = chunks.reduce((n, c) => n + c.length, 0);
    const buffer = new ArrayBuffer(44 + total * 2);
    const v = new DataView(buffer);
    const str = (o, s) => { for (let i = 0; i < s.length; i++) v.setUint8(o + i, s.charCodeAt(i)); };
    str(0, 'RIFF'); v.setUint32(4, 36 + total * 2, true); str(8, 'WAVE');
    str(12, 'fmt '); v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
    v.setUint32(24, sampleRate, true); v.setUint32(28, sampleRate * 2, true); v.setUint16(32, 2, true); v.setUint16(34, 16, true);
    str(36, 'data'); v.setUint32(40, total * 2, true);
    let off = 44;
    for (const c of chunks) { for (let i = 0; i < c.length; i++, off += 2) v.setInt16(off, c[i], true); }
    return buffer;
}

/** Concatenate Int16 chunks into one ArrayBuffer of raw PCM (for incremental upload). */
export function concatPcm(chunks) {
    const total = chunks.reduce((n, c) => n + c.length, 0);
    const out = new Int16Array(total);
    let off = 0;
    for (const c of chunks) { out.set(c, off); off += c.length; }
    return out.buffer;
}
