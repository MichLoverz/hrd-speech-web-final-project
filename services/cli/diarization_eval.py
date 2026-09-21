"""
Diarization feasibility / evaluation.

Builds a two-speaker "interview" from labelled clips (interviewer clips and
candidate audio, alternating, with silence gaps), runs the same VAD
segmentation the streamer uses, assigns each segment with OnlineDiarizer,
and scores the labels against the construction ground truth.

    python cli/diarization_eval.py --interviewer q_david_*.wav --candidate answer.wav
    python cli/diarization_eval.py ... --model 3dspeaker_speech_eres2net_base_sv_zh-cn_3dspeaker_16k.onnx

Ground truth is by construction: every sample of the mixed file knows who
spoke. A VAD segment's true label = majority owner of its samples.
"""

from __future__ import annotations

import argparse
import glob
import sys
import time
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.asr.diarizer import OnlineDiarizer, SPEAKER_MODEL_DIR, DEFAULT_SPEAKER_MODEL   # noqa: E402
from app.asr.vad import StreamingVad                                                   # noqa: E402
from app.config import SAMPLE_RATE, VAD_FRAME_SAMPLES                                  # noqa: E402
from file_demo import load_mono_16k                                                    # noqa: E402


def build_interview(interviewer_clips: list[np.ndarray], candidate: np.ndarray, answer_s: float, gap_s: float):
    """Interleave: Q1, answer slice, Q2, answer slice, ... Returns (audio, owner per sample: 0/1/-1)."""
    gap = np.zeros(int(gap_s * SAMPLE_RATE), dtype=np.float32)
    parts, owners = [], []
    ans_len = int(answer_s * SAMPLE_RATE)
    pos = 0
    for q in interviewer_clips:
        parts += [q, gap]
        owners += [np.full(len(q), 0, np.int8), np.full(len(gap), -1, np.int8)]
        a = candidate[pos:pos + ans_len]
        pos += ans_len
        if len(a) == 0:
            break
        parts += [a, gap]
        owners += [np.full(len(a), 1, np.int8), np.full(len(gap), -1, np.int8)]
    return np.concatenate(parts), np.concatenate(owners)


def vad_segments(audio: np.ndarray, threshold: float, min_silence_ms: int, pad_ms: int):
    """Same rules as the streamer: speech starts on prob>=thr, ends after min_silence of silence."""
    vad = StreamingVad()
    frame_ms = VAD_FRAME_SAMPLES / SAMPLE_RATE * 1000
    segs, start, silence = [], None, 0.0
    n = len(audio) // VAD_FRAME_SAMPLES
    for i in range(n):
        p = vad.probability(audio[i * VAD_FRAME_SAMPLES:(i + 1) * VAD_FRAME_SAMPLES])
        t = i * VAD_FRAME_SAMPLES
        if p >= threshold:
            if start is None:
                start = max(0, t - int(pad_ms / 1000 * SAMPLE_RATE))
            silence = 0.0
        elif start is not None:
            silence += frame_ms
            if silence >= min_silence_ms:
                segs.append((start, t))
                start, silence = None, 0.0
    if start is not None:
        segs.append((start, len(audio)))
    return segs


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--interviewer", nargs="+", required=True, help="interviewer clips (globs ok)")
    p.add_argument("--candidate", required=True, help="one long candidate WAV, sliced between questions")
    p.add_argument("--answer-s", type=float, default=10.0)
    p.add_argument("--gap-s", type=float, default=0.8)
    p.add_argument("--model", default=DEFAULT_SPEAKER_MODEL)
    p.add_argument("--threshold", type=float, default=0.55)
    p.add_argument("--vad-threshold", type=float, default=0.5)
    p.add_argument("--min-silence", type=int, default=500)
    p.add_argument("--save-mix", default=None, help="write the mixed WAV here")
    a = p.parse_args()

    clips = [load_mono_16k(Path(f)) for pat in a.interviewer for f in sorted(glob.glob(pat))]
    cand = load_mono_16k(Path(a.candidate))
    audio, owner = build_interview(clips, cand, a.answer_s, a.gap_s)
    if a.save_mix:
        import soundfile as sf
        sf.write(a.save_mix, audio, SAMPLE_RATE)

    segs = vad_segments(audio, a.vad_threshold, a.min_silence, 200)
    diar = OnlineDiarizer(SPEAKER_MODEL_DIR / a.model, new_speaker_threshold=a.threshold)

    print(f"model={a.model}  segments={len(segs)}  audio={len(audio)/SAMPLE_RATE:.1f}s")
    print(f"{'#':>3} {'start':>6} {'len':>5} {'truth':<12} {'pred':<12} {'sim':>5} {'margin':>6} {'ms':>4}  {'reason'}")
    correct = total = 0
    confusion = {}
    t_embed = 0
    preds, truth_list = [], []
    for i, (s, e) in enumerate(segs):
        seg_owner = owner[s:e]
        counts = np.bincount(seg_owner[seg_owner >= 0], minlength=2)
        truth = "interviewer" if counts[0] >= counts[1] else "candidate"
        d = diar.assign(audio[s:e], segment_id=i)
        preds.append(d.label); truth_list.append(truth)
        t_embed += d.embed_ms
        ok = d.label == truth
        if d.label != "unknown":
            total += 1
            correct += ok
        confusion[(truth, d.label)] = confusion.get((truth, d.label), 0) + 1
        sim = "" if d.similarity is None else f"{d.similarity:.2f}"
        mg = "" if d.margin is None else f"{d.margin:.2f}"
        print(f"{i:>3} {s/SAMPLE_RATE:6.1f} {(e-s)/SAMPLE_RATE:5.1f} {truth:<12} {d.label:<12} {sim:>5} {mg:>6} {d.embed_ms:>4}  {d.reason}{'' if ok else '   <-- WRONG'}")

    print(f"\nlabel accuracy: {correct}/{total} = {correct/max(total,1):.3f}   embed time total {t_embed} ms "
          f"(avg {t_embed/max(len(segs),1):.0f} ms/segment)")
    print("confusion (truth -> pred):", {f"{k[0]}->{k[1]}": v for k, v in confusion.items()})

    # Post-hoc re-clustering, exactly as done when the interview ends.
    changes = diar.finalize()
    final_labels = [changes.get(i, preds[i]) for i in range(len(segs))]
    ok = sum(1 for i in range(len(segs)) if final_labels[i] == truth_list[i])
    print(f"after finalize(): {ok}/{len(segs)} = {ok / len(segs):.3f}   relabelled {len(changes)}: "
          + ", ".join(f"#{k}->{v}" for k, v in changes.items()))


if __name__ == "__main__":
    main()
