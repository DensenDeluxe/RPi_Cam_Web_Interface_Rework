/*! RPi Cam Web Interface – live view, camera actions and settings */
(function () {
	'use strict';

	var R = window.RPI, t = R.t;
	var cfg = R.live || {};
	var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
	var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

	var img = $('#mjpeg_dest');
	var stage = $('#stage');
	var overlay = $('#stageOverlay');
	var pill = $('#statusPill');
	var badge = $('#recBadge');
	if (!img || !stage) return;

	var state = 'unknown';
	var halted = false;
	var pageHidden = document.hidden;
	var previewDelay = calcDelay(cfg.videoFps, cfg.divider);

	function calcDelay(fps, divider) {
		return Math.floor((divider || 1) / Math.max(fps || 25, 1) * 1000000);
	}

	function setIcon(svg, name) {
		var use = svg && svg.querySelector('use');
		if (use) use.setAttribute('href', 'img/icons.svg#' + name);
	}

	// ---------- preview ----------
	var errors = 0;
	var frameTimer = null;
	var streaming = false;

	function showOverlay(kind) {
		if (!overlay) return;
		if (!kind) { overlay.hidden = true; return; }
		var startBtn = $('#overlayStart');
		overlay.hidden = false;
		setIcon($('svg', overlay), kind === 'halted' ? 'power' : 'alert');
		$('strong', overlay).textContent = t(kind === 'halted' ? 'live.halted' : 'live.nosignal');
		$('p', overlay).textContent = t(kind === 'halted' ? 'live.halted_hint' : 'live.nosignal_hint');
		if (startBtn) startBtn.hidden = kind !== 'halted';
	}

	function nextFrame() {
		clearTimeout(frameTimer);
		if (pageHidden || halted) return;
		img.src = 'cam_pic.php?time=' + Date.now() + '&pDelay=' + previewDelay;
	}

	function startStream() {
		if (pageHidden || halted) return;
		streaming = true;
		img.src = 'cam_pic_new.php?time=' + Date.now() + '&pDelay=' + previewDelay;
	}

	function stopStream() {
		streaming = false;
		img.removeAttribute('src');
	}

	function resumePreview() {
		if (cfg.mjpeg) { if (!streaming) startStream(); } else nextFrame();
	}

	function restartPreview() {
		if (cfg.mjpeg) { stopStream(); startStream(); } else nextFrame();
	}

	img.addEventListener('load', function () {
		errors = 0;
		if (!halted) showOverlay(null);
		if (!cfg.mjpeg) nextFrame();
	});
	img.addEventListener('error', function () {
		if (!img.getAttribute('src')) return;
		errors++;
		if (errors >= 3 && !halted) showOverlay('nosignal');
		clearTimeout(frameTimer);
		frameTimer = setTimeout(function () {
			if (cfg.mjpeg) { streaming = false; startStream(); } else nextFrame();
		}, Math.min(500 * errors, 4000));
	});

	document.addEventListener('visibilitychange', function () {
		pageHidden = document.hidden;
		if (pageHidden) {
			clearTimeout(frameTimer);
			if (cfg.mjpeg) stopStream();
			abortStatus();
		} else {
			resumePreview();
			pollStatus();
		}
	});

	// ---------- status & action buttons ----------
	// per state: [enabled, active] for each action, motion active 2 = recording triggered by motion
	var STATES = {
		ready:       { video: [1, 0], image: [1, 0], timelapse: [1, 0], motion: [1, 0], camera: [1, 0] },
		md_ready:    { video: [0, 0], image: [1, 0], timelapse: [1, 0], motion: [1, 1], camera: [0, 0] },
		timelapse:   { video: [1, 0], image: [0, 0], timelapse: [1, 1], motion: [0, 0], camera: [0, 0] },
		tl_md_ready: { video: [0, 0], image: [1, 0], timelapse: [1, 1], motion: [1, 1], camera: [0, 0] },
		video:       { video: [1, 1], image: [1, 0], timelapse: [1, 0], motion: [0, 0], camera: [0, 0] },
		md_video:    { video: [0, 1], image: [1, 0], timelapse: [1, 0], motion: [0, 2], camera: [0, 0] },
		tl_video:    { video: [1, 1], image: [0, 0], timelapse: [1, 1], motion: [0, 0], camera: [0, 0] },
		tl_md_video: { video: [1, 1], image: [0, 0], timelapse: [1, 1], motion: [0, 2], camera: [0, 0] },
		image:       { video: [0, 0], image: [0, 1], timelapse: [0, 0], motion: [0, 0], camera: [0, 0] },
		halted:      { video: [0, 0], image: [0, 0], timelapse: [0, 0], motion: [0, 0], camera: [1, 1] }
	};
	var ACTIONS = {
		video: function (a) { return a ? ['act.video_stop', 'ca 0', 'stop'] : ['act.video', 'ca 1', 'record']; },
		image: function (a) { return a ? ['act.image_busy', 'im', 'camera'] : ['act.image', 'im', 'camera']; },
		timelapse: function (a) { return a ? ['act.timelapse_stop', 'tl 0', 'stop'] : ['act.timelapse', 'tl 1', 'clock']; },
		motion: function (a) { return a === 2 ? ['act.motion_rec', '', 'activity'] : a ? ['act.motion_stop', 'md 0', 'stop'] : ['act.motion', 'md 1', 'activity']; },
		camera: function (a) { return a ? ['act.camera_start', 'ru 1', 'play'] : ['act.camera_stop', 'ru 0', 'power']; }
	};
	var RECORDING = { video: 1, md_video: 1, tl_video: 1, tl_md_video: 1 };

	function setPill(s) {
		if (!pill) return;
		pill.setAttribute('data-state', s);
		$('.label', pill).textContent = t('status.' + s);
		pill.title = t('status.' + s);
	}

	function applyState(s) {
		if (s.substr(0, 5) === 'Error') {
			setPill('error');
			R.toast(t('live.error', { msg: s.substr(7) || s }), 'error', 10000);
			return;
		}
		var def = STATES[s];
		if (!def) return;
		state = s;
		setPill(s);

		$$('.action[data-action]').forEach(function (btn) {
			var name = btn.getAttribute('data-action');
			var st = def[name];
			var info = ACTIONS[name](st[1]);
			btn.disabled = !st[0] || !info[1];
			btn.setAttribute('data-cmd', info[1]);
			$('.label', btn).textContent = t(info[0]);
			setIcon($('svg', btn), info[2]);
			btn.classList.toggle('is-active', name !== 'camera' && !!st[1]);
			btn.classList.toggle('is-start', name === 'camera' && !!st[1]);
			btn.setAttribute('aria-pressed', name !== 'camera' && st[1] ? 'true' : 'false');
		});
		$$('[data-recording-lock]').forEach(function (el) { el.disabled = s === 'video' || s === 'md_video'; });
		if (badge) badge.hidden = !RECORDING[s];

		var wasHalted = halted;
		halted = s === 'halted';
		if (halted) {
			showOverlay('halted');
			if (cfg.mjpeg) stopStream();
		} else if (wasHalted) {
			showOverlay(null);
			resumePreview();
		}
	}

	var lastStatus = '';
	var statusCtl = null;
	var statusTimer = null;
	function abortStatus() {
		clearTimeout(statusTimer);
		if (statusCtl) { statusCtl.abort(); statusCtl = null; }
	}
	function pollStatus() {
		abortStatus();
		if (pageHidden) return;
		var ctl = statusCtl = (typeof AbortController === 'function') ? new AbortController() : null;
		fetch('status_mjpeg.php?last=' + encodeURIComponent(lastStatus), { cache: 'no-store', credentials: 'same-origin', signal: ctl ? ctl.signal : undefined })
			.then(function (res) { if (!res.ok) throw new Error(res.status); return res.text(); })
			.then(function (text) {
				if (ctl !== statusCtl) return;
				lastStatus = text;
				applyState(text.trim());
				statusTimer = setTimeout(pollStatus, 50);
			})
			.catch(function (err) {
				if (err && err.name === 'AbortError') return;
				setPill('offline');
				lastStatus = '';
				statusTimer = setTimeout(pollStatus, 3000);
			});
	}

	$$('.action[data-action]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var cmd = btn.getAttribute('data-cmd');
			if (cmd) R.sendCmd(cmd);
		});
	});
	var overlayStart = $('#overlayStart');
	if (overlayStart) overlayStart.addEventListener('click', function () { R.sendCmd('ru 1'); });

	// ---------- fullscreen ----------
	var fsBtn = $('#fullscreenBtn');
	function isFullscreen() {
		return document.fullscreenElement === stage || document.webkitFullscreenElement === stage || stage.classList.contains('pseudo-fullscreen');
	}
	function updateFsIcon() { if (fsBtn) setIcon($('svg', fsBtn), isFullscreen() ? 'minimize' : 'maximize'); }
	function toggleFullscreen(force) {
		var on = typeof force === 'boolean' ? force : !isFullscreen();
		if (on === isFullscreen()) return;
		if (on) {
			if (stage.requestFullscreen) stage.requestFullscreen().catch(function () { stage.classList.add('pseudo-fullscreen'); updateFsIcon(); });
			else if (stage.webkitRequestFullscreen) stage.webkitRequestFullscreen();
			else stage.classList.add('pseudo-fullscreen');
		} else {
			if (stage.classList.contains('pseudo-fullscreen')) stage.classList.remove('pseudo-fullscreen');
			else if (document.exitFullscreen) document.exitFullscreen();
			else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
		}
		updateFsIcon();
	}
	document.addEventListener('fullscreenchange', updateFsIcon);
	document.addEventListener('webkitfullscreenchange', updateFsIcon);
	document.addEventListener('keydown', function (ev) {
		if (ev.key === 'Escape' && stage.classList.contains('pseudo-fullscreen')) toggleFullscreen(false);
	});
	if (fsBtn) fsBtn.addEventListener('click', function () { toggleFullscreen(); });
	if (cfg.panMode !== 1) img.addEventListener('click', function () { toggleFullscreen(); });
	else img.addEventListener('dblclick', function () { toggleFullscreen(); });
	function hashHandler() {
		var h = window.location.hash;
		if (h === '#full' || h === '#fullscreen') toggleFullscreen(true);
		else if (h === '#normal' || h === '#normalscreen') toggleFullscreen(false);
	}
	window.addEventListener('hashchange', hashHandler);
	if (cfg.fullscreen) stage.classList.add('pseudo-fullscreen');

	// ---------- settings ----------
	function markApplied(el) {
		var box = el.closest('.field, .field-group');
		var mark = box && box.querySelector('.applied-mark');
		if (!mark) return;
		mark.classList.add('show');
		clearTimeout(mark._t);
		mark._t = setTimeout(function () { mark.classList.remove('show'); }, 1800);
	}

	function hexToYuv(hex) {
		var n = parseInt(hex.slice(1), 16), r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
		var y = 0.299 * r + 0.587 * g + 0.114 * b;
		var u = -0.168736 * r - 0.331264 * g + 0.5 * b + 128;
		var v = 0.5 * r - 0.418688 * g - 0.081312 * b + 128;
		return [y, u, v].map(function (c) { return Math.max(0, Math.min(255, Math.round(c))); });
	}

	// value of a control as sent to raspimjpeg, or null when invalid
	function controlValue(el) {
		if (el.type === 'checkbox') return el.checked ? '1' : '0';
		if (el.type === 'color') return hexToYuv(el.value).join(' ');
		if (el.type === 'number' || el.type === 'range') {
			var n = parseFloat(String(el.value).replace(',', '.'));
			if (isNaN(n)) return null;
			if (el.min !== '') n = Math.max(n, parseFloat(el.min));
			if (el.max !== '') n = Math.min(n, parseFloat(el.max));
			if (String(n) !== String(el.value)) el.value = n;
			if (el.dataset.scale) n = Math.round(n * parseFloat(el.dataset.scale));
			return String(n);
		}
		return el.value;
	}

	function afterApply(el) {
		if (el.getAttribute('data-after') === 'reload') setTimeout(function () { window.location.reload(); }, 1000);
	}

	function sendSetting(el) {
		var value = controlValue(el);
		if (value === null) { el.focus(); return; }
		R.sendCmd(el.getAttribute('data-cmd') + ' ' + value).then(function (ok) {
			if (!ok) return;
			markApplied(el);
			R.toast(t('applied', { name: el.getAttribute('data-label') }));
			afterApply(el);
		});
	}

	$$('[data-cmd]:not(.action)').forEach(function (el) {
		if (el.type === 'range') {
			var out = el.parentNode.querySelector('output');
			el.addEventListener('input', function () { if (out) out.textContent = el.value; });
		}
		el.addEventListener('change', function () { sendSetting(el); });
		if (el.type === 'text') {
			el.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); el.blur(); } });
		}
	});

	$$('[data-default-for]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var el = document.getElementById(btn.getAttribute('data-default-for'));
			if (!el) return;
			el.value = btn.getAttribute('data-default');
			el.dispatchEvent(new Event('input'));
			el.dispatchEvent(new Event('change'));
		});
	});

	function groupCommand(group) {
		var parts = [group.getAttribute('data-group-cmd')];
		var ids = group.getAttribute('data-fields').split(' ');
		for (var i = 0; i < ids.length; i++) {
			var el = document.getElementById(ids[i]);
			var v = el ? controlValue(el) : null;
			if (v === null) { if (el) el.focus(); return null; }
			parts.push(v);
		}
		return parts.join(' ');
	}

	function applyGroup(group) {
		var cmd = groupCommand(group);
		if (!cmd) return Promise.resolve(false);
		return R.sendCmd(cmd).then(function (ok) {
			if (!ok) return false;
			markApplied(group);
			R.toast(t('applied', { name: group.getAttribute('data-label') }));
			var c = group.getAttribute('data-group-cmd');
			if (c === 'px' || c === 'pv') {
				var fps = parseInt(($('#video_fps') || {}).value, 10) || cfg.videoFps;
				var div = parseInt(($('#divider') || {}).value, 10) || cfg.divider;
				previewDelay = calcDelay(fps, div);
				setTimeout(restartPreview, 1000);
			}
			return true;
		});
	}

	$$('[data-group-cmd]').forEach(function (group) {
		var btn = $('[data-apply]', group);
		if (btn) btn.addEventListener('click', function () { applyGroup(group); });
		$$('input', group).forEach(function (input) {
			if (input.type !== 'checkbox' && input.type !== 'color') {
				input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); applyGroup(group); } });
			}
		});
	});

	// colour effect preview swatch
	var ceSwatch = $('#ce_swatch');
	function updateSwatch() {
		var u = (parseFloat(($('#ce_u') || {}).value) || 128) - 128;
		var v = (parseFloat(($('#ce_v') || {}).value) || 128) - 128;
		var y = 128;
		var rgb = [y + 1.402 * v, y - 0.344136 * u - 0.714136 * v, y + 1.772 * u].map(function (c) { return Math.max(0, Math.min(255, Math.round(c))); });
		ceSwatch.style.background = 'rgb(' + rgb.join(',') + ')';
	}
	if (ceSwatch) {
		updateSwatch();
		['#ce_u', '#ce_v'].forEach(function (s) { var el = $(s); if (el) el.addEventListener('input', updateSwatch); });
	}

	// resolution presets: "videoW videoH fps boxFps imageW imageH [divider]", "-" keeps the current value
	var preset = $('#preset');
	if (preset) preset.addEventListener('change', function () {
		if (!preset.value) return;
		var v = preset.value.trim().split(/\s+/);
		var ids = ['video_width', 'video_height', 'video_fps', 'MP4Box_fps', 'image_width', 'image_height', 'fps_divider'];
		ids.forEach(function (id, i) {
			var el = document.getElementById(id);
			if (el && v[i] !== undefined && v[i] !== '-') el.value = parseInt(v[i], 10);
		});
		var group = document.querySelector('[data-group-cmd="px"]');
		if (group) applyGroup(group);
	});

	var annoDefault = $('#annotationDefault');
	if (annoDefault) annoDefault.addEventListener('click', function () {
		var el = $('#annotation');
		el.value = 'RPi Cam %Y.%M.%D_%h:%m:%s';
		sendSetting(el);
	});

	var streamMode = $('#streamMode');
	if (streamMode) streamMode.addEventListener('change', function () {
		R.setCookie('stream_mode', streamMode.value);
		window.location.reload();
	});

	// job macros: um <index> [-]<macro>
	$$('.macro-row').forEach(function (row) {
		$('.macro-apply', row).addEventListener('click', function () {
			var value = $('.macro-value', row).value.trim();
			if (!$('.macro-enabled', row).checked) value = '-' + value;
			R.sendCmd('um ' + row.getAttribute('data-index') + ' ' + value).then(function (ok) {
				if (ok) R.toast(t('applied', { name: $('code', row).textContent }));
			});
		});
	});

	// ---------- system functions ----------
	function systemCall(data) {
		return R.post('cmd_func.php', data).then(function () { return true; }, function (err) { R.reportError(err); return false; });
	}

	function timeString(d) {
		var months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
		var p = function (n) { return (n < 10 ? '0' : '') + n; };
		return p(d.getDate()) + ' ' + months[d.getMonth()] + ' ' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
	}

	function setTime(str) {
		if (!str || str.indexOf('-') >= 0) { R.toast(t('failed', { msg: str || '?' }), 'error'); return; }
		systemCall({ cmd: 'settime', timestr: str }).then(function (ok) { if (ok) R.toast(t('time_set', { t: str })); });
	}
	var timeSync = $('#timeSync');
	if (timeSync) timeSync.addEventListener('click', function () { setTime(timeString(new Date())); });
	var timeSet = $('#timeSet');
	if (timeSet) timeSet.addEventListener('click', function () { setTime($('#timestr').value.trim()); });

	$$('[data-system]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var what = btn.getAttribute('data-system');
			var text = { reset: 'confirm_reset', reboot: 'confirm_reboot', shutdown: 'confirm_shutdown' }[what];
			R.confirm(t(text), { danger: true, okLabel: btn.textContent.trim() }).then(function (yes) {
				if (!yes) return;
				if (what === 'reset') {
					R.sendCmd('rs 1').then(function (ok) { if (ok) setTimeout(function () { window.location.reload(); }, 1000); });
				} else {
					systemCall({ cmd: what }).then(function (ok) {
						if (!ok) return;
						R.toast(t(what === 'reboot' ? 'rebooting' : 'shutting_down'), 'info', 8000);
						if (what === 'reboot') setTimeout(function wait() {
							fetch('status_mjpeg.php?last=x', { cache: 'no-store' }).then(function () { window.location.reload(); }, function () { setTimeout(wait, 5000); });
						}, 30000);
					});
				}
			});
		});
	});

	// ---------- tabs ----------
	var tabs = $$('.tab[role="tab"]');
	function selectTab(tab, focus) {
		tabs.forEach(function (tb) {
			var on = tb === tab;
			tb.setAttribute('aria-selected', on ? 'true' : 'false');
			tb.tabIndex = on ? 0 : -1;
			var panel = document.getElementById(tb.getAttribute('aria-controls'));
			if (panel) panel.hidden = !on;
		});
		if (focus) tab.focus();
		tab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
		R.store.set('tab', tab.id);
	}
	tabs.forEach(function (tab, i) {
		tab.addEventListener('click', function () { selectTab(tab); });
		tab.addEventListener('keydown', function (ev) {
			var d = ev.key === 'ArrowRight' ? 1 : ev.key === 'ArrowLeft' ? -1 : 0;
			if (d) { ev.preventDefault(); selectTab(tabs[(i + d + tabs.length) % tabs.length], true); }
		});
	});
	if (tabs.length) {
		var saved = document.getElementById(R.store.get('tab', ''));
		var initial = tabs.indexOf(saved) >= 0 ? saved : tabs[0];
		tabs.forEach(function (tb) { tb.tabIndex = tb === initial ? 0 : -1; });
		if (initial !== tabs[0]) selectTab(initial);
	}

	// ---------- pan / tilt ----------
	var pan = cfg.pan || 100, tilt = cfg.tilt || 100;
	var panBusy = false, panDirty = false;
	function panRequest(query) {
		return fetch('pipan.php?' + query, { cache: 'no-store', credentials: 'same-origin' }).catch(function () {});
	}
	function sendPanTilt() {
		if (panBusy) { panDirty = true; return; }
		panBusy = true; panDirty = false;
		panRequest('pan=' + pan + '&tilt=' + tilt).then(function () {
			panBusy = false;
			if (panDirty) sendPanTilt();
		});
	}
	function panMove(dir) {
		var btn = $('[data-pan="' + dir + '"]');
		if (btn) { btn.classList.add('flash'); setTimeout(function () { btn.classList.remove('flash'); }, 150); }
		if (cfg.panMode === 1) {
			if (dir === 'left') pan = Math.min(200, pan + 10);
			if (dir === 'right') pan = Math.max(0, pan - 10);
			if (dir === 'up') tilt = Math.max(0, tilt - 10);
			if (dir === 'down') tilt = Math.min(200, tilt + 10);
			sendPanTilt();
		} else if (cfg.panMode === 2) {
			panRequest('action=' + dir);
		}
	}
	$$('[data-pan]').forEach(function (btn) {
		btn.addEventListener('click', function () { panMove(btn.getAttribute('data-pan')); });
	});

	if (cfg.panMode === 1) {
		var drag = null;
		img.addEventListener('pointerdown', function (ev) {
			drag = { x: ev.clientX, y: ev.clientY, pan: pan, tilt: tilt };
			img.setPointerCapture(ev.pointerId);
		});
		img.addEventListener('pointermove', function (ev) {
			if (!drag) return;
			pan = Math.max(0, Math.min(200, drag.pan + Math.round((ev.clientX - drag.x) / 5)));
			tilt = Math.max(0, Math.min(200, drag.tilt + Math.round((drag.y - ev.clientY) / 5)));
			sendPanTilt();
		});
		['pointerup', 'pointercancel'].forEach(function (n) { img.addEventListener(n, function () { drag = null; }); });
	}

	// ---------- pi-light ----------
	var lightOn = false;
	var lightColour = $('#pilightColour');
	var lightBtn = $('#pilightToggle');
	function sendLight() {
		var n = parseInt(lightColour.value.slice(1), 16);
		var rgb = lightOn ? [(n >> 16) & 255, (n >> 8) & 255, n & 255] : [0, 0, 0];
		panRequest('red=' + rgb[0] + '&green=' + rgb[1] + '&blue=' + rgb[2]);
		lightBtn.classList.toggle('btn-primary', lightOn);
		lightBtn.classList.toggle('btn-soft', !lightOn);
		lightBtn.setAttribute('aria-pressed', lightOn ? 'true' : 'false');
	}
	function toggleLight() { lightOn = !lightOn; sendLight(); }
	if (lightBtn && lightColour) {
		lightBtn.addEventListener('click', toggleLight);
		lightColour.addEventListener('change', function () { if (lightOn) sendLight(); });
	}

	// keyboard shortcuts for pan/tilt and light, ignored while typing
	document.addEventListener('keydown', function (ev) {
		if (ev.ctrlKey || ev.metaKey || ev.altKey) return;
		var tag = (ev.target.tagName || '').toLowerCase();
		if (tag === 'input' || tag === 'textarea' || tag === 'select' || ev.target.isContentEditable) return;
		var dir = { a: 'left', w: 'up', d: 'right', s: 'down' }[ev.key.toLowerCase()];
		if (dir && cfg.panMode) { ev.preventDefault(); panMove(dir); }
		else if (ev.key.toLowerCase() === 'f' && lightBtn) { ev.preventDefault(); toggleLight(); }
	});

	// ---------- user buttons ----------
	$$('[data-macro]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			R.sendCmd('sy ' + btn.getAttribute('data-macro')).then(function (ok) { if (ok) R.toast(t('sent')); });
		});
	});

	// ---------- start ----------
	setPill('unknown');
	hashHandler();
	pollStatus();
	// the preview reloads itself endlessly, start it after the page load so the browser does not show it as loading forever
	if (document.readyState === 'complete') resumePreview();
	else window.addEventListener('load', resumePreview);
})();
