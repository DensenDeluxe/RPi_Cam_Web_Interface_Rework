/*! RPi Cam Web Interface – schedule page */
(function () {
	'use strict';

	var R = window.RPI, t = R.t;
	var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

	// day mode: 0 = sun based, 1 = all day, 2 = fixed times
	var modeInput = document.getElementById('DayMode');
	function showMode(mode) {
		var show = { 0: 'sun', 1: 'day', 2: 'fixed' }[mode];
		$$('.periods tbody tr').forEach(function (tr) { tr.hidden = !tr.classList.contains(show); });
		$$('.sun-only').forEach(function (el) { el.hidden = mode !== '0'; });
		$$('#dayModeSwitch [data-mode]').forEach(function (b) {
			var on = b.getAttribute('data-mode') === mode;
			b.classList.toggle('active', on);
			b.setAttribute('aria-checked', on ? 'true' : 'false');
		});
	}
	if (modeInput) {
		showMode(modeInput.value);
		$$('#dayModeSwitch [data-mode]').forEach(function (b) {
			b.addEventListener('click', function () {
				modeInput.value = b.getAttribute('data-mode');
				showMode(modeInput.value);
				dirty = true;
			});
		});
	}

	// warn before leaving with unsaved changes
	var form = document.getElementById('scheduleForm');
	var dirty = false;
	if (form) {
		form.addEventListener('input', function () { dirty = true; });
		form.addEventListener('change', function () { dirty = true; });
		form.addEventListener('submit', function () { dirty = false; });
		window.addEventListener('beforeunload', function (ev) {
			if (!dirty) return;
			ev.preventDefault();
			ev.returnValue = t('sched.unsaved');
			return ev.returnValue;
		});
	}

	// command reference filter
	var filter = document.getElementById('cmdFilter');
	if (filter) filter.addEventListener('input', function () {
		var q = filter.value.trim().toLowerCase();
		$$('#cmdTable tbody tr').forEach(function (tr) { tr.hidden = q !== '' && tr.textContent.toLowerCase().indexOf(q) < 0; });
	});

	// log view: start at the newest entries
	var log = document.getElementById('log');
	if (log) log.scrollTop = log.scrollHeight;
})();
