"""Unified image controls and their translation to libcamera.

The web UI only knows the unified control ids below. Each backend reports
which of them it supports (with real limits) through ``ControlSpec`` objects,
and translates the values to its own API.
"""

from __future__ import annotations

from typing import Any

from pinhole.camera.types import ControlSpec

AWB_MODES = ["auto", "incandescent", "tungsten", "fluorescent", "indoor", "daylight", "cloudy", "manual"]
METERING = ["centre", "spot", "matrix"]
EXPOSURE_MODES = ["normal", "short", "long"]
DENOISE = ["off", "fast", "high_quality", "minimal"]
AF_MODES = ["manual", "auto", "continuous"]
AF_RANGES = ["normal", "macro", "full"]
HDR_MODES = ["off", "single", "multi", "night"]
FLICKER = ["off", "50", "60", "auto"]

_LIBCAMERA_ENUMS: dict[str, dict[str, int]] = {
    "AwbMode": {
        "auto": 0,
        "incandescent": 1,
        "tungsten": 2,
        "fluorescent": 3,
        "indoor": 4,
        "daylight": 5,
        "cloudy": 6,
    },
    "AeMeteringMode": {"centre": 0, "spot": 1, "matrix": 2},
    "AeExposureMode": {"normal": 0, "short": 1, "long": 2},
    "NoiseReductionMode": {"off": 0, "fast": 1, "high_quality": 2, "minimal": 3},
    "AfMode": {"manual": 0, "auto": 1, "continuous": 2},
    "AfRange": {"normal": 0, "macro": 1, "full": 2},
    "HdrMode": {"off": 0, "single": 3, "multi": 2, "night": 4},
}


def _opts(values: list[str]) -> list[dict[str, Any]]:
    return [{"value": v, "label": v} for v in values]


def _clamp(value: float, low: float, high: float) -> float:
    return max(low, min(high, value))


def picamera2_specs(camera_controls: dict[str, tuple[Any, Any, Any]]) -> list[ControlSpec]:
    """Build the control list for a libcamera camera from ``Picamera2.camera_controls``."""
    has = camera_controls.__contains__
    specs: list[ControlSpec] = []

    def limits(name: str, low: float, high: float, fallback: tuple[float, float, float]) -> tuple[float, ...]:
        lo, hi, default = camera_controls.get(name, fallback)
        lo = float(lo) if isinstance(lo, int | float) else fallback[0]
        hi = float(hi) if isinstance(hi, int | float) else fallback[1]
        default = float(default) if isinstance(default, int | float) else fallback[2]
        return max(lo, low), min(hi, high), default

    if has("Brightness"):
        lo, hi, _ = limits("Brightness", -1, 1, (-1, 1, 0))
        specs.append(ControlSpec("brightness", "Brightness", "range", "image", lo, hi, 0.05, 0.0))
    if has("Contrast"):
        lo, hi, _ = limits("Contrast", 0, 4, (0, 32, 1))
        specs.append(ControlSpec("contrast", "Contrast", "range", "image", lo, hi, 0.05, 1.0))
    if has("Saturation"):
        lo, hi, _ = limits("Saturation", 0, 4, (0, 32, 1))
        specs.append(ControlSpec("saturation", "Saturation", "range", "image", lo, hi, 0.05, 1.0))
    if has("Sharpness"):
        lo, hi, _ = limits("Sharpness", 0, 8, (0, 16, 1))
        specs.append(ControlSpec("sharpness", "Sharpness", "range", "image", lo, hi, 0.1, 1.0))
    if has("ScalerCrop"):
        specs.append(ControlSpec("zoom", "Digital zoom", "range", "image", 1.0, 8.0, 0.1, 1.0, unit="×"))
    if has("NoiseReductionMode"):
        specs.append(
            ControlSpec("denoise", "Noise reduction", "enum", "image", default="fast", options=_opts(DENOISE))
        )
    if has("ExposureValue"):
        lo, hi, _ = limits("ExposureValue", -4, 4, (-8, 8, 0))
        specs.append(ControlSpec("ev", "Exposure compensation", "range", "exposure", lo, hi, 0.25, 0.0, unit="EV"))
    if has("AeEnable") and has("ExposureTime"):
        specs.append(ControlSpec("exposure_auto", "Automatic exposure", "bool", "exposure", default=True))
        lo, hi, _ = limits("ExposureTime", 50, 10_000_000, (100, 1_000_000, 10_000))
        specs.append(
            ControlSpec(
                "shutter_us",
                "Shutter speed",
                "range",
                "exposure",
                lo,
                hi,
                50,
                10_000,
                unit="µs",
                depends={"exposure_auto": False},
            )
        )
        if has("AnalogueGain"):
            lo, hi, _ = limits("AnalogueGain", 1, 64, (1, 16, 1))
            specs.append(
                ControlSpec(
                    "gain", "Gain (ISO)", "range", "exposure", lo, hi, 0.1, 1.0, depends={"exposure_auto": False}
                )
            )
    if has("AeMeteringMode"):
        specs.append(ControlSpec("metering", "Metering", "enum", "exposure", default="centre", options=_opts(METERING)))
    if has("AeExposureMode"):
        specs.append(
            ControlSpec(
                "exposure_mode",
                "Exposure profile",
                "enum",
                "exposure",
                default="normal",
                options=_opts(EXPOSURE_MODES),
                advanced=True,
            )
        )
    if has("AeFlickerMode"):
        specs.append(ControlSpec("anti_flicker", "Anti-flicker", "enum", "exposure", default="off", options=_opts(FLICKER)))
    if has("HdrMode"):
        specs.append(ControlSpec("hdr", "HDR", "enum", "exposure", default="off", options=_opts(HDR_MODES)))
    if has("AwbMode") or has("AwbEnable"):
        specs.append(ControlSpec("awb_mode", "White balance", "enum", "color", default="auto", options=_opts(AWB_MODES)))
        if has("ColourGains"):
            for channel in ("red", "blue"):
                specs.append(
                    ControlSpec(
                        f"gain_{channel}",
                        f"{channel.title()} gain",
                        "range",
                        "color",
                        0.5,
                        4.0,
                        0.01,
                        1.5,
                        depends={"awb_mode": "manual"},
                    )
                )
    if has("AfMode"):
        specs.append(ControlSpec("af_mode", "Focus mode", "enum", "focus", default="continuous", options=_opts(AF_MODES)))
        if has("LensPosition"):
            lo, hi, default = limits("LensPosition", 0, 32, (0, 15, 1))
            specs.append(
                ControlSpec(
                    "lens_position",
                    "Focus distance",
                    "range",
                    "focus",
                    lo,
                    hi,
                    0.05,
                    default,
                    unit="dpt",
                    depends={"af_mode": "manual"},
                )
            )
        if has("AfRange"):
            specs.append(
                ControlSpec("af_range", "Focus range", "enum", "focus", default="normal", options=_opts(AF_RANGES))
            )
        specs.append(ControlSpec("af_trigger", "Focus now", "action", "focus", depends={"af_mode": "auto"}))
    return specs


def defaults(specs: list[ControlSpec]) -> dict[str, Any]:
    return {s.id: s.default for s in specs if s.type != "action" and s.default is not None}


def sanitize(values: dict[str, Any], specs: list[ControlSpec]) -> dict[str, Any]:
    """Keep only known controls and coerce/clamp them to the reported limits."""
    by_id = {s.id: s for s in specs}
    clean: dict[str, Any] = {}
    for key, value in values.items():
        spec = by_id.get(key)
        if spec is None or spec.type == "action" or value is None:
            continue
        try:
            if spec.type == "range":
                number = float(value)
                if spec.min is not None and spec.max is not None:
                    number = _clamp(number, spec.min, spec.max)
                clean[key] = number
            elif spec.type == "bool":
                clean[key] = value if isinstance(value, bool) else str(value).lower() in {"1", "true", "on", "yes"}
            elif spec.type == "enum":
                allowed = {str(o["value"]) for o in spec.options or []}
                if str(value) in allowed:
                    clean[key] = str(value)
        except (TypeError, ValueError):
            continue
    return clean


def _enum(name: str, value: int) -> Any:
    """Return a libcamera enum member when the bindings offer one, else the int."""
    try:
        from libcamera import controls as lc

        for namespace in (lc, getattr(lc, "draft", None)):
            enum = getattr(namespace, f"{name}Enum", None) if namespace else None
            if enum is not None:
                return enum(value)
    except Exception:
        pass
    return value


def to_libcamera(
    values: dict[str, Any],
    camera_controls: dict[str, tuple[Any, Any, Any]],
    crop_max: tuple[int, int, int, int] | None = None,
) -> dict[str, Any]:
    """Translate unified control values to a libcamera control dictionary."""
    has = camera_controls.__contains__
    out: dict[str, Any] = {}
    direct = {"brightness": "Brightness", "contrast": "Contrast", "saturation": "Saturation", "sharpness": "Sharpness"}
    for key, name in direct.items():
        if key in values and has(name):
            out[name] = float(values[key])
    if "ev" in values and has("ExposureValue"):
        out["ExposureValue"] = float(values["ev"])
    if "denoise" in values and has("NoiseReductionMode"):
        out["NoiseReductionMode"] = _enum("NoiseReductionMode", _LIBCAMERA_ENUMS["NoiseReductionMode"][values["denoise"]])
    if "exposure_auto" in values and has("AeEnable"):
        auto = bool(values["exposure_auto"])
        out["AeEnable"] = auto
        if not auto:
            if has("ExposureTime"):
                out["ExposureTime"] = int(values.get("shutter_us", 10_000))
            if has("AnalogueGain"):
                out["AnalogueGain"] = float(values.get("gain", 1.0))
    if "metering" in values and has("AeMeteringMode"):
        out["AeMeteringMode"] = _enum("AeMeteringMode", _LIBCAMERA_ENUMS["AeMeteringMode"][values["metering"]])
    if "exposure_mode" in values and has("AeExposureMode"):
        out["AeExposureMode"] = _enum("AeExposureMode", _LIBCAMERA_ENUMS["AeExposureMode"][values["exposure_mode"]])
    if "anti_flicker" in values and has("AeFlickerMode"):
        mode = values["anti_flicker"]
        if mode == "off":
            out["AeFlickerMode"] = _enum("AeFlickerMode", 0)
        elif mode == "auto":
            out["AeFlickerMode"] = _enum("AeFlickerMode", 2)
        else:
            out["AeFlickerMode"] = _enum("AeFlickerMode", 1)
            if has("AeFlickerPeriod"):
                out["AeFlickerPeriod"] = 10_000 if mode == "50" else 8_333
    if "hdr" in values and has("HdrMode"):
        out["HdrMode"] = _enum("HdrMode", _LIBCAMERA_ENUMS["HdrMode"][values["hdr"]])
    if "awb_mode" in values:
        mode = values["awb_mode"]
        if mode == "manual":
            if has("AwbEnable"):
                out["AwbEnable"] = False
            if has("ColourGains"):
                out["ColourGains"] = (float(values.get("gain_red", 1.5)), float(values.get("gain_blue", 1.5)))
        else:
            if has("AwbEnable"):
                out["AwbEnable"] = True
            if has("AwbMode"):
                out["AwbMode"] = _enum("AwbMode", _LIBCAMERA_ENUMS["AwbMode"].get(mode, 0))
    if "af_mode" in values and has("AfMode"):
        out["AfMode"] = _enum("AfMode", _LIBCAMERA_ENUMS["AfMode"][values["af_mode"]])
        if values["af_mode"] == "manual" and has("LensPosition"):
            out["LensPosition"] = float(values.get("lens_position", 1.0))
    if "af_range" in values and has("AfRange"):
        out["AfRange"] = _enum("AfRange", _LIBCAMERA_ENUMS["AfRange"][values["af_range"]])
    if "zoom" in values and has("ScalerCrop") and crop_max:
        x, y, w, h = crop_max
        zoom = max(1.0, float(values["zoom"]))
        cw, ch = int(w / zoom), int(h / zoom)
        out["ScalerCrop"] = (x + (w - cw) // 2, y + (h - ch) // 2, cw, ch)
    return out
