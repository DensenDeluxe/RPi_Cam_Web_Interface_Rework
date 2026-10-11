# RPi Cam Web Interface – Rework

Web interface for the Raspberry Pi camera: live view, video, photos, timelapse, motion detection,
scheduling and a media gallery. This fork modernises the user interface of the
[original RPi Cam Web Interface](https://github.com/silvanmelchior/RPi_Cam_Web_Interface) and fixes
a number of bugs and security problems. The camera backend (`raspimjpeg`) and its command protocol
are unchanged, so existing setups, macros and integrations keep working.

Current version: **7.0.1** – see [CHANGELOG.md](CHANGELOG.md).

![Live view](docs/screenshots/live-desktop.jpg)

| Mobile (German) | Media gallery | Overlay settings |
| --- | --- | --- |
| ![Mobile](docs/screenshots/live-mobile-de.jpg) | ![Media](docs/screenshots/media-dark.jpg) | ![Settings](docs/screenshots/settings-overlay.jpg) |

## Features

- Responsive interface for phone, tablet and desktop with a bottom navigation bar on phones
- Light, dark and automatic theme, English and German interface (`www/lang/`)
- Live preview with status indicator, recording badge, fullscreen (also via `#full` in the URL)
  and an automatic pause while the browser tab is hidden
- Camera settings grouped in tabs; changes apply immediately, sliders with reset to default,
  colour pickers for the annotation colours
- Media gallery grouped by day with lightbox viewer (keyboard and swipe navigation), multi-select,
  ZIP download, lock/unlock, delete and timelapse-to-video conversion
- Scheduler with sun based, all day or fixed time periods, per-day selection, purge rules and a
  searchable command reference
- No external frameworks or CDNs – everything works offline on the Pi

## Compatibility – please read

The camera is driven by `raspimjpeg`, a 32-bit program that uses the **legacy MMAL camera stack** of the
Raspberry Pi firmware. It needs the VideoCore GPU of a Raspberry Pi, a CSI camera module and the
VideoCore userland libraries. In short, the camera only works with:

- a **Raspberry Pi 1–4, Zero (v1.3 or later), Zero 2 W or Compute Module 1–4**,
- **32-bit Raspberry Pi OS Buster or Bullseye** (Bullseye with *Legacy Camera* enabled in `raspi-config`),
- **Camera Module 1 (OV5647), Camera Module 2 (IMX219) or the HQ Camera (IMX477)**, or a third-party
  board with one of these sensors that the firmware accepts.

Everything else (Pi 5, 64-bit OS, Bookworm/Trixie, Camera Module 3, most Arducam cameras, USB webcams,
non-Raspberry-Pi boards) does not work with the current backend. The web interface itself installs on
those systems too, and `install.sh` warns when the camera can not work. Supporting them needs a new
libcamera/Picamera2 based backend that speaks the same FIFO command protocol – this is the main open
item of this fork.

### Boards

| Board | Camera | Notes |
| --- | --- | --- |
| Pi 1 A/B/A+/B+, Pi 2 B, Pi 3 A+/B/B+ | ✅ | `gpu_mem` at least 128; the Pi 1 is slow for this interface |
| Pi 4 B | ✅ | Bullseye: *Legacy Camera* switches to the fkms display driver. If the camera fails with the 64-bit kernel that firmware boots by default, add `arm_64bit=0` to `config.txt` |
| Pi Zero v1.3, Zero W/WH, Zero 2 W | ✅ | needs the 22-pin Zero camera cable; Zero 2 W needs a fully updated Buster or Bullseye |
| Compute Module 1, 3, 3+, 4 | ✅ with setup | `dt-blob.bin` (`dt-blob-cam1.bin` or `dt-blob-dualcam.bin`) and the camera adapter/jumpers of the IO board are required. Two cameras: set `camera_num 1` in `/etc/raspimjpeg` to show the camera selector; only one camera runs at a time, no stereo mode |
| Compute Module 4S | ❓ | Bullseye only, untested |
| Boards released after 2023 (Pi 4 3 GB, Compute Module 0, Pi 3 B with BCM2837B0) | ❓ | newer than the last firmware released with the legacy stack |
| Pi Zero v1.2 (first 2015 batch), Pi 400 | ❌ | no camera connector |
| Pi 5, Pi 500/500+, Compute Module 5 | ❌ | no legacy camera stack and no H.264 hardware encoder |
| Pico (all versions) | ❌ | microcontroller, no Linux |
| Clones and other boards: Orange Pi, Banana Pi, Radxa, Libre Computer, ODROID, Tinker Board, Khadas, Pine64, NVIDIA Jetson, CM-compatible modules with other SoCs | ❌ | no VideoCore GPU, no MMAL |

### Operating systems

| System | Camera | Notes |
| --- | --- | --- |
| Raspberry Pi OS Buster, 32-bit | ✅ | enable the camera in `raspi-config`; end of life since June 2024 |
| Raspberry Pi OS Bullseye, 32-bit | ✅ | enable *Interface Options > Legacy Camera* in `raspi-config`; Debian 11 LTS ended on 31 Aug 2026. Raspberry Pi Imager's "Legacy" image is now Bookworm, download the archived Bullseye armhf image instead |
| Raspbian Stretch, 32-bit | ⚠️ | the installer uses the older raspimjpeg 5.9.5 build; no Pi 4, Zero 2 W or HQ Camera without firmware updates |
| Any 64-bit OS (arm64) | ❌ | the MMAL libraries exist for 32-bit only |
| Raspberry Pi OS Bookworm, Trixie | ❌ | legacy camera stack removed (the web interface installs) |
| Ubuntu, DietPi, Armbian etc. on a Raspberry Pi | ❓ | only 32-bit with the VideoCore userland libraries and the `start_x` firmware |

Because every supported OS is past its security support, keep the camera in a trusted network.

### Camera modules

| Camera | Camera | Notes |
| --- | --- | --- |
| Camera Module 1 (OV5647), also NoIR | ✅ | stills up to 2592×1944; NoIR: use white balance *Greyworld* |
| Camera Module 2 (IMX219), also NoIR | ✅ | stills up to 3280×2464 |
| HQ Camera (IMX477), C/CS and M12 mount | ✅ | stills up to 4056×3040, `gpu_mem=256` recommended; the M12 version was released after the legacy stack and is not officially tested with it |
| Camera Module 3 (IMX708), all variants | ❌ | libcamera only (also no autofocus support in this project) |
| Global Shutter Camera (IMX296), AI Camera (IMX500) | ❌ | libcamera only |
| Third-party OV5647 boards (Arducam, Waveshare and others: NoIR, fisheye, M12, IR-cut) | ✅ | image only; IR-cut and motorized focus need own scripts, e.g. as macros |
| Third-party IMX219 and IMX477 boards | ⚠️ | on a Pi 1–4 or Zero the firmware rejects boards without Raspberry Pi's authentication chip; on Compute Modules they work. Current Arducam IMX219/477P products are sold for libcamera only |
| Arducam IMX519, 64MP (Hawkeye/OwlSight), IMX708 boards, OV9281/OV9782/OV2311/OV7251/AR0234, Pivariety cameras (IMX462, IMX290, IMX327 …), ToF and thermal cameras | ❌ | libcamera or V4L2 only |
| Arducam multi-camera adapter | ⚠️ | one camera at a time, switching needs own macros (GPIO/I2C) |
| USB/UVC webcams (including Arducam USB cameras) | ❌ | raspimjpeg only drives CSI cameras |
| HDMI-to-CSI bridges (TC358743) | ❓ | unsupported firmware driver, untested |

### Limits of the legacy stack

- Video is recorded at most at **1920×1080**. raspimjpeg encodes H.264 level 4.0, which covers
  1080p30; higher frame rates (e.g. 720p90) may drop frames. Stills use the full sensor resolution.
- The firmware chooses the sensor mode, there is no autofocus and no stereo mode.
- Resolution presets for each sensor are in `www/uPresets[N|P]-OV5647.html`, `-IMX219.html` and
  `-IMX477.html` (N = 30 fps, P = 25 fps); copy one to `uPresets.html` in the web folder.

## Installation

```bash
sudo apt-get install -y git
git clone https://github.com/DensenDeluxe/RPi_Cam_Web_Interface_Rework.git
cd RPi_Cam_Web_Interface_Rework
./install.sh          # interactive, ./install.sh q for a quiet install with the saved config.txt
```

On Bullseye enable *Interface Options > Legacy Camera* in `sudo raspi-config` and reboot first.
At the end the installer checks that raspimjpeg can load its libraries and that the firmware detects a
camera, and tells you what is missing.

The installer asks for the sub folder (default `html`, i.e. `http://<pi>/html/`), web server
(apache, nginx or lighttpd), port, an optional user/password and the PHP version (detected
automatically for Debian 9–13).

Other scripts: `./update.sh` (pull latest version and reinstall), `./start.sh`, `./stop.sh`,
`./debug.sh` (start with raspimjpeg output on the console), `./remove.sh`.

To convert timelapses into videos from the media page, install ffmpeg: `sudo apt-get install ffmpeg`.

## Configuration

| File (in the web folder) | Purpose |
| --- | --- |
| `raspimjpeg` / `uconfig` | camera defaults / settings changed in the interface |
| `userLevel` | user levels for authenticated users (`name:level`, see `userLevelExample`) |
| `userbuttons` | up to 12 quick action buttons running macros (see `userbuttonsD`) |
| `uPresets.html` | own resolution presets (examples per camera: `uPresets*-OV5647/IMX219/IMX477.html`) |
| `macros/` | scripts run by job macros and quick action buttons |
| `css/custom.css` | optional stylesheet loaded after the built-in one |
| `multiview.json` | cameras shown by `multiview.html` (see `multiview.jsonD`) |
| `diskUsage.txt` | storage locations shown on the media page (`name:path` per line) |

### User levels

Without a login everyone has full access. With the installer's user/password option the web server
asks for a login and `userLevel` assigns levels; users missing from the file get level 0.

| Level | Allowed |
| --- | --- |
| 0 | live view and quick action buttons |
| 2 | + media page (view and download) |
| 3 | + video, photo and timelapse, delete/lock media |
| 6 | everything: settings, motion detection, schedule, system functions |

The levels are enforced on the server for every request, not only hidden in the interface.

## HTTP interface for scripts and integrations

| URL | Description |
| --- | --- |
| `cmd_pipe.php?cmd=<command>` | send a raspimjpeg command, e.g. `cmd_pipe.php?cmd=im` (GET or POST) |
| `status_mjpeg.php?last=<status>` | long poll for the current status (`ready`, `video`, `halted`, …) |
| `cam_pic.php` | current preview JPEG |
| `cam_pic_new.php` | MJPEG stream |
| `cam_get.php` | current preview JPEG, used by multiview |
| `cam_picLatestTL.php` | latest timelapse image |
| `min.php` | live view only, e.g. for kiosk displays |
| `preview.php?preview=<thumbnail>` | open a recording in the media viewer |

The command list is shown on the schedule page (*Command reference*). Requests coming from other
web sites (CSRF) are rejected; scripts and tools such as curl or Home Assistant are not affected.

## Credits and license

Based on the RPi Cam Web Interface by Silvan Melchior, Robert Tidey and contributors.
Icons based on [Feather](https://feathericons.com) (MIT). See [LICENSE.txt](LICENSE.txt).

Original project, forum and wiki:
[GitHub](https://github.com/silvanmelchior/RPi_Cam_Web_Interface) ·
[Forum](https://forums.raspberrypi.com/viewtopic.php?t=63276) ·
[Wiki](https://elinux.org/RPi-Cam-Web-Interface)
