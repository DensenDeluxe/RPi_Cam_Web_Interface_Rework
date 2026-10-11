"""JPEG encoding of I420 frames (simplejpeg when available, Pillow otherwise)."""

from __future__ import annotations

import io
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    import numpy as np

try:  # simplejpeg ships with Picamera2 and is several times faster than Pillow
    import simplejpeg
except ImportError:  # pragma: no cover - depends on the platform
    simplejpeg = None


def encode_i420(y: np.ndarray, u: np.ndarray, v: np.ndarray, quality: int = 85) -> bytes:
    if simplejpeg is not None:
        return bytes(simplejpeg.encode_jpeg_yuv_planes(y, u, v, quality=quality))
    from PIL import Image

    height, width = y.shape
    luma = Image.fromarray(y.copy(), "L")
    cb = Image.fromarray(u.copy(), "L").resize((width, height))
    cr = Image.fromarray(v.copy(), "L").resize((width, height))
    buffer = io.BytesIO()
    Image.merge("YCbCr", (luma, cb, cr)).save(buffer, "JPEG", quality=quality)
    return buffer.getvalue()


def add_exif(jpeg: bytes, make: str, model: str, when: float) -> bytes:
    """Insert basic EXIF (camera and capture time) so photo apps sort correctly."""
    try:
        import time

        import piexif

        stamp = time.strftime("%Y:%m:%d %H:%M:%S", time.localtime(when))
        exif = piexif.dump(
            {
                "0th": {
                    piexif.ImageIFD.Make: make.encode(),
                    piexif.ImageIFD.Model: model.encode(),
                    piexif.ImageIFD.Software: b"Pinhole",
                    piexif.ImageIFD.DateTime: stamp.encode(),
                },
                "Exif": {piexif.ExifIFD.DateTimeOriginal: stamp.encode()},
            }
        )
        output = io.BytesIO()
        piexif.insert(exif, jpeg, output)
        return output.getvalue()
    except Exception:
        return jpeg
