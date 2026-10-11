"""User settings: one validated document, persisted as JSON in the data directory.

The document is edited through ``PATCH /api/settings`` with partial updates.
Listeners are told which top-level sections changed so that each subsystem
can react (re-apply camera controls live, restart the camera for a new
resolution, regenerate the MediaMTX config, ...).
"""

from __future__ import annotations

import json
import logging
import os
import re
import secrets
import tempfile
import threading
from collections.abc import Callable
from copy import deepcopy
from pathlib import Path
from typing import Annotated, Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

log = logging.getLogger(__name__)

SETTINGS_VERSION = 1

Weekday = Annotated[int, Field(ge=0, le=6)]


class _Model(BaseModel):
    model_config = ConfigDict(extra="ignore", validate_assignment=True)


def _new_id() -> str:
    return secrets.token_hex(4)


class CameraSettings(_Model):
    source: str = Field("auto", description="auto, picamera2:<n>, v4l2:<device> or synthetic")
    width: int = Field(1920, ge=160, le=4096)
    height: int = Field(1080, ge=120, le=3072)
    fps: int = Field(30, ge=1, le=120)
    bitrate_kbps: int = Field(0, ge=0, le=50_000, description="0 picks a bitrate for the resolution")
    h264_profile: Literal["baseline", "main", "high"] = "main"
    rotation: Literal[0, 90, 180, 270] = 0
    hflip: bool = False
    vflip: bool = False
    photo_resolution: Literal["max", "video"] = "max"
    jpeg_quality: int = Field(90, ge=10, le=100)
    controls: dict[str, float | int | bool | str] = Field(default_factory=dict)

    @model_validator(mode="after")
    def _even_size(self) -> CameraSettings:
        # H.264 needs even dimensions; silently round down instead of failing.
        object.__setattr__(self, "width", self.width - self.width % 2)
        object.__setattr__(self, "height", self.height - self.height % 2)
        return self

    def effective_bitrate(self) -> int:
        """Bitrate in bit/s. ~6 Mbit/s for 1080p30, scaled by pixel rate."""
        if self.bitrate_kbps:
            return self.bitrate_kbps * 1000
        pixel_rate = self.width * self.height * min(self.fps, 60)
        reference = 1920 * 1080 * 30
        return int(max(800_000, min(20_000_000, 6_000_000 * (pixel_rate / reference) ** 0.75)))


class OverlaySettings(_Model):
    enabled: bool = True
    text: str = Field("{name}  %Y-%m-%d %H:%M:%S", max_length=200)
    position: Literal["top-left", "top-right", "bottom-left", "bottom-right"] = "top-left"
    scale: float = Field(1.0, ge=0.5, le=3.0)


class StreamSettings(_Model):
    webrtc: bool = True
    rtsp: bool = True
    rtsp_auth: bool = True
    rtsp_username: str = Field("viewer", min_length=1, max_length=64, pattern=r"^[A-Za-z0-9._-]+$")
    rtsp_password: str = Field(default_factory=lambda: secrets.token_urlsafe(9), min_length=1, max_length=128)
    mjpeg_fps: int = Field(10, ge=1, le=30)
    lores_width: int = Field(640, ge=160, le=1280)


class RecordingSettings(_Model):
    pre_seconds: int = Field(3, ge=0, le=30)
    segment_minutes: int = Field(30, ge=1, le=240)


class MotionSettings(_Model):
    armed: bool = False
    sensitivity: int = Field(60, ge=1, le=100)
    min_area: float = Field(0.5, ge=0.05, le=50.0, description="percent of the active image area")
    post_seconds: int = Field(6, ge=1, le=120)
    max_clip_seconds: int = Field(120, ge=5, le=3600)
    cooldown_seconds: int = Field(0, ge=0, le=3600)
    detect_fps: int = Field(5, ge=1, le=15)
    record: bool = True
    snapshot: bool = True
    mask: str = Field("", description="base64 bitmap of excluded grid cells (32x18)")


class TimelapseSettings(_Model):
    active: bool = False
    interval_seconds: float = Field(10.0, ge=1.0, le=86_400.0)
    resolution: Literal["max", "video"] = "video"
    auto_video: bool = True
    video_fps: int = Field(25, ge=1, le=60)


TimeKind = Literal["time", "sunrise", "sunset", "dawn", "dusk"]


class TimeSpec(_Model):
    kind: TimeKind = "time"
    time: str = Field("00:00", pattern=r"^([01]\d|2[0-3]):[0-5]\d$")
    offset_minutes: int = Field(0, ge=-720, le=720)


class ScheduleRule(_Model):
    id: str = Field(default_factory=_new_id)
    name: str = Field("", max_length=80)
    enabled: bool = True
    action: Literal["motion", "timelapse", "record", "privacy", "photo"] = "motion"
    days: list[Weekday] = Field(default_factory=lambda: [0, 1, 2, 3, 4, 5, 6])
    start: TimeSpec = Field(default_factory=lambda: TimeSpec(kind="sunset"))
    end: TimeSpec = Field(default_factory=lambda: TimeSpec(kind="sunrise"))
    every_minutes: int = Field(0, ge=0, le=1440, description="photo rule: repeat interval within the window")

    @field_validator("days")
    @classmethod
    def _unique_days(cls, value: list[int]) -> list[int]:
        return sorted(set(value))


class ScheduleSettings(_Model):
    enabled: bool = True
    rules: list[ScheduleRule] = Field(default_factory=list, max_length=50)


class StorageSettings(_Model):
    min_free_mb: int = Field(1024, ge=100, le=1_000_000)
    max_usage_percent: int = Field(92, ge=50, le=99)
    keep_days_video: int = Field(30, ge=0, le=3650, description="0 keeps videos forever")
    keep_days_photo: int = Field(0, ge=0, le=3650)
    keep_days_timelapse: int = Field(0, ge=0, le=3650)
    delete_oldest_when_full: bool = True


EventName = Literal[
    "motion.start",
    "motion.end",
    "recording.start",
    "recording.end",
    "photo.taken",
    "timelapse.end",
    "camera.error",
    "storage.low",
]


class WebhookTarget(_Model):
    id: str = Field(default_factory=_new_id)
    name: str = Field("Webhook", max_length=80)
    enabled: bool = True
    url: str = Field(..., max_length=2000, pattern=r"^https?://")
    events: list[EventName] = Field(default_factory=lambda: ["motion.start"])
    secret: str = Field("", max_length=200)


class ScriptHook(_Model):
    id: str = Field(default_factory=_new_id)
    name: str = Field("Script", max_length=80)
    enabled: bool = True
    script: str = Field(..., pattern=r"^[A-Za-z0-9._-]+$", description="file name in the scripts folder")
    events: list[EventName] = Field(default_factory=lambda: ["motion.start"])


class QuickAction(_Model):
    id: str = Field(default_factory=_new_id)
    label: str = Field(..., min_length=1, max_length=40)
    icon: str = Field("zap", max_length=40)
    script: str = Field(..., pattern=r"^[A-Za-z0-9._-]+$")
    min_role: Literal["viewer", "operator", "admin"] = "operator"


class MqttSettings(_Model):
    enabled: bool = False
    host: str = Field("", max_length=255)
    port: int = Field(1883, ge=1, le=65535)
    username: str = Field("", max_length=255)
    password: str = Field("", max_length=255)
    tls: bool = False
    topic_prefix: str = Field("pinhole", pattern=r"^[A-Za-z0-9_/-]+$")
    discovery: bool = True
    discovery_prefix: str = Field("homeassistant", pattern=r"^[A-Za-z0-9_/-]+$")


class IntegrationSettings(_Model):
    webhooks: list[WebhookTarget] = Field(default_factory=list, max_length=20)
    scripts: list[ScriptHook] = Field(default_factory=list, max_length=20)
    quick_actions: list[QuickAction] = Field(default_factory=list, max_length=12)
    mqtt: MqttSettings = Field(default_factory=MqttSettings)


class SystemSettings(_Model):
    name: str = Field("Pinhole", min_length=1, max_length=60)
    language: Literal["auto", "en", "de"] = "auto"
    latitude: float | None = Field(None, ge=-90, le=90)
    longitude: float | None = Field(None, ge=-180, le=180)
    anonymous_view: bool = False
    check_updates: bool = True


class Settings(_Model):
    version: int = SETTINGS_VERSION
    camera: CameraSettings = Field(default_factory=CameraSettings)
    overlay: OverlaySettings = Field(default_factory=OverlaySettings)
    stream: StreamSettings = Field(default_factory=StreamSettings)
    recording: RecordingSettings = Field(default_factory=RecordingSettings)
    motion: MotionSettings = Field(default_factory=MotionSettings)
    timelapse: TimelapseSettings = Field(default_factory=TimelapseSettings)
    schedule: ScheduleSettings = Field(default_factory=ScheduleSettings)
    storage: StorageSettings = Field(default_factory=StorageSettings)
    integrations: IntegrationSettings = Field(default_factory=IntegrationSettings)
    system: SystemSettings = Field(default_factory=SystemSettings)


SECTIONS = tuple(name for name in Settings.model_fields if name != "version")

Listener = Callable[[Settings, Settings, set[str]], None]


def deep_merge(base: dict[str, Any], patch: dict[str, Any]) -> dict[str, Any]:
    """Merge ``patch`` into a copy of ``base``. Lists and scalars are replaced."""
    result = deepcopy(base)
    for key, value in patch.items():
        if isinstance(value, dict) and isinstance(result.get(key), dict) and key != "controls":
            result[key] = deep_merge(result[key], value)
        elif isinstance(value, dict) and key == "controls" and isinstance(result.get(key), dict):
            merged = dict(result[key])
            for control, control_value in value.items():
                if control_value is None:
                    merged.pop(control, None)
                else:
                    merged[control] = control_value
            result[key] = merged
        else:
            result[key] = deepcopy(value)
    return result


def changed_paths(old: dict[str, Any], new: dict[str, Any], prefix: str = "") -> set[str]:
    """Dotted paths of every leaf that differs between two settings dicts."""
    paths: set[str] = set()
    for key in set(old) | set(new):
        path = f"{prefix}{key}"
        a, b = old.get(key), new.get(key)
        if isinstance(a, dict) and isinstance(b, dict) and key != "controls":
            paths |= changed_paths(a, b, path + ".")
        elif a != b:
            paths.add(path)
    return paths


def _migrate(raw: dict[str, Any]) -> dict[str, Any]:
    version = int(raw.get("version", 1))
    # Future migrations go here: ``if version < 2: ...``.
    raw["version"] = max(version, SETTINGS_VERSION)
    return raw


class SettingsStore:
    """Thread-safe holder of the current settings with atomic persistence."""

    def __init__(self, path: Path) -> None:
        self._path = path
        self._lock = threading.RLock()
        self._listeners: list[Listener] = []
        self._settings = self._load()

    def _load(self) -> Settings:
        if self._path.exists():
            try:
                raw = json.loads(self._path.read_text("utf-8"))
                settings = Settings.model_validate(_migrate(raw))
                return settings
            except (ValueError, OSError) as exc:
                backup = self._path.with_suffix(".invalid.json")
                log.error("settings file is invalid (%s); keeping a copy at %s and using defaults", exc, backup)
                try:
                    self._path.replace(backup)
                except OSError:
                    pass
        settings = Settings()
        self._write(settings)
        return settings

    def _write(self, settings: Settings) -> None:
        self._path.parent.mkdir(parents=True, exist_ok=True)
        data = settings.model_dump_json(indent=2)
        fd, tmp = tempfile.mkstemp(prefix=".settings-", dir=self._path.parent)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                handle.write(data)
                handle.flush()
                os.fsync(handle.fileno())
            os.chmod(tmp, 0o600)
            os.replace(tmp, self._path)
        except BaseException:
            Path(tmp).unlink(missing_ok=True)
            raise

    @property
    def current(self) -> Settings:
        return self._settings

    def subscribe(self, listener: Listener) -> None:
        self._listeners.append(listener)

    def update(self, patch: dict[str, Any]) -> tuple[Settings, set[str]]:
        """Apply a partial update. Raises ``pydantic.ValidationError`` on bad input."""
        with self._lock:
            old = self._settings
            old_dict = old.model_dump(mode="json")
            merged = deep_merge(old_dict, {k: v for k, v in patch.items() if k in SECTIONS})
            new = Settings.model_validate(merged)
            paths = changed_paths(old_dict, new.model_dump(mode="json"))
            if not paths:
                return old, paths
            self._write(new)
            self._settings = new
        for listener in list(self._listeners):
            try:
                listener(old, new, paths)
            except Exception:
                log.exception("settings listener failed")
        return new, paths

    def replace(self, document: dict[str, Any]) -> tuple[Settings, set[str]]:
        """Replace everything (used by restore). Unknown keys are dropped."""
        new = Settings.model_validate(_migrate(dict(document)))
        patch = new.model_dump(mode="json")
        return self.update(patch)

    def reset(self, section: str | None = None) -> tuple[Settings, set[str]]:
        defaults = Settings().model_dump(mode="json")
        if section:
            if section not in SECTIONS:
                raise KeyError(section)
            patch = {section: defaults[section]}
            if section == "stream":
                # Keep the RTSP password stable – NVRs would lose access otherwise.
                patch["stream"]["rtsp_password"] = self._settings.stream.rtsp_password
        else:
            patch = {k: defaults[k] for k in SECTIONS}
            patch["stream"]["rtsp_password"] = self._settings.stream.rtsp_password
        if "camera" in patch:
            # replace semantics for controls: send every current control as None
            patch["camera"]["controls"] = dict.fromkeys(self._settings.camera.controls)
        return self.update(patch)


def section_changed(paths: set[str], *prefixes: str) -> bool:
    return any(p == prefix or p.startswith(prefix + ".") for p in paths for prefix in prefixes)


_SAFE_NAME = re.compile(r"[^A-Za-z0-9._-]+")


def safe_filename(name: str) -> str:
    return _SAFE_NAME.sub("_", name).strip("._") or "file"
