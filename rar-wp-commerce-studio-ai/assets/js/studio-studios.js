/**
 * RAR Commerce Studio — Dashboard, Product, Image, Content and SEO studios.
 */
(function (RSS) {
	'use strict';
	if (!RSS) { return; }
	var h = RSS.h, $ = RSS.$, $$ = RSS.$$, clear = RSS.clear, icon = RSS.icon, B = RSS.boot;

	function setQuery(params) {
		var url = new URL(location.href);
		Object.keys(params).forEach(function (k) { if (params[k]) { url.searchParams.set(k, params[k]); } else { url.searchParams.delete(k); } });
		history.replaceState(null, '', url.toString());
	}

	function kpi(label, value, sub, ico, kind, href) {
		var el = h(href ? 'a' : 'div', { class: 'rss-kpi' + (kind ? ' is-' + kind : ''), href: href || null },
			h('span', { class: 'rss-kpi-ico' }, icon(ico || 'info')),
			h('span', { class: 'rss-kpi-text' }, h('small', { text: label }), h('strong', { text: value }), sub ? h('em', { text: sub }) : null));
		return el;
	}
	RSS.kpi = kpi;

	var LANGS = [{ value: 'en', label: 'English' }, { value: 'bn', label: 'বাংলা' }, { value: 'bilingual', label: 'Bangla + English' }];

	/* ===================================================== generator panel */

	/**
	 * Shared "Generate" panel. opts: type(), id(), fields (object payload fields), onDrafts(result), allowed (array)
	 */
	RSS.generatorPanel = function (opts) {
		var wrap = h('div', { class: 'rss-gen' });
		var engine = 'local';
		var aiReason = '';
		var groups = { content: 'Content', catalog: 'Catalog', seo: 'SEO', media: 'Media' };
		var fieldBox = h('div', { class: 'rss-fieldgrid' });
		var checks = [];
		var renderFields = function () {
			clear(fieldBox); checks = [];
			var byGroup = {};
			Object.keys(opts.fields).forEach(function (key) {
				var f = opts.fields[key];
				if (opts.allowed && opts.allowed.indexOf(key) === -1) { return; }
				if (f.kind === 'image' || f.kind === 'gallery') { return; }
				(byGroup[f.group] = byGroup[f.group] || []).push([key, f]);
			});
			Object.keys(byGroup).forEach(function (g) {
				var col = h('fieldset', { class: 'rss-fieldset' }, h('legend', { text: groups[g] || g }));
				byGroup[g].forEach(function (pair) {
					var key = pair[0], f = pair[1];
					var supported = engine === 'local' ? f.local : f.ai;
					var disabled = !!f.locked || !supported;
					var id = RSS.uid('fld');
					var cb = h('input', { type: 'checkbox', id: id, value: key, disabled: disabled || null });
					cb.checked = !disabled && (opts.defaults ? opts.defaults.indexOf(key) !== -1 : (g === 'seo'));
					checks.push(cb);
					var reason = f.locked || (!supported ? (engine === 'local' ? 'Needs AI or the subscription prompt' : 'Not available for AI') : '');
					col.appendChild(h('label', { class: 'rss-check' + (disabled ? ' is-disabled' : ''), for: id, 'data-tip': reason || null },
						cb, h('span', { text: f.label }), reason ? h('small', { class: 'rss-muted', text: reason }) : null));
				});
				fieldBox.appendChild(col);
			});
			if (!checks.length) { fieldBox.appendChild(RSS.empty('info', 'No editable fields', '')); }
		};
		var chosen = function () { return checks.filter(function (c) { return c.checked && !c.disabled; }).map(function (c) { return c.value; }); };

		var lang = RSS.select(LANGS, B.settings.language, { class: 'rss-input', 'aria-label': 'Language' });
		var instr = h('input', { type: 'text', class: 'rss-input', placeholder: 'Optional instruction, e.g. "mention it is a gift-ready box" (AI / subscription only)', maxlength: '500' });
		var actions = h('div', { class: 'rss-gen-actions' });
		var manualBox = h('div', { class: 'rss-manual', hidden: true });

		var seg = RSS.segmented([
			{ value: 'local', label: 'Free local', icon: 'leaf', badge: '৳0' },
			{ value: 'ai', label: 'AI API', icon: 'spark' },
			{ value: 'manual', label: 'Subscription', icon: 'copy', title: 'Use ChatGPT / Gemini / Claude chat apps you already pay for' }
		], 'local', function (v) { engine = v === 'manual' ? 'ai' : v; mode = v; renderFields(); renderActions(); }, 'Engine');
		var mode = 'local';

		var explain = h('p', { class: 'rss-help' });
		var renderActions = function () {
			clear(actions); manualBox.hidden = true;
			if (mode === 'local') {
				explain.textContent = 'Uses only the information already in this item (name, descriptions, attributes, SKU). No internet, no cost. It reformats and shortens — it never invents facts.';
				actions.appendChild(RSS.btn('Generate free preview', { kind: 'primary', icon: 'leaf', onClick: function (e) { run('local', e.currentTarget); } }));
			} else if (mode === 'ai') {
				explain.textContent = aiReason ? aiReason : 'Sends this item’s text to your selected provider. You will see the estimated cost first. Results are previews — nothing is published automatically.';
				var b = RSS.btn('Generate with AI', { kind: 'primary', icon: 'spark', disabled: !!aiReason, onClick: function (e) { aiRun(e.currentTarget); } });
				actions.appendChild(b);
				if (aiReason && B.admin) { actions.appendChild(h('a', { class: 'rss-btn is-ghost', href: B.urls.ai }, icon('key'), h('span', { text: 'Open AI Providers' }))); }
			} else {
				explain.textContent = 'Free with your ChatGPT Plus/Pro, Gemini or Claude subscription: copy the prompt, paste it in the chat app, then paste the JSON answer back here. Subscriptions are not API credits — this is the official, manual way to use them.';
				actions.appendChild(RSS.btn('Create prompt', { kind: 'primary', icon: 'copy', onClick: function (e) { manual(e.currentTarget); } }));
			}
		};

		var payload = function (extra) {
			return Object.assign({ type: opts.type(), id: opts.id(), fields: chosen(), language: lang.value, instruction: instr.value }, extra || {});
		};
		var run = function (eng, btn) {
			if (!chosen().length) { RSS.toast('Choose at least one field.', 'warn'); return; }
			RSS.busy(btn, true, eng === 'ai' ? 'Asking AI…' : 'Generating…');
			RSS.api('/generate', { method: 'POST', body: payload({ engine: eng }) }).then(function (res) {
				var n = res.drafts.length;
				var skipped = Object.keys(res.skipped || {});
				RSS.toast(n ? n + ' suggestion' + (n > 1 ? 's' : '') + ' ready for review. Nothing has been changed yet.' : 'No new suggestions.', n ? 'success' : 'warn');
				if (skipped.length) {
					RSS.toast(skipped.map(function (k) { return (opts.fields[k] ? opts.fields[k].label : k) + ': ' + res.skipped[k]; }).join(' · '), 'info', 9000);
				}
				if (res.usage) { RSS.toast('AI usage: ' + RSS.num(res.usage.input_tokens) + ' in / ' + RSS.num(res.usage.output_tokens) + ' out tokens · est. ' + RSS.money(res.usage.cost), 'info'); }
				if (opts.onDrafts) { opts.onDrafts(res); }
			}).catch(RSS.fail).then(function () { RSS.busy(btn, false); });
		};
		var aiRun = function (btn) {
			if (!chosen().length) { RSS.toast('Choose at least one field.', 'warn'); return; }
			RSS.busy(btn, true, 'Estimating…');
			RSS.api('/estimate', { method: 'POST', body: payload() }).then(function (est) {
				RSS.busy(btn, false);
				if (est.reason) { RSS.toast(est.reason, 'warn'); return; }
				var body = h('div', { class: 'rss-estimate' },
					h('dl', {},
						h('dt', { text: 'Provider / model' }), h('dd', { text: est.provider + ' · ' + est.model }),
						h('dt', { text: 'Estimated tokens' }), h('dd', { text: '≈ ' + RSS.num(est.input_tokens) + ' in · up to ' + RSS.num(est.output_tokens) + ' out' }),
						h('dt', { text: 'Estimated cost' }), h('dd', { text: est.free_tier ? '$0 (marked free tier)' : 'up to ' + RSS.money(est.cost) }),
						h('dt', { text: 'Today' }), h('dd', { text: est.today + ' of ' + est.daily_limit + ' requests used' }),
						h('dt', { text: 'This month' }), h('dd', { text: RSS.money(est.month_cost) + ' of ' + RSS.money(est.budget) + ' budget' })),
					h('p', { class: 'rss-help', text: 'Estimates use the prices set in AI Providers. Your provider’s billing page is the final word. Only this item’s name, descriptions, attributes and SKU are sent — never prices, customers or orders.' }));
				return RSS.modal({ title: 'Send this request to AI?', body: body, actions: [{ label: 'Cancel', value: false }, { label: 'Send request', primary: true, value: true }] })
					.then(function (yes) { if (yes) { run('ai', btn); } });
			}).catch(function (e) { RSS.busy(btn, false); RSS.fail(e); });
		};
		var manual = function (btn) {
			if (!chosen().length) { RSS.toast('Choose at least one field.', 'warn'); return; }
			RSS.busy(btn, true, 'Building prompt…');
			RSS.api('/manual-prompt', { method: 'POST', body: payload() }).then(function (res) {
				clear(manualBox); manualBox.hidden = false;
				var ta = h('textarea', { class: 'rss-input rss-code', rows: '8', readonly: true, 'aria-label': 'Prompt' }); ta.value = res.prompt;
				var paste = h('textarea', { class: 'rss-input rss-code', rows: '6', placeholder: 'Paste the JSON answer from the chat app here…', 'aria-label': 'Paste answer' });
				var app = 'chatgpt';
				var importBtn = RSS.btn('Import answer as suggestions', { kind: 'primary', icon: 'download' });
				importBtn.addEventListener('click', function () {
					if (!paste.value.trim()) { RSS.toast('Paste the answer first.', 'warn'); return; }
					RSS.busy(importBtn, true, 'Importing…');
					RSS.api('/import', { method: 'POST', body: payload({ text: paste.value, app: app }) }).then(function (r) {
						RSS.toast(r.drafts.length + ' suggestion(s) imported for review.', 'success');
						paste.value = '';
						if (opts.onDrafts) { opts.onDrafts(r); }
					}).catch(RSS.fail).then(function () { RSS.busy(importBtn, false); });
				});
				manualBox.appendChild(h('div', { class: 'rss-manual-steps' },
					h('div', { class: 'rss-mstep' }, h('b', { text: '1' }), h('div', {}, h('strong', { text: 'Copy the prompt' }), ta,
						h('div', { class: 'rss-row' }, RSS.btn('Copy prompt', { icon: 'copy', kind: 'primary', small: true, onClick: function () { RSS.copy(ta.value).then(function () { RSS.toast('Prompt copied.', 'success'); }, RSS.fail); } })))),
					h('div', { class: 'rss-mstep' }, h('b', { text: '2' }), h('div', {}, h('strong', { text: 'Open your chat app and paste it' }),
						h('div', { class: 'rss-row' },
							h('a', { class: 'rss-btn is-ghost is-sm', href: 'https://chatgpt.com/', target: '_blank', rel: 'noopener noreferrer', on: { click: function () { app = 'chatgpt'; } } }, icon('external'), h('span', { text: 'ChatGPT' })),
							h('a', { class: 'rss-btn is-ghost is-sm', href: 'https://gemini.google.com/app', target: '_blank', rel: 'noopener noreferrer', on: { click: function () { app = 'gemini'; } } }, icon('external'), h('span', { text: 'Gemini' })),
							h('a', { class: 'rss-btn is-ghost is-sm', href: 'https://claude.ai/new', target: '_blank', rel: 'noopener noreferrer', on: { click: function () { app = 'claude'; } } }, icon('external'), h('span', { text: 'Claude' }))))),
					h('div', { class: 'rss-mstep' }, h('b', { text: '3' }), h('div', {}, h('strong', { text: 'Paste the answer back' }), paste, h('div', { class: 'rss-row' }, importBtn)))));
				manualBox.scrollIntoView({ block: 'nearest' });
			}).catch(RSS.fail).then(function () { RSS.busy(btn, false); });
		};

		wrap.appendChild(h('div', { class: 'rss-gen-top' }, seg, h('div', { class: 'rss-gen-lang' }, RSS.field('Language', lang))));
		wrap.appendChild(explain);
		wrap.appendChild(fieldBox);
		wrap.appendChild(RSS.field('Instruction', instr));
		wrap.appendChild(actions);
		wrap.appendChild(manualBox);
		renderFields(); renderActions();
		RSS.api('/ai').then(function (st) {
			aiReason = !st.can_use ? 'Your role cannot run AI API requests (Settings → General).' : !st.enabled ? 'AI API is OFF (default). An administrator can enable it in AI Providers. The free engine and subscription prompt work without it.' : (!Object.keys(st.providers).some(function (k) { return st.providers[k].ready; }) ? 'No AI provider is enabled with a key.' : '');
			renderActions();
		}).catch(function () {});
		return wrap;
	};

	/* ============================================================ dashboard */

	RSS.pages.dashboard = function (main) {
		var top = h('div', { class: 'rss-kpis' }, RSS.skeleton(2));
		var health = RSS.section('health', 'Catalog health', 'Local scan of up to 3,000 products — free, no AI.', { actions: RSS.btn('Rescan now', { icon: 'refresh', small: true, onClick: function (e) { scan(e.currentTarget); } }) });
		var modules = RSS.section('modules', 'Studios', 'What each module does and what it costs.');
		var activity = RSS.section('activity', 'Recent activity', '', { actions: h('a', { class: 'rss-btn is-ghost is-sm', href: B.urls.logs }, h('span', { text: 'All logs' }), icon('chevron')) });
		var env = RSS.section('system', 'System check', 'Server and plugin compatibility for this site.');
		var hero = h('div', { class: 'rss-hero rss-card' },
			h('div', { class: 'rss-hero-text' },
				h('span', { class: 'rss-eyebrow', text: 'Free-first · Review before publish · Always reversible' }),
				h('h3', { text: 'Manage products, images, content and SEO from one place' }),
				h('p', { text: 'Start free with the local engine. Turn on GPT, Gemini or Claude only when you need it — with limits, budgets and cost estimates.' }),
				h('div', { class: 'rss-row' },
					h('a', { class: 'rss-btn is-primary', href: B.urls.products }, icon('box'), h('span', { text: 'Open Product Studio' })),
					h('a', { class: 'rss-btn is-ghost', href: B.urls.bulk }, icon('layers'), h('span', { text: 'Bulk Manager' })))),
			h('div', { class: 'rss-hero-art', 'aria-hidden': 'true' }, h('span'), h('span'), h('span')));
		RSS.sections.unshift({ id: 'overview', title: 'Overview', el: hero });
		hero.id = 'rss-sec-overview';
		main.appendChild(hero);
		main.appendChild(top);
		main.appendChild(health);
		main.appendChild(modules);
		main.appendChild(activity);
		main.appendChild(env);

		var mods = [
			['products', 'box', 'Product Studio', 'Titles, descriptions, tags, categories, brand and SEO with side-by-side review and rollback.', 'Free + AI'],
			['images', 'image', 'Image Studio', 'Trim, centre, white/transparent background, 300–2400 px, WebP/JPEG/PNG. Originals kept.', 'Free'],
			['content', 'doc', 'Content Studio', 'Posts, pages, media alt text, translations, internal links and new drafts.', 'Free + AI'],
			['seo', 'search', 'SEO Studio', 'Checks, live Google preview, keyphrase placement, headings and readability.', 'Free'],
			['bulk', 'layers', 'Bulk Manager', 'Queue hundreds of items in small batches; pause, resume, retry, report.', 'Free + AI'],
			['ai', 'spark', 'AI Providers', 'GPT, Gemini and Claude with limits, budget and usage history. OFF by default.', 'Optional']
		];
		modules.body.appendChild(h('div', { class: 'rss-modules' }, mods.map(function (m) {
			return h('a', { class: 'rss-module', href: B.urls[m[0]] }, h('span', { class: 'rss-module-ico' }, icon(m[1])), h('strong', { text: m[2] }), h('p', { text: m[3] }), RSS.badge(m[4], m[4] === 'Free' ? 'success' : m[4] === 'Optional' ? 'neutral' : 'info'));
		})));

		var renderHealth = function (hd) {
			clear(health.body);
			if (!hd) {
				health.body.appendChild(RSS.empty('gauge', 'No scan yet', 'Scan your catalog to find thin descriptions, duplicates and missing SEO or alt text.', RSS.btn('Scan catalog', { kind: 'primary', icon: 'refresh', onClick: function (e) { scan(e.currentTarget); } })));
				return;
			}
			var c = hd.counts;
			var rows = [
				['no_meta', 'Missing meta description', c.no_meta, 'seo_local', hd.seo_plugin ? '' : 'Needs an SEO plugin'],
				['no_seo_title', 'Missing custom SEO title', c.no_seo_title, 'seo_local', hd.seo_plugin ? '' : 'Needs an SEO plugin'],
				['thin', 'Thin description (under 40 words)', c.thin, 'content_ai', ''],
				['no_short', 'No short description', c.no_short, 'content_local', ''],
				['image_no_alt', 'Product image without alt text', c.image_no_alt, 'alt_local', ''],
				['no_image', 'No product image', c.no_image, '', 'Upload a photo in WooCommerce'],
				['duplicates', 'Duplicate / near-duplicate descriptions', c.duplicates, '', 'Rewrite one of each pair']
			];
			var grid = h('div', { class: 'rss-health' }, h('div', { class: 'rss-health-score' }, RSS.ring(hd.score, 'Health'), h('p', { text: RSS.num(hd.products) + ' products scanned ' + RSS.ago(new Date(hd.scanned_at * 1000).toISOString().replace('T', ' ').slice(0, 19)) })));
			var list = h('ul', { class: 'rss-issues' });
			rows.forEach(function (r) {
				var ok = !r[2];
				var link = null;
				if (!ok && r[3]) {
					link = h('a', { class: 'rss-btn is-ghost is-sm', href: B.urls.bulk + '&op=' + r[3] + '&issue=' + r[0] }, h('span', { text: 'Fix in bulk' }), icon('chevron'));
				}
				list.appendChild(h('li', { class: ok ? 'is-ok' : 'is-todo' },
					h('span', { class: 'rss-issue-ico' }, icon(ok ? 'check' : 'alert')),
					h('span', { class: 'rss-issue-text' }, h('strong', { text: r[1] }), r[4] && !ok ? h('small', { text: r[4] }) : null),
					h('span', { class: 'rss-issue-count', text: RSS.num(r[2]) }), link));
			});
			grid.appendChild(list);
			health.body.appendChild(grid);
			if (hd.duplicates && hd.duplicates.length) {
				var det = h('details', { class: 'rss-dupes' }, h('summary', { text: 'Show duplicate groups (' + hd.duplicates.length + ')' }));
				var ul = h('ul', {});
				hd.duplicates.slice(0, 30).forEach(function (g) {
					ul.appendChild(h('li', {}, RSS.badge(g.type === 'exact' ? 'Identical' : 'Very similar', g.type === 'exact' ? 'danger' : 'warn'), ' ',
						g.ids.map(function (id, i) { return [i ? ', ' : '', h('a', { href: B.urls.products + '&id=' + id, text: '#' + id })]; })));
				});
				det.appendChild(ul);
				health.body.appendChild(det);
			}
		};
		var scan = function (btn) {
			RSS.busy(btn, true, 'Scanning…');
			RSS.api('/health/scan', { method: 'POST' }).then(function (hd) { renderHealth(hd); RSS.toast('Catalog scanned.', 'success'); }).catch(RSS.fail).then(function () { RSS.busy(btn, false); });
		};

		RSS.api('/bootstrap').then(function (d) {
			clear(top);
			var running = d.jobs.filter(function (j) { return j.status === 'running'; }).length;
			top.appendChild(kpi('Catalog health', d.health ? d.health.score + '/100' : '—', d.health ? RSS.num(d.health.products) + ' products' : 'Not scanned yet', 'gauge', d.health && d.health.score >= 80 ? 'good' : 'warn', '#rss-sec-health'));
			top.appendChild(kpi('Waiting for review', RSS.num(d.drafts.pending), RSS.num(d.drafts.applied) + ' applied · ' + RSS.num(d.drafts.rolled_back) + ' rolled back', 'check', d.drafts.pending ? 'warn' : 'good', B.urls.bulk + '#rss-sec-jobs'));
			top.appendChild(kpi('Bulk jobs', running ? running + ' running' : 'Idle', d.jobs.length ? 'Last: ' + d.jobs[0].label : 'No jobs yet', 'layers', running ? 'info' : '', B.urls.bulk));
			top.appendChild(kpi('AI API', d.ai.enabled ? 'ON' : 'OFF', d.ai.enabled ? d.usage.today_requests + '/' + d.usage.daily_limit + ' today · ' + RSS.money(d.usage.month_cost) + ' this month' : 'Free mode — no API costs', 'spark', d.ai.enabled ? 'ai' : 'good', B.urls.ai));
			renderHealth(d.health);
			clear(activity.body);
			if (!d.logs.length) { activity.body.appendChild(RSS.empty('clock', 'No activity yet', 'Applied changes, API requests and security events will appear here.')); }
			else {
				activity.body.appendChild(h('ul', { class: 'rss-timeline' }, d.logs.map(function (l) {
					return h('li', { class: 'is-' + l.level }, h('span', { class: 'rss-dot' }), h('div', {}, h('strong', { text: l.message }), h('small', { text: RSS.ago(l.created_at) + (l.user ? ' · ' + l.user : '') + ' · ' + l.action })));
				})));
			}
			var e = d.environment;
			var checks = [
				['PHP ' + e.php, true, 'Requires 7.4+'],
				['WordPress ' + e.wp, true, ''],
				['WooCommerce ' + (e.wc || 'not active'), !!e.wc, e.wc ? (e.hpos ? 'HPOS on — compatible' : 'Legacy order storage — compatible') : 'Product features need WooCommerce'],
				['GD image library', e.gd, e.gd ? 'Local image processing available' : 'Ask your host to enable PHP GD'],
				['WebP output', e.webp, e.webp ? 'Supported' : 'JPEG/PNG only'],
				['SEO plugin', !!e.seo.active, e.seo.active ? e.seo.label + ' fields are used' : 'None detected — SEO metadata cannot be written'],
				['Key encryption', e.encryption, e.encryption ? 'libsodium available' : 'Use wp-config.php constants'],
				['Background jobs (WP-Cron)', e.cron, e.cron ? 'Jobs also run when the page is closed' : 'WP-Cron disabled — keep the Bulk page open or use a server cron'],
				['HTTPS admin', e.https, e.https ? 'Secure' : 'Admin is not on HTTPS'],
				['PHP memory', true, e.memory]
			];
			clear(env.body).appendChild(h('ul', { class: 'rss-checks' }, checks.map(function (c) {
				return h('li', { class: c[1] ? 'is-ok' : 'is-warn' }, icon(c[1] ? 'check' : 'alert'), h('strong', { text: c[0] }), h('small', { text: c[2] }));
			})));
		}).catch(function (e) { clear(top).appendChild(RSS.empty('alert', 'Could not load the dashboard', e.message)); });
	};

	/* ======================================================= product studio */

	function objectCard(obj) {
		var s = obj.summary;
		var img = s.images && s.images.length ? s.images[0].url : '';
		return h('div', { class: 'rss-object' },
			img ? h('img', { src: img, alt: '', class: 'rss-object-img' }) : h('span', { class: 'rss-object-img is-empty' }, icon(s.type === 'product' ? 'box' : 'doc')),
			h('div', { class: 'rss-object-text' },
				h('div', { class: 'rss-row is-tight' }, RSS.badge(s.type, 'neutral'), RSS.badge(s.status, s.status === 'publish' ? 'success' : 'warn'), s.elementor ? RSS.badge('Elementor', 'info') : null, s.product_type ? RSS.badge(s.product_type, 'neutral') : null),
				h('h4', { text: s.title }),
				h('p', { class: 'rss-muted', text: '#' + s.id + (s.sku ? ' · SKU ' + s.sku : '') + (s.price_html ? ' · ' + s.price_html : '') + (s.stock ? ' · ' + s.stock + (s.stock_qty !== null && s.stock_qty !== undefined ? ' (' + s.stock_qty + ')' : '') : '') }),
				h('div', { class: 'rss-row is-tight' },
					h('a', { class: 'rss-btn is-ghost is-sm', href: s.edit_url }, icon('edit'), h('span', { text: 'Edit in WordPress' })),
					s.view_url ? h('a', { class: 'rss-btn is-ghost is-sm', href: s.view_url, target: '_blank', rel: 'noopener' }, icon('external'), h('span', { text: 'View' })) : null)),
			obj.report ? h('div', { class: 'rss-object-score' }, RSS.ring(obj.report.score, 'SEO')) : null);
	}
	RSS.objectCard = objectCard;

	RSS.pages.products = function (main) {
		if (!B.wc) { main.appendChild(RSS.empty('box', 'WooCommerce is not active', 'Product Studio needs WooCommerce. Content Studio and SEO Studio still work for posts and pages.')); return; }
		var state = { id: B.initial.type && B.initial.type !== 'product' ? 0 : B.initial.id, obj: null };
		var sel = RSS.section('select', 'Choose a product', 'Search by name, SKU or ID. Nothing changes until you approve a suggestion.', { step: '1' });
		var gen = RSS.section('generate', 'Generate suggestions', 'Pick fields and an engine. You will review every result before it is saved.', { step: '2' });
		var rev = RSS.section('review', 'Review & apply', 'Compare current and suggested values, edit, then approve or reject.', { step: '3' });
		var hist = RSS.section('history', 'History & rollback', 'Every applied change can be restored.', { step: '4' });
		var quality = RSS.section('quality', 'Quality check', 'Free local analysis of this product page.');
		var card = h('div', {});
		var picker = RSS.picker({ type: 'product', placeholder: 'Search products by name, SKU or ID…', label: 'Search products', onSelect: function (it) { load(it.id); } });
		sel.body.appendChild(picker);
		sel.body.appendChild(card);
		[sel, gen, rev, hist, quality].forEach(function (s) { main.appendChild(s); });
		var pending = RSS.draftList({ query: function () { return { object_type: 'product', object_id: state.id, status: 'pending', per_page: 50 }; }, emptyTitle: 'No suggestions waiting', emptyText: 'Generate suggestions above — they appear here for review.', onChange: function () { history.load(); reloadCard(); } });
		var history = RSS.draftList({ query: function () { return { object_type: 'product', object_id: state.id, status: 'applied,rolled_back', per_page: 50 }; }, emptyIcon: 'undo', emptyTitle: 'No applied changes yet', onChange: function () { reloadCard(); } });
		var blank = function () {
			[gen, rev, hist, quality].forEach(function (s) { clear(s.body).appendChild(RSS.empty('box', 'Choose a product first', '')); });
		};
		var reloadCard = function () {
			return RSS.api('/objects/product/' + state.id).then(function (obj) { state.obj = obj; clear(card).appendChild(objectCard(obj)); renderQuality(obj); });
		};
		var renderQuality = function (obj) {
			clear(quality.body);
			var r = obj.report;
			quality.body.appendChild(h('div', { class: 'rss-quality' },
				h('div', {}, RSS.ring(r.score, 'SEO score')),
				h('ul', { class: 'rss-checklist' }, r.checks.map(function (c) { return h('li', { class: 'is-' + c.status }, icon(c.status === 'pass' ? 'check' : 'alert'), h('span', {}, h('strong', { text: c.label }), c.detail ? h('small', { text: c.detail }) : null)); })),
				h('div', { class: 'rss-quality-side' },
					h('h4', { text: 'Readability' }),
					h('p', { class: 'rss-muted', text: r.readability.words + ' words · ' + r.readability.sentences + ' sentences · avg ' + r.readability.avg_sentence_words + ' words/sentence' + (r.readability.flesch !== null ? ' · Flesch ' + r.readability.flesch : '') }),
					h('h4', { text: 'Attributes' }),
					Object.keys(obj.summary.attributes || {}).length ? h('ul', { class: 'rss-kv' }, Object.keys(obj.summary.attributes).map(function (k) { return h('li', {}, h('span', { text: k }), h('strong', { text: obj.summary.attributes[k] })); })) : h('p', { class: 'rss-muted', text: 'No attributes. Adding attributes in WooCommerce gives the generators more real facts to work with.' }),
					h('a', { class: 'rss-btn is-ghost is-sm', href: B.urls.seo + '&type=product&id=' + obj.summary.id }, icon('search'), h('span', { text: 'Open in SEO Studio' })))));
		};
		var load = function (id) {
			state.id = Number(id);
			setQuery({ id: state.id, type: 'product' });
			clear(card).appendChild(RSS.skeleton(3));
			RSS.api('/objects/product/' + state.id).then(function (obj) {
				state.obj = obj;
				clear(card).appendChild(objectCard(obj));
				picker.input.value = obj.summary.title;
				clear(gen.body).appendChild(RSS.generatorPanel({
					type: function () { return 'product'; }, id: function () { return state.id; }, fields: obj.fields,
					defaults: ['short_description', 'seo_title', 'seo_description', 'seo_focus'],
					onDrafts: function () { pending.load(); rev.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
				}));
				clear(rev.body).appendChild(pending); pending.load();
				clear(hist.body).appendChild(history); history.load();
				renderQuality(obj);
			}).catch(function (e) { clear(card).appendChild(RSS.empty('alert', 'Could not load this product', e.message)); blank(); });
		};
		if (state.id) { load(state.id); } else { blank(); }
	};

	/* ========================================================= image studio */

	RSS.pages.images = function (main) {
		var st = { mode: 'product', productId: 0, images: [], selected: {}, options: Object.assign({}, B.settings.image) };
		var sel = RSS.section('select', 'Choose images', 'Pick a product to work on its featured and gallery images, or any image in the Media Library.', { step: '1' });
		var preset = RSS.section('preset', 'Output settings', 'Product pixels are only resized and moved — never repainted. Originals stay in the Media Library.', { step: '2' });
		var results = RSS.section('results', 'Review new images', 'Compare before and after. Apply replaces the image on the product; reject deletes the unused copy.', { step: '3' });
		var alt = RSS.section('alt', 'Alt text', 'Accessible, SEO-friendly descriptions for each image.');
		var scene = RSS.section('scene', 'AI background scene', 'Optional: AI paints only the background; the product photo is placed on top unchanged.');
		[sel, preset, results, alt, scene].forEach(function (s) { main.appendChild(s); });

		var grid = h('div', { class: 'rss-imggrid' });
		var modeSeg = RSS.segmented([{ value: 'product', label: 'Product images', icon: 'box' }, { value: 'media', label: 'Media Library', icon: 'image' }], 'product', function (v) { st.mode = v; renderPicker(); }, 'Source');
		var pickWrap = h('div', {});
		sel.body.appendChild(modeSeg); sel.body.appendChild(pickWrap); sel.body.appendChild(grid);

		var renderGrid = function () {
			clear(grid);
			if (!st.images.length) { grid.appendChild(RSS.empty('image', st.mode === 'product' ? 'This product has no images' : 'Choose an image', '')); return; }
			st.images.forEach(function (img) {
				var id = RSS.uid('img');
				var cb = h('input', { type: 'checkbox', id: id });
				cb.checked = !!st.selected[img.id];
				cb.addEventListener('change', function () { if (cb.checked) { st.selected[img.id] = img; } else { delete st.selected[img.id]; } tile.classList.toggle('is-selected', cb.checked); });
				var tile = h('label', { class: 'rss-imgtile' + (cb.checked ? ' is-selected' : ''), for: id },
					cb, h('span', { class: 'rss-imgtile-thumb' }, img.url ? h('img', { src: img.url, alt: img.alt || '', loading: 'lazy' }) : icon('image')),
					h('span', { class: 'rss-imgtile-meta' },
						h('strong', { text: img.role === 'featured' ? 'Featured image' : img.role === 'gallery' ? 'Gallery ' + (img.index + 1) : img.title }),
						h('small', { text: img.width + '×' + img.height + ' · ' + RSS.bytes(img.bytes) + ' · ' + (img.mime || '').replace('image/', '').toUpperCase() }),
						img.derivative ? RSS.badge('Studio image', 'success') : null,
						img.alt ? null : RSS.badge('No alt text', 'warn')));
				grid.appendChild(tile);
			});
		};
		var loadProduct = function (id) {
			st.productId = Number(id); st.selected = {};
			setQuery({ id: id, type: 'product' });
			clear(grid).appendChild(RSS.skeleton(3));
			RSS.api('/objects/product/' + id).then(function (obj) {
				st.images = obj.summary.images || [];
				st.images.forEach(function (i) { if (i.role === 'featured' && !i.derivative) { st.selected[i.id] = i; } });
				renderGrid(); renderAlt(); drafts.load();
			}).catch(function (e) { clear(grid).appendChild(RSS.empty('alert', 'Could not load images', e.message)); });
		};
		var renderPicker = function () {
			clear(pickWrap); st.images = []; st.selected = {}; renderGrid();
			if (st.mode === 'product') {
				if (!B.wc) { pickWrap.appendChild(RSS.empty('box', 'WooCommerce is not active', '')); return; }
				pickWrap.appendChild(RSS.picker({ type: 'product', placeholder: 'Search products…', onSelect: function (it) { loadProduct(it.id); } }));
			} else {
				st.productId = 0;
				pickWrap.appendChild(RSS.picker({ type: 'attachment', placeholder: 'Search the Media Library…', onSelect: function (it) {
					RSS.api('/objects/attachment/' + it.id).then(function (obj) {
						var img = Object.assign({}, obj.summary.image, { role: 'media', index: -1 });
						if (!st.images.some(function (x) { return x.id === img.id; })) { st.images.push(img); }
						st.selected[img.id] = img; renderGrid(); renderAlt();
					}).catch(RSS.fail);
				} }));
			}
		};

		/* preset form */
		var o = st.options;
		var sizeSel = RSS.select([{ value: 300, label: '300 × 300 (thumbnail)' }, { value: 600, label: '600 × 600' }, { value: 800, label: '800 × 800 (recommended gallery)' }, { value: 1000, label: '1000 × 1000' }, { value: 1200, label: '1200 × 1200 (zoom)' }, { value: 1600, label: '1600 × 1600' }, { value: 'custom', label: 'Custom…' }], [300, 600, 800, 1000, 1200, 1600].indexOf(Number(o.size)) === -1 ? 'custom' : o.size, { class: 'rss-input' });
		var custom = h('input', { type: 'number', class: 'rss-input', min: '100', max: '2400', value: o.size, hidden: [300, 600, 800, 1000, 1200, 1600].indexOf(Number(o.size)) !== -1, 'aria-label': 'Custom size in pixels' });
		sizeSel.addEventListener('change', function () { custom.hidden = sizeSel.value !== 'custom'; if (!custom.hidden) { custom.focus(); } });
		var canvas = RSS.segmented([{ value: 'white', label: 'White' }, { value: 'transparent', label: 'Transparent' }, { value: 'color', label: 'Colour' }, { value: 'original', label: 'Extend original' }], o.canvas, function (v) { color.hidden = v !== 'color'; fmtNote(); }, 'Canvas');
		var color = h('input', { type: 'color', value: o.background || '#ffffff', hidden: o.canvas !== 'color', 'aria-label': 'Canvas colour' });
		var format = RSS.select([{ value: 'jpeg', label: 'JPEG (smallest for photos)' }, { value: 'webp', label: 'WebP (modern, small)' }, { value: 'png', label: 'PNG (lossless, keeps transparency)' }], o.format, { class: 'rss-input' });
		var quality = h('input', { type: 'range', min: '40', max: '100', value: o.quality, 'aria-label': 'Quality' });
		var qv = h('output', { text: o.quality });
		quality.addEventListener('input', function () { qv.textContent = quality.value; });
		var padding = h('input', { type: 'range', min: '0', max: '30', value: o.padding, 'aria-label': 'Padding' });
		var pv = h('output', { text: o.padding + '%' });
		padding.addEventListener('input', function () { pv.textContent = padding.value + '%'; });
		var removeBg = RSS.toggle('Remove plain background', o.remove_bg, null, 'Only for photos on a plain backdrop. Busy backgrounds are refused to protect the product.');
		var tol = h('input', { type: 'range', min: '4', max: '60', value: o.tolerance, 'aria-label': 'Background tolerance' });
		var tv = h('output', { text: o.tolerance });
		tol.addEventListener('input', function () { tv.textContent = tol.value; });
		var trim = RSS.toggle('Trim empty borders', o.trim, null, 'Centres the product and makes all images consistent.');
		var sharpen = RSS.toggle('Light sharpening', o.sharpen, null, 'After downscaling only.');
		var upscale = RSS.toggle('Allow upscaling', o.upscale, null, 'Enlarges small photos by resampling — adds no real detail.');
		var fmtHint = h('small', { class: 'rss-help' });
		var fmtNote = function () { fmtHint.textContent = canvas.value() === 'transparent' && format.value === 'jpeg' ? 'JPEG cannot store transparency — PNG will be used.' : ''; };
		format.addEventListener('change', fmtNote);
		var saveDefault = h('input', { type: 'checkbox', id: RSS.uid('sd') });
		preset.body.appendChild(h('div', { class: 'rss-form-grid' },
			RSS.field('Output size', h('div', { class: 'rss-row is-tight' }, sizeSel, custom)),
			RSS.field('Format', h('div', {}, format, fmtHint)),
			RSS.field('Canvas', h('div', { class: 'rss-row is-tight' }, canvas, color)),
			RSS.field('Quality', h('div', { class: 'rss-range' }, quality, qv), 'Higher = larger file. 82–88 is a good balance.'),
			RSS.field('Padding around product', h('div', { class: 'rss-range' }, padding, pv)),
			RSS.field('Background tolerance', h('div', { class: 'rss-range' }, tol, tv), 'How different from the backdrop a pixel must be to count as product.')));
		preset.body.appendChild(h('div', { class: 'rss-toggles' }, trim, removeBg, sharpen, upscale));
		var current = function () {
			return { size: sizeSel.value === 'custom' ? Number(custom.value) : Number(sizeSel.value), format: format.value, quality: Number(quality.value), padding: Number(padding.value), canvas: canvas.value(), background: color.value, remove_bg: removeBg.input.checked ? 1 : 0, tolerance: Number(tol.value), trim: trim.input.checked ? 1 : 0, sharpen: sharpen.input.checked ? 1 : 0, upscale: upscale.input.checked ? 1 : 0 };
		};
		var processBtn = RSS.btn('Create new images', { kind: 'primary', icon: 'wand' });
		var prog = h('div', { class: 'rss-inline-progress' });
		preset.body.appendChild(h('div', { class: 'rss-row' }, processBtn, B.admin ? h('label', { class: 'rss-check', for: saveDefault.id }, saveDefault, h('span', { text: 'Save as default' })) : null, prog));
		processBtn.addEventListener('click', function () {
			var list = Object.keys(st.selected).map(function (k) { return st.selected[k]; });
			if (!list.length) { RSS.toast('Select at least one image.', 'warn'); return; }
			RSS.busy(processBtn, true, 'Processing…');
			var done = 0, fails = 0, notes = [];
			var next = function (i) {
				if (i >= list.length) {
					RSS.busy(processBtn, false); clear(prog);
					RSS.toast(done + ' new image(s) ready for review' + (fails ? ', ' + fails + ' failed' : '') + '. Products are unchanged until you apply.', fails ? 'warn' : 'success', 7000);
					notes.forEach(function (n) { RSS.toast(n, 'info', 9000); });
					if (st.productId) { drafts.load(); loadProduct(st.productId); } else { mediaResults(); }
					return;
				}
				clear(prog).appendChild(h('span', { text: 'Image ' + (i + 1) + ' of ' + list.length }));
				prog.appendChild(RSS.progress(100 * i / list.length));
				var img = list[i];
				RSS.api('/images/process', { method: 'POST', body: { attachment_id: img.id, product_id: st.productId, role: img.role, options: current(), save_default: i === 0 && saveDefault.checked ? 1 : 0 } })
					.then(function (res) {
						done++;
						(res.notes || []).forEach(function (n) { if (/skipped|not|memory/i.test(n)) { notes.push(n); } });
						if (!st.productId) { st.mediaResults = (st.mediaResults || []).concat([res]); }
					})
					.catch(function (e) { fails++; RSS.toast((img.title || '#' + img.id) + ': ' + e.message, 'error'); })
					.then(function () { next(i + 1); });
			};
			next(0);
		});

		var drafts = RSS.draftList({ query: function () { return { object_type: 'product', object_id: st.productId, status: 'pending,applied', per_page: 40 }; }, emptyIcon: 'image', emptyTitle: 'No new images yet', emptyText: 'Create new images above to compare them here.', onChange: function () { if (st.productId) { loadProduct(st.productId); } } });
		var mediaResults = function () {
			clear(results.body);
			if (st.productId) { results.body.appendChild(drafts); return; }
			var list = st.mediaResults || [];
			if (!list.length) { results.body.appendChild(RSS.empty('image', 'No new images yet', 'Media Library images are saved as new copies. Use them anywhere; delete the ones you do not need.')); return; }
			results.body.appendChild(h('div', { class: 'rss-media-results' }, list.map(function (r) {
				var box = h('div', { class: 'rss-draft' },
					RSS.compare(r.source.url, r.derivative.url, ['Original', 'New']),
					h('p', { class: 'rss-muted', text: r.source.width + '×' + r.source.height + ' ' + RSS.bytes(r.source.bytes) + ' → ' + r.derivative.width + '×' + r.derivative.height + ' ' + RSS.bytes(r.derivative.bytes) }),
					h('div', { class: 'rss-row' },
						h('a', { class: 'rss-btn is-ghost is-sm', href: r.derivative.full, target: '_blank', rel: 'noopener' }, icon('external'), h('span', { text: 'Open' })),
						RSS.btn('Delete copy', { icon: 'trash', small: true, onClick: function (e) {
							var b = e.currentTarget; RSS.busy(b, true);
							RSS.api('/images/' + r.derivative.id, { method: 'DELETE' }).then(function () { st.mediaResults = st.mediaResults.filter(function (x) { return x !== r; }); mediaResults(); RSS.toast('Copy deleted.', 'success'); }).catch(function (er) { RSS.busy(b, false); RSS.fail(er); });
						} })));
				return box;
			})));
		};
		results.body.appendChild(drafts);

		/* alt text */
		var renderAlt = function () {
			clear(alt.body);
			if (!st.images.length) { alt.body.appendChild(RSS.empty('image', 'Choose images first', '')); return; }
			var altDrafts = h('div', {});
			alt.body.appendChild(h('ul', { class: 'rss-altlist' }, st.images.map(function (img) {
				var mk = function (engine) {
					return RSS.btn(engine === 'ai' ? 'AI vision' : 'Free', { icon: engine === 'ai' ? 'spark' : 'leaf', small: true, onClick: function (e) {
						var b = e.currentTarget; RSS.busy(b, true);
						RSS.api('/images/alt', { method: 'POST', body: { attachment_id: img.id, product_id: st.productId, engine: engine } }).then(function (res) {
							if (!res.drafts.length) { RSS.toast(Object.values(res.skipped || {})[0] || 'No change.', 'info'); return; }
							res.drafts.forEach(function (d) { altDrafts.appendChild(RSS.draftCard(d, { onChange: function () { loadProduct(st.productId || 0); } })); });
						}).catch(RSS.fail).then(function () { RSS.busy(b, false); });
					} });
				};
				return h('li', {}, img.url ? h('img', { src: img.url, alt: '' }) : null,
					h('div', {}, h('strong', { text: img.role === 'featured' ? 'Featured' : img.role === 'gallery' ? 'Gallery ' + (img.index + 1) : img.title }),
						h('p', { class: img.alt ? '' : 'rss-muted', text: img.alt || 'No alt text' })),
					h('div', { class: 'rss-row is-tight' }, mk('local'), mk('ai')));
			})));
			alt.body.appendChild(altDrafts);
		};

		/* scene */
		RSS.api('/ai').then(function (stt) {
			clear(scene.body);
			if (!stt.enabled || !stt.image_features) {
				scene.body.appendChild(RSS.empty('spark', 'AI image features are off', 'Enable the AI API and “Image features” in AI Providers to use background scenes. Product pixels are never edited by AI — only the backdrop is generated.', B.admin ? h('a', { class: 'rss-btn is-ghost', href: B.urls.ai }, icon('key'), h('span', { text: 'AI Providers' })) : null));
				return;
			}
			var prompt = h('input', { type: 'text', class: 'rss-input', placeholder: 'e.g. soft beige marble table with warm window light', maxlength: '300' });
			var go = RSS.btn('Generate scene (paid request)', { kind: 'primary', icon: 'spark' });
			go.addEventListener('click', function () {
				var first = Object.keys(st.selected)[0];
				if (!first || !st.productId) { RSS.toast('Choose one product image first.', 'warn'); return; }
				if (!prompt.value.trim()) { RSS.toast('Describe the background.', 'warn'); prompt.focus(); return; }
				RSS.confirm({ title: 'Generate an AI background?', text: 'One image request will be sent (estimated ' + RSS.money(stt.limits.image_price) + '). The product is cut out from the original photo and placed on the new background unchanged.', confirm: 'Generate' }).then(function (yes) {
					if (!yes) { return; }
					RSS.busy(go, true, 'Generating…');
					RSS.api('/images/scene', { method: 'POST', body: { attachment_id: Number(first), product_id: st.productId, role: st.selected[first].role, scene: prompt.value, options: current() } })
						.then(function () { RSS.toast('Scene image ready for review.', 'success'); drafts.load(); })
						.catch(RSS.fail).then(function () { RSS.busy(go, false); });
				});
			});
			scene.body.appendChild(h('div', { class: 'rss-row' }, prompt, go));
			scene.body.appendChild(h('p', { class: 'rss-help', text: 'Works only when the plain background can be removed safely. The result is marked as an AI scene in the Media Library.' }));
		}).catch(function () {});

		renderPicker();
		if (B.initial.id && (!B.initial.type || B.initial.type === 'product')) { loadProduct(B.initial.id); }
		renderAlt();
	};

	/* ======================================================= content studio */

	RSS.pages.content = function (main) {
		var st = { type: B.initial.type === 'page' ? 'page' : 'post', id: (B.initial.type === 'post' || B.initial.type === 'page') ? B.initial.id : 0, obj: null };
		var sel = RSS.section('select', 'Choose a post or page', '', { step: '1' });
		var gen = RSS.section('generate', 'Improve content', 'Summaries and SEO for free; readability rewrites and translations with AI or your subscription.', { step: '2' });
		var links = RSS.section('links', 'Internal links', 'Related pages and products from this site, with one-click link drafts.');
		var rev = RSS.section('review', 'Review & apply', 'Applied changes to posts and pages also create a normal WordPress revision.');
		var article = RSS.section('article', 'New article draft', 'Write a brief; get a WordPress draft (never published automatically).');
		var media = RSS.section('media', 'Media without alt text', 'Images in the Media Library that have no alt text.');
		[sel, gen, links, rev, article, media].forEach(function (s) { main.appendChild(s); });
		var card = h('div', {});
		sel.body.appendChild(RSS.picker({ types: [{ value: 'content', label: 'Posts & pages' }], type: 'content', placeholder: 'Search posts and pages…', onSelect: function (it) { load(it.type, it.id); } }));
		sel.body.appendChild(card);
		var pending = RSS.draftList({ query: function () { return { object_type: st.type, object_id: st.id, status: 'pending,applied', per_page: 40 }; }, emptyTitle: 'Nothing to review yet', onChange: function () { refresh(); } });
		var refresh = function () { return RSS.api('/objects/' + st.type + '/' + st.id).then(function (obj) { st.obj = obj; clear(card).appendChild(objectCard(obj)); }); };
		var load = function (type, id) {
			st.type = type; st.id = Number(id);
			setQuery({ id: id, type: type });
			clear(card).appendChild(RSS.skeleton(2));
			RSS.api('/objects/' + type + '/' + id).then(function (obj) {
				st.obj = obj;
				clear(card).appendChild(objectCard(obj));
				if (obj.summary.elementor) {
					card.appendChild(h('div', { class: 'rss-callout is-info' }, icon('info'), h('p', { text: 'Built with Elementor: the content itself is edited in Elementor. Analysis, excerpt and SEO fields still work here.' })));
				}
				clear(gen.body).appendChild(RSS.generatorPanel({ type: function () { return st.type; }, id: function () { return st.id; }, fields: obj.fields, defaults: ['excerpt', 'seo_title', 'seo_description', 'seo_focus'], onDrafts: function () { pending.load(); } }));
				clear(rev.body).appendChild(pending); pending.load();
				loadLinks();
			}).catch(function (e) { clear(card).appendChild(RSS.empty('alert', 'Could not load', e.message)); });
		};
		var loadLinks = function () {
			clear(links.body).appendChild(RSS.skeleton(3));
			RSS.api('/content/' + st.type + '/' + st.id + '/links').then(function (list) {
				clear(links.body);
				if (!list.length) { links.body.appendChild(RSS.empty('link', 'No suggestions', 'No related published content was found for this text.')); return; }
				links.body.appendChild(h('ul', { class: 'rss-links' }, list.map(function (l) {
					var add = l.in_content && !(st.obj && st.obj.summary.elementor) ? RSS.btn('Add link draft', { icon: 'link', small: true, onClick: function (e) {
						var b = e.currentTarget; RSS.busy(b, true);
						RSS.api('/content/link', { method: 'POST', body: { type: st.type, id: st.id, url: l.url, anchor: l.anchor } }).then(function () {
							RSS.toast('Link draft created — review it below.', 'success'); pending.load();
						}).catch(RSS.fail).then(function () { RSS.busy(b, false); });
					} }) : null;
					return h('li', {}, RSS.badge(l.type, 'neutral'), h('div', {}, h('strong', { text: l.title }), h('small', { text: l.in_content ? 'Anchor in your text: “' + l.anchor + '”' : 'No matching phrase in the text — add a sentence that mentions it.' })),
						h('div', { class: 'rss-row is-tight' }, RSS.btn('', { icon: 'copy', small: true, title: 'Copy URL', onClick: function () { RSS.copy(l.url).then(function () { RSS.toast('URL copied.', 'success'); }, RSS.fail); } }), add));
				})));
			}).catch(function (e) { clear(links.body).appendChild(RSS.empty('alert', 'Could not load suggestions', e.message)); });
		};
		var blank = function () { [gen, links, rev].forEach(function (s) { clear(s.body).appendChild(RSS.empty('doc', 'Choose a post or page first', '')); }); };

		/* article */
		var brief = h('textarea', { class: 'rss-input', rows: '5', placeholder: 'Topic, audience, key points and facts to include. Example: "How to store attar in hot weather — for Bangladeshi customers. Points: keep away from sunlight, close the cap, use a cool drawer."' });
		var ptype = RSS.select([{ value: 'post', label: 'Post' }, { value: 'page', label: 'Page' }], 'post', { class: 'rss-input' });
		var lang = RSS.select(LANGS, B.settings.language, { class: 'rss-input' });
		var out = h('div', {});
		var aiBtn = RSS.btn('Write with AI', { kind: 'primary', icon: 'spark' });
		var subBtn = RSS.btn('Prompt for my subscription', { icon: 'copy' });
		article.body.appendChild(RSS.field('Brief', brief));
		article.body.appendChild(h('div', { class: 'rss-form-grid' }, RSS.field('Create as', ptype), RSS.field('Language', lang)));
		article.body.appendChild(h('div', { class: 'rss-row' }, aiBtn, subBtn));
		article.body.appendChild(out);
		var created = function (res) {
			clear(out).appendChild(h('div', { class: 'rss-callout is-success' }, icon('check'), h('div', {}, h('p', { text: res.note }), h('a', { class: 'rss-btn is-primary is-sm', href: res.edit_url }, icon('edit'), h('span', { text: 'Open the draft' })))));
		};
		aiBtn.addEventListener('click', function () {
			RSS.confirm({ title: 'Write the article with AI?', text: 'One API request will be sent with your brief. The result is saved as a WordPress draft.', confirm: 'Send request' }).then(function (yes) {
				if (!yes) { return; }
				RSS.busy(aiBtn, true, 'Writing…');
				RSS.api('/article', { method: 'POST', body: { brief: brief.value, engine: 'ai', post_type: ptype.value, language: lang.value } }).then(created).catch(RSS.fail).then(function () { RSS.busy(aiBtn, false); });
			});
		});
		subBtn.addEventListener('click', function () {
			RSS.busy(subBtn, true, 'Building…');
			RSS.api('/article', { method: 'POST', body: { brief: brief.value, engine: 'manual', post_type: ptype.value, language: lang.value } }).then(function (res) {
				var ta = h('textarea', { class: 'rss-input rss-code', rows: '7', readonly: true }); ta.value = res.prompt;
				var paste = h('textarea', { class: 'rss-input rss-code', rows: '6', placeholder: 'Paste the JSON answer here…' });
				var imp = RSS.btn('Create draft from answer', { kind: 'primary', icon: 'download', onClick: function (e) {
					var b = e.currentTarget; RSS.busy(b, true);
					RSS.api('/article', { method: 'POST', body: { brief: brief.value, engine: 'manual', pasted: paste.value, post_type: ptype.value } }).then(created).catch(RSS.fail).then(function () { RSS.busy(b, false); });
				} });
				clear(out).appendChild(h('div', { class: 'rss-manual' }, ta, h('div', { class: 'rss-row' }, RSS.btn('Copy prompt', { icon: 'copy', small: true, onClick: function () { RSS.copy(ta.value).then(function () { RSS.toast('Copied.', 'success'); }, RSS.fail); } })), paste, h('div', { class: 'rss-row' }, imp)));
			}).catch(RSS.fail).then(function () { RSS.busy(subBtn, false); });
		});

		/* media without alt */
		var mpage = 1;
		var loadMedia = function () {
			clear(media.body).appendChild(RSS.skeleton(3));
			RSS.api('/media', { query: { missing_alt: 1, page: mpage } }).then(function (res) {
				clear(media.body);
				if (!res.items.length) { media.body.appendChild(RSS.empty('check', 'Every image has alt text', '')); return; }
				var altDrafts = h('div', {});
				media.body.appendChild(h('p', { class: 'rss-muted', text: res.total + ' image(s) without alt text. ' }, h('a', { href: B.urls.bulk + '&op=alt_local', text: 'Fix all in Bulk Manager →' })));
				media.body.appendChild(h('ul', { class: 'rss-altlist' }, res.items.map(function (img) {
					return h('li', {}, h('img', { src: img.url, alt: '', loading: 'lazy' }), h('div', {}, h('strong', { text: img.title }), h('small', { class: 'rss-muted', text: img.width + '×' + img.height })),
						h('div', { class: 'rss-row is-tight' }, ['local', 'ai'].map(function (engine) {
							return RSS.btn(engine === 'ai' ? 'AI vision' : 'Free', { icon: engine === 'ai' ? 'spark' : 'leaf', small: true, onClick: function (e) {
								var b = e.currentTarget; RSS.busy(b, true);
								RSS.api('/images/alt', { method: 'POST', body: { attachment_id: img.id, engine: engine } }).then(function (r) {
									r.drafts.forEach(function (d) { altDrafts.appendChild(RSS.draftCard(d, { showObject: true, onChange: function () {} })); });
								}).catch(RSS.fail).then(function () { RSS.busy(b, false); });
							} });
						})));
				})));
				media.body.appendChild(altDrafts);
				if (res.pages > 1) {
					media.body.appendChild(h('div', { class: 'rss-pager' },
						RSS.btn('Previous', { small: true, disabled: mpage <= 1, onClick: function () { mpage--; loadMedia(); } }),
						h('span', { text: 'Page ' + mpage + ' of ' + res.pages }),
						RSS.btn('Next', { small: true, disabled: mpage >= res.pages, onClick: function () { mpage++; loadMedia(); } })));
				}
			}).catch(function (e) { clear(media.body).appendChild(RSS.empty('alert', 'Could not load media', e.message)); });
		};
		loadMedia();
		if (st.id) { load(st.type, st.id); } else { blank(); }
	};

	/* =========================================================== seo studio */

	RSS.pages.seo = function (main) {
		var st = { type: B.initial.type || (B.wc ? 'product' : 'post'), id: B.initial.id || 0, obj: null };
		var sel = RSS.section('select', 'Choose a page to check', '', { step: '1' });
		var score = RSS.section('score', 'SEO checks', 'Local, free analysis. Fix items marked in amber or red.', { step: '2' });
		var serp = RSS.section('serp', 'Search preview & metadata', 'Edit and watch the Google preview update. Saving creates a reviewable change.', { step: '3' });
		var structure = RSS.section('structure', 'Headings & readability', '');
		var rev = RSS.section('review', 'Review & apply', '');
		[sel, score, serp, structure, rev].forEach(function (s) { main.appendChild(s); });
		var card = h('div', {});
		var types = [];
		if (B.wc) { types.push({ value: 'product', label: 'Products' }); }
		types.push({ value: 'content', label: 'Posts & pages' });
		sel.body.appendChild(RSS.picker({ types: types, type: st.type === 'product' && B.wc ? 'product' : 'content', placeholder: 'Search…', onSelect: function (it) { load(it.type, it.id); } }));
		sel.body.appendChild(card);
		var drafts = RSS.draftList({ query: function () { return { object_type: st.type, object_id: st.id, status: 'pending,applied', per_page: 30 }; }, emptyTitle: 'No SEO changes yet', onChange: function () { load(st.type, st.id, true); } });

		var load = function (type, id, keepScroll) {
			st.type = type; st.id = Number(id);
			setQuery({ id: id, type: type });
			if (!keepScroll) { clear(card).appendChild(RSS.skeleton(2)); }
			RSS.api('/objects/' + type + '/' + id).then(function (obj) {
				st.obj = obj;
				clear(card).appendChild(objectCard(obj));
				var r = obj.report;
				clear(score.body).appendChild(h('div', { class: 'rss-quality' },
					h('div', {}, RSS.ring(r.score, 'SEO score'), r.adapter ? RSS.badge(r.adapter, 'info') : RSS.badge('No SEO plugin', 'warn')),
					h('ul', { class: 'rss-checklist' }, r.checks.map(function (c) { return h('li', { class: 'is-' + c.status }, icon(c.status === 'pass' ? 'check' : 'alert'), h('span', {}, h('strong', { text: c.label }), c.detail ? h('small', { text: c.detail }) : null)); }))));
				renderSerp(obj);
				var rd = r.readability;
				clear(structure.body).appendChild(h('div', { class: 'rss-two' },
					h('div', {}, h('h4', { text: 'Heading outline' }), r.headings.length ? h('ol', { class: 'rss-outline' }, r.headings.map(function (hh) { return h('li', { class: 'lvl-' + hh.level }, h('code', { text: 'H' + hh.level }), ' ', hh.text); })) : h('p', { class: 'rss-muted', text: 'No headings in the content.' })),
					h('div', {}, h('h4', { text: 'Readability' }), h('ul', { class: 'rss-kv' },
						h('li', {}, h('span', { text: 'Words' }), h('strong', { text: RSS.num(rd.words) })),
						h('li', {}, h('span', { text: 'Reading time' }), h('strong', { text: rd.reading_minutes + ' min' })),
						h('li', {}, h('span', { text: 'Avg. sentence' }), h('strong', { text: rd.avg_sentence_words + ' words' })),
						h('li', {}, h('span', { text: 'Long sentences' }), h('strong', { text: rd.long_sentences_pct + '%' })),
						h('li', {}, h('span', { text: 'Flesch reading ease' }), h('strong', { text: rd.flesch === null ? 'n/a (non-English)' : rd.flesch })),
						h('li', {}, h('span', { text: 'Links in / out' }), h('strong', { text: r.links.internal + ' / ' + r.links.external }))),
						h('h4', { text: 'Top keywords' }), h('div', { class: 'rss-chips' }, r.keywords.map(function (k) { return h('span', { class: 'rss-chip', text: k }); })))));
				clear(rev.body).appendChild(drafts); drafts.load();
			}).catch(function (e) { clear(card).appendChild(RSS.empty('alert', 'Could not load', e.message)); });
		};
		var renderSerp = function (obj) {
			var r = obj.report; var f = obj.fields;
			clear(serp.body);
			if (!r.adapter) {
				serp.body.appendChild(h('div', { class: 'rss-callout is-warn' }, icon('alert'), h('p', { text: 'No SEO plugin is active. Install Yoast SEO, Rank Math or SEOPress (or choose one in Settings → SEO) to save titles and meta descriptions.' })));
			}
			var t = h('input', { type: 'text', class: 'rss-input', value: r.seo.title || '' , placeholder: r.seo.effective });
			var d = h('textarea', { class: 'rss-input', rows: '3', placeholder: 'Write a 120–155 character summary that includes the keyphrase.' }); d.value = r.seo.description || '';
			var k = h('input', { type: 'text', class: 'rss-input', value: r.seo.focus || '', list: 'rss-kw' });
			var dl = h('datalist', { id: 'rss-kw' }, r.keywords.map(function (w) { return h('option', { value: w }); }));
			var pv = h('div', { class: 'rss-serp' });
			var mode = 'desktop';
			var draw = function () {
				clear(pv);
				pv.className = 'rss-serp is-' + mode;
				var url = r.serp.url || '';
				var host = ''; try { host = new URL(url).host; } catch (e) { host = url; }
				pv.appendChild(h('div', { class: 'rss-serp-site' }, h('span', { class: 'rss-serp-fav', 'aria-hidden': 'true' }), h('span', {}, h('strong', { text: host }), h('small', { text: url }))));
				var title = (t.value || r.seo.effective || '').trim();
				var desc = (d.value || r.serp.desc || '').trim();
				var tmax = mode === 'mobile' ? 78 : 60;
				pv.appendChild(h('div', { class: 'rss-serp-title', text: title.length > tmax ? title.slice(0, tmax - 1) + '…' : title }));
				pv.appendChild(h('div', { class: 'rss-serp-desc', text: desc.length > 158 ? desc.slice(0, 157) + '…' : desc }));
			};
			[t, d].forEach(function (el) { el.addEventListener('input', draw); });
			var modeSeg = RSS.segmented([{ value: 'desktop', label: 'Desktop' }, { value: 'mobile', label: 'Mobile' }], 'desktop', function (v) { mode = v; draw(); }, 'Preview');
			var save = RSS.btn('Save as reviewable change', { kind: 'primary', icon: 'check', disabled: !r.adapter });
			var suggest = RSS.btn('Suggest (free)', { icon: 'leaf', disabled: !r.adapter });
			save.addEventListener('click', function () {
				var vals = { seo_title: t.value, seo_description: d.value, seo_focus: k.value };
				var lines = Object.keys(vals).filter(function (key) { return (vals[key] || '') !== (f[key] ? f[key].value : ''); });
				if (!lines.length) { RSS.toast('Nothing changed.', 'info'); return; }
				RSS.busy(save, true, 'Saving…');
				var payload = {}; lines.forEach(function (key) { payload[key] = vals[key]; });
				RSS.api('/drafts/create', { method: 'POST', body: { type: st.type, id: st.id, values: payload } }).then(function () {
					RSS.toast('Saved as a change waiting for review below.', 'success'); drafts.load();
				}).catch(RSS.fail).then(function () { RSS.busy(save, false); });
			});
			suggest.addEventListener('click', function () {
				RSS.busy(suggest, true, 'Working…');
				RSS.api('/generate', { method: 'POST', body: { type: st.type, id: st.id, fields: ['seo_title', 'seo_description', 'seo_focus'], engine: 'local' } }).then(function (res) {
					RSS.toast(res.drafts.length ? res.drafts.length + ' suggestion(s) added below.' : 'Current values are already fine.', res.drafts.length ? 'success' : 'info'); drafts.load();
				}).catch(RSS.fail).then(function () { RSS.busy(suggest, false); });
			});
			serp.body.appendChild(h('div', { class: 'rss-two' },
				h('div', {},
					RSS.field('SEO title', h('div', {}, t, RSS.counter(t, B.settings.seo.title_max, 30))),
					RSS.field('Meta description', h('div', {}, d, RSS.counter(d, B.settings.seo.meta_max, 120))),
					RSS.field('Focus keyphrase', h('div', {}, k, dl)),
					h('div', { class: 'rss-row' }, save, suggest)),
				h('div', {}, modeSeg, pv, h('p', { class: 'rss-help', text: 'Approximate preview — search engines may rewrite titles and snippets.' }))));
			draw();
		};
		if (st.id && st.type) { load(st.type, st.id); }
		else { [score, serp, structure, rev].forEach(function (s) { s.body.appendChild(RSS.empty('search', 'Choose something to check', '')); }); }
	};
})(window.RSS);
