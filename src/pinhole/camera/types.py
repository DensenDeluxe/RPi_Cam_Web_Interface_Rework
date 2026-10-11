"""Data types shared by every camera backend and the media pipeline."""

from __future__ import annotations

import time
from dataclasses import asdict, dataclass, field
from typing import TYPE_CHECKING, Any, Literal, Protocol

if TYPE_CHECKING:
    import numpy as np


@dataclass(slots=True, frozen=True)
class EncodedPacket:
    """One H.264 access unit in Annex B format."""

    data: bytes
    keyframe: bool
    pts: int  # microseconds, monotonic within one stream session
    wallclock: float = field(default_factory=time.time)


@dataclass(slots=True)
class LoresFrame:
    """A small YUV 4:2:0 (I420) frame used for motion detection and MJPEG."""

    yuv: np.ndarray  # shape (height * 3 // 2, width), contiguous I420
    width: int
    height: int
    pts: int
    wallclock: float = field(default_factory=time.time)

    def planes(self) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
        w, h = self.width, self.height
        y = self.yuv[:h]
        chroma = self.yuv[h:].reshape(-1)
        quarter = (w // 2) * (h // 2)
        u = chroma[:quarter].reshape(h // 2, w // 2)
        v = chroma[quarter : 2 * quarter].reshape(h // 2, w // 2)
        return y, u, v

    @property
    def luma(self) -> np.ndarray:
        return self.yuv[: self.height]


@dataclass(slots=True, frozen=True)
class StreamInfo:
    width: int
    height: int
    fps: float
    session: int  # changes whenever the encoder restarts


ControlType = Literal["range", "bool", "enum", "action"]


@dataclass(slots=True)
class ControlSpec:
    """Describes one adjustable camera setting for the generic settings UI."""

    id: str
    label: str
    type: ControlType
    group: str = "image"
    min: float | None = None
    max: float | None = None
    step: float | None = None
    default: Any = None
    options: list[dict[str, Any]] | None = None
    unit: str | None = None
    depends: dict[str, Any] | None = None  # only shown when another control has this value
    advanced: bool = False

    def as_dict(self) -> dict[str, Any]:
        return {k: v for k, v in asdict(self).items() if v is not None}


@dataclass(slots=True)
class SensorMode:
    width: int
    height: int
    fps: float
    bit_depth: int | None = None


@dataclass(slots=True)
class CameraInfo:
    backend: str
    model: str
    device: str
    sensor_width: int
    sensor_height: int
    max_video_width: int = 1920
    max_video_height: int = 1080
    max_fps: float = 30
    modes: list[SensorMode] = field(default_factory=list)
    controls: list[ControlSpec] = field(default_factory=list)
    rotations: list[int] = field(default_factory=lambda: [0, 180])
    features: dict[str, bool] = field(default_factory=dict)
    hardware_encoder: bool = False

    def as_dict(self) -> dict[str, Any]:
        data = asdict(self)
        data["controls"] = [c.as_dict() for c in self.controls]
        return data


@dataclass(slots=True, frozen=True)
class CameraDevice:
    """A camera Pinhole found on the system."""

    source: str  # e.g. "picamera2:0", "v4l2:/dev/video0", "synthetic"
    backend: str
    name: str
    detail: str = ""

    def as_dict(self) -> dict[str, Any]:
        return asdict(self)


@dataclass(slots=True)
class OverlayConfig:
    enabled: bool = True
    text: str = "%Y-%m-%d %H:%M:%S"
    position: str = "top-left"
    scale: float = 1.0
    name: str = "Pinhole"


@dataclass(slots=True)
class CameraConfig:
    width: int = 1920
    height: int = 1080
    fps: int = 30
    bitrate: int = 6_000_000
    profile: str = "main"
    rotation: int = 0
    hflip: bool = False
    vflip: bool = False
    lores_width: int = 640
    lores_fps: int = 10
    controls: dict[str, Any] = field(default_factory=dict)
    overlay: OverlayConfig = field(default_factory=OverlayConfig)

    @property
    def lores_size(self) -> tuple[int, int]:
        width = min(self.lores_width, self.width)
        width -= width % 32 or 0
        width = max(width, 160)
        height = round(width * self.height / self.width)
        height -= height % 2
        return width, height

    @property
    def gop(self) -> int:
        # One keyframe per second keeps WebRTC start-up and pre-record precise.
        return max(1, self.fps)


class FrameSink(Protocol):
    def on_stream(self, info: StreamInfo) -> None: ...
    def on_video(self, packet: EncodedPacket) -> None: ...
    def on_lores(self, frame: LoresFrame) -> None: ...
    def on_error(self, error: BaseException) -> None: ...


@dataclass(slots=True, frozen=True)
class StillResult:
    data: bytes
    width: int
    height: int
    metadata: dict[str, Any] = field(default_factory=dict)
