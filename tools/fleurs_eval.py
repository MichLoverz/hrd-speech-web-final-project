"""
WER baseline on FLEURS id_id (Google's public read-speech corpus, CC-BY-4.0).

    python tools/fleurs_eval.py --download          # fetch test.tsv + test.tar.gz once
    python tools/fleurs_eval.py --model small --limit 200
    python tools/fleurs_eval.py --model base --limit 200 --label base-200

Why this exists: every WER figure in this project so far comes from the
student's own recordings, scored against transcripts typed by hand. Those
numbers have no outside reference point -- nobody else has that audio. FLEURS
ships reference transcripts for 102 languages and is what published Indonesian
ASR results are measured on, so a run here can be compared with the literature.

What it is NOT: a stand-in for the interview recordings. FLEURS is people
reading prepared sentences -- clear, unhurried, no hesitation, no crosstalk.
Interview speech has all of that. The gap between FLEURS WER and interview WER
is the point, and it belongs in the report; quoting the FLEURS number alone
would overstate what the system does on the job it was built for.

Scoring is corpus-level and uses the same normaliser as tools/wer_eval.py, so
these numbers sit beside the interview rows in asr-runs.csv on equal terms.
"""

from __future__ import annotations

import argparse
import csv
import json
import random
import sys
import tarfile
import time
from pathlib import Path

import numpy as np
import soundfile as sf

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "tools"))
sys.path.insert(0, str(ROOT / "services"))

from wer_eval import normalise, score, score_corpus            # noqa: E402

DATA = ROOT / "services" / "data" / "fleurs"
TSV = DATA / "data" / "id_id" / "test.tsv"
TAR = DATA / "data" / "id_id" / "audio" / "test.tar.gz"
WAV_CACHE = DATA / "wav" / "test"
OUT_DIR = ROOT / "docs" / "experiments" / "raw" / "fleurs"

# test.tsv columns, tab separated, no header.
COLS = ["id", "filename", "raw_transcription", "normalised_transcription",
        "phonemes", "num_samples", "gender"]


def download() -> None:
    from huggingface_hub import hf_hub_download
    for f in ["data/id_id/test.tsv", "data/id_id/audio/test.tar.gz"]:
        p = hf_hub_download("google/fleurs", f, repo_type="dataset", local_dir=str(DATA))
        print(f"{f} -> {p}  ({Path(p).stat().st_size / 1e6:.1f} MB)")


def load_rows() -> list[dict]:
    if not TSV.exists():
        sys.exit(f"missing {TSV}\nrun: python tools/fleurs_eval.py --download")
    rows = []
    for line in TSV.read_text(encoding="utf-8").splitlines():
        parts = line.split("\t")
        if len(parts) != len(COLS):
            continue
        rows.append(dict(zip(COLS, parts)))
    return rows


def ensure_wavs(names: set[str]) -> dict[str, Path]:
    """Pull just the requested members out of the tar, once, into a cache dir."""
    WAV_CACHE.mkdir(parents=True, exist_ok=True)
    have = {p.name: p for p in WAV_CACHE.glob("*.wav")}
    missing = names - set(have)
    if missing:
        if not TAR.exists():
            sys.exit(f"missing {TAR}\nrun: python tools/fleurs_eval.py --download")
        print(f"extracting {len(missing)} wav from {TAR.name} ...", end=" ", flush=True)
        with tarfile.open(TAR, "r:gz") as tf:
            for member in tf:
                base = Path(member.name).name
                if base in missing and member.isfile():
                    src = tf.extractfile(member)
                    if src is None:
                        continue
                    dst = WAV_CACHE / base
                    dst.write_bytes(src.read())
                    have[base] = dst
                    missing.discard(base)
                    if not missing:
                        break
        print("done")
    return have


def load_audio(path: Path) -> np.ndarray:
    audio, sr = sf.read(path, dtype="float32", always_2d=True)
    audio = audio.mean(axis=1)
    if sr != 16_000:
        n = int(len(audio) * 16_000 / sr)
        audio = np.interp(np.linspace(0, 1, n, endpoint=False),
                          np.linspace(0, 1, len(audio), endpoint=False), audio).astype(np.float32)
    return audio


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--download", action="store_true", help="fetch the corpus and exit")
    p.add_argument("--model", default="small", help="tiny | base | small | medium | large-v3")
    p.add_argument("--compute", default="int8")
    p.add_argument("--threads", type=int, default=0, help="0 = all logical cores")
    p.add_argument("--limit", type=int, default=200, help="utterances to score (0 = all 687)")
    p.add_argument("--seed", type=int, default=20260923, help="sampling seed, so a run can be repeated exactly")
    p.add_argument("--ref-col", default="raw_transcription", choices=["raw_transcription", "normalised_transcription"],
                   help="which FLEURS reference column to score against")
    p.add_argument("--label", default=None, help="name for the output files")
    p.add_argument("--punct", choices=["remove", "space"], default="remove")
    a = p.parse_args()

    if a.download:
        download()
        return

    rows = load_rows()
    random.Random(a.seed).shuffle(rows)
    if a.limit:
        rows = rows[:a.limit]
    label = a.label or f"{a.model}-{len(rows)}"

    wavs = ensure_wavs({r["filename"] for r in rows})
    rows = [r for r in rows if r["filename"] in wavs]

    import os
    from app.asr.engine import WhisperEngine
    from app.config import AsrConfig

    config = AsrConfig(model_size=a.model, partial_model_size=None, compute_type=a.compute,
                       cpu_threads=a.threads or (os.cpu_count() or 4), language="id")
    print(f"loading {a.model}/{a.compute} on {config.cpu_threads} threads ...", end=" ", flush=True)
    engine = WhisperEngine(config)
    print(f"{engine.load_seconds}s")
    print(f"scoring {len(rows)} FLEURS id_id utterances (seed {a.seed}, ref={a.ref_col})\n")

    refs, hyps, details = [], [], []
    audio_s = infer_s = 0.0
    t_start = time.time()
    for i, r in enumerate(rows, 1):
        audio = load_audio(wavs[r["filename"]])
        t = engine.transcribe(audio, final=True)
        ref = r[a.ref_col]
        refs.append(ref)
        hyps.append(t.text)
        dur = len(audio) / 16_000
        audio_s += dur
        infer_s += t.inference_ms / 1000
        one = score(ref, t.text, numbers=True, punct=a.punct)
        details.append({"id": r["id"], "filename": r["filename"], "gender": r["gender"],
                        "duration_s": round(dur, 2), "inference_ms": t.inference_ms,
                        "rtf": t.real_time_factor, "wer_num": round(one.wer, 4),
                        "ref": ref, "hyp": t.text})
        if i % 10 == 0 or i == len(rows):
            print(f"  {i}/{len(rows)}  audio {audio_s/60:.1f} min  elapsed {(time.time()-t_start)/60:.1f} min",
                  end="\r", flush=True)
    print()

    raw = score_corpus(refs, hyps, numbers=False, punct=a.punct)
    num = score_corpus(refs, hyps, numbers=True, punct=a.punct)
    rtfs = sorted(d["rtf"] for d in details)
    summary = {
        "label": label, "model": a.model, "compute": a.compute, "threads": config.cpu_threads,
        "utterances": len(rows), "ref_words": num.ref_words,
        "audio_seconds": round(audio_s, 1), "inference_seconds": round(infer_s, 1),
        "rtf_median": rtfs[len(rtfs) // 2], "rtf_overall": round(infer_s / audio_s, 3),
        "wer_raw": round(raw.wer, 4), "wer_num": round(num.wer, 4),
        "cer_raw": round(raw.cer, 4), "cer_num": round(num.cer, 4),
        "S": num.substitutions, "D": num.deletions, "I": num.insertions,
        "ref_col": a.ref_col, "punct": a.punct, "seed": a.seed,
    }

    print(f"\n{'utterances':<22}{summary['utterances']}")
    print(f"{'reference words':<22}{summary['ref_words']}")
    print(f"{'audio':<22}{audio_s/60:.1f} min")
    print(f"{'WER raw':<22}{summary['wer_raw']:.4f}")
    print(f"{'WER + numbers':<22}{summary['wer_num']:.4f}")
    print(f"{'CER + numbers':<22}{summary['cer_num']:.4f}")
    print(f"{'S / D / I':<22}{num.substitutions} / {num.deletions} / {num.insertions}")
    print(f"{'RTF median':<22}{summary['rtf_median']}")
    print(f"{'RTF overall':<22}{summary['rtf_overall']}")

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    stamp = time.strftime("%Y%m%d-%H%M%S")
    (OUT_DIR / f"{stamp}_{label}.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False), encoding="utf-8")
    csv_path = OUT_DIR / f"{stamp}_{label}.csv"
    with csv_path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(details[0].keys()))
        w.writeheader()
        w.writerows(details)
    print(f"\nwritten: {csv_path}\n         {OUT_DIR / f'{stamp}_{label}.json'}")


if __name__ == "__main__":
    main()
