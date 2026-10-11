/*! RPi Cam Web Interface – shared helpers (theme, language, toasts, dialogs, requests) */
(function () {
	'use strict';

	var R = window.RPI = window.RPI || {};
	var i18n = R.i18n || {};

	R.t = function (key, vars) {
		var s = Object.prototype.hasOwnProperty.call(i18n, key) ? i18n[key] : key;
		if (vars) {
			Object.keys(vars).forEach(function (k) { s = s.split('{' + k + '}').join(vars[k]); });
		}
		return s;
	};

	// localStorage can be unavailable (private mode, blocked storage)
	R.store = {
		get: function (key, fallback) {
			try { var v = window.localStorage.getItem('rpicam.' + key); return v === null ? fallback : v; } catch (e) { return fallback; }
		},
		set: function (key, value) {
			try { window.localStorage.setItem('rpicam.' + key, value); } catch (e) { /* ignore */ }
		}
	};

	R.setCookie = function (name, value, days) {
		var d = new Date();
		d.setTime(d.getTime() + (days || 365) * 86400000);
		document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
	};

	R.icon = function (name) {
		return '<svg class="icon" aria-hidden="true"><use href="img/icons.svg#' + name + '"></use></svg>';
	};

	R.escape = function (s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	};

	// ---------- toasts ----------
	var toastBox = document.getElementById('toasts');
	R.toast = function (msg, type, ms) {
		if (!toastBox) return;
		type = type || 'success';
		var icon = { success: 'check', error: 'alert', info: 'info' }[type] || 'info';
		var el = document.createElement('div');
		el.className = 'toast ' + type;
		el.setAttribute('role', type === 'error' ? 'alert' : 'status');
		el.innerHTML = R.icon(icon) + '<span></span>';
		el.lastChild.textContent = msg;
		toastBox.appendChild(el);
		while (toastBox.children.length > 4) toastBox.removeChild(toastBox.firstChild);
		setTimeout(function () {
			el.classList.add('hide');
			setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 300);
		}, ms || (type === 'error' ? 6000 : 2600));
	};

	// ---------- confirm dialog ----------
	R.confirm = function (text, opts) {
		opts = opts || {};
		var dlg = document.getElementById('confirmDialog');
		if (!dlg || typeof dlg.showModal !== 'function') {
			return Promise.resolve(window.confirm(text));
		}
		dlg.querySelector('.dialog-text').textContent = text;
		var ok = dlg.querySelector('button[value="ok"]');
		var cancel = dlg.querySelector('button[value="cancel"]');
		ok.textContent = opts.okLabel || R.t('ok');
		cancel.textContent = R.t('cancel');
		ok.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');
		return new Promise(function (resolve) {
			dlg.returnValue = '';
			dlg.addEventListener('close', function onClose() {
				dlg.removeEventListener('close', onClose);
				resolve(dlg.returnValue === 'ok');
			});
			dlg.showModal();
			(opts.danger ? cancel : ok).focus();
		});
	};

	// ---------- requests ----------
	R.post = function (url, data) {
		var body = new URLSearchParams();
		Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
		return fetch(url, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'fetch' }
		}).then(function (res) {
			return res.text().then(function (text) {
				if (!res.ok) {
					var err = new Error(text || res.statusText || String(res.status));
					err.status = res.status;
					throw err;
				}
				return text;
			});
		});
	};

	R.reportError = function (err) {
		R.toast(err && err.status === 403 ? R.t('forbidden') : R.t('failed', { msg: (err && err.message) || '?' }), 'error');
	};

	// send a raspimjpeg command; resolves to true on success
	R.sendCmd = function (cmd) {
		return R.post('cmd_pipe.php', { cmd: cmd }).then(function () { return true; }, function (err) {
			R.reportError(err);
			return false;
		});
	};

	// ---------- theme ----------
	var root = document.documentElement;
	var themeBtn = document.getElementById('themeToggle');
	function setTheme(theme, announce) {
		root.setAttribute('data-theme', theme);
		R.store.set('theme', theme);
		if (themeBtn) themeBtn.title = R.t('theme.' + theme);
		if (announce) R.toast(R.t('theme.' + theme), 'info', 1500);
	}
	if (themeBtn) {
		themeBtn.title = R.t('theme.' + (root.getAttribute('data-theme') || 'auto'));
		themeBtn.addEventListener('click', function () {
			var order = ['auto', 'light', 'dark'];
			var cur = order.indexOf(root.getAttribute('data-theme'));
			setTheme(order[(cur + 1) % order.length], true);
		});
	}

	// ---------- language ----------
	var langBtn = document.getElementById('langToggle');
	if (langBtn) {
		langBtn.addEventListener('click', function () {
			R.setCookie('lang', langBtn.getAttribute('data-lang'));
			window.location.reload();
		});
	}

	// ---------- server side flash messages ----------
	Array.prototype.forEach.call(document.querySelectorAll('.flash'), function (el) {
		R.toast(el.textContent, el.getAttribute('data-type') || 'success');
	});

	// ---------- confirm on form buttons: <button data-confirm="text"> ----------
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-confirm]');
		if (!btn || btn.dataset.confirmed === '1') return;
		ev.preventDefault();
		R.confirm(btn.getAttribute('data-confirm'), { danger: btn.hasAttribute('data-danger'), okLabel: btn.getAttribute('data-ok') || undefined }).then(function (yes) {
			if (!yes) return;
			btn.dataset.confirmed = '1';
			btn.click();
			setTimeout(function () { btn.dataset.confirmed = ''; }, 0);
		});
	});
})();
