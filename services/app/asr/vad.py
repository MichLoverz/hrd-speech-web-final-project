"""
Frame-level voice activity detection using the Silero VAD v5 model that
ships inside faster-whisper (ONNX, runs on onnxruntime, no PyTorch needed).

faster-whisper's own SileroVADModel.__call__ is batch-only and resets the
recurrent state on every call, so for streaming we drive the two ONNX
sessions ourselves: one 512-sample frame (32 ms at 16 kHz) per call, with
the LSTM state and the 64-sample context carried over between calls.

Tensor shapes (checked against faster-whisper 1.2.0):
  encoder  input  (1, 64 + 512)  ->  output (1, 128, 1)
  decoder  input  (1, 128), state (2, 1, 128)  ->  prob (1, 1, 1), new state (2, 1, 128)
"""

from __future__ import annotations

import os

import numpy as np
from faster_whisper.vad import SileroVADModel, get_assets_path

from app.config import VAD_FRAME_SAMPLES

CONTEXT_SAMPLES = 64


class StreamingVad:
    def __init__(self):
        assets = get_assets_path()
        self.model = SileroVADModel(
            os.path.join(assets, "silero_encoder_v5.onnx"),
            os.path.join(assets, "silero_decoder_v5.onnx"),
        )
        self.reset()

    def reset(self) -> None:
        self._state = np.zeros((2, 1, 128), dtype=np.float32)
        self._context = np.zeros((1, CONTEXT_SAMPLES), dtype=np.float32)

    def probability(self, frame: np.ndarray) -> float:
        """Speech probability in [0, 1] for one 512-sample float32 frame."""
        if frame.shape[0] != VAD_FRAME_SAMPLES:
            raise ValueError(f"expected {VAD_FRAME_SAMPLES} samples, got {frame.shape[0]}")

        x = np.concatenate([self._context, frame.astype(np.float32).reshape(1, -1)], axis=1)
        encoded = self.model.encoder_session.run(None, {"input": x})[0]          # (1, 128, 1)
        prob, self._state = self.model.decoder_session.run(
            None, {"input": encoded.reshape(1, 128), "state": self._state}
        )
        # The last 64 samples of this frame become the context for the next one.
        self._context = x[:, -CONTEXT_SAMPLES:]
        return float(prob.reshape(-1)[0])
