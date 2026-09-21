"""
Online two-speaker diarization for a structured interview.

Every speech segment the VAD produces is turned into a *speaker embedding*
(a vector describing the voice, not the words) with an ONNX model via
sherpa-onnx. Segments are assigned to one of two centroids:

  speaker 0 = "interviewer"  — whoever speaks first (the interviewer opens
                               the session and reads the first question)
  speaker 1 = "candidate"    — the first voice that is clearly different

Assignment = cosine similarity to each centroid. A centroid is updated with
a running mean of the embeddings assigned to it, so it sharpens as the
interview goes on. Segments shorter than `min_embed_s` are too short for a
reliable embedding and inherit the previous label with low confidence.

Limits (documented for the report): overlapping speech gets one label;
two very similar voices in a noisy room can be confused; the first few
segments may be relabelled in hindsight only by the post-hoc correction UI.
"""

from __future__ import annotations

import time
from dataclasses import dataclass
from pathlib import Path

import numpy as np
import sherpa_onnx

from app.config import SAMPLE_RATE, MODELS_DIR

SPEAKER_MODEL_DIR = MODELS_DIR / "speaker"
DEFAULT_SPEAKER_MODEL = "3dspeaker_speech_eres2net_base_sv_zh-cn_3dspeaker_16k.onnx"  # chosen 2026-09-22, see ADR-007
LABELS = ("interviewer", "candidate")


@dataclass
class SpeakerDecision:
    label: str                 # interviewer | candidate | unknown
    speaker_index: int | None  # 0 | 1 | None
    similarity: float | None   # cosine similarity to the chosen centroid
    margin: float | None       # similarity(best) - similarity(other); small = uncertain
    embed_ms: int              # time spent computing the embedding
    reason: str                # first | new_speaker | assigned | too_short


class OnlineDiarizer:
    def __init__(
        self,
        model_file: str | Path = SPEAKER_MODEL_DIR / DEFAULT_SPEAKER_MODEL,
        *,
        new_speaker_threshold: float = 0.55,   # below this vs the only centroid -> open the second speaker
        min_embed_s: float = 1.0,
        num_threads: int = 2,
    ):
        cfg = sherpa_onnx.SpeakerEmbeddingExtractorConfig(
            model=str(model_file), num_threads=num_threads, provider="cpu",
        )
        if not cfg.validate():
            raise RuntimeError(f"invalid speaker embedding model config: {model_file}")
        self.extractor = sherpa_onnx.SpeakerEmbeddingExtractor(cfg)
        self.model_name = Path(model_file).name
        self.new_speaker_threshold = new_speaker_threshold
        self.min_embed_s = min_embed_s
        self.centroids: list[np.ndarray] = []
        self.counts: list[int] = []
        self._last_index: int | None = None

    # ---------------------------------------------------------------- api

    def embed(self, audio: np.ndarray) -> np.ndarray:
        stream = self.extractor.create_stream()
        stream.accept_waveform(sample_rate=SAMPLE_RATE, waveform=audio.astype(np.float32))
        stream.input_finished()
        vec = np.asarray(self.extractor.compute(stream), dtype=np.float32)
        return vec / (np.linalg.norm(vec) + 1e-9)

    def assign(self, audio: np.ndarray) -> SpeakerDecision:
        t0 = time.time()
        if len(audio) < int(self.min_embed_s * SAMPLE_RATE):
            idx = self._last_index
            return SpeakerDecision(
                label=LABELS[idx] if idx is not None else "unknown",
                speaker_index=idx, similarity=None, margin=None,
                embed_ms=0, reason="too_short",
            )

        vec = self.embed(audio)
        embed_ms = int((time.time() - t0) * 1000)

        if not self.centroids:
            self._add(vec)
            self._last_index = 0
            return SpeakerDecision("interviewer", 0, None, None, embed_ms, "first")

        sims = [float(vec @ c) for c in self.centroids]
        best = int(np.argmax(sims))

        if len(self.centroids) == 1 and sims[0] < self.new_speaker_threshold:
            self._add(vec)
            self._last_index = 1
            return SpeakerDecision("candidate", 1, sims[0], None, embed_ms, "new_speaker")

        self._update(best, vec)
        self._last_index = best
        margin = (sims[best] - min(sims)) if len(sims) > 1 else None
        return SpeakerDecision(LABELS[best], best, sims[best], margin, embed_ms, "assigned")

    def reset(self) -> None:
        self.centroids, self.counts, self._last_index = [], [], None

    # ----------------------------------------------------------- internals

    def _add(self, vec: np.ndarray) -> None:
        self.centroids.append(vec.copy())
        self.counts.append(1)

    def _update(self, i: int, vec: np.ndarray) -> None:
        n = self.counts[i]
        c = (self.centroids[i] * n + vec) / (n + 1)
        self.centroids[i] = c / (np.linalg.norm(c) + 1e-9)
        self.counts[i] = n + 1
