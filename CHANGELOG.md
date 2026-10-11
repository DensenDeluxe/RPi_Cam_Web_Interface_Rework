# Changelog

## 7.1.0 – rpicamd: support for current boards and cameras

- New camera daemon `backend/rpicamd.py`, a drop-in replacement for raspimjpeg (same FIFO commands,
  config files, status file, preview, file names and thumbnails, so the web interface and scheduler are
  unchanged). Backends: Picamera2/libcamera (Pi 1–5, Zero, Compute Modules; Camera Module 1/2/3, HQ,
  Global Shutter, AI Camera, Arducam and other libcamera cameras; Bullseye or newer, 32/64-bit),
  V4L2 for USB webcams and other boards, and a synthetic test camera.
- Supports video with pre-record buffer, split and timed recording, mp4 boxing with ffmpeg (MP4Box as
  fallback), stills (also during recording), timelapse, annotation with custom colours, motion detection
  with the scheduler, job macros, user config and camera selection on boards with two cameras.
- Autofocus mode and lens position settings for autofocus cameras (e.g. Camera Module 3).
- The settings page reads the capabilities of the running daemon (`camera_info.json`) and hides settings
  it can not apply; the *System* tab shows the camera model and daemon.
- install.sh chooses the daemon automatically (raspimjpeg only on Buster and on Bullseye with Legacy Camera),
  `camerabackend` in config.txt overrides it.

## 7.0.1 – Hardware compatibility

- README: detailed compatibility tables for boards, operating systems, official and third-party cameras,
  clones and USB webcams (only Pi 1–4/Zero/CM1–4 with 32-bit Buster/Bullseye and OV5647, IMX219 or
  IMX477 sensors work with raspimjpeg).
- Installer: detects the 64-bit userland with `dpkg --print-architecture` (the old `uname -m` check warned
  on every Pi 4 with 32-bit OS because the firmware boots a 64-bit kernel), warns for Pi 400, the first
  Pi Zero, Pi 500, Compute Module 5, non-Raspberry-Pi boards and unknown Debian releases.
- Installer: uses `raspimjpeg-stretch` on Debian 9 (the current build needs glibc 2.28), installs
  `libraspberrypi-dev` on Bullseye (unversioned MMAL libraries), installs `gpac` only when the release
  provides it, and checks after the install that raspimjpeg can load and that `vcgencmd get_camera`
  reports an enabled and detected camera.
- `cam.sh` points to `raspi-config` on Bullseye and newer instead of writing an incomplete legacy setup.
- Settings: video size limited to 1920×1080 (H.264 hardware encoder), ISO to 800, frame rate up to 200;
  the built-in resolution presets keep the still size so they suit every camera module.
- Presets: fixed the "Full Chip" entries (video above 1080p could not be recorded) and wrong labels,
  added presets for the HQ Camera (`uPresetsN-IMX477.html`, `uPresetsP-IMX477.html`).

## 7.0.0 – Rework

### New interface
- Complete redesign of all pages (live view, media, schedule, motion, minimal view) with a
  self-contained stylesheet and vanilla JavaScript. jQuery 2.1.3, Bootstrap 3.3.2, the Glyphicons
  font and the `es_*.css` styles were removed (they had known security issues and no mobile layout).
- Light / dark / automatic theme and an English / German interface (language follows the browser,
  switchable in the top bar).
- Live view: status indicator, recording badge, overlay with start button while the camera is stopped,
  fullscreen button, preview pauses while the tab is hidden.
- Camera settings in tabs (Image, Exposure, Video, Overlay, Motion, System); single values apply on
  change, grouped values with one *Apply* button; sliders with reset to default; colour pickers for
  the annotation colours; browser time sync for the Pi clock.
- Pan/tilt pad with keyboard (W A S D, ignored while typing) and drag on the image, Pi-Light colour picker.
- Media gallery: grouping by day, filters as links, adjustable thumbnail size, lazy loading,
  lightbox with keyboard/swipe navigation, selection bar for ZIP / lock / unlock / delete,
  confirmation dialogs, storage meter.
- Schedule: day mode switch, sun information, period table with day chips (cards on phones),
  grouped and explained parameters, log viewer, searchable command reference, warning about unsaved changes.
- Motion daemon page: search, highlighted changes.
- Use `css/custom.css` for own styling instead of the old style selector.

### Security
- User levels are enforced on the server: `cmd_pipe.php` (capture commands need level 3, everything else
  level 6, quick action macros are allowed for everyone who sees them), `cmd_func.php`, media actions,
  schedule and motion pages.
- Cross-site requests (CSRF) are rejected using `Sec-Fetch-Site` / `Origin`; scripts are not affected.
- `cmd_pipe.php` accepts one command per request (line breaks are no longer passed through).
- `sy` and `um` only accept plain macro names – shell syntax could previously be injected through the
  macro name and was executed by raspimjpeg.
- The schedule form can no longer change the FIFO paths (previously any writable file could be overwritten).
- Shell arguments are escaped (ZIP download, timelapse conversion, `settime`, file sizes).
- Output is HTML-escaped (setting values, file names); cookie values are validated.
- Delay parameters of `cam_pic.php`, `cam_get.php` and `cam_pic_new.php` are clamped.
- Apache: directory listing disabled, configuration/log/FIFO files blocked, Apache 2.4 `Require` syntax.
- nginx: basic authentication now also protects media files (it only covered PHP before), same file blocking.

### Fixes
- PHP 8.x: replaced deprecated `strftime`, `date_sunrise`/`date_sunset` and `preg_split(..., null)`;
  fixed undefined index warnings.
- Scheduler crashed with a TypeError when a period had no day selected.
- Numeric GMT offsets: the current time now uses the same offset as sunrise/sunset.
- `motion.php` restore failed (JSON object used as array).
- `cam_picLatestTL.php` was broken on PHP 8 (`count(files)`).
- Downloading a timelapse appended the HTML page to the ZIP file.
- `filesize_n()` reported 4 GB for empty files and wrong sizes above 2 GB on 32-bit PHP.
- Sending a command or saving the schedule no longer hangs when raspimjpeg / the scheduler is not running.
- Locking videos now also locks the matching `.h264` file.
- Keyboard pan control no longer fires while typing in input fields.
- After actions the media page redirects (no resubmission on reload).

### Installer and scripts
- PHP version detection for Debian 9–13 (8.4 on Trixie) with a fallback to the distribution default.
- Warning when the legacy camera stack is not available (Bookworm or newer, 64-bit OS, Raspberry Pi 5).
- nginx: PHP-FPM socket path works for every PHP version (was broken for PHP 8), streaming without buffering.
- No longer installs the obsolete APC config (`apc.so` does not exist for PHP 7+) and removes it from older installs.
- Creates a valid `/etc/rc.local` when the system has none.
- Removes files of the old interface on update.
- `remove.sh`: current package names, fixed `>dev/null` redirects.
- Removed the deprecated `RPi_Cam_Web_Interface_Installer.sh` and `installreadme.txt`.
