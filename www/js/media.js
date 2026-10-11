/*! RPi Cam Web Interface – media gallery, selection and viewer */
(function () {
	'use strict';

	var R = window.RPI, t = R.t;
	var items = R.media || [];
	var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
	var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

	// ---------- date headings in the browser's language ----------
	var today = new Date(); today.setHours(0, 0, 0, 0);
	$$('.media-group h2[data-date]').forEach(function (h) {
		var p = h.getAttribute('data-date').split('-');
		var d = new Date(+p[0], +p[1] - 1, +p[2]);
		var diff = Math.round((today - d) / 86400000);
		if (diff === 0) h.textContent = t('media.today');
		else if (diff === 1) h.textContent = t('media.yesterday');
		else {
			try { h.textContent = d.toLocaleDateString(R.lang, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); } catch (e) { /* keep ISO date */ }
		}
	});

	// ---------- thumbnail size ----------
	var size = $('#thumbSize');
	function applySize(v) { $$('.media-grid').forEach(function (g) { g.style.setProperty('--thumb', v + 'px'); }); }
	if (size) {
		size.value = R.store.get('thumb', size.value);
		applySize(size.value);
		size.addEventListener('input', function () { applySize(size.value); R.store.set('thumb', size.value); });
	}

	// ---------- selection ----------
	var boxes = $$('.media-check input');
	var bulkbar = $('#bulkbar');
	var selCount = $('#selCount');
	var selectAll = $('#selectAll');
	var deleteSel = $('#deleteSel');
	function updateSelection() {
		var n = 0;
		boxes.forEach(function (b) {
			b.closest('.media-card').classList.toggle('selected', b.checked);
			if (b.checked) n++;
		});
		document.body.classList.toggle('selecting', n > 0);
		if (bulkbar) bulkbar.hidden = n === 0;
		if (selCount) selCount.textContent = t('media.selected', { n: n });
		if (deleteSel) deleteSel.setAttribute('data-confirm', t('media.confirm_delete_sel', { n: n }));
		if (selectAll) {
			var all = n === boxes.length && n > 0;
			selectAll.querySelector('span').textContent = t(all ? 'media.select_none' : 'media.select_all');
			selectAll.dataset.all = all ? '1' : '';
		}
	}
	boxes.forEach(function (b) { b.addEventListener('change', updateSelection); });
	if (selectAll) selectAll.addEventListener('click', function () {
		var check = !selectAll.dataset.all;
		boxes.forEach(function (b) { b.checked = check; });
		updateSelection();
	});
	var selectNone = $('#selectNone');
	if (selectNone) selectNone.addEventListener('click', function () {
		boxes.forEach(function (b) { b.checked = false; });
		updateSelection();
	});
	updateSelection();

	// ---------- viewer ----------
	var box = $('#lightbox');
	if (!box || !items.length) return;
	var mediaEl = $('#lbMedia'), title = $('#lbTitle');
	var prevBtn = $('#lbPrev'), nextBtn = $('#lbNext');
	var current = -1, lastFocus = null;
	var typeIcon = { i: 'image', v: 'video', t: 'clock' };

	function setParam(thumb) {
		try {
			var url = new URL(window.location.href);
			if (thumb) url.searchParams.set('preview', thumb); else url.searchParams.delete('preview');
			url.searchParams.delete('done'); url.searchParams.delete('n');
			history.replaceState(null, '', url.pathname + url.search);
		} catch (e) { /* old browser */ }
	}

	function preload(i) {
		var it = items[i];
		if (it && it.type !== 'v' && !it.busy) { var im = new Image(); im.src = it.url; }
	}

	function show(i) {
		if (i < 0 || i >= items.length) return;
		current = i;
		var it = items[i];
		var html;
		if (it.busy) {
			html = '<img src="media/' + encodeURI(it.thumb) + '" alt="">';
		} else if (it.type === 'v') {
			html = '<video controls autoplay playsinline preload="metadata" src="' + R.escape(encodeURI(it.url)) + '"></video>';
		} else {
			html = '<img src="' + R.escape(encodeURI(it.url)) + '" alt="">';
		}
		mediaEl.innerHTML = html;
		title.innerHTML = R.icon(typeIcon[it.type] || 'image') + ' #' + R.escape(it.index) + ' · ' + R.escape(it.meta) +
			'<small>' + R.escape(it.when) + (it.busy ? '' : ' · ' + R.escape(it.size)) + '</small>';
		['#lbDownload', '#lbDelete', '#lbConvert'].forEach(function (s) { var b = $(s); if (b) b.value = it.thumb; });
		var conv = $('#lbConvert');
		if (conv) conv.hidden = it.type !== 't';
		$('#lbOpen').href = encodeURI(it.url);
		prevBtn.disabled = i === 0;
		nextBtn.disabled = i === items.length - 1;
		preload(i - 1); preload(i + 1);
		setParam(it.thumb);
	}

	function open(i) {
		lastFocus = document.activeElement;
		box.hidden = false;
		document.body.style.overflow = 'hidden';
		show(i);
		$('#lbClose').focus();
	}

	function close() {
		box.hidden = true;
		mediaEl.innerHTML = '';
		document.body.style.overflow = '';
		setParam(null);
		if (lastFocus) lastFocus.focus();
	}

	$$('[data-open]').forEach(function (btn) {
		btn.addEventListener('click', function () { open(parseInt(btn.getAttribute('data-open'), 10)); });
	});
	$('#lbClose').addEventListener('click', close);
	prevBtn.addEventListener('click', function () { show(current - 1); });
	nextBtn.addEventListener('click', function () { show(current + 1); });
	document.addEventListener('keydown', function (ev) {
		if (box.hidden) return;
		if (ev.key === 'Escape') { ev.preventDefault(); close(); }
		else if (ev.key === 'ArrowLeft') show(current - 1);
		else if (ev.key === 'ArrowRight') show(current + 1);
	});
	var touchX = null;
	box.addEventListener('touchstart', function (ev) { touchX = ev.changedTouches[0].clientX; }, { passive: true });
	box.addEventListener('touchend', function (ev) {
		if (touchX === null) return;
		var dx = ev.changedTouches[0].clientX - touchX;
		touchX = null;
		if (Math.abs(dx) > 60 && ev.target.tagName !== 'VIDEO') show(current + (dx < 0 ? 1 : -1));
	});

	// deep link: preview.php?preview=<thumbnail>
	var wanted = new URLSearchParams(window.location.search).get('preview');
	if (wanted) {
		for (var i = 0; i < items.length; i++) if (items[i].thumb === wanted) { open(i); break; }
	}
})();
