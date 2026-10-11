"""The interface every camera backend implements."""

from __future__ import annotations

import abc
from typing import Any

from pinhole.camera.types import CameraConfig, CameraDevice, CameraInfo, FrameSink, StillResult


class CameraError(RuntimeError):
    """A camera problem worth showing to the user (with a hint how to fix it)."""

    def __init__(self, message: str, hint: str = "") -> None:
        super().__init__(message)
        self.hint = hint


class CameraBackend(abc.ABC):
    name: str = "base"

    def __init__(self, device: CameraDevice, config: CameraConfig, sink: FrameSink) -> None:
        self.device = device
        self.config = config
        self.sink = sink

    @abc.abstractmethod
    def start(self) -> CameraInfo:
        """Open the camera and start streaming. Blocks until frames flow or raises."""

    @abc.abstractmethod
    def stop(self) -> None:
        """Stop streaming and release the camera. Must be idempotent."""

    @abc.abstractmethod
    def apply_controls(self, controls: dict[str, Any]) -> dict[str, Any]:
        """Apply image controls live. Returns the controls that were applied."""

    @abc.abstractmethod
    def capture_still(self, quality: int, full_resolution: bool) -> StillResult:
        """Return a JPEG still. ``full_resolution`` may briefly pause the stream."""

    def set_overlay(self, overlay: Any) -> None:
        self.config.overlay = overlay

    def autofocus(self) -> bool:
        return False

    def request_keyframe(self) -> None:
        """Ask the encoder for an IDR frame as soon as possible (best effort)."""

    @property
    def supports_live_overlay(self) -> bool:
        return True
