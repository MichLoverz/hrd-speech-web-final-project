"""
Online two-speaker diarization for a structured interview.

Every speech segment the VAD produces is turned into a *speaker embedding*
(a vector describing the voice, not the words) with an ONNX model via
sherpa-onnx. Segments are assigned to one of two centroids:

  speaker 0 = "interviewer"  — the first *substantial* voice (the interviewer
                               opens the session and reads the first question)
  speaker 1 = "candidate"    — the first substantial voice that is clearly different

Assignment = cosine similarity to each centroid; a centroid is a running
mean of the embeddings assigned to it. Only segments of at least
`min_open_s` may *open* a centroid: the 2026-09-22 browser test showed that
a 1.2 s "Good morning" run through Chrome's noise suppression made a poor
first centroid and every later label went wrong. Shorter segments are
labelled by nearest centroid but never update it.

Because online assignment is greedy and cannot undo early mistakes,
`finalize()` re-clusters every stored embedding (2-means) at the end of the
interview and returns the segments whose label changed. The consumer sends
those corrections downstream (the browser re-uploads them).

Limits (documented for the report): overlapping speech gets one label;
two very similar voices in a noisy room can be confused; corrections after
finalize() may still need the post-hoc UI.
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
    reason: str                # first | new_speaker | assigned | short | pending | peek*


class OnlineDiarizer:
    def __init__(
        self,
        model_file: str | Path = SPEAKER_MODEL_DIR / DEFAULT_SPEAKER_MODEL,
        *,
        new_speaker_threshold: float = 0.55,   # below this vs the only centroid -> open the second speaker
        min_embed_s: float = 1.0,              # shorter than this: no embedding at all
        min_open_s: float = 2.0,               # shorter than this: may be labelled, may not open a centroid
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
        self.min_open_s = min_open_s
        self.centroids: list[np.ndarray] = []
        self.counts: list[int] = []
        self._last_index: int | None = None
        # Everything seen, for finalize(): (segment_id, embedding, duration_s, online_index)
        self._history: list[tuple[int, np.ndarray, float, int | None]] = []

    # ---------------------------------------------------------------- api

    def embed(self, audio: np.ndarray) -> np.ndarray:
        stream = self.extractor.create_stream()
        stream.accept_waveform(sample_rate=SAMPLE_RATE, waveform=audio.astype(np.float32))
        stream.input_finished()
        vec = np.asarray(self.extractor.compute(stream), dtype=np.float32)
        return vec / (np.linalg.norm(vec) + 1e-9)

    def assign(self, audio: np.ndarray, segment_id: int = 0) -> SpeakerDecision:
        """Label a FINAL segment and (if it is long enough) learn from it."""
        t0 = time.time()
        dur = len(audio) / SAMPLE_RATE
        if dur < self.min_embed_s:
            return self._inherit("short")

        vec = self.embed(audio)
        embed_ms = int((time.time() - t0) * 1000)
        can_open = dur >= self.min_open_s

        if not self.centroids:
            if not can_open:
                self._history.append((segment_id, vec, dur, None))
                return SpeakerDecision("unknown", None, None, None, embed_ms, "pending")
            self._add(vec)
            self._last_index = 0
            self._history.append((segment_id, vec, dur, 0))
            return SpeakerDecision("interviewer", 0, None, None, embed_ms, "first")

        sims = [float(vec @ c) for c in self.centroids]
        best = int(np.argmax(sims))

        if len(self.centroids) == 1 and sims[0] < self.new_speaker_threshold and can_open:
            self._add(vec)
            self._last_index = 1
            self._history.append((segment_id, vec, dur, 1))
            return SpeakerDecision("candidate", 1, sims[0], None, embed_ms, "new_speaker")

        if can_open:
            self._update(best, vec)
        self._last_index = best
        self._history.append((segment_id, vec, dur, best))
        margin = (sims[best] - min(sims)) if len(sims) > 1 else None
        return SpeakerDecision(LABELS[best], best, sims[best], margin, embed_ms, "assigned" if can_open else "assigned_short")

    def peek(self, audio: np.ndarray) -> SpeakerDecision:
        """Label without learning: used for partials so the same audio is not counted twice."""
        if not self.centroids or len(audio) < int(self.min_embed_s * SAMPLE_RATE):
            return self._inherit("peek_short")
        t0 = time.time()
        vec = self.embed(audio)
        sims = [float(vec @ c) for c in self.centroids]
        best = int(np.argmax(sims))
        if len(self.centroids) == 1 and sims[0] < self.new_speaker_threshold:
            return SpeakerDecision("candidate", 1, sims[0], None, int((time.time() - t0) * 1000), "peek_new")
        margin = (sims[best] - min(sims)) if len(sims) > 1 else None
        return SpeakerDecision(LABELS[best], best, sims[best], margin, int((time.time() - t0) * 1000), "peek")

    def finalize(self, iterations: int = 10) -> dict[int, str]:
        """
        Re-cluster all stored embeddings into two speakers (2-means, cosine).
        The cluster holding the first segment that was long enough to open a
        centroid is the interviewer. Returns {segment_id: new_label} for
        segments whose label differs from the online decision.
        """
        if len(self._history) < 2:
            return {}
        X = np.stack([h[1] for h in self._history])
        # Init from the online centroids when both exist, else the farthest pair.
        if len(self.centroids) == 2:
            C = np.stack(self.centroids)
        else:
            i0 = 0
            i1 = int(np.argmin(X @ X[i0]))
            C = np.stack([X[i0], X[i1]])
        for _ in range(iterations):
            labels = np.argmax(X @ C.T, axis=1)
            newC = []
            for k in range(2):
                members = X[labels == k]
                if len(members) == 0:
                    newC.append(C[k])
                    continue
                m = members.mean(axis=0)
                newC.append(m / (np.linalg.norm(m) + 1e-9))
            newC = np.stack(newC)
            if np.allclose(newC, C, atol=1e-5):
                break
            C = newC
        labels = np.argmax(X @ C.T, axis=1)

        # Which cluster is the interviewer? The first substantial segment.
        first_idx = next((i for i, h in enumerate(self._history) if h[2] >= self.min_open_s), 0)
        interviewer_cluster = int(labels[first_idx])

        changes: dict[int, str] = {}
        for (segment_id, _vec, _dur, online_idx), k in zip(self._history, labels):
            new_label = "interviewer" if k == interviewer_cluster else "candidate"
            old_label = LABELS[online_idx] if online_idx is not None else "unknown"
            if new_label != old_label:
                changes[segment_id] = new_label
        return changes

    @property
    def current_label(self) -> str:
        return LABELS[self._last_index] if self._last_index is not None else "unknown"

    def reset(self) -> None:
        self.centroids, self.counts, self._last_index, self._history = [], [], None, []

    # ----------------------------------------------------------- internals

    def _inherit(self, reason: str) -> SpeakerDecision:
        idx = self._last_index
        return SpeakerDecision(LABELS[idx] if idx is not None else "unknown", idx, None, None, 0, reason)

    def _add(self, vec: np.ndarray) -> None:
        self.centroids.append(vec.copy())
        self.counts.append(1)

    def _update(self, i: int, vec: np.ndarray) -> None:
        n = self.counts[i]
        c = (self.centroids[i] * n + vec) / (n + 1)
        self.centroids[i] = c / (np.linalg.norm(c) + 1e-9)
        self.counts[i] = n + 1
