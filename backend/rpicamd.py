#!/usr/bin/env python3
"""rpicamd - camera daemon for the RPi Cam Web Interface.

Drop-in replacement for raspimjpeg. It reads the same configuration (/etc/raspimjpeg plus the
user_config file), accepts the same commands on the control FIFO, writes the same status file and
preview JPEG and stores images, videos and timelapses with the same names and thumbnails, so the web
interface and the scheduler work unchanged.

Camera backends:
  picamera2  libcamera via Picamera2: every Raspberry Pi camera (Camera Module 1/2/3, HQ, Global
             Shutter, AI Camera, libcamera third party cameras such as Arducam) on every Raspberry Pi
             (Pi 1-5, Zero, Compute Modules) with Raspberry Pi OS Bullseye or newer, 32 or 64-bit.
  v4l2       USB/UVC webcams and V4L2 cameras of other boards, via OpenCV and ffmpeg.
  test       synthetic picture, for development without a camera.

Usage: rpicamd [-c /etc/raspimjpeg] [--backend auto|picamera2|v4l2|test]
Started as /usr/bin/raspimjpeg (symlink) the process keeps the name raspimjpeg, so start.sh,
stop.sh and rc.local work unchanged.
"""

import argparse
import collections
import datetime
import json
import os
import queue
import re
import select
import shlex
import shutil
import signal
import subprocess
import sys
import threading
import time

try:
    import numpy as np
except ImportError:  # numpy is needed for motion detection and the frame backends
    np = None

try:
    import cv2
except ImportError:
    cv2 = None

try:
    import simplejpeg
except ImportError:
    simplejpeg = None

VERSION = "1.0.0"

# Defaults follow etc/raspimjpeg/raspimjpeg.1, values from the config files override them.
DEFAULTS = {
    "annotation": "RPi Cam %Y.%M.%D_%h:%m:%s", "anno_background": "0",
    "anno3_custom_background_colour": "0", "anno3_custom_background_Y": "0",
    "anno3_custom_background_U": "128", "anno3_custom_background_V": "128",
    "anno3_custom_text_colour": "0", "anno3_custom_text_Y": "255",
    "anno3_custom_text_U": "128", "anno3_custom_text_V": "128", "anno_text_size": "50",
    "sharpness": "0", "contrast": "0", "brightness": "50", "saturation": "0", "iso": "0",
    "metering_mode": "average", "video_stabilisation": "0", "exposure_compensation": "0",
    "exposure_mode": "auto", "white_balance": "auto", "autowbgain_r": "150", "autowbgain_b": "150",
    "image_effect": "none", "colour_effect_en": "0", "colour_effect_u": "128", "colour_effect_v": "128",
    "rotation": "0", "hflip": "0", "vflip": "0", "sensor_region_x": "0", "sensor_region_y": "0",
    "sensor_region_w": "65536", "sensor_region_h": "65536", "shutter_speed": "0", "raw_layer": "0",
    "stat_pass": "0", "camera_num": "0", "hdmi_preview": "0",
    "minimise_frag": "0", "initial_quant": "25", "encode_qp": "31",
    "width": "512", "quality": "10", "divider": "1",
    "video_width": "1920", "video_height": "1080", "video_fps": "25", "fps_divider": "1",
    "video_bitrate": "17000000", "video_buffer": "0", "video_split": "0",
    "MP4Box": "background", "MP4Box_fps": "25",
    "image_width": "2592", "image_height": "1944", "image_quality": "10", "tl_interval": "30",
    "motion_external": "0", "vector_preview": "0", "motion_noise": "1010", "motion_threshold": "250",
    "motion_clip": "0", "motion_image": "", "motion_initframes": "0", "motion_startframes": "3",
    "motion_stopframes": "150", "motion_pipe": "/var/www/FIFO1", "motion_file": "0",
    "base_path": "/var/www", "preview_path": "/dev/shm/mjpeg/cam.jpg",
    "image_path": "/var/www/media/im_%i_%Y%M%D_%h%m%s.jpg",
    "lapse_path": "/var/www/media/tl_%i_%t_%Y%M%D_%h%m%s.jpg",
    "video_path": "/var/www/media/vi_%v_%Y%M%D_%h%m%s.mp4",
    "status_file": "/dev/shm/mjpeg/status_mjpeg.txt", "control_file": "/var/www/FIFO",
    "media_path": "/var/www/media", "macros_path": "/var/www/macros",
    "user_annotate": "/dev/shm/mjpeg/user_annotate.txt", "boxing_path": "", "subdir_char": "@",
    "count_format": "%04d",
    "error_soft": "error_soft.sh", "error_hard": "error_hard.sh", "start_img": "start_img.sh",
    "end_img": "&end_img.sh", "start_vid": "&start_vid.sh", "end_vid": "end_vid.sh",
    "end_box": "&end_box.sh", "do_cmd": "&do_cmd.sh", "motion_event": "motion_event.sh",
    "startstop": "startstop.sh",
    "thumb_gen": "vit", "autostart": "standard", "motion_detection": "0",
    "user_config": "/var/www/uconfig", "log_file": "/var/www/scheduleLog.txt", "log_size": "5000",
    "motion_logfile": "/var/www/motionLog.txt", "enforce_lf": "0", "fifo_interval": "100000",
    # rpicamd only
    "af_mode": "2", "lens_position": "0", "v4l2_device": "", "ffmpeg_encoder": "libx264",
}

# job macros in the order used by the "um" command
MACROS = ["error_soft", "error_hard", "start_img", "end_img", "start_vid", "end_vid", "end_box",
          "do_cmd", "motion_event", "startstop"]

# config keys changed by each settings command (values are taken from the arguments in this order)
SETTINGS = {
    "an": ["annotation"], "ab": ["anno_background"], "as": ["anno_text_size"],
    "at": ["anno3_custom_text_colour", "anno3_custom_text_Y", "anno3_custom_text_U", "anno3_custom_text_V"],
    "ac": ["anno3_custom_background_colour", "anno3_custom_background_Y", "anno3_custom_background_U",
           "anno3_custom_background_V"],
    "px": ["video_width", "video_height", "video_fps", "MP4Box_fps", "image_width", "image_height", "fps_divider"],
    "sh": ["sharpness"], "co": ["contrast"], "br": ["brightness"], "sa": ["saturation"], "is": ["iso"],
    "vs": ["video_stabilisation"], "ec": ["exposure_compensation"], "em": ["exposure_mode"],
    "wb": ["white_balance"], "ag": ["autowbgain_r", "autowbgain_b"], "mm": ["metering_mode"],
    "ie": ["image_effect"], "ce": ["colour_effect_en", "colour_effect_u", "colour_effect_v"],
    "ro": ["rotation"], "ri": ["sensor_region_x", "sensor_region_y", "sensor_region_w", "sensor_region_h"],
    "ss": ["shutter_speed"], "qu": ["image_quality"], "pv": ["quality", "width", "divider"],
    "bu": ["video_buffer"], "bi": ["video_bitrate"], "rl": ["raw_layer"], "st": ["stat_pass"],
    "qp": ["minimise_frag", "initial_quant", "encode_qp"], "hp": ["hdmi_preview"], "cn": ["camera_num"],
    "wd": ["watchdog_interval", "watchdog_errors"], "ls": ["log_size"], "tv": ["tl_interval"],
    "vi": ["video_split"], "vp": ["vector_preview"], "mn": ["motion_noise"], "mt": ["motion_threshold"],
    "mc": ["motion_clip"], "mi": ["motion_image"], "ms": ["motion_initframes"], "mb": ["motion_startframes"],
    "me": ["motion_stopframes"], "mf": ["motion_file"], "mx": ["motion_external"],
    "af": ["af_mode"], "lp": ["lens_position"],
}
# settings that need the camera to be reconfigured
RESTART_CMDS = {"px", "pv", "ro", "cn", "bu", "bi", "fl"}
# settings only the legacy raspimjpeg can do, reported to the web interface
UNSUPPORTED_COMMON = ["ie", "ce", "vs", "rl", "st", "hp", "qp", "wd", "mf"]


def now_str():
    return datetime.datetime.now().strftime("[%Y/%m/%d %H:%M:%S]")


def to_int(value, default=0):
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return default


def to_float(value, default=0.0):
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def to_bool(value):
    return str(value).strip().lower() in ("1", "true", "on", "yes")


def write_atomic(path, data):
    tmp = path + ".tmp"
    mode = "wb" if isinstance(data, (bytes, bytearray)) else "w"
    with open(tmp, mode) as f:
        f.write(data)
    os.replace(tmp, path)


def yuv_to_bgr(y, u, v):
    y, u, v = float(y), float(u) - 128, float(v) - 128
    r = y + 1.402 * v
    g = y - 0.344136 * u - 0.714136 * v
    b = y + 1.772 * u
    return tuple(int(max(0, min(255, round(c)))) for c in (b, g, r))


def encode_jpeg_bgr(frame, quality):
    quality = max(1, min(100, int(quality)))
    if simplejpeg is not None:
        return simplejpeg.encode_jpeg(np.ascontiguousarray(frame[:, :, :3]), quality=quality, colorspace="BGR")
    if cv2 is not None:
        ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, quality])
        return buf.tobytes() if ok else None
    from PIL import Image
    import io
    out = io.BytesIO()
    Image.fromarray(frame[:, :, 2::-1]).save(out, "JPEG", quality=quality)
    return out.getvalue()


def encode_jpeg_yuv420(arr, width, height, quality):
    """JPEG from a planar YUV420 array as returned by Picamera2 (shape h*3/2 x stride)."""
    quality = max(1, min(100, int(quality)))
    y = arr[:height, :width]
    if simplejpeg is not None:
        stride = arr.shape[1]
        flat = arr.reshape(-1)
        u_start = height * stride
        u = flat[u_start:u_start + height * stride // 4].reshape(height // 2, stride // 2)[:, :width // 2]
        v_start = u_start + height * stride // 4
        v = flat[v_start:v_start + height * stride // 4].reshape(height // 2, stride // 2)[:, :width // 2]
        return simplejpeg.encode_jpeg_yuv_planes(np.ascontiguousarray(y), np.ascontiguousarray(u),
                                                 np.ascontiguousarray(v), quality=quality)
    if cv2 is not None:
        bgr = cv2.cvtColor(np.ascontiguousarray(arr[:height * 3 // 2, :width]), cv2.COLOR_YUV420p2BGR)
        return encode_jpeg_bgr(bgr, quality)
    return encode_jpeg_bgr(np.dstack([y, y, y]), quality)


class Annotation:
    """Annotation text and style from the config, drawn with OpenCV."""

    def __init__(self, daemon):
        self.daemon = daemon
        self.warned = False

    def spec(self):
        cfg = self.daemon.cfg
        text = cfg.get("annotation", "")
        if not text:
            return None
        if cv2 is None:
            if not self.warned:
                self.daemon.log("Annotation needs OpenCV (python3-opencv), it is not drawn")
                self.warned = True
            return None
        text = self.daemon.expand(text, annotate=True)
        size = max(5, min(99, to_int(cfg.get("anno_text_size"), 50)))
        if to_bool(cfg.get("anno3_custom_text_colour")):
            colour = yuv_to_bgr(cfg.get("anno3_custom_text_Y"), cfg.get("anno3_custom_text_U"), cfg.get("anno3_custom_text_V"))
            luma = to_int(cfg.get("anno3_custom_text_Y"), 255)
        else:
            colour, luma = (255, 255, 255), 255
        background = None
        bg_luma = 0
        if to_bool(cfg.get("anno_background")) or to_bool(cfg.get("anno3_custom_background_colour")):
            if to_bool(cfg.get("anno3_custom_background_colour")):
                background = yuv_to_bgr(cfg.get("anno3_custom_background_Y"), cfg.get("anno3_custom_background_U"),
                                        cfg.get("anno3_custom_background_V"))
                bg_luma = to_int(cfg.get("anno3_custom_background_Y"), 0)
            else:
                background = (0, 0, 0)
        return {"text": text, "size": size, "colour": colour, "luma": luma, "background": background, "bg_luma": bg_luma}

    @staticmethod
    def draw(img, spec, luma_only=False):
        """Draws the text centred at the top of a BGR(A) image or a luma plane."""
        h, w = img.shape[:2]
        scale = spec["size"] / 50.0 * h / 1080.0 * 1.6
        scale = max(0.3, scale)
        thickness = max(1, int(round(scale * 1.6)))
        font = cv2.FONT_HERSHEY_SIMPLEX
        (tw, th), base = cv2.getTextSize(spec["text"], font, scale, thickness)
        x = max(0, (w - tw) // 2)
        y = th + max(4, int(th * 0.4))
        channels = 1 if img.ndim == 2 else img.shape[2]

        def colour(bgr, lum):
            if luma_only or channels == 1:
                return int(lum)
            return tuple(bgr) + ((255,) if channels == 4 else ())

        if spec["background"] is not None:
            pad = max(2, th // 4)
            cv2.rectangle(img, (x - pad, y - th - pad), (x + tw + pad, y + base + pad),
                          colour(spec["background"], spec["bg_luma"]), -1)
        cv2.putText(img, spec["text"], (x, y), font, scale, colour(spec["colour"], spec["luma"]), thickness, cv2.LINE_AA)


# ---------------------------------------------------------------------------------------------------
# Backends
# ---------------------------------------------------------------------------------------------------

class BackendError(Exception):
    pass


class Picamera2Backend:
    """libcamera cameras through Picamera2 (hardware H.264 on Pi 1-4, software encoder on Pi 5)."""

    name = "picamera2"

    def __init__(self, daemon):
        self.daemon = daemon
        from picamera2 import Picamera2, MappedArray  # noqa: F401  (import errors select another backend)
        from picamera2.encoders import H264Encoder  # noqa: F401
        from picamera2.outputs import FileOutput  # noqa: F401
        self.cam = None
        self.lock = threading.RLock()
        self.running = False
        self.thread = None
        self.encoder = None
        self.circular = None
        self.recording = False
        self.lores_size = (0, 0)
        self.af_available = False

    @staticmethod
    def available():
        try:
            from picamera2 import Picamera2
            return len(Picamera2.global_camera_info()) > 0
        except Exception:
            return False

    def open(self, cfg, preview_cb):
        from picamera2 import Picamera2
        cameras = Picamera2.global_camera_info()
        if not cameras:
            raise BackendError("no libcamera camera found")
        num = to_int(cfg.get("camera_num"), 0)
        index = num - 1 if num > 0 else 0
        if index >= len(cameras):
            index = 0
        self.cam = Picamera2(index)
        self.preview_cb = preview_cb
        self._configure(cfg)
        self.cam.pre_callback = self._pre_callback
        self.cam.start()
        self.apply_controls(cfg)
        if to_int(cfg.get("video_buffer"), 0) > 0:
            self._start_circular(cfg)
        self.running = True
        self.thread = threading.Thread(target=self._preview_loop, args=(cfg,), daemon=True)
        self.thread.start()
        controls = self.cam.camera_controls
        self.af_available = "AfMode" in controls
        unsupported = list(UNSUPPORTED_COMMON)
        if not self.af_available:
            unsupported += ["af", "lp"]
        model = self.cam.camera_properties.get("Model", "unknown")
        size = self.cam.camera_properties.get("PixelArraySize", (0, 0))
        return {"model": model, "sensor": list(size), "autofocus": self.af_available, "unsupported": unsupported,
                "cameras": len(cameras)}

    def _transform(self, cfg):
        from libcamera import Transform
        hflip, vflip = to_bool(cfg.get("hflip")), to_bool(cfg.get("vflip"))
        rotation = to_int(cfg.get("rotation"), 0) % 360
        if rotation == 180:
            hflip, vflip = not hflip, not vflip
        elif rotation in (90, 270):
            self.daemon.log("Rotation %d is not supported by libcamera, use 0 or 180" % rotation)
        return Transform(hflip=hflip, vflip=vflip)

    def _configure(self, cfg):
        vw = max(64, to_int(cfg.get("video_width"), 1920))
        vh = max(64, to_int(cfg.get("video_height"), 1080))
        fps = max(0.1, to_float(cfg.get("video_fps"), 25))
        pw = max(64, min(1024, to_int(cfg.get("width"), 512)))
        pw = (pw // 64) * 64  # YUV420 lores without row padding
        ph = max(32, int(round(pw * vh / vw / 2.0)) * 2)
        transform = self._transform(cfg)
        self.video_config = self.cam.create_video_configuration(
            main={"size": (vw, vh), "format": "XBGR8888"}, lores={"size": (pw, ph), "format": "YUV420"},
            controls={"FrameRate": fps}, transform=transform, buffer_count=6)
        iw = max(64, to_int(cfg.get("image_width"), 2592))
        ih = max(64, to_int(cfg.get("image_height"), 1944))
        self.still_config = self.cam.create_still_configuration(main={"size": (iw, ih)}, transform=transform)
        self.cam.configure(self.video_config)
        self.lores_size = self.cam.camera_configuration()["lores"]["size"]

    def _pre_callback(self, request):
        spec = self.daemon.annotation.spec()
        if not spec:
            return
        from picamera2 import MappedArray
        for stream in ("main", "lores"):
            try:
                with MappedArray(request, stream) as m:
                    arr = m.array
                    if stream == "lores":
                        Annotation.draw(arr[:self.lores_size[1], :self.lores_size[0]], spec, luma_only=True)
                    elif arr.ndim == 3:
                        Annotation.draw(arr, spec)
            except Exception:
                pass

    def _preview_loop(self, cfg):
        divider = max(1, to_int(cfg.get("divider"), 1))
        quality = to_int(cfg.get("quality"), 10)
        w, h = self.lores_size
        n = 0
        while self.running:
            try:
                with self.lock:
                    if not self.running:
                        break
                    request = self.cam.capture_request()
                    try:
                        n += 1
                        if n % divider:
                            continue
                        arr = request.make_array("lores")
                    finally:
                        request.release()
                jpeg = encode_jpeg_yuv420(arr, w, h, quality)
                self.preview_cb(jpeg, arr[:h, :w])
            except Exception as e:
                if self.running:
                    self.daemon.log("Preview error: %s" % e)
                    time.sleep(0.5)

    def _make_encoder(self, cfg):
        bitrate = to_int(cfg.get("video_bitrate"), 0) or None
        fps = max(1, int(round(to_float(cfg.get("video_fps"), 25))))
        if os.path.exists("/dev/video11"):  # hardware encoder of the Pi 1-4 (bcm2835-codec)
            from picamera2.encoders import H264Encoder
            return H264Encoder(bitrate=bitrate, repeat=True, iperiod=fps)
        try:
            from picamera2.encoders import LibavH264Encoder
        except ImportError:
            from picamera2.encoders import H264Encoder
            return H264Encoder(bitrate=bitrate, repeat=True, iperiod=fps)
        try:
            return LibavH264Encoder(bitrate=bitrate, repeat=True, iperiod=fps, framerate=fps)
        except TypeError:
            return LibavH264Encoder(bitrate=bitrate)

    def _start_circular(self, cfg):
        from picamera2.outputs import CircularOutput
        fps = max(1, to_float(cfg.get("video_fps"), 25))
        frames = max(1, int(fps * to_int(cfg.get("video_buffer"), 0) / 1000.0))
        self.circular = CircularOutput(buffersize=frames)
        self.encoder = self._make_encoder(cfg)
        self.cam.start_encoder(self.encoder, self.circular)

    def _stop_encoder(self):
        try:
            self.cam.stop_encoder(self.encoder)
        except TypeError:
            self.cam.stop_encoder()

    def start_recording(self, path, cfg):
        if self.circular is not None:
            self.circular.fileoutput = path
            self.circular.start()
        else:
            from picamera2.outputs import FileOutput
            self.encoder = self._make_encoder(cfg)
            self.cam.start_encoder(self.encoder, FileOutput(path))
        self.recording = True

    def stop_recording(self):
        if not self.recording:
            return
        if self.circular is not None:
            self.circular.stop()
        else:
            self._stop_encoder()
            self.encoder = None
        self.recording = False

    def capture_still(self, path, cfg):
        self.cam.options["quality"] = max(1, min(100, to_int(cfg.get("image_quality"), 85)))
        with self.lock:
            if self.recording or self.circular is not None:
                # the encoder uses the video stream, take the still from it
                self.cam.capture_file(path, name="main")
            else:
                self.cam.switch_mode_and_capture_file(self.still_config, path)

    def apply_controls(self, cfg):
        controls = build_libcamera_controls(cfg, self.cam.camera_controls, self.daemon.log)
        if controls:
            self.cam.set_controls(controls)

    def autofocus(self):
        if self.af_available:
            self.cam.set_controls({"AfMode": 1, "AfTrigger": 0})

    def close(self):
        self.running = False
        if self.thread:
            self.thread.join(timeout=3)
        try:
            self.stop_recording()
            if self.circular is not None:
                self._stop_encoder()
                self.circular = None
        except Exception as e:
            self.daemon.log("Stopping encoder failed: %s" % e)
        if self.cam is not None:
            try:
                self.cam.stop()
            finally:
                self.cam.close()
                self.cam = None


def build_libcamera_controls(cfg, available, log):
    """Maps raspimjpeg settings to libcamera controls, limited to what the camera offers."""
    c = {}

    def put(name, value):
        if name not in available:
            return
        limits = available[name]
        if isinstance(value, (int, float)) and not isinstance(value, bool) and limits[0] is not None and limits[1] is not None:
            try:
                value = type(value)(max(limits[0], min(limits[1], value)))
            except TypeError:
                pass
        c[name] = value

    put("Brightness", (to_int(cfg.get("brightness"), 50) - 50) / 50.0)
    put("Contrast", max(0.0, 1.0 + to_int(cfg.get("contrast"), 0) / 100.0))
    put("Saturation", max(0.0, 1.0 + to_int(cfg.get("saturation"), 0) / 100.0))
    put("Sharpness", max(0.0, 1.0 + to_int(cfg.get("sharpness"), 0) / 25.0))
    put("ExposureValue", to_int(cfg.get("exposure_compensation"), 0) / 6.0)
    # shutter speed and ISO: 0 = automatic. Newer libcamera has explicit Auto/Manual mode controls,
    # older versions take 0 as automatic (it must not be clamped to the minimum then).
    shutter = to_int(cfg.get("shutter_speed"), 0)
    iso = to_int(cfg.get("iso"), 0)
    if "ExposureTimeMode" in available:
        c["ExposureTimeMode"] = 1 if shutter > 0 else 0
        if shutter > 0:
            put("ExposureTime", shutter)
    elif "ExposureTime" in available:
        if shutter > 0:
            put("ExposureTime", shutter)
        else:
            c["ExposureTime"] = 0
    if "AnalogueGainMode" in available:
        c["AnalogueGainMode"] = 1 if iso > 0 else 0
        if iso > 0:
            put("AnalogueGain", iso / 100.0)
    elif "AnalogueGain" in available:
        if iso > 0:
            put("AnalogueGain", iso / 100.0)
        else:
            c["AnalogueGain"] = 0.0
    mode = str(cfg.get("exposure_mode", "auto")).lower()
    if mode == "off":
        put("AeEnable", False)
    else:
        put("AeEnable", True)
        exposure = {"night": 2, "nightpreview": 2, "verylong": 2, "backlight": 2, "sports": 1, "snow": 1,
                    "beach": 1, "antishake": 1, "fireworks": 2}.get(mode, 0)
        put("AeExposureMode", exposure)
    metering = {"average": 0, "spot": 1, "matrix": 2, "backlit": 2}.get(str(cfg.get("metering_mode")).lower(), 0)
    put("AeMeteringMode", metering)
    wb = str(cfg.get("white_balance", "auto")).lower()
    if wb == "off":
        put("AwbEnable", False)
        put("ColourGains", (max(0.1, to_int(cfg.get("autowbgain_r"), 150) / 100.0),
                            max(0.1, to_int(cfg.get("autowbgain_b"), 150) / 100.0)))
    else:
        put("AwbEnable", True)
        awb = {"auto": 0, "greyworld": 0, "incandescent": 1, "tungsten": 2, "fluorescent": 3, "indoor": 4,
               "sun": 5, "flash": 5, "cloudy": 6, "shade": 6, "horizon": 1}.get(wb, 0)
        put("AwbMode", awb)
    if "ScalerCrop" in available:
        try:
            maximum = available["ScalerCrop"][1]
            x0, y0, mw, mh = maximum
            rx, ry = to_int(cfg.get("sensor_region_x"), 0), to_int(cfg.get("sensor_region_y"), 0)
            rw, rh = to_int(cfg.get("sensor_region_w"), 65536), to_int(cfg.get("sensor_region_h"), 65536)
            crop = (int(x0 + mw * rx / 65536), int(y0 + mh * ry / 65536),
                    max(64, int(mw * rw / 65536)), max(64, int(mh * rh / 65536)))
            c["ScalerCrop"] = crop
        except Exception:
            pass
    if "AfMode" in available:
        af = max(0, min(2, to_int(cfg.get("af_mode"), 2)))
        c["AfMode"] = af
        if af == 0 and "LensPosition" in available:
            put("LensPosition", to_float(cfg.get("lens_position"), 0.0))
    return c


class FrameBackend:
    """Base for backends that deliver BGR frames: annotation, preview and ffmpeg recording in Python."""

    name = "frames"

    def __init__(self, daemon):
        if np is None or cv2 is None:
            raise BackendError("the %s backend needs python3-numpy and python3-opencv" % self.name)
        self.daemon = daemon
        self.running = False
        self.thread = None
        self.latest = None
        self.latest_lock = threading.Lock()
        self.ffmpeg = None
        self.ffmpeg_lock = threading.Lock()
        self.size = (0, 0)
        self.fps = 25.0

    def open(self, cfg, preview_cb):
        self.preview_cb = preview_cb
        self.cfg = cfg
        self._open_source(cfg)
        self.running = True
        self.thread = threading.Thread(target=self._loop, daemon=True)
        self.thread.start()
        return self.info()

    def info(self):
        return {"model": self.name, "sensor": list(self.size), "autofocus": False,
                "unsupported": UNSUPPORTED_COMMON + ["em", "mm", "wb", "ag", "ri", "ss", "is", "ec", "bu", "af", "lp", "cn"],
                "cameras": 1}

    def _loop(self):
        divider = max(1, to_int(self.cfg.get("divider"), 1))
        quality = to_int(self.cfg.get("quality"), 10)
        pw = max(64, min(1024, to_int(self.cfg.get("width"), 512)))
        n = 0
        while self.running:
            frame = self._read_frame()
            if frame is None:
                time.sleep(0.2)
                continue
            frame = self._postprocess(frame)
            spec = self.daemon.annotation.spec()
            if spec:
                Annotation.draw(frame, spec)
            with self.latest_lock:
                self.latest = frame
            with self.ffmpeg_lock:
                if self.ffmpeg is not None:
                    try:
                        self.ffmpeg.stdin.write(frame.tobytes())
                    except (BrokenPipeError, ValueError):
                        self.daemon.log("ffmpeg stopped unexpectedly")
                        self.ffmpeg = None
            n += 1
            if n % divider:
                continue
            h, w = frame.shape[:2]
            ph = max(2, int(round(pw * h / w / 2.0)) * 2)
            small = cv2.resize(frame, (pw, ph), interpolation=cv2.INTER_AREA)
            gray = cv2.cvtColor(small, cv2.COLOR_BGR2GRAY)
            self.preview_cb(encode_jpeg_bgr(small, quality), gray)

    def _postprocess(self, frame):
        return frame

    def start_recording(self, path, cfg):
        h, w = self.latest.shape[:2] if self.latest is not None else (self.size[1], self.size[0])
        bitrate = to_int(cfg.get("video_bitrate"), 0)
        encoder = cfg.get("ffmpeg_encoder") or "libx264"
        cmd = ["ffmpeg", "-y", "-loglevel", "error", "-f", "rawvideo", "-pix_fmt", "bgr24", "-s", "%dx%d" % (w, h),
               "-r", "%g" % self.fps, "-i", "-", "-c:v", encoder]
        if encoder == "libx264":
            cmd += ["-preset", "ultrafast", "-tune", "zerolatency"]
        if bitrate > 0:
            cmd += ["-b:v", str(bitrate)]
        cmd += ["-pix_fmt", "yuv420p", "-f", "h264", path]
        with self.ffmpeg_lock:
            self.ffmpeg = subprocess.Popen(cmd, stdin=subprocess.PIPE)

    def stop_recording(self):
        with self.ffmpeg_lock:
            proc, self.ffmpeg = self.ffmpeg, None
        if proc is not None:
            try:
                proc.stdin.close()
                proc.wait(timeout=30)
            except Exception:
                proc.kill()

    def capture_still(self, path, cfg):
        with self.latest_lock:
            frame = None if self.latest is None else self.latest.copy()
        if frame is None:
            raise BackendError("no frame available")
        quality = max(1, min(100, to_int(cfg.get("image_quality"), 85)))
        if not cv2.imwrite(path, frame, [cv2.IMWRITE_JPEG_QUALITY, quality]):
            raise BackendError("could not write %s" % path)

    def apply_controls(self, cfg):
        pass

    def autofocus(self):
        pass

    def close(self):
        self.running = False
        if self.thread:
            self.thread.join(timeout=3)
        self.stop_recording()
        self._close_source()

    def _open_source(self, cfg):
        raise NotImplementedError

    def _read_frame(self):
        raise NotImplementedError

    def _close_source(self):
        pass


class V4L2Backend(FrameBackend):
    """USB/UVC webcams and V4L2 cameras (also on boards that are not a Raspberry Pi)."""

    name = "v4l2"

    @staticmethod
    def find_device(cfg):
        """v4l2_device from the config, else the first USB camera, else /dev/video0."""
        dev = cfg.get("v4l2_device")
        if dev:
            return dev if os.path.exists(dev) else None
        try:
            usb = sorted(n for n in os.listdir("/dev/v4l/by-id") if n.endswith("video-index0"))
        except OSError:
            usb = []
        if usb:
            return os.path.realpath(os.path.join("/dev/v4l/by-id", usb[0]))
        return "/dev/video0" if os.path.exists("/dev/video0") else None

    @staticmethod
    def available(cfg):
        return cv2 is not None and V4L2Backend.find_device(cfg) is not None

    def _open_source(self, cfg):
        dev = self.find_device(cfg)
        if dev is None:
            raise BackendError("no V4L2 device found")
        self.device = dev
        self.cap = cv2.VideoCapture(dev, cv2.CAP_V4L2)
        if not self.cap.isOpened():
            raise BackendError("can not open %s" % dev)
        self.cap.set(cv2.CAP_PROP_FOURCC, cv2.VideoWriter_fourcc(*"MJPG"))
        self.cap.set(cv2.CAP_PROP_FRAME_WIDTH, to_int(cfg.get("video_width"), 1280))
        self.cap.set(cv2.CAP_PROP_FRAME_HEIGHT, to_int(cfg.get("video_height"), 720))
        self.cap.set(cv2.CAP_PROP_FPS, to_float(cfg.get("video_fps"), 25))
        self.size = (int(self.cap.get(cv2.CAP_PROP_FRAME_WIDTH)), int(self.cap.get(cv2.CAP_PROP_FRAME_HEIGHT)))
        self.fps = self.cap.get(cv2.CAP_PROP_FPS) or to_float(cfg.get("video_fps"), 25)
        self.apply_controls(cfg)

    def info(self):
        info = FrameBackend.info(self)
        info["model"] = self._card_name() or "V4L2 camera"
        return info

    def _card_name(self):
        try:
            out = subprocess.run(["v4l2-ctl", "-d", self.device, "--info"], capture_output=True, text=True, timeout=5).stdout
            m = re.search(r"Card type\s*:\s*(.+)", out)
            return m.group(1).strip() if m else None
        except Exception:
            return None

    def _read_frame(self):
        ok, frame = self.cap.read()
        return frame if ok else None

    def _postprocess(self, frame):
        flip = (1 if to_bool(self.cfg.get("hflip")) else 0) + (2 if to_bool(self.cfg.get("vflip")) else 0)
        if to_int(self.cfg.get("rotation"), 0) % 360 == 180:
            flip ^= 3
        if flip == 1:
            frame = cv2.flip(frame, 1)
        elif flip == 2:
            frame = cv2.flip(frame, 0)
        elif flip == 3:
            frame = cv2.flip(frame, -1)
        return frame

    def apply_controls(self, cfg):
        """Sets brightness, contrast, saturation and sharpness with v4l2-ctl, scaled to the device range."""
        if not shutil.which("v4l2-ctl"):
            return
        try:
            listing = subprocess.run(["v4l2-ctl", "-d", self.device, "--list-ctrls"], capture_output=True, text=True, timeout=5).stdout
        except Exception:
            return
        ranges = {}
        for m in re.finditer(r"^\s*(\w+)\s+0x[0-9a-f]+\s+\(int\)\s*:\s*min=(-?\d+)\s+max=(-?\d+).*?default=(-?\d+)", listing, re.M):
            ranges[m.group(1)] = (int(m.group(2)), int(m.group(3)), int(m.group(4)))
        wanted = {"brightness": (to_int(cfg.get("brightness"), 50) - 50) / 50.0,
                  "contrast": to_int(cfg.get("contrast"), 0) / 100.0,
                  "saturation": to_int(cfg.get("saturation"), 0) / 100.0,
                  "sharpness": to_int(cfg.get("sharpness"), 0) / 100.0}
        args = []
        for name, rel in wanted.items():
            if name in ranges:
                lo, hi, default = ranges[name]
                value = default + rel * ((hi - default) if rel > 0 else (default - lo))
                args.append("%s=%d" % (name, round(value)))
        if args:
            subprocess.run(["v4l2-ctl", "-d", self.device, "--set-ctrl", ",".join(args)], capture_output=True, timeout=5)

    def _close_source(self):
        self.cap.release()


class TestBackend(FrameBackend):
    """Synthetic moving picture, used for development and automated tests."""

    name = "test"

    def _open_source(self, cfg):
        self.size = (max(64, to_int(cfg.get("video_width"), 1280)), max(64, to_int(cfg.get("video_height"), 720)))
        self.fps = max(1.0, min(60.0, to_float(cfg.get("video_fps"), 25)))
        w, h = self.size
        ramp = np.linspace(40, 200, h, dtype=np.float32)[:, None]
        self.base = np.zeros((h, w, 3), np.uint8)
        self.base[:, :, 0] = ramp
        self.base[:, :, 1] = ramp * 0.6
        self.base[h * 3 // 4:, :, :] = (40, 110, 50)
        self.n = 0
        self.next = time.time()

    def _read_frame(self):
        self.next += 1.0 / self.fps
        delay = self.next - time.time()
        if delay > 0:
            time.sleep(delay)
        else:
            self.next = time.time()
        w, h = self.size
        frame = self.base.copy()
        self.n += 1
        x = int((np.sin(self.n / 20.0) + 1) / 2 * (w - w // 8))
        cv2.rectangle(frame, (x, h * 3 // 4 - h // 10), (x + w // 8, h * 3 // 4), (40, 40, 200), -1)
        return frame


# ---------------------------------------------------------------------------------------------------
# Daemon
# ---------------------------------------------------------------------------------------------------

class Motion:
    """Frame difference motion detection on a macroblock grid (comparable to raspimjpeg's vector counts)."""

    def __init__(self, daemon):
        self.daemon = daemon
        self.reset()

    def reset(self):
        self.background = None
        self.frames = 0
        self.active = False
        self.change_count = 0
        self.still_count = 0
        self.mask = None
        self.mask_file = None
        self.last_blocks = None

    def _grid(self):
        cfg = self.daemon.cfg
        vw = max(16, to_int(cfg.get("video_width"), 1920))
        vh = max(16, to_int(cfg.get("video_height"), 1080))
        return max(4, vw // 16), max(4, vh // 16)

    def _load_mask(self, gw, gh):
        path = self.daemon.cfg.get("motion_image", "")
        if path == self.mask_file:
            return
        self.mask_file = path
        self.mask = None
        if path and os.path.exists(path) and cv2 is not None:
            img = cv2.imread(path, cv2.IMREAD_GRAYSCALE)
            if img is not None:
                self.mask = cv2.resize(img, (gw, gh), interpolation=cv2.INTER_AREA) > 127

    def process(self, gray):
        if np is None:
            return
        cfg = self.daemon.cfg
        gw, gh = self._grid()
        if cv2 is not None:
            blocks = cv2.resize(gray, (gw, gh), interpolation=cv2.INTER_AREA).astype(np.float32)
        else:
            h, w = gray.shape
            ys = (np.arange(gh) * h // gh)
            xs = (np.arange(gw) * w // gw)
            blocks = gray[ys][:, xs].astype(np.float32)
        self.frames += 1
        if self.background is None or self.background.shape != blocks.shape:
            self.background = blocks
            return
        diff = np.abs(blocks - self.background)
        self.background = self.background * 0.85 + blocks * 0.15
        noise = to_int(cfg.get("motion_noise"), 1010)
        if noise > 255:  # automatic noise level
            level = max(6.0, float(np.median(diff)) * 3.0 + 3.0)
        else:
            level = max(1.0, float(noise) / 8.0)
        moving = diff > level
        if not to_bool(self.daemon.motion_mask_disabled):
            self._load_mask(gw, gh)
            if self.mask is not None and self.mask.shape == moving.shape:
                moving &= self.mask
        count = int(moving.sum())
        self.last_blocks = moving
        if self.frames <= to_int(cfg.get("motion_initframes"), 0):
            return
        if count > moving.size * 0.8:  # whole picture changed: light switched, ignore
            self.background = blocks
            return
        threshold = max(1, to_int(cfg.get("motion_threshold"), 250))
        if count >= threshold:
            self.change_count += 1
            self.still_count = 0
            if not self.active and self.change_count >= max(1, to_int(cfg.get("motion_startframes"), 3)):
                self.active = True
                self.daemon.events.put(("motion", True, count))
        else:
            self.still_count += 1
            self.change_count = 0
            if self.active and self.still_count >= max(1, to_int(cfg.get("motion_stopframes"), 150)):
                self.active = False
                self.daemon.events.put(("motion", False, count))


class Daemon:
    def __init__(self, config_path, backend_name):
        self.config_path = config_path
        self.backend_name = backend_name
        self.events = queue.Queue()
        self.running = True
        self.backend = None
        self.info = {}
        self.halted = True
        self.error = None
        self.video = False
        self.video_path = None
        self.video_h264 = None
        self.video_start = 0
        self.video_end = 0
        self.video_split_at = 0
        self.timelapse = False
        self.tl_next = 0
        self.lapse_cnt = 0
        self.md = False
        self.motion_recording = False
        self.motion_mask_disabled = "0"
        self.pending_restart = False
        self.image_busy = False
        self.video_cnt = 0
        self.image_cnt = 0
        self.last_jpeg = None
        self.preview_lock = threading.Lock()
        self.external_motion = None
        self.annotation = Annotation(self)
        self.motion = Motion(self)
        self.load_config()

    # ---- config, logging, status ----
    def load_config(self):
        cfg = dict(DEFAULTS)
        cfg.update(self.read_config_file(self.config_path))
        self.user_config_path = cfg.get("user_config", "")
        self.user = self.read_config_file(self.user_config_path) if self.user_config_path else {}
        cfg.update(self.user)
        if "MP4Box" in cfg and str(cfg["MP4Box"]).lower() == "true":
            cfg["MP4Box"] = "1"
        self.cfg = cfg

    @staticmethod
    def read_config_file(path):
        values = {}
        if not path or not os.path.exists(path):
            return values
        with open(path, encoding="utf-8", errors="replace") as f:
            for line in f:
                line = line.rstrip("\r\n")
                if not line or line.startswith("#"):
                    continue
                key, _, value = line.partition(" ")
                if value == "true":
                    value = "1"
                elif value == "false":
                    value = "0"
                values[key] = value
        return values

    def save_user_config(self):
        if not self.user_config_path:
            return
        try:
            lines = ["#User config file\n"] + ["%s %s\n" % (k, v) for k, v in self.user.items()]
            write_atomic(self.user_config_path, "".join(lines))
        except OSError as e:
            self.log("Could not save %s: %s" % (self.user_config_path, e))

    def set_cfg(self, key, value):
        self.cfg[key] = value
        self.user[key] = value

    def log(self, msg):
        line = "%s {rpicamd} %s\n" % (now_str(), msg)
        sys.stderr.write(line)
        path = self.cfg.get("log_file") if hasattr(self, "cfg") else None
        if path and to_int(self.cfg.get("log_size"), 1) > 0:
            try:
                with open(path, "a") as f:
                    f.write(line)
            except OSError:
                pass

    def status(self):
        if self.error:
            return "Error: " + self.error
        if self.halted:
            return "halted"
        if self.image_busy:
            return "image"
        prefix = ("tl_" if self.timelapse else "") + ("md_" if self.md else "")
        if self.video:
            return prefix + "video"
        if self.timelapse and not self.md:
            return "timelapse"
        return prefix + "ready"

    def write_status(self):
        path = self.cfg.get("status_file")
        if not path:
            return
        try:
            os.makedirs(os.path.dirname(path), exist_ok=True)
            write_atomic(path, self.status())
        except OSError as e:
            sys.stderr.write("status write failed: %s\n" % e)

    def write_info(self):
        path = os.path.join(os.path.dirname(self.cfg.get("status_file", "/dev/shm/mjpeg/x")), "camera_info.json")
        info = dict(self.info)
        info.update({"backend": self.backend.name if self.backend else self.backend_name, "daemon": "rpicamd " + VERSION})
        try:
            write_atomic(path, json.dumps(info))
        except OSError:
            pass

    # ---- names, indexes, macros ----
    def media_file(self, template):
        path = template if template.startswith("/") else os.path.join(self.cfg.get("media_path", ""), template)
        return path

    def fmt_count(self, n):
        try:
            return self.cfg.get("count_format", "%04d") % n
        except (TypeError, ValueError):
            return "%04d" % n

    def expand(self, template, annotate=False):
        now = datetime.datetime.now()
        out = []
        i = 0
        while i < len(template):
            ch = template[i]
            if ch == "%" and i + 1 < len(template):
                code = template[i + 1]
                i += 2
                if code == "v":
                    out.append(self.fmt_count(self.video_cnt))
                elif code == "i":
                    out.append(self.fmt_count(self.image_cnt))
                elif code == "t":
                    out.append(self.fmt_count(self.lapse_cnt))
                elif code in "YyMDhms":
                    out.append(now.strftime({"Y": "%Y", "y": "%y", "M": "%m", "D": "%d", "h": "%H", "m": "%M", "s": "%S"}[code]))
                elif code == "u":
                    out.append("%03d" % (now.microsecond // 1000))
                elif code == "a":
                    out.append(self.user_annotate())
                elif code == "%":
                    out.append("%")
                else:
                    out.append("%" + code)
            else:
                out.append(ch)
                i += 1
        return "".join(out)

    def user_annotate(self):
        path = self.cfg.get("user_annotate", "")
        try:
            with open(path) as f:
                return f.read().strip()
        except OSError:
            return ""

    def scan_indexes(self):
        media = self.cfg.get("media_path", "")
        self.video_cnt = self.image_cnt = 0
        try:
            names = os.listdir(media)
        except OSError:
            return
        for name in names:
            if not name.endswith(".th.jpg") or len(name) < 9:
                continue
            i = name.rfind(".", 0, len(name) - 7)
            if i < 0:
                continue
            kind, index = name[i + 1:i + 2], name[i + 2:len(name) - 7]
            n = to_int(index, 0)
            if kind == "v":
                self.video_cnt = max(self.video_cnt, n)
            elif kind in ("i", "t"):
                self.image_cnt = max(self.image_cnt, n)

    def thumbnail(self, kind, index, datafile):
        if kind not in self.cfg.get("thumb_gen", "vit"):
            return
        media = self.cfg.get("media_path", "")
        rel = os.path.relpath(datafile, media) if datafile.startswith(media) else os.path.basename(datafile)
        name = "%s.%s%s.th.jpg" % (rel.replace("/", self.cfg.get("subdir_char", "@")), kind, self.fmt_count(index))
        with self.preview_lock:
            data = self.last_jpeg
        if data:
            try:
                write_atomic(os.path.join(media, name), data)
            except OSError as e:
                self.log("Thumbnail failed: %s" % e)

    def run_macro(self, key, arg=""):
        value = str(self.cfg.get(key, "") or "")
        if not value or value.startswith("-"):
            return
        self.exec_macro(value, [arg] if arg != "" else [])

    def exec_macro(self, value, extra_args):
        asynchronous = value.startswith("&")
        value = value.lstrip("&")
        parts = value.split()
        if not parts or "/" in parts[0] or ".." in parts[0]:
            return
        path = os.path.join(self.cfg.get("macros_path", ""), parts[0])
        if not os.path.isfile(path):
            return
        try:
            proc = subprocess.Popen([path] + parts[1:] + extra_args, stdin=subprocess.DEVNULL)
            if not asynchronous:
                proc.wait(timeout=60)
        except Exception as e:
            self.log("Macro %s failed: %s" % (parts[0], e))

    # ---- camera ----
    def select_backend(self):
        name = self.backend_name
        if name == "auto":
            if Picamera2Backend.available():
                name = "picamera2"
            elif V4L2Backend.available(self.cfg):
                name = "v4l2"
            else:
                raise BackendError("no camera found (libcamera or V4L2)")
        if name == "picamera2":
            return Picamera2Backend(self)
        if name == "v4l2":
            return V4L2Backend(self)
        if name == "test":
            return TestBackend(self)
        raise BackendError("unknown backend " + name)

    def start_camera(self):
        if self.backend is not None:
            return
        try:
            self.backend = self.select_backend()
            self.info = self.backend.open(self.cfg, self.on_preview)
            self.error = None
            self.halted = False
            self.motion.reset()
            self.log("Camera started: %s (%s)" % (self.info.get("model"), self.backend.name))
        except Exception as e:
            self.backend = None
            self.halted = True
            self.error = str(e) or e.__class__.__name__
            self.log("Camera start failed: %s" % self.error)
            self.run_macro("error_soft", self.error)
        self.write_info()
        self.write_status()

    def stop_camera(self):
        if self.video:
            self.stop_video()
        self.timelapse = False
        if self.backend is not None:
            try:
                self.backend.close()
            except Exception as e:
                self.log("Camera stop failed: %s" % e)
            self.backend = None
        self.halted = True
        self.write_status()

    def restart_camera(self):
        recording = self.video
        if recording:
            self.stop_video()
        self.stop_camera()
        self.start_camera()

    def on_preview(self, jpeg, gray):
        """Called by the backend thread for every preview frame."""
        if jpeg is None:
            return
        if self.md and to_int(self.cfg.get("motion_external"), 0) != 1:
            self.motion.process(gray)
            if to_bool(self.cfg.get("vector_preview")) and self.motion.last_blocks is not None and cv2 is not None:
                jpeg = self.draw_vectors(jpeg)
        with self.preview_lock:
            self.last_jpeg = jpeg
        try:
            write_atomic(self.cfg.get("preview_path"), jpeg)
        except OSError as e:
            sys.stderr.write("preview write failed: %s\n" % e)

    def draw_vectors(self, jpeg):
        img = cv2.imdecode(np.frombuffer(jpeg, np.uint8), cv2.IMREAD_COLOR)
        if img is None:
            return jpeg
        mask = cv2.resize(self.motion.last_blocks.astype(np.uint8) * 255, (img.shape[1], img.shape[0]), interpolation=cv2.INTER_NEAREST)
        img[mask > 0] = (img[mask > 0] * 0.5 + np.array([0, 0, 255]) * 0.5).astype(np.uint8)
        return encode_jpeg_bgr(img, to_int(self.cfg.get("quality"), 10))

    # ---- capture ----
    def capture_image(self):
        if self.backend is None:
            return
        self.image_cnt += 1
        path = self.expand(self.media_file(self.cfg.get("image_path")))
        os.makedirs(os.path.dirname(path), exist_ok=True)
        self.image_busy = True
        self.write_status()
        self.run_macro("start_img", path)
        try:
            self.backend.capture_still(path, self.cfg)
            self.thumbnail("i", self.image_cnt, path)
            self.log("Capturing image %s" % path)
        except Exception as e:
            self.log("Image capture failed: %s" % e)
            self.run_macro("error_soft", str(e))
        self.image_busy = False
        self.run_macro("end_img", path)
        self.write_status()

    def lapse_frame(self):
        self.lapse_cnt += 1
        path = self.expand(self.media_file(self.cfg.get("lapse_path")))
        os.makedirs(os.path.dirname(path), exist_ok=True)
        try:
            self.backend.capture_still(path, self.cfg)
            if self.lapse_cnt == 1:
                self.thumbnail("t", self.image_cnt, path)
        except Exception as e:
            self.log("Timelapse capture failed: %s" % e)

    def start_timelapse(self):
        if self.backend is None or self.timelapse:
            return
        self.image_cnt += 1
        self.lapse_cnt = 0
        self.timelapse = True
        self.tl_next = time.time()
        self.log("Timelapse started")
        self.write_status()

    def stop_timelapse(self):
        if self.timelapse:
            self.timelapse = False
            self.log("Timelapse stopped")
            self.write_status()

    def start_video(self, duration=0):
        if self.backend is None or self.video:
            return
        self.video_cnt += 1
        final = self.expand(self.media_file(self.cfg.get("video_path")))
        os.makedirs(os.path.dirname(final), exist_ok=True)
        base = os.path.splitext(final)[0]
        boxing_dir = self.cfg.get("boxing_path", "")
        h264 = os.path.join(boxing_dir, os.path.basename(base) + ".h264") if boxing_dir else base + ".h264"
        try:
            self.backend.start_recording(h264, self.cfg)
        except Exception as e:
            self.log("Video start failed: %s" % e)
            self.run_macro("error_soft", str(e))
            return
        self.video = True
        self.video_path, self.video_h264 = final, h264
        self.video_start = time.time()
        self.video_end = self.video_start + duration if duration > 0 else 0
        split = to_int(self.cfg.get("video_split"), 0)
        self.video_split_at = self.video_start + split if split > 0 else 0
        boxing = str(self.cfg.get("MP4Box", "background")).lower() not in ("0", "false", "off")
        self.thumbnail("v", self.video_cnt, final if boxing else h264)
        self.run_macro("start_vid", final)
        self.log("Capturing started %s" % final)
        self.write_status()

    def stop_video(self):
        if not self.video:
            return
        try:
            self.backend.stop_recording()
        except Exception as e:
            self.log("Video stop failed: %s" % e)
        self.video = False
        final, h264 = self.video_path, self.video_h264
        self.run_macro("end_vid", final)
        self.log("Capturing stopped %s" % final)
        mode = str(self.cfg.get("MP4Box", "background")).lower()
        if mode not in ("0", "false", "off"):
            args = (h264, final, to_float(self.cfg.get("MP4Box_fps"), 25), time.time())
            if mode == "background":
                threading.Thread(target=self.box, args=args, daemon=True).start()
            else:
                self.box(*args)
        self.write_status()

    def box(self, h264, final, fps, end_time):
        """Wraps the raw H.264 stream into an mp4 file (ffmpeg, or MP4Box from gpac)."""
        if shutil.which("ffmpeg"):
            cmd = ["ffmpeg", "-y", "-loglevel", "error", "-framerate", "%g" % fps, "-i", h264, "-c", "copy", final]
        elif shutil.which("MP4Box"):
            cmd = ["MP4Box", "-quiet", "-fps", "%g" % fps, "-add", h264, final]
        else:
            self.log("No ffmpeg or MP4Box for boxing, keeping %s" % h264)
            return
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.returncode == 0 and os.path.exists(final):
            os.utime(final, (end_time, end_time))
            try:
                os.remove(h264)
            except OSError:
                pass
            self.run_macro("end_box", final)
        else:
            self.log("Boxing failed: %s" % result.stderr.strip()[:300])
            try:
                os.replace(h264, h264 + ".bad")
            except OSError:
                pass

    # ---- motion ----
    def set_motion_detection(self, on):
        if on == self.md:
            return
        self.md = on
        self.motion.reset()
        external = to_int(self.cfg.get("motion_external"), 0) == 1
        if external:
            if on and shutil.which("motion"):
                self.external_motion = subprocess.Popen(["motion"], stdin=subprocess.DEVNULL)
            elif not on and self.external_motion is not None:
                self.external_motion.terminate()
                self.external_motion = None
        self.log("Motion detection %s" % ("started" if on else "stopped"))
        self.write_status()

    def motion_event(self, start, count):
        monitor = to_int(self.cfg.get("motion_external"), 0) == 2
        self.log("Motion %s (%d blocks)" % ("start" if start else "stop", count))
        try:
            with open(self.cfg.get("motion_logfile", "/dev/null"), "a") as f:
                f.write("%s motion %s %d\n" % (now_str(), "start" if start else "stop", count))
        except OSError:
            pass
        if monitor:
            return
        self.run_macro("motion_event", "1" if start else "0")
        pipe = self.cfg.get("motion_pipe", "")
        if pipe and os.path.exists(pipe):
            try:
                fd = os.open(pipe, os.O_RDWR | os.O_NONBLOCK)
                os.write(fd, b"1" if start else b"0")
                os.close(fd)
            except OSError as e:
                self.log("Motion pipe write failed: %s" % e)

    # ---- commands ----
    def handle(self, line):
        line = line.strip()
        if len(line) < 2:
            return
        cmd, args_str = line[:2], line[2:].strip()
        args = args_str.split()
        cfg = self.cfg
        if cmd == "ca":
            if args and args[0] == "1":
                self.start_video(to_int(args[1], 0) if len(args) > 1 else 0)
            else:
                self.stop_video()
        elif cmd == "im":
            self.capture_image()
        elif cmd == "tl":
            self.start_timelapse() if args and args[0] == "1" else self.stop_timelapse()
        elif cmd == "md":
            self.set_motion_detection(bool(args) and args[0] == "1")
        elif cmd == "ru":
            if args and args[0] == "0":
                self.set_motion_detection(False)
                self.stop_camera()
            else:
                self.start_camera()
        elif cmd == "sy":
            if args_str:
                self.exec_macro("&" + args_str, [])
        elif cmd == "um":
            if args and to_int(args[0], -1) in range(len(MACROS)):
                self.set_cfg(MACROS[to_int(args[0])], args[1] if len(args) > 1 else "")
                self.save_user_config()
        elif cmd == "rs":
            self.user = {}
            try:
                os.remove(self.user_config_path)
            except OSError:
                pass
            self.load_config()
            self.restart_camera()
        elif cmd == "sc":
            self.scan_indexes()
        elif cmd == "mz":
            self.motion_mask_disabled = args[0] if args else "0"
        elif cmd == "fl":
            value = to_int(args[0], 0) if args else 0
            self.set_cfg("hflip", "1" if value & 1 else "0")
            self.set_cfg("vflip", "1" if value & 2 else "0")
            self.save_user_config()
            self.apply_change(cmd)
        elif cmd == "bo":
            value = args[0] if args else "0"
            self.set_cfg("MP4Box", {"0": "false", "1": "true", "2": "background"}.get(value, value))
            self.save_user_config()
        elif cmd == "an":
            self.set_cfg("annotation", args_str)
            self.save_user_config()
        elif cmd == "mi":
            self.set_cfg("motion_image", args_str)
            self.save_user_config()
        elif cmd == "af" and args and args[0] == "1" and self.backend is not None:
            self.set_cfg("af_mode", "1")
            self.save_user_config()
            self.apply_change(cmd)
            self.backend.autofocus()
        elif cmd in SETTINGS:
            keys = SETTINGS[cmd]
            if cmd == "tv" and args:  # timelapse interval, raspimjpeg minimum 1 (0.1 s)
                args = [str(max(1, to_int(args[0], 30)))]
            if cmd == "px" and len(args) == 6:  # older presets without divider
                args.append(cfg.get("fps_divider", "1"))
            for key, value in zip(keys, args):
                self.set_cfg(key, value)
            self.save_user_config()
            self.apply_change(cmd)
        else:
            self.log("Unknown command: %s" % line)

    def apply_change(self, cmd):
        if self.backend is None:
            return
        if cmd in RESTART_CMDS:
            if self.video:
                self.log("Setting applied after the recording")
                self.pending_restart = True
                return
            self.restart_camera()
        else:
            try:
                self.backend.apply_controls(self.cfg)
            except Exception as e:
                self.log("Setting controls failed: %s" % e)

    # ---- main loop ----
    def fifo_reader(self):
        path = self.cfg.get("control_file")
        if not os.path.exists(path):
            try:
                os.mkfifo(path, 0o666)
            except OSError as e:
                self.log("Can not create %s: %s" % (path, e))
                return
        fd = os.open(path, os.O_RDONLY | os.O_NONBLOCK)
        keep = os.open(path, os.O_WRONLY | os.O_NONBLOCK)  # keeps the FIFO open so reads never see EOF
        buf = b""
        enforce_lf = to_bool(self.cfg.get("enforce_lf"))
        last_data = 0
        while self.running:
            ready, _, _ = select.select([fd], [], [], 0.2)
            if ready:
                try:
                    data = os.read(fd, 4096)
                except BlockingIOError:
                    data = b""
                if data:
                    buf += data
                    last_data = time.time()
            while b"\n" in buf:
                line, buf = buf.split(b"\n", 1)
                self.events.put(("cmd", line.decode("utf-8", "replace")))
            if buf and not enforce_lf and time.time() - last_data > 0.2:
                self.events.put(("cmd", buf.decode("utf-8", "replace")))
                buf = b""
        os.close(keep)
        os.close(fd)

    def timers(self):
        now = time.time()
        if self.video:
            if self.video_end and now >= self.video_end:
                self.stop_video()
            elif self.video_split_at and now >= self.video_split_at:
                self.stop_video()
                self.start_video()
        if not self.video and self.pending_restart:
            self.pending_restart = False
            self.restart_camera()
        if self.timelapse and self.backend is not None and now >= self.tl_next:
            interval = max(0.1, to_int(self.cfg.get("tl_interval"), 30) / 10.0)
            self.tl_next += interval
            if self.tl_next < now:
                self.tl_next = now + interval
            self.lapse_frame()

    def stop(self, *_):
        self.running = False

    def run(self):
        signal.signal(signal.SIGTERM, self.stop)
        signal.signal(signal.SIGINT, self.stop)
        os.makedirs(os.path.dirname(self.cfg.get("preview_path")), exist_ok=True)
        self.scan_indexes()
        self.log("rpicamd %s started" % VERSION)
        self.run_macro("startstop", "start")
        if self.cfg.get("autostart", "standard") == "standard":
            self.start_camera()
            if to_bool(self.cfg.get("motion_detection")):
                self.set_motion_detection(True)
        else:
            self.write_status()
        threading.Thread(target=self.fifo_reader, daemon=True).start()
        last_status = 0
        while self.running:
            try:
                event = self.events.get(timeout=0.05)
            except queue.Empty:
                event = None
            try:
                if event:
                    if event[0] == "cmd":
                        self.handle(event[1])
                    elif event[0] == "motion":
                        self.motion_event(event[1], event[2])
                self.timers()
                if time.time() - last_status > 2:
                    self.write_status()  # refresh, the web interface uses the file time
                    last_status = time.time()
            except Exception as e:
                self.log("Error: %s" % e)
                self.run_macro("error_soft", str(e))
        self.log("rpicamd stopping")
        self.set_motion_detection(False)
        self.stop_camera()
        self.run_macro("startstop", "stop")


def main():
    parser = argparse.ArgumentParser(description="Camera daemon for RPi Cam Web Interface (raspimjpeg replacement)")
    parser.add_argument("-c", "--config", default=os.environ.get("RPICAMD_CONFIG", "/etc/raspimjpeg"))
    parser.add_argument("--backend", default=os.environ.get("RPICAMD_BACKEND", "auto"),
                        choices=["auto", "picamera2", "v4l2", "test"])
    parser.add_argument("--version", action="version", version="rpicamd " + VERSION)
    args = parser.parse_args()
    if np is None:
        sys.stderr.write("rpicamd needs python3-numpy\n")
        return 1
    Daemon(args.config, args.backend).run()
    return 0


if __name__ == "__main__":
    sys.exit(main())
