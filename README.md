# RPi Cam Web Interface – Rework

Web interface for the Raspberry Pi camera: live view, video, photos, timelapse, motion detection,
scheduling and a media gallery. This fork modernises the user interface of the
[original RPi Cam Web Interface](https://github.com/silvanmelchior/RPi_Cam_Web_Interface) and fixes
a number of bugs and security problems. The camera backend (`raspimjpeg`) and its command protocol
are unchanged, so existing setups, macros and integrations keep working.

Current version: **7.0.0** – see [CHANGELOG.md](CHANGELOG.md).

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

The camera is driven by `raspimjpeg`, a 32-bit program built against the **legacy MMAL camera stack**.

| System | Camera works? |
| --- | --- |
| Raspberry Pi OS Buster (32-bit) | yes |
| Raspberry Pi OS Bullseye (32-bit) with *Legacy Camera* enabled in `raspi-config` | yes |
| Any 64-bit Raspberry Pi OS | no – MMAL libraries are 32-bit only |
| Raspberry Pi OS Bookworm or newer | no – the legacy camera stack was removed |
| Raspberry Pi 5 | no – no legacy camera stack |
| Camera Module 3 and other libcamera-only sensors | no |

The web interface itself installs and runs on Bookworm/Trixie (PHP 8.2/8.4), and `install.sh` warns
when the camera can not work. Supporting current systems needs a new libcamera/Picamera2 based
backend that speaks the same FIFO command protocol – this is the main open item of this fork.

## Installation

```bash
sudo apt-get install -y git
git clone https://github.com/DensenDeluxe/RPi_Cam_Web_Interface_Rework.git
cd RPi_Cam_Web_Interface_Rework
./install.sh          # interactive, ./install.sh q for a quiet install with the saved config.txt
```

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
| `uPresets.html` | own resolution presets (see `uPresets*.html` examples) |
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
