"""Burn a text label (camera name, date and time) into I420 frames.

The label is rendered once per distinct text with Pillow and blended into the
luma plane with a darkened, neutral box behind it, which stays readable on any
background and costs well under a millisecond per frame.
"""

from __future__ import annotations

import time
from collections import OrderedDict
from pathlib import Path
from typing import TYPE_CHECKING

import numpy as np

if TYPE_CHECKING:
    from pinhole.camera.types import OverlayConfig

FONT_CANDIDATES = (
    "/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf",
    "/usr/share/fonts/truetype/liberation/LiberationMono-Bold.ttf",
    "/usr/share/fonts/TTF/DejaVuSansMono-Bold.ttf",
)


def expand_text(template: str, name: str, now: float | None = None) -> str:
    text = template.replace("{name}", name.replace("%", "%%"))
    try:
        return time.strftime(text, time.localtime(now))
    except ValueError:
        return text


class OverlayRenderer:
    def __init__(self) -> None:
        self._cache: OrderedDict[tuple[str, int], np.ndarray] = OrderedDict()
        self._fonts: dict[int, object] = {}

    def _font(self, size: int) -> object:
        font = self._fonts.get(size)
        if font is None:
            from PIL import ImageFont

            for candidate in FONT_CANDIDATES:
                if Path(candidate).exists():
                    font = ImageFont.truetype(candidate, size)
                    break
            else:
                try:
                    font = ImageFont.load_default(size=size)
                except TypeError:  # Pillow < 10.1
                    font = ImageFont.load_default()
            self._fonts[size] = font
        return font

    def _mask(self, text: str, size: int) -> np.ndarray:
        key = (text, size)
        mask = self._cache.get(key)
        if mask is not None:
            self._cache.move_to_end(key)
            return mask
        from PIL import Image, ImageDraw

        font = self._font(size)
        probe = ImageDraw.Draw(Image.new("L", (1, 1)))
        left, top, right, bottom = probe.textbbox((0, 0), text, font=font)  # type: ignore[arg-type]
        pad = max(2, size // 3)
        width = max(1, right - left) + 2 * pad
        height = max(1, bottom - top) + 2 * pad
        width += width % 2
        height += height % 2
        image = Image.new("L", (width, height), 0)
        ImageDraw.Draw(image).text((pad - left, pad - top), text, fill=255, font=font)  # type: ignore[arg-type]
        mask = np.asarray(image, dtype=np.uint8)
        self._cache[key] = mask
        if len(self._cache) > 8:
            self._cache.popitem(last=False)
        return mask

    def draw(
        self,
        y: np.ndarray,
        u: np.ndarray,
        v: np.ndarray,
        config: OverlayConfig,
        now: float | None = None,
    ) -> None:
        if not config.enabled or not config.text.strip():
            return
        frame_h, frame_w = y.shape[0], y.shape[1]
        size = max(10, round(frame_h * 0.03 * config.scale))
        mask = self._mask(expand_text(config.text, config.name, now), size)
        box_h, box_w = mask.shape
        box_w, box_h = min(box_w, frame_w - frame_w % 2), min(box_h, frame_h - frame_h % 2)
        if box_w < 2 or box_h < 2:
            return
        mask = mask[:box_h, :box_w]
        margin = max(2, round(frame_h * 0.02))
        margin -= margin % 2
        x0 = margin if "left" in config.position else frame_w - box_w - margin
        y0 = margin if "top" in config.position else frame_h - box_h - margin
        x0, y0 = max(0, x0 - x0 % 2), max(0, y0 - y0 % 2)

        region = y[y0 : y0 + box_h, x0 : x0 + box_w]
        alpha = mask.astype(np.uint16)
        dark = (region.astype(np.uint16) * 90) >> 8  # darken the box to ~35 %
        region[...] = ((dark * (255 - alpha) + 235 * alpha) // 255).astype(np.uint8)

        cy0, cx0, ch, cw = y0 // 2, x0 // 2, box_h // 2, box_w // 2
        for plane in (u, v):
            sub = plane[cy0 : cy0 + ch, cx0 : cx0 + cw].astype(np.int16)
            plane[cy0 : cy0 + ch, cx0 : cx0 + cw] = (128 + ((sub - 128) * 90 >> 8)).astype(np.uint8)
