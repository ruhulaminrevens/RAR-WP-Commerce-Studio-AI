/**
 * RAR Commerce Studio — shared UI core (no build step, no dependencies).
 * Exposes window.RSS for studio-pages.js.
 */
(function () {
	'use strict';

	var B = window.RSS_BOOT || {};
	var RSS = {};
	window.RSS = RSS;
	RSS.boot = B;

	/* ------------------------------------------------------------------ DOM */

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	/**
	 * Element builder. attrs: class, text, html (trusted only), on{}, data{}, any attribute.
	 */
	function h(tag, attrs) {
		var el = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'class') { el.className = v; }
			else if (k === 'text') { el.textContent = v; }
			else if (k === 'html') { el.innerHTML = v; }
			else if (k === 'on') { Object.keys(v).forEach(function (ev) { el.addEventListener(ev, v[ev]); }); }
			else if (k === 'data') { Object.keys(v).forEach(function (d) { el.dataset[d] = v[d]; }); }
			else if (k === 'style' && typeof v === 'object') { Object.keys(v).forEach(function (s) { el.style[s] = v[s]; }); }
			else if (v === true) { el.setAttribute(k, ''); }
			else { el.setAttribute(k, v); }
		});
		for (var i = 2; i < arguments.length; i++) { append(el, arguments[i]); }
		return el;
	}
	function append(el, child) {
		if (child === null || child === undefined || child === false) { return; }
		if (Array.isArray(child)) { child.forEach(function (c) { append(el, c); }); return; }
		el.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
	}
	function clear(el) { while (el && el.firstChild) { el.removeChild(el.firstChild); } return el; }

	RSS.$ = $; RSS.$$ = $$; RSS.h = h; RSS.clear = clear; RSS.append = append;

	/* ---------------------------------------------------------------- icons */

	var ICONS = {
		check: '<path d="M20 6 9 17l-5-5"/>',
		x: '<path d="M18 6 6 18M6 6l12 12"/>',
		alert: '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
		info: '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
		search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		spark: '<path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/>',
		leaf: '<path d="M5 19c8 0 14-6 14-14-8 0-14 6-14 14Z"/><path d="M5 19 13 11"/>',
		copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
		undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
		play: '<path d="m7 4 13 8-13 8z"/>',
		pause: '<path d="M8 5v14M16 5v14"/>',
		stop: '<rect x="6" y="6" width="12" height="12" rx="1.5"/>',
		refresh: '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
		download: '<path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/>',
		link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
		external: '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
		image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-8 9"/>',
		box: '<path d="M3 7.5 12 3l9 4.5-9 4.5L3 7.5Z"/><path d="M3 7.5V16.5L12 21l9-4.5V7.5"/><path d="M12 12v9"/>',
		doc: '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 13h7M9 17h5"/>',
		shield: '<path d="M12 3 4 6v6c0 5 3.4 8.3 8 9 4.6-.7 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
		key: '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3M15 8l2 2"/>',
		gauge: '<path d="M4 18a8 8 0 1 1 16 0"/><path d="m12 18 4-6"/>',
		layers: '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		trash: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
		edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/>',
		eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
		chevron: '<path d="m9 6 6 6-6 6"/>',
		globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
		wand: '<path d="m4 20 11-11M14 4v2M19 9h2M18 5l-1.5 1.5M17 13l1.5 1.5"/>',
		list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>'
	};
	function icon(name, cls) {
		return h('span', {
			class: 'rss-i ' + (cls || ''),
			'aria-hidden': 'true',
			html: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">' + (ICONS[name] || '') + '</svg>'
		});
	}
	RSS.icon = icon;

	/* --------------------------------------------------------------- format */

	var nf = new Intl.NumberFormat(undefined);
	RSS.num = function (n) { return nf.format(Number(n || 0)); };
	RSS.money = function (n) {
		var v = Number(n || 0);
		return '$' + (v === 0 ? '0.00' : v < 0.01 ? v.toFixed(4) : v.toFixed(2));
	};
	RSS.bytes = function (b) {
		b = Number(b || 0);
		if (b < 1024) { return b + ' B'; }
		if (b < 1048576) { return (b / 1024).toFixed(1) + ' KB'; }
		return (b / 1048576).toFixed(2) + ' MB';
	};
	RSS.when = function (mysqlUtc) {
		if (!mysqlUtc) { return ''; }
		var d = new Date(String(mysqlUtc).replace(' ', 'T') + 'Z');
		if (isNaN(d.getTime())) { return mysqlUtc; }
		return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
	};
	RSS.ago = function (mysqlUtc) {
		var d = new Date(String(mysqlUtc).replace(' ', 'T') + 'Z');
		var s = Math.round((Date.now() - d.getTime()) / 1000);
		if (isNaN(s)) { return ''; }
		if (s < 60) { return 'just now'; }
		if (s < 3600) { return Math.round(s / 60) + ' min ago'; }
		if (s < 86400) { return Math.round(s / 3600) + ' h ago'; }
		return Math.round(s / 86400) + ' d ago';
	};
	RSS.plain = function (html) {
		var d = document.createElement('div');
		d.innerHTML = String(html || '').replace(/<(script|style|iframe)[\s\S]*?<\/\1>/gi, '');
		return (d.textContent || '').replace(/\s+/g, ' ').trim();
	};

	/* ------------------------------------------------------------------ API */

	RSS.api = function (path, opts) {
		opts = opts || {};
		var url = B.rest + path;
		if (opts.query) {
			var qs = Object.keys(opts.query).filter(function (k) { return opts.query[k] !== undefined && opts.query[k] !== null && opts.query[k] !== ''; })
				.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(opts.query[k]); }).join('&');
			if (qs) { url += (url.indexOf('?') === -1 ? '?' : '&') + qs; }
		}
		var init = {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': B.nonce, 'Accept': 'application/json' }
		};
		if (opts.body !== undefined) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.body);
		}
		return fetch(url, init).then(function (res) {
			return res.text().then(function (text) {
				var data = null;
				try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
				if (!res.ok) {
					var err = new Error((data && data.message) || ('Request failed (' + res.status + ')'));
					err.code = data && data.code;
					err.status = res.status;
					err.data = data && data.data;
					if (res.status === 403 && err.code === 'rest_cookie_invalid_nonce') {
						err.message = 'Your session expired. Reload the page and try again.';
					}
					throw err;
				}
				if (data === null && text) {
					var bad = new Error('The server returned an unexpected response. A PHP error or another plugin may be interfering.');
					throw bad;
				}
				return data;
			});
		}, function () {
			throw new Error('Network error — check your connection and try again.');
		});
	};

	/* --------------------------------------------------------------- toasts */

	RSS.toast = function (message, type, timeout) {
		var box = $('#rss-toasts');
		if (!box) { return; }
		type = type || 'info';
		var map = { success: 'check', error: 'alert', warn: 'alert', info: 'info' };
		var t = h('div', { class: 'rss-toast is-' + type, role: type === 'error' ? 'alert' : 'status' },
			icon(map[type] || 'info'), h('span', { text: message }),
			h('button', { type: 'button', class: 'rss-toast-x', 'aria-label': 'Dismiss', on: { click: function () { t.remove(); } } }, icon('x')));
		box.appendChild(t);
		setTimeout(function () { t.classList.add('is-out'); setTimeout(function () { t.remove(); }, 300); }, timeout || (type === 'error' ? 9000 : 4500));
	};
	RSS.fail = function (err) { RSS.toast(err && err.message ? err.message : String(err), 'error'); };

	/* ---------------------------------------------------------------- modal */

	RSS.modal = function (opts) {
		return new Promise(function (resolve) {
			var prev = document.activeElement;
			var dlg = h('div', { class: 'rss-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'rss-modal-title' });
			var card = h('div', { class: 'rss-modal-card' + (opts.wide ? ' is-wide' : '') });
			var close = function (val) {
				document.removeEventListener('keydown', onKey);
				dlg.classList.add('is-out');
				setTimeout(function () { dlg.remove(); if (prev && prev.focus) { prev.focus(); } }, 150);
				resolve(val);
			};
			var onKey = function (e) {
				if (e.key === 'Escape') { close(false); }
				if (e.key === 'Tab') {
					var f = $$('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])', card).filter(function (x) { return !x.disabled && x.offsetParent !== null; });
					if (!f.length) { return; }
					if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
					else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
				}
			};
			var head = h('div', { class: 'rss-modal-head' },
				h('h3', { id: 'rss-modal-title', text: opts.title || '' }),
				h('button', { type: 'button', class: 'rss-icon-btn', 'aria-label': 'Close', on: { click: function () { close(false); } } }, icon('x')));
			var body = h('div', { class: 'rss-modal-body' }, opts.body || null);
			var foot = h('div', { class: 'rss-modal-foot' });
			(opts.actions || [{ label: 'OK', primary: true, value: true }]).forEach(function (a) {
				var b = h('button', { type: 'button', class: 'rss-btn ' + (a.danger ? 'is-danger' : a.primary ? 'is-primary' : 'is-ghost') }, a.label);
				b.addEventListener('click', function () {
					if (a.validate && !a.validate()) { return; }
					close(typeof a.value === 'function' ? a.value() : a.value);
				});
				if (a.disabledUntil) { a.disabledUntil(b); }
				foot.appendChild(b);
			});
			card.appendChild(head); card.appendChild(body); card.appendChild(foot);
			dlg.appendChild(card);
			dlg.addEventListener('mousedown', function (e) { if (e.target === dlg) { close(false); } });
			($('#rss-app') || document.body).appendChild(dlg);
			document.addEventListener('keydown', onKey);
			setTimeout(function () {
				var first = $('input, textarea, select', body) || $('.is-primary, .is-danger', foot) || $('button', foot);
				if (first) { first.focus(); }
			}, 30);
		});
	};

	/**
	 * Confirmation dialog. opts: title, text, confirm, danger, require (checkbox label that must be ticked).
	 */
	RSS.confirm = function (opts) {
		var body = h('div', {}, opts.text ? h('p', { class: 'rss-muted', text: opts.text }) : null, opts.extra || null);
		var box = null;
		if (opts.require) {
			box = h('input', { type: 'checkbox', id: 'rss-confirm-req' });
			body.appendChild(h('label', { class: 'rss-check rss-confirm-req', for: 'rss-confirm-req' }, box, h('span', { text: opts.require })));
		}
		return RSS.modal({
			title: opts.title,
			body: body,
			actions: [
				{ label: opts.cancel || 'Cancel', value: false },
				{
					label: opts.confirm || 'Confirm', primary: !opts.danger, danger: !!opts.danger, value: true,
					disabledUntil: box ? function (btn) { btn.disabled = true; box.addEventListener('change', function () { btn.disabled = !box.checked; }); } : null
				}
			]
		});
	};

	/* --------------------------------------------------------- building blocks */

	RSS.busy = function (btn, on, label) {
		if (!btn) { return; }
		if (on) {
			btn.dataset.label = btn.dataset.label || btn.innerHTML;
			btn.disabled = true;
			btn.classList.add('is-busy');
			btn.setAttribute('aria-busy', 'true');
			if (label) { btn.innerHTML = ''; btn.appendChild(h('span', { class: 'rss-spinner is-sm' })); btn.appendChild(document.createTextNode(' ' + label)); }
		} else {
			btn.disabled = false;
			btn.classList.remove('is-busy');
			btn.removeAttribute('aria-busy');
			if (btn.dataset.label) { btn.innerHTML = btn.dataset.label; delete btn.dataset.label; }
		}
	};

	RSS.btn = function (label, opts) {
		opts = opts || {};
		var b = h('button', { type: 'button', class: 'rss-btn ' + (opts.kind ? 'is-' + opts.kind : 'is-ghost') + (opts.small ? ' is-sm' : ''), title: opts.title || null, 'data-tip': opts.tip || null, disabled: opts.disabled || null },
			opts.icon ? icon(opts.icon) : null, label ? h('span', { text: label }) : null);
		if (opts.onClick) { b.addEventListener('click', opts.onClick); }
		if (!label && opts.title) { b.setAttribute('aria-label', opts.title); }
		return b;
	};

	RSS.badge = function (text, kind) { return h('span', { class: 'rss-badge is-' + (kind || 'neutral'), text: text }); };

	RSS.empty = function (iconName, title, text, action) {
		return h('div', { class: 'rss-empty' }, h('div', { class: 'rss-empty-ico' }, icon(iconName || 'info')), h('strong', { text: title }), text ? h('p', { text: text }) : null, action || null);
	};

	RSS.skeleton = function (lines) {
		var s = h('div', { class: 'rss-skeleton', 'aria-hidden': 'true' });
		for (var i = 0; i < (lines || 3); i++) { s.appendChild(h('span', { style: { width: (60 + (i * 17) % 40) + '%' } })); }
		return s;
	};

	RSS.progress = function (pct, kind) {
		var p = Math.max(0, Math.min(100, Number(pct || 0)));
		return h('div', { class: 'rss-progress' + (kind ? ' is-' + kind : ''), role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(p) }, h('span', { style: { width: p + '%' } }));
	};

	RSS.field = function (label, control, help, id) {
		if (id && control && !control.id) { control.id = id; }
		return h('div', { class: 'rss-field' }, h('label', { for: control && control.id ? control.id : null, text: label }), control, help ? h('small', { class: 'rss-help', text: help }) : null);
	};

	var uid = 0;
	RSS.uid = function (p) { uid += 1; return (p || 'rss') + '-' + uid; };

	RSS.toggle = function (label, checked, onChange, help) {
		var id = RSS.uid('tg');
		var input = h('input', { type: 'checkbox', id: id, role: 'switch', checked: !!checked });
		if (onChange) { input.addEventListener('change', function () { onChange(input.checked); }); }
		var wrap = h('label', { class: 'rss-switch', for: id }, input, h('span', { class: 'rss-switch-ui', 'aria-hidden': 'true' }), h('span', { class: 'rss-switch-text' }, h('strong', { text: label }), help ? h('small', { text: help }) : null));
		wrap.input = input;
		return wrap;
	};

	RSS.select = function (options, value, attrs) {
		var s = h('select', attrs || {});
		options.forEach(function (o) {
			var opt = h('option', { value: o.value, text: o.label });
			if (String(o.value) === String(value)) { opt.selected = true; }
			if (o.disabled) { opt.disabled = true; }
			s.appendChild(opt);
		});
		return s;
	};

	RSS.segmented = function (options, value, onChange, label) {
		var wrap = h('div', { class: 'rss-seg', role: 'radiogroup', 'aria-label': label || '' });
		var current = value;
		options.forEach(function (o) {
			var b = h('button', { type: 'button', role: 'radio', class: 'rss-seg-btn', 'aria-checked': String(o.value === value), disabled: o.disabled || null, title: o.title || null },
				o.icon ? icon(o.icon) : null, h('span', { text: o.label }), o.badge ? h('em', { text: o.badge }) : null);
			b.addEventListener('click', function () {
				current = o.value;
				$$('.rss-seg-btn', wrap).forEach(function (x) { x.setAttribute('aria-checked', 'false'); });
				b.setAttribute('aria-checked', 'true');
				if (onChange) { onChange(o.value); }
			});
			wrap.appendChild(b);
		});
		wrap.value = function () { return current; };
		return wrap;
	};

	RSS.counter = function (input, max, min) {
		var c = h('span', { class: 'rss-counter' });
		var upd = function () {
			var n = input.value.length;
			c.textContent = n + ' / ' + max;
			c.className = 'rss-counter' + (n > max ? ' is-over' : (min && n < min ? ' is-under' : ' is-ok'));
		};
		input.addEventListener('input', upd);
		upd();
		return c;
	};

	RSS.copy = function (text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var ta = h('textarea', { style: { position: 'fixed', left: '-9999px' } });
			ta.value = text;
			document.body.appendChild(ta);
			ta.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
			ta.remove();
			if (ok) { resolve(); } else { reject(new Error('Copy failed — select the text and copy it manually.')); }
		});
	};

	/* -------------------------------------------------------------- sections */

	RSS.sections = [];
	RSS.section = function (id, title, sub, opts) {
		opts = opts || {};
		var body = h('div', { class: 'rss-section-body' });
		var head = h('header', { class: 'rss-section-head' },
			h('div', {}, opts.step ? h('span', { class: 'rss-step', text: opts.step }) : null, h('h3', { text: title }), sub ? h('p', { text: sub }) : null),
			opts.actions ? h('div', { class: 'rss-section-actions' }, opts.actions) : null);
		var sec = h('section', { class: 'rss-section rss-card' + (opts.cls ? ' ' + opts.cls : ''), id: 'rss-sec-' + id, 'aria-labelledby': 'rss-h-' + id }, head, body);
		head.querySelector('h3').id = 'rss-h-' + id;
		sec.body = body;
		sec.head = head;
		if (!opts.noNav) { RSS.sections.push({ id: id, title: opts.nav || title, el: sec }); }
		return sec;
	};

	RSS.buildSubnav = function () {
		var nav = $('#rss-subnav');
		if (!nav) { return; }
		clear(nav);
		RSS.sections.forEach(function (s) {
			var a = h('a', { href: '#rss-sec-' + s.id, class: 'rss-subnav-link', data: { sec: s.id } }, h('span', { text: s.title }));
			a.addEventListener('click', function (e) {
				e.preventDefault();
				var el = document.getElementById('rss-sec-' + s.id);
				if (el) {
					el.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
					history.replaceState(null, '', '#rss-sec-' + s.id);
					RSS.closeNav();
					var hd = el.querySelector('h3');
					if (hd) { hd.setAttribute('tabindex', '-1'); hd.focus({ preventScroll: true }); }
				}
			});
			nav.appendChild(a);
		});
		var setActive = function (id) {
			$$('.rss-subnav-link', nav).forEach(function (a) {
				var on = a.dataset.sec === id;
				a.classList.toggle('is-active', on);
				if (on) { a.setAttribute('aria-current', 'location'); } else { a.removeAttribute('aria-current'); }
			});
		};
		if (RSS.sections.length) { setActive(RSS.sections[0].id); }
		// ScrollSpy: the active section is the last one whose top has passed the reading line
		// (measured live on scroll, so fast jumps and tall sections are handled correctly).
		if (RSS.spyOff) { RSS.spyOff(); }
		var queued = false;
		var update = function () {
			queued = false;
			if (!RSS.sections.length) { return; }
			var line = Math.min(180, window.innerHeight * 0.3);
			var best = RSS.sections[0].id;
			RSS.sections.forEach(function (s) {
				if (s.el && s.el.isConnected && s.el.getBoundingClientRect().top - line <= 0) { best = s.id; }
			});
			var doc = document.documentElement;
			if (window.innerHeight + window.scrollY >= doc.scrollHeight - 4) {
				// At the very bottom, short last sections can never reach the line.
				var last = RSS.sections[RSS.sections.length - 1];
				if (last.el && last.el.getBoundingClientRect().top < window.innerHeight) { best = last.id; }
			}
			setActive(best);
		};
		var onScroll = function () { if (!queued) { queued = true; window.requestAnimationFrame(update); } };
		window.addEventListener('scroll', onScroll, { passive: true });
		window.addEventListener('resize', onScroll, { passive: true });
		RSS.spyOff = function () { window.removeEventListener('scroll', onScroll); window.removeEventListener('resize', onScroll); };
		update();
	};

	/* ------------------------------------------------------------ nav/theme */

	RSS.closeNav = function () {
		var app = $('#rss-app');
		if (!app || !app.classList.contains('is-nav-open')) { return; }
		app.classList.remove('is-nav-open');
		var scrim = $('.rss-scrim', app);
		if (scrim) { scrim.hidden = true; }
		var b = $('[data-rss-open-nav]', app);
		if (b) { b.setAttribute('aria-expanded', 'false'); b.focus(); }
	};

	function initShell() {
		var app = $('#rss-app');
		if (!app) { return; }
		var openBtn = $('[data-rss-open-nav]', app);
		var scrim = $('.rss-scrim', app);
		if (openBtn) {
			openBtn.addEventListener('click', function () {
				app.classList.add('is-nav-open');
				scrim.hidden = false;
				openBtn.setAttribute('aria-expanded', 'true');
				var first = $('.rss-nav-link', app);
				if (first) { first.focus(); }
			});
		}
		if (scrim) { scrim.addEventListener('click', RSS.closeNav); }
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { RSS.closeNav(); } });
		var themeBtn = $('#rss-theme');
		if (themeBtn) {
			themeBtn.addEventListener('click', function () {
				var next = app.dataset.theme === 'dark' ? 'light' : 'dark';
				app.dataset.theme = next;
				document.body.classList.toggle('rss-theme-dark', next === 'dark');
				document.body.classList.toggle('rss-theme-light', next === 'light');
				RSS.api('/prefs', { method: 'POST', body: { theme: next } }).catch(function () {});
			});
		}
	}

	/* -------------------------------------------------------------- pickers */

	/**
	 * Search-as-you-type picker. opts: types [{value,label}], type, placeholder, onSelect(item)
	 */
	RSS.picker = function (opts) {
		var type = opts.type || (opts.types ? opts.types[0].value : 'product');
		var wrap = h('div', { class: 'rss-picker' });
		var listId = RSS.uid('pick');
		var input = h('input', { type: 'search', class: 'rss-input', placeholder: opts.placeholder || 'Search…', autocomplete: 'off', role: 'combobox', 'aria-expanded': 'false', 'aria-controls': listId, 'aria-autocomplete': 'list', 'aria-label': opts.label || 'Search' });
		var list = h('ul', { class: 'rss-picker-list', id: listId, role: 'listbox', hidden: true });
		var row = h('div', { class: 'rss-picker-row' });
		if (opts.types && opts.types.length > 1) {
			row.appendChild(RSS.segmented(opts.types, type, function (v) { type = v; run(); }, 'Type'));
		}
		row.appendChild(h('div', { class: 'rss-picker-input' }, icon('search'), input));
		wrap.appendChild(row);
		wrap.appendChild(list);
		var items = []; var active = -1; var timer = null; var seq = 0; var shownFor = null; var pendingEnter = false;
		var render = function () {
			clear(list);
			if (!items.length) {
				list.appendChild(h('li', { class: 'rss-picker-empty', text: 'No matches. Try a name, SKU or ID.' }));
			}
			items.forEach(function (it, i) {
				var li = h('li', { role: 'option', id: listId + '-' + i, class: 'rss-picker-item' + (i === active ? ' is-active' : ''), 'aria-selected': String(i === active) },
					it.image ? h('img', { src: it.image, alt: '', loading: 'lazy' }) : h('span', { class: 'rss-picker-noimg' }, icon(it.type === 'attachment' ? 'image' : it.type === 'product' ? 'box' : 'doc')),
					h('span', { class: 'rss-picker-text' }, h('strong', { text: it.title || '(no title)' }), h('small', { text: '#' + it.id + (it.sku ? ' · SKU ' + it.sku : '') + ' · ' + it.type + ' · ' + it.status })));
				li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(i); });
				list.appendChild(li);
			});
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			if (active >= 0) { input.setAttribute('aria-activedescendant', listId + '-' + active); }
		};
		var hide = function () { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); };
		var choose = function (i) {
			var it = items[i];
			if (!it) { return; }
			hide();
			input.value = it.title;
			if (opts.onSelect) { opts.onSelect(it); }
		};
		var run = function () {
			var q = input.value.trim();
			var my = ++seq;
			list.hidden = false;
			clear(list).appendChild(h('li', { class: 'rss-picker-empty' }, h('span', { class: 'rss-spinner is-sm' }), ' Searching…'));
			RSS.api('/objects/search', { query: { type: type, q: q } }).then(function (res) {
				if (my !== seq) { return; }
				items = res || []; active = items.length ? 0 : -1; shownFor = q; render();
				if (pendingEnter) { pendingEnter = false; choose(active); }
			}).catch(function (e) { if (my === seq) { pendingEnter = false; hide(); RSS.fail(e); } });
		};
		input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 250); });
		input.addEventListener('focus', function () { if (!input.value) { run(); } });
		input.addEventListener('blur', function () { setTimeout(hide, 120); });
		input.addEventListener('keydown', function (e) {
			if (list.hidden && (e.key === 'ArrowDown')) { run(); return; }
			if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(items.length - 1, active + 1); render(); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); render(); }
			else if (e.key === 'Enter') {
				e.preventDefault();
				// Results still loading or typed text changed since they were shown: wait for the fresh results.
				if (shownFor !== input.value.trim()) { clearTimeout(timer); pendingEnter = true; run(); }
				else { choose(active); }
			}
			else if (e.key === 'Escape') { hide(); }
		});
		wrap.input = input;
		wrap.type = function () { return type; };
		return wrap;
	};

	/* --------------------------------------------------------- word diff */

	function tokens(s) { return String(s || '').match(/\s+|[^\s]+/g) || []; }
	RSS.diff = function (a, b) {
		var x = tokens(a); var y = tokens(b);
		if (x.length * y.length > 250000) { return null; }
		var n = x.length; var m = y.length; var dp = [];
		for (var i = 0; i <= n; i++) { dp.push(new Uint16Array(m + 1)); }
		for (i = n - 1; i >= 0; i--) {
			for (var j = m - 1; j >= 0; j--) {
				dp[i][j] = x[i] === y[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
			}
		}
		var out = h('span', { class: 'rss-diff' }); i = 0; j = 0;
		while (i < n && j < m) {
			if (x[i] === y[j]) { out.appendChild(document.createTextNode(y[j])); i++; j++; }
			else if (dp[i + 1][j] >= dp[i][j + 1]) { out.appendChild(h('del', { text: x[i] })); i++; }
			else { out.appendChild(h('ins', { text: y[j] })); j++; }
		}
		while (i < n) { out.appendChild(h('del', { text: x[i++] })); }
		while (j < m) { out.appendChild(h('ins', { text: y[j++] })); }
		return out;
	};

	/* ------------------------------------------------- safe HTML preview */

	RSS.htmlPreview = function (html) {
		// Sandboxed: no scripts can run. allow-same-origin only lets us measure the height.
		var f = h('iframe', { class: 'rss-html-frame', sandbox: 'allow-same-origin', title: 'Preview' });
		var app = $('#rss-app');
		var dark = app && app.dataset.theme === 'dark';
		var bg = dark ? '#0c1b15' : '#ffffff';
		var css = ':root{color-scheme:' + (dark ? 'dark' : 'light') + '}html,body{background:' + bg + '}body{margin:0;padding:12px 14px;font:14px/1.6 system-ui,-apple-system,"Segoe UI","Noto Sans Bengali","Hind Siliguri",sans-serif;color:' + (dark ? '#dbe8e1' : '#1f2937') + '}h2,h3,h4{margin:.9em 0 .4em;line-height:1.3}p{margin:.5em 0}table{border-collapse:collapse;width:100%;margin:.6em 0}th,td{border:1px solid ' + (dark ? '#24463a' : '#e5e7eb') + ';padding:6px 8px;text-align:left;vertical-align:top}th{width:38%}a{color:' + (dark ? '#3ee09a' : '#0b8f5c') + '}img{max-width:100%;height:auto}ul{padding-left:1.2em}.rss-empty-note{opacity:.6;font-style:italic}';
		f.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><base target="_blank"><style>' + css + '</style></head><body>' + (String(html || '').trim() ? String(html) : '<p class="rss-empty-note">(empty)</p>') + '</body></html>';
		var fit = function () {
			try {
				var b = f.contentDocument && f.contentDocument.documentElement;
				if (b) { f.style.height = Math.max(60, Math.min(520, b.scrollHeight + 4)) + 'px'; }
			} catch (e) { f.style.height = '240px'; }
		};
		f.addEventListener('load', function () { fit(); setTimeout(fit, 200); });
		return f;
	};

	/* -------------------------------------------------- compare slider */

	RSS.compare = function (before, after, labels) {
		labels = labels || ['Before', 'After'];
		var wrap = h('div', { class: 'rss-compare' });
		var a = h('img', { src: before, alt: labels[0], loading: 'lazy' });
		var b = h('img', { src: after, alt: labels[1], loading: 'lazy' });
		var top = h('div', { class: 'rss-compare-top' }, b);
		var range = h('input', { type: 'range', min: '0', max: '100', value: '50', 'aria-label': 'Compare before and after' });
		var set = function () { top.style.clipPath = 'inset(0 0 0 ' + range.value + '%)'; handle.style.left = range.value + '%'; };
		var handle = h('span', { class: 'rss-compare-handle', 'aria-hidden': 'true' });
		range.addEventListener('input', set);
		wrap.appendChild(h('div', { class: 'rss-compare-base' }, a));
		wrap.appendChild(top);
		wrap.appendChild(handle);
		wrap.appendChild(h('span', { class: 'rss-compare-label is-l', text: labels[0] }));
		wrap.appendChild(h('span', { class: 'rss-compare-label is-r', text: labels[1] }));
		wrap.appendChild(range);
		set();
		return wrap;
	};

	/* ------------------------------------------------------ draft card */

	var STATUS_LABEL = { pending: 'Waiting for review', applied: 'Applied', rejected: 'Rejected', rolled_back: 'Rolled back', superseded: 'Replaced by newer preview' };
	var STATUS_KIND = { pending: 'warn', applied: 'success', rejected: 'neutral', rolled_back: 'info', superseded: 'neutral' };

	function sourceKind(src) {
		src = String(src || '');
		if (/^AI/i.test(src)) { return 'ai'; }
		if (/^Subscription/i.test(src)) { return 'sub'; }
		return 'free';
	}

	/**
	 * Review card for one draft. opts.onChange(draft) after any action; opts.select (bool) adds a checkbox.
	 */
	RSS.draftCard = function (draft, opts) {
		opts = opts || {};
		var card = h('article', { class: 'rss-draft is-' + draft.status + (draft.blockers ? ' has-blockers' : ''), data: { id: draft.id } });
		var kind = sourceKind(draft.source);
		var check = null;
		if (opts.select && draft.status === 'pending') {
			check = h('input', { type: 'checkbox', class: 'rss-draft-check', 'aria-label': 'Select ' + draft.label, data: { id: draft.id } });
			card.check = check;
		}
		var head = h('header', { class: 'rss-draft-head' },
			check,
			h('div', { class: 'rss-draft-title' },
				h('strong', { text: draft.label }),
				opts.showObject ? h('small', { text: draft.object_name + ' #' + draft.object_id }) : null),
			h('div', { class: 'rss-draft-meta' },
				RSS.badge(kind === 'ai' ? 'AI' : kind === 'sub' ? 'Subscription' : 'Free', kind === 'ai' ? 'ai' : kind === 'sub' ? 'info' : 'success'),
				h('span', { class: 'rss-draft-source', text: draft.source, title: draft.source }),
				RSS.badge(STATUS_LABEL[draft.status] || draft.status, STATUS_KIND[draft.status] || 'neutral')));
		card.appendChild(head);

		if (draft.flags && draft.flags.length) {
			var fl = h('div', { class: 'rss-flags' + (draft.blockers ? ' is-block' : ' is-warn') },
				icon('alert'),
				h('div', {}, h('strong', { text: draft.blockers ? 'Check these facts before applying' : 'Please double-check' }),
					h('ul', {}, draft.flags.map(function (f) { return h('li', {}, h('code', { text: f.text }), ' — ', f.label); }))));
			card.appendChild(fl);
		}

		var body = h('div', { class: 'rss-draft-body' });
		var editor = null;
		if (draft.kind === 'image' || draft.kind === 'gallery') {
			var o = draft.original_images || []; var p = draft.proposed_images || [];
			var changed = p.filter(function (img) { return !o.some(function (x) { return x.id === img.id; }); });
			var replaced = o.filter(function (img) { return !p.some(function (x) { return x.id === img.id; }); });
			var pairs = [];
			for (var i = 0; i < Math.max(changed.length, replaced.length); i++) { pairs.push([replaced[i], changed[i]]); }
			if (!pairs.length && p.length) { pairs.push([o[0], p[0]]); }
			pairs.forEach(function (pair) {
				var before = pair[0]; var after = pair[1];
				var cell = h('div', { class: 'rss-img-pair' });
				if (before && after && before.url && after.url) {
					cell.appendChild(RSS.compare(before.url, after.url, ['Original', 'New']));
				} else if (after && after.url) {
					cell.appendChild(h('img', { src: after.url, alt: 'New image', class: 'rss-img-single' }));
				}
				cell.appendChild(h('div', { class: 'rss-img-facts' },
					before ? h('span', { text: 'Original: ' + before.width + '×' + before.height + ' · ' + RSS.bytes(before.bytes) + ' · ' + (before.mime || '').replace('image/', '').toUpperCase() }) : null,
					after ? h('span', { text: 'New: ' + after.width + '×' + after.height + ' · ' + RSS.bytes(after.bytes) + ' · ' + (after.mime || '').replace('image/', '').toUpperCase() }) : null,
					before && after && before.bytes ? RSS.badge((after.bytes <= before.bytes ? '−' : '+') + Math.abs(Math.round(100 - 100 * after.bytes / before.bytes)) + '% file size', after.bytes <= before.bytes ? 'success' : 'warn') : null));
				body.appendChild(cell);
			});
			if (draft.meta && draft.meta.notes && draft.meta.notes.length) {
				body.appendChild(h('ul', { class: 'rss-notes' }, draft.meta.notes.map(function (n) { return h('li', { text: n }); })));
			}
		} else {
			var colO = h('div', { class: 'rss-col' }, h('span', { class: 'rss-col-label', text: 'Current' }));
			var colP = h('div', { class: 'rss-col' }, h('span', { class: 'rss-col-label', text: draft.status === 'applied' ? 'Applied' : 'Suggested' }));
			if (draft.kind === 'html') {
				colO.appendChild(RSS.htmlPreview(draft.original));
				var prev = RSS.htmlPreview(draft.proposed);
				colP.appendChild(prev);
				if (draft.status === 'pending') {
					editor = h('textarea', { class: 'rss-input rss-code', rows: '8', 'aria-label': 'Edit suggested HTML', hidden: true });
					editor.value = draft.proposed;
					var tgl = RSS.btn('Edit HTML', { icon: 'edit', small: true });
					tgl.addEventListener('click', function () {
						editor.hidden = !editor.hidden;
						prev.hidden = !editor.hidden;
						tgl.querySelector('span:last-child').textContent = editor.hidden ? 'Edit HTML' : 'Show preview';
						if (!editor.hidden) { editor.focus(); }
					});
					colP.appendChild(editor);
					colP.appendChild(h('div', { class: 'rss-col-tools' }, tgl));
				}
			} else {
				colO.appendChild(h('div', { class: 'rss-value' + (draft.original ? '' : ' is-empty'), text: draft.original || '(empty)' }));
				if (draft.status === 'pending') {
					editor = h('textarea', { class: 'rss-input', rows: String(Math.min(6, Math.max(2, Math.ceil((draft.proposed || '').length / 70)))), 'aria-label': 'Edit suggested ' + draft.label });
					editor.value = draft.proposed;
					colP.appendChild(editor);
					var lim = /seo_title/.test(draft.field) ? B.settings.seo.title_max : /seo_description/.test(draft.field) ? B.settings.seo.meta_max : /alt/.test(draft.field) ? 125 : 0;
					if (lim) { colP.appendChild(RSS.counter(editor, lim)); }
				} else {
					colP.appendChild(h('div', { class: 'rss-value', text: draft.proposed || '(empty)' }));
				}
				if (draft.original && draft.proposed && draft.original.length < 3000) {
					var d = RSS.diff(draft.original, draft.proposed);
					if (d) { body.appendChild(h('details', { class: 'rss-diff-wrap' }, h('summary', { text: 'Show word changes' }), d)); }
				}
			}
			body.insertBefore(h('div', { class: 'rss-cols' }, colO, colP), body.firstChild);
		}
		card.appendChild(body);

		var foot = h('footer', { class: 'rss-draft-foot' });
		var ack = null;
		var refresh = function (updated) { if (opts.onChange) { opts.onChange(updated || draft); } };
		var act = function (btn, path, payload, busyLabel, okMsg) {
			RSS.busy(btn, true, busyLabel);
			return RSS.api('/drafts/' + draft.id + path, { method: 'POST', body: payload || {} }).then(function (res) {
				RSS.toast(okMsg, 'success');
				refresh(res);
				return res;
			}).catch(function (e) {
				RSS.busy(btn, false);
				if (e.code === 'rar_stale' || e.code === 'rar_conflict') {
					return RSS.confirm({ title: e.code === 'rar_stale' ? 'Field changed since this preview' : 'Field edited after applying', text: e.message, confirm: e.code === 'rar_stale' ? 'Apply anyway' : 'Roll back anyway', danger: true })
						.then(function (yes) { if (yes) { return act(btn, path, Object.assign({}, payload || {}, { force: true }), busyLabel, okMsg); } });
				}
				RSS.fail(e);
			});
		};
		if (draft.status === 'pending') {
			if (draft.blockers) {
				var ackId = RSS.uid('ack');
				ack = h('input', { type: 'checkbox', id: ackId });
				foot.appendChild(h('label', { class: 'rss-check rss-ack', for: ackId }, ack, h('span', { text: 'I verified these facts' })));
			}
			var applyBtn = RSS.btn('Approve & apply', { kind: 'primary', icon: 'check' });
			var rejectBtn = RSS.btn('Reject', { icon: 'x' });
			applyBtn.addEventListener('click', function () {
				if (ack && !ack.checked) { RSS.toast('Tick "I verified these facts" first — the flagged items are not in your product data.', 'warn'); ack.focus(); return; }
				var save = editor && editor.value !== draft.proposed
					? RSS.api('/drafts/' + draft.id, { method: 'POST', body: { proposed: editor.value } })
					: Promise.resolve(draft);
				RSS.busy(applyBtn, true, 'Applying…');
				save.then(function (d2) {
					RSS.busy(applyBtn, false);
					if (d2.blockers && !(ack && ack.checked)) { RSS.toast('Your edit contains facts not in the product data. Review the flags.', 'warn'); refresh(d2); return; }
					return act(applyBtn, '/apply', { ack: !!(ack && ack.checked) }, 'Applying…', draft.label + ' applied. You can roll it back any time.');
				}).catch(function (e) { RSS.busy(applyBtn, false); RSS.fail(e); });
			});
			rejectBtn.addEventListener('click', function () { act(rejectBtn, '/reject', {}, 'Rejecting…', 'Suggestion rejected.'); });
			if (editor) {
				var saveBtn = RSS.btn('Save edit', { icon: 'edit' });
				saveBtn.addEventListener('click', function () {
					RSS.busy(saveBtn, true, 'Saving…');
					RSS.api('/drafts/' + draft.id, { method: 'POST', body: { proposed: editor.value } }).then(function (d2) {
						RSS.toast('Edit saved and re-checked.', 'success'); refresh(d2);
					}).catch(function (e) { RSS.busy(saveBtn, false); RSS.fail(e); });
				});
				foot.appendChild(saveBtn);
			}
			foot.appendChild(rejectBtn);
			foot.appendChild(applyBtn);
		} else if (draft.status === 'applied') {
			foot.appendChild(h('span', { class: 'rss-muted', text: 'Applied ' + RSS.ago(draft.applied_at) }));
			var rb = RSS.btn('Roll back', { icon: 'undo' });
			rb.addEventListener('click', function () {
				RSS.confirm({ title: 'Roll back this change?', text: 'The previous value of "' + draft.label + '" will be restored.', confirm: 'Roll back' }).then(function (yes) {
					if (yes) { act(rb, '/rollback', {}, 'Restoring…', 'Previous value restored.'); }
				});
			});
			foot.appendChild(rb);
		}
		card.appendChild(foot);
		return card;
	};

	/**
	 * Draft list with selection + batch actions. opts: query (object), title, emptyText, showObject.
	 */
	RSS.draftList = function (opts) {
		var wrap = h('div', { class: 'rss-draft-list' });
		var bar = h('div', { class: 'rss-batch', hidden: true });
		var listEl = h('div', { class: 'rss-drafts' });
		wrap.appendChild(bar); wrap.appendChild(listEl);
		var cards = [];
		var load = function () {
			clear(listEl).appendChild(RSS.skeleton(4));
			return RSS.api('/drafts', { query: opts.query() }).then(function (res) {
				clear(listEl); cards = [];
				if (!res.items.length) {
					listEl.appendChild(RSS.empty(opts.emptyIcon || 'check', opts.emptyTitle || 'Nothing waiting for review', opts.emptyText || ''));
					bar.hidden = true;
					return res;
				}
				res.items.forEach(function (d) {
					var c = RSS.draftCard(d, { select: true, showObject: opts.showObject, onChange: function () { load(); if (opts.onChange) { opts.onChange(); } } });
					cards.push(c); listEl.appendChild(c);
				});
				var pending = res.items.filter(function (d) { return d.status === 'pending'; });
				bar.hidden = pending.length < 2;
				clear(bar);
				if (pending.length >= 2) {
					var all = h('input', { type: 'checkbox', id: RSS.uid('all') });
					all.addEventListener('change', function () { cards.forEach(function (c) { if (c.check) { c.check.checked = all.checked; } }); });
					var applySel = RSS.btn('Apply selected', { kind: 'primary', icon: 'check', small: true });
					var rejectSel = RSS.btn('Reject selected', { icon: 'x', small: true });
					var run = function (action, btn) {
						var ids = cards.filter(function (c) { return c.check && c.check.checked; }).map(function (c) { return Number(c.check.dataset.id); });
						if (!ids.length) { RSS.toast('Select at least one suggestion.', 'warn'); return; }
						RSS.busy(btn, true, 'Working…');
						RSS.api('/drafts/batch', { method: 'POST', body: { ids: ids, action: action } }).then(function (out) {
							var ok = out.filter(function (x) { return x.ok; }).length;
							var bad = out.filter(function (x) { return !x.ok; });
							RSS.toast(ok + ' ' + (action === 'apply' ? 'applied' : 'rejected') + (bad.length ? ', ' + bad.length + ' need individual review (flagged facts or changed fields).' : '.'), bad.length ? 'warn' : 'success', 7000);
							load(); if (opts.onChange) { opts.onChange(); }
						}).catch(RSS.fail).then(function () { RSS.busy(btn, false); });
					};
					applySel.addEventListener('click', function () { run('apply', applySel); });
					rejectSel.addEventListener('click', function () { run('reject', rejectSel); });
					bar.appendChild(h('label', { class: 'rss-check', for: all.id }, all, h('span', { text: 'Select all pending (' + pending.length + ')' })));
					bar.appendChild(h('span', { class: 'rss-batch-actions' }, rejectSel, applySel));
					bar.appendChild(h('small', { class: 'rss-muted', text: 'Flagged suggestions are skipped by batch apply — review them one by one.' }));
				}
				return res;
			}).catch(function (e) { clear(listEl).appendChild(RSS.empty('alert', 'Could not load suggestions', e.message)); });
		};
		wrap.load = load;
		return wrap;
	};

	/* ---------------------------------------------------------- score ring */

	RSS.ring = function (score, label) {
		var s = Math.max(0, Math.min(100, Number(score || 0)));
		var kind = s >= 80 ? 'good' : s >= 50 ? 'mid' : 'low';
		var r = 34; var c = 2 * Math.PI * r;
		return h('div', { class: 'rss-ring is-' + kind, role: 'img', 'aria-label': (label || 'Score') + ' ' + s + ' of 100' },
			h('span', { html: '<svg viewBox="0 0 80 80" aria-hidden="true"><circle cx="40" cy="40" r="' + r + '" class="rss-ring-bg"/><circle cx="40" cy="40" r="' + r + '" class="rss-ring-fg" stroke-dasharray="' + (c * s / 100).toFixed(1) + ' ' + c.toFixed(1) + '"/></svg>' }),
			h('strong', { text: String(s) }), label ? h('small', { text: label }) : null);
	};

	/* --------------------------------------------------------------- start */

	RSS.pages = {};
	RSS.start = function () {
		initShell();
		var main = $('#rss-main');
		var page = B.page || 'dashboard';
		var fn = RSS.pages[page];
		if (!main || !fn) { return; }
		clear(main);
		try {
			fn(main);
		} catch (e) {
			main.appendChild(RSS.empty('alert', 'This screen failed to load', e.message));
			if (window.console) { window.console.error(e); }
		}
		RSS.buildSubnav();
		if (location.hash && /^#rss-sec-/.test(location.hash)) {
			var el = document.getElementById(location.hash.slice(1));
			if (el) { setTimeout(function () { el.scrollIntoView(); }, 50); }
		}
	};
})();
