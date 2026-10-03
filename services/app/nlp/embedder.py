"""
Local sentence embeddings for the scorecard pipeline (ADR-004): evidence
retrieval and the similarity tier of quote verification.

ONNX through fastembed, so still no PyTorch in this service. Models load on
first use, not at startup: the ASR service starts and streams exactly as
before when the scorecard is never run, and an interview never waits for an
embedding model to load.

Vectors are L2-normalised, so cosine similarity is a plain dot product on
the caller's side.
"""

from __future__ import annotations

import os
import threading
import time

import numpy as np

from app.config import MODELS_DIR

DEFAULT_EMBED_MODEL = os.environ.get(
    "HIRENOTE_EMBED_MODEL", "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2")
EMBED_CACHE_DIR = MODELS_DIR / "embed"         # git-ignored with the rest of services/models/

# Candidates for the embedding sub-experiment (ADR-008). Kept to an explicit list so a request
# cannot make the service download an arbitrary model.
ALLOWED_MODELS = (
    "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2",   # 0.22 GB, 384-d
    "sentence-transformers/paraphrase-multilingual-mpnet-base-v2",   # 1.0 GB, 768-d
    "intfloat/multilingual-e5-large",                                # 2.24 GB, 1024-d
)

# Some models were trained with role prefixes and are measurably worse without them.
# fastembed's dense models do not add these, so it is done here.
PREFIXES: dict[str, dict[str, str]] = {
    "intfloat/multilingual-e5-large": {"query": "query: ", "passage": "passage: "},
}


class Embedder:
    def __init__(self, model_name: str = DEFAULT_EMBED_MODEL, threads: int | None = None) -> None:
        from fastembed import TextEmbedding  # imported here so the ASR-only path never pays for it

        t0 = time.time()
        self.name = model_name
        self.model = TextEmbedding(model_name, cache_dir=str(EMBED_CACHE_DIR), threads=threads)
        self.load_s = time.time() - t0
        self._lock = threading.Lock()          # one ONNX session per model; keep calls serial
        self.dim: int | None = None

    def embed(self, texts: list[str], kind: str = "passage") -> np.ndarray:
        prefix = PREFIXES.get(self.name, {}).get(kind, "")
        with self._lock:
            vectors = np.vstack(list(self.model.embed([prefix + t for t in texts]))).astype(np.float32)
        vectors /= np.clip(np.linalg.norm(vectors, axis=1, keepdims=True), 1e-12, None)
        self.dim = vectors.shape[1]
        return vectors


_EMBEDDERS: dict[str, Embedder] = {}
_LOAD_LOCK = threading.Lock()


def get_embedder(model_name: str | None = None) -> Embedder:
    name = model_name or DEFAULT_EMBED_MODEL
    if name not in ALLOWED_MODELS:
        raise ValueError(f"embedding model {name!r} is not in ALLOWED_MODELS")
    with _LOAD_LOCK:
        if name not in _EMBEDDERS:
            _EMBEDDERS[name] = Embedder(name)
            print(f"[nlp] {name} loaded in {_EMBEDDERS[name].load_s:.1f}s")
        return _EMBEDDERS[name]


def loaded_models() -> list[str]:
    return list(_EMBEDDERS)
