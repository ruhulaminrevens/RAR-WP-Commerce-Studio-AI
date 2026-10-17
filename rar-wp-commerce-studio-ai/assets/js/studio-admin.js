/**
 * RAR Commerce Studio — Bulk Manager, AI Providers, Settings, Activity Logs.
 */
(function (RSS) {
	'use strict';
	if (!RSS) { return; }
	var h = RSS.h, $ = RSS.$, $$ = RSS.$$, clear = RSS.clear, icon = RSS.icon, B = RSS.boot;

	var JOB_STATUS = { running: 'info', paused: 'warn', completed: 'success', cancelled: 'neutral', failed: 'danger', queued: 'neutral' };

	/* ========================================================= bulk manager */

	RSS.pages.bulk = function (main) {
		var params = new URLSearchParams(location.search);
		var create = RSS.section('create', 'Create a bulk job', 'Jobs create suggestions only. You decide what to apply afterwards.', { step: '1' });
		var jobs = RSS.section('jobs', 'Jobs', 'Progress updates every few seconds while this page is open; WP-Cron continues in the background.', { step: '2', actions: RSS.btn('Refresh', { icon: 'refresh', small: true, onClick: function () { loadJobs(); } }) });
		var detail = RSS.section('detail', 'Job details & review', 'Apply all clean suggestions at once, or review them one by one.', { step: '3' });
		[create, jobs, detail].forEach(function (s) { main.appendChild(s); });

		var ops = {};
		var tax = { categories: [], brands: [] };
		var ai = null;
		var polling = null;
		var current = 0;

		/* form */
		var opSel = RSS.select([], '', { class: 'rss-input', id: 'rss-op' });
		var fieldsBox = h('div', { class: 'rss-fieldgrid' });
		var targetBox = h('div', { class: 'rss-form-grid' });
		var optsBox = h('div', {});
		var preview = h('div', { class: 'rss-preview' });
		var startBtn = RSS.btn('Start job', { kind: 'primary', icon: 'play' });
		var previewBtn = RSS.btn('Preview items', { icon: 'eye' });
		var ids = h('textarea', { class: 'rss-input', rows: '2', placeholder: 'Optional: product IDs separated by commas' });
		ids.value = (B.initial.ids || '').replace(/[^0-9,]/g, '');
		var cat = RSS.select([{ value: '', label: 'All categories' }], '', { class: 'rss-input' });
		var brand = RSS.select([{ value: '', label: 'All brands' }], '', { class: 'rss-input' });
		var status = RSS.select([{ value: '', label: 'Any status' }, { value: 'publish', label: 'Published' }, { value: 'draft', label: 'Draft' }, { value: 'private', label: 'Private' }], '', { class: 'rss-input' });
		var stock = RSS.select([{ value: '', label: 'Any stock' }, { value: 'instock', label: 'In stock' }, { value: 'outofstock', label: 'Out of stock' }, { value: 'onbackorder', label: 'On backorder' }], '', { class: 'rss-input' });
		var issue = RSS.select([{ value: '', label: 'Any' }, { value: 'no_meta', label: 'Missing meta description' }, { value: 'no_seo_title', label: 'Missing SEO title' }, { value: 'thin', label: 'Thin description' }, { value: 'no_short', label: 'No short description' }, { value: 'image_no_alt', label: 'Image without alt text' }, { value: 'no_image', label: 'No image' }], params.get('issue') || '', { class: 'rss-input' });
		var search = h('input', { type: 'search', class: 'rss-input', placeholder: 'Name contains…' });
		var limit = h('input', { type: 'number', class: 'rss-input', min: '1', max: '5000', value: '200' });
		var missingAlt = RSS.toggle('Only images without alt text', true);
		var productImages = RSS.toggle('Only product images (featured + gallery)', true);
		var includeGallery = RSS.toggle('Include gallery images', false, null, 'Each product gets one reviewable change for its gallery.');
		var lang = RSS.select([{ value: 'en', label: 'English' }, { value: 'bn', label: 'বাংলা' }, { value: 'bilingual', label: 'Bangla + English' }], B.settings.language, { class: 'rss-input' });
		var batch = h('input', { type: 'number', class: 'rss-input', min: '1', max: '50', value: (B.settings.bulk && B.settings.bulk.batch_size) || 10 });
		var imgSize = RSS.select([{ value: 300, label: '300 px' }, { value: 600, label: '600 px' }, { value: 800, label: '800 px' }, { value: 1200, label: '1200 px' }], B.settings.image.size || 800, { class: 'rss-input' });
		var imgFormat = RSS.select([{ value: 'jpeg', label: 'JPEG' }, { value: 'webp', label: 'WebP' }, { value: 'png', label: 'PNG' }], B.settings.image.format || 'jpeg', { class: 'rss-input' });
		var imgBg = RSS.toggle('Remove plain backgrounds', !!Number(B.settings.image.remove_bg));

		var FIELD_LABELS = { title: 'Title', short_description: 'Short description', description: 'Long description', tags: 'Tags', categories: 'Categories', brand: 'Brand', seo_title: 'SEO title', seo_description: 'Meta description', seo_focus: 'Focus keyphrase' };
		var LOCAL_FIELDS = ['title', 'short_description', 'description', 'tags', 'categories', 'brand'];
		var AI_FIELDS = ['title', 'short_description', 'description', 'tags', 'categories', 'brand', 'seo_title', 'seo_description', 'seo_focus'];
		var fieldChecks = [];
		var renderForm = function () {
			var op = opSel.value; var def = ops[op] || {};
			clear(fieldsBox); fieldChecks = [];
			var list = op === 'content_local' ? LOCAL_FIELDS : op === 'content_ai' ? AI_FIELDS : [];
			if (!B.brand) { list = list.filter(function (f) { return f !== 'brand'; }); }
			list.forEach(function (f) {
				var id = RSS.uid('bf');
				var cb = h('input', { type: 'checkbox', id: id, value: f });
				cb.checked = ['short_description', 'seo_description', 'seo_title'].indexOf(f) !== -1;
				fieldChecks.push(cb);
				fieldsBox.appendChild(h('label', { class: 'rss-check', for: id }, cb, h('span', { text: FIELD_LABELS[f] })));
			});
			fieldsBox.hidden = !list.length;
			clear(targetBox);
			if (def.target === 'product' || (def.target === 'attachment' && productImages.input.checked)) {
				targetBox.appendChild(RSS.field('Category', cat));
				if (B.brand) { targetBox.appendChild(RSS.field('Brand', brand)); }
				targetBox.appendChild(RSS.field('Status', status));
				targetBox.appendChild(RSS.field('Stock', stock));
				targetBox.appendChild(RSS.field('Has issue', issue, 'From the last catalog scan.'));
				targetBox.appendChild(RSS.field('Search', search));
				targetBox.appendChild(RSS.field('Specific IDs', ids));
			} else if (def.target === 'post') {
				targetBox.appendChild(RSS.field('Status', status));
				targetBox.appendChild(RSS.field('Search', search));
			}
			targetBox.appendChild(RSS.field('Maximum items', limit, 'Up to 5,000 per job.'));
			clear(optsBox);
			if (def.target === 'attachment') { optsBox.appendChild(h('div', { class: 'rss-toggles' }, missingAlt, productImages)); }
			if (op === 'image_standardize') {
				optsBox.appendChild(h('div', { class: 'rss-form-grid' }, RSS.field('Size', imgSize), RSS.field('Format', imgFormat)));
				optsBox.appendChild(h('div', { class: 'rss-toggles' }, includeGallery, imgBg));
				optsBox.appendChild(h('p', { class: 'rss-help', text: 'Other image settings (canvas, padding, quality, trim) come from Image Studio defaults.' }));
			}
			if (op === 'content_ai' || op === 'alt_ai' || op === 'content_local') {
				optsBox.appendChild(h('div', { class: 'rss-form-grid' }, RSS.field('Language', lang), RSS.field('Batch size', batch, 'Items per background step (1–50).')));
			} else {
				optsBox.appendChild(h('div', { class: 'rss-form-grid' }, RSS.field('Batch size', batch, 'Items per background step (1–50).')));
			}
			if (def.ai) {
				var reason = ai && !ai.enabled ? 'AI API is OFF — enable it in AI Providers first.' : '';
				optsBox.appendChild(h('div', { class: 'rss-callout is-' + (reason ? 'warn' : 'info') }, icon(reason ? 'alert' : 'spark'), h('p', { text: reason || 'AI jobs send one request per item and count against your daily limit and monthly budget. Preview first to see the estimate.' })));
			}
			clear(preview);
		};
		opSel.addEventListener('change', renderForm);
		productImages.input.addEventListener('change', renderForm);
		var filters = function () {
			return { category: cat.value, brand: brand.value, status: status.value, stock_status: stock.value, issue: issue.value, search: search.value, ids: ids.value, limit: limit.value, missing_alt: missingAlt.input.checked ? 1 : 0, product_images: productImages.input.checked ? 1 : 0 };
		};
		var options = function () {
			return {
				fields: fieldChecks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; }),
				language: lang.value, batch_size: Number(batch.value), include_gallery: includeGallery.input.checked ? 1 : 0,
				image: { size: Number(imgSize.value), format: imgFormat.value, remove_bg: imgBg.input.checked ? 1 : 0 }
			};
		};
		var lastPreview = null;
		previewBtn.addEventListener('click', function () {
			RSS.busy(previewBtn, true, 'Counting…');
			RSS.api('/jobs/preview', { method: 'POST', body: { operation: opSel.value, filters: filters(), options: options() } }).then(function (res) {
				lastPreview = res;
				clear(preview);
				preview.appendChild(h('p', {}, h('strong', { text: RSS.num(res.count) + ' item(s) match. ' }), res.sample.length ? h('span', { class: 'rss-muted', text: 'e.g. ' + res.sample.map(function (s) { return s.title; }).join(', ') }) : null));
				if (res.estimate) {
					preview.appendChild(h('div', { class: 'rss-callout is-' + (res.reason ? 'warn' : 'info') }, icon('spark'), h('div', {},
						h('p', { text: res.reason || ('Estimated cost: up to ' + RSS.money(res.estimate_total) + ' for ' + res.count + ' requests (' + res.estimate.provider + ' · ' + res.estimate.model + ').') }),
						h('p', { class: 'rss-muted', text: 'Remaining today: ' + res.daily_left + ' requests · budget left this month: ' + RSS.money(res.budget_left) + '. The job pauses automatically when a limit is reached.' }))));
				}
			}).catch(RSS.fail).then(function () { RSS.busy(previewBtn, false); });
		});
		startBtn.addEventListener('click', function () {
			var def = ops[opSel.value] || {};
			var go = function (confirmAi) {
				RSS.busy(startBtn, true, 'Creating…');
				RSS.api('/jobs', { method: 'POST', body: { operation: opSel.value, filters: filters(), options: Object.assign(options(), { confirm_ai: confirmAi ? 1 : 0 }) } }).then(function (job) {
					RSS.toast('Job #' + job.id + ' started with ' + job.total + ' items.', 'success');
					current = job.id;
					loadJobs(); showJob(job.id); startPolling();
					jobs.scrollIntoView({ behavior: 'smooth' });
				}).catch(RSS.fail).then(function () { RSS.busy(startBtn, false); });
			};
			if (def.ai) {
				var est = lastPreview && lastPreview.estimate ? 'Estimated: up to ' + RSS.money(lastPreview.estimate_total) + '. ' : 'Use "Preview items" to see the estimate. ';
				RSS.confirm({ title: 'Start an AI bulk job?', text: est + 'Each item sends one API request to your provider.', require: 'I understand this job uses paid or quota-limited API requests.', confirm: 'Start job' }).then(function (yes) { if (yes) { go(true); } });
			} else { go(false); }
		});
		create.body.appendChild(RSS.field('Operation', opSel));
		create.body.appendChild(fieldsBox);
		create.body.appendChild(h('h4', { class: 'rss-subhead', text: 'Which items?' }));
		create.body.appendChild(targetBox);
		create.body.appendChild(optsBox);
		create.body.appendChild(h('div', { class: 'rss-row' }, previewBtn, startBtn));
		create.body.appendChild(preview);

		/* jobs table */
		var table = h('div', { class: 'rss-table-wrap' });
		jobs.body.appendChild(table);
		var renderJobs = function (list) {
			clear(table);
			if (!list.length) { table.appendChild(RSS.empty('layers', 'No jobs yet', 'Create your first job above.')); return; }
			var t = h('table', { class: 'rss-table rss-jobs' }, h('thead', {}, h('tr', {}, ['Job', 'Status', 'Progress', 'Result', 'Started', ''].map(function (x) { return h('th', { scope: 'col', text: x }); }))));
			var tb = h('tbody', {});
			var stat = function (n, label, cls) { return h('span', { class: 'rss-stat' + (cls ? ' ' + cls : ''), text: n + ' ' + label }); };
			list.forEach(function (j) {
				var ctl = h('div', { class: 'rss-row is-tight' });
				var act = function (a, ic, title) {
					return RSS.btn('', { icon: ic, small: true, title: title, onClick: function (e) {
						var b = e.currentTarget; RSS.busy(b, true);
						RSS.api('/jobs/' + j.id + '/' + a, { method: 'POST' }).then(function () { RSS.toast('Job #' + j.id + ': ' + title.toLowerCase() + '.', 'success'); loadJobs(); if (a === 'resume' || a === 'retry') { startPolling(); } }).catch(RSS.fail).then(function () { RSS.busy(b, false); });
					} });
				};
				if (j.status === 'running') { ctl.appendChild(act('pause', 'pause', 'Pause')); }
				if (j.status === 'paused') { ctl.appendChild(act('resume', 'play', 'Resume')); }
				if (j.status === 'running' || j.status === 'paused') { ctl.appendChild(act('cancel', 'stop', 'Cancel')); }
				if (j.failed && j.status !== 'running') { ctl.appendChild(act('retry', 'refresh', 'Retry failed')); }
				ctl.appendChild(RSS.btn('Open', { small: true, icon: 'chevron', onClick: function () { showJob(j.id); detail.scrollIntoView({ behavior: 'smooth' }); } }));
				var row = h('tr', { class: j.id === current ? 'is-current' : '' },
					h('td', {}, h('strong', { text: '#' + j.id + ' ' + j.label }), h('small', { class: 'rss-muted', text: (j.ai ? 'AI · ' : 'Free · ') + 'by ' + j.created_by })),
					h('td', {}, RSS.badge(j.status, JOB_STATUS[j.status] || 'neutral'), j.last_error ? h('small', { class: 'rss-error-text', text: j.last_error }) : null),
					h('td', {}, RSS.progress(j.progress, j.status === 'paused' ? 'warn' : ''), h('small', { text: j.processed + ' / ' + j.total })),
					h('td', {}, h('small', {}, stat(j.succeeded, 'done'), stat(j.skipped, 'skipped'), stat(j.failed, 'failed', j.failed ? 'is-bad' : '')), h('small', { class: 'rss-muted' }, stat(j.drafts.pending || 0, 'waiting'), stat(j.drafts.applied || 0, 'applied'))),
					h('td', {}, h('small', { text: RSS.ago(j.created_at) })),
					h('td', {}, ctl));
				tb.appendChild(row);
			});
			t.appendChild(tb);
			table.appendChild(t);
		};
		var loadJobs = function () { return RSS.api('/jobs').then(function (res) { renderJobs(res.jobs); return res; }).catch(RSS.fail); };
		var startPolling = function () {
			if (polling) { return; }
			var tick = function () {
				RSS.api('/jobs/tick', { method: 'POST', body: {} }).then(function (res) {
					renderJobs(res.jobs);
					if (current) { showJob(current, true); }
					if (!res.running) { clearInterval(polling); polling = null; }
				}).catch(function () {});
			};
			polling = setInterval(tick, 4000);
			tick();
		};

		/* detail */
		var showJob = function (id, quiet) {
			// Background refreshes only update the progress header and item table, never the buttons the user may be clicking.
			if (quiet && (detail.jobId !== id || !detail.headEl)) { quiet = false; }
			current = id;
			if (!quiet) { clear(detail.body).appendChild(RSS.skeleton(4)); }
			RSS.api('/jobs/' + id).then(function (j) {
				var head = h('div', { class: 'rss-jobhead' },
					h('div', {}, h('h4', { text: '#' + j.id + ' ' + j.label }), h('p', { class: 'rss-muted', text: j.total + ' items · ' + j.succeeded + ' done · ' + j.skipped + ' skipped · ' + j.failed + ' failed · ' + (j.drafts.pending || 0) + ' suggestions waiting · ' + (j.drafts.applied || 0) + ' applied' })),
					RSS.progress(j.progress));
				var items = h('div', { class: 'rss-table-wrap' });
				var it = h('table', { class: 'rss-table is-compact' }, h('thead', {}, h('tr', {}, ['Item', 'Status', 'Attempts', 'Message'].map(function (x) { return h('th', { scope: 'col', text: x }); }))));
				var itb = h('tbody', {});
				j.items.items.forEach(function (row) {
					itb.appendChild(h('tr', {}, h('td', {}, h('strong', { text: row.title || '#' + row.object_id }), h('small', { class: 'rss-muted', text: row.object_type + ' #' + row.object_id })), h('td', {}, RSS.badge(row.status, row.status === 'done' ? 'success' : row.status === 'failed' ? 'danger' : row.status === 'pending' ? 'info' : 'neutral')), h('td', { text: String(row.attempts) }), h('td', {}, h('small', { text: row.message }))));
				});
				it.appendChild(itb); items.appendChild(it);
				var itemsBox = h('details', { class: 'rss-details', open: j.items.total <= 20 || null }, h('summary', { text: 'Items (' + j.items.total + (j.items.total > 50 ? ', first 50 shown — full list in the CSV report' : '') + ')' }), items);
				if (quiet) {
					if (detail.itemsEl && detail.itemsEl.open) { itemsBox.open = true; }
					detail.headEl.replaceWith(head); detail.headEl = head;
					detail.itemsEl.replaceWith(itemsBox); detail.itemsEl = itemsBox;
					if (j.status !== 'running' && detail.lastStatus === 'running' && detail.list) { detail.list.load(); }
					detail.lastStatus = j.status;
					return;
				}
				clear(detail.body);
				detail.jobId = id; detail.lastStatus = j.status;
				detail.headEl = head; detail.itemsEl = itemsBox;
				detail.body.appendChild(head);
				var bulk = function (action, label, kind, confirmText) {
					return RSS.btn(label, { kind: kind, icon: action === 'apply_clean' ? 'check' : action === 'reject_pending' ? 'x' : 'undo', small: true, onClick: function (e) {
						var b = e.currentTarget;
						RSS.confirm({ title: label + '?', text: confirmText, confirm: label, danger: action !== 'apply_clean' }).then(function (yes) {
							if (!yes) { return; }
							RSS.busy(b, true, 'Working…');
							var total = 0, failed = 0, errs = [];
							var loop = function () {
								return RSS.api('/jobs/' + id + '/drafts', { method: 'POST', body: { action: action } }).then(function (r) {
									total += r.done; failed += r.failed; errs = errs.concat(r.errors);
									if (r.remaining > 0 && r.done > 0) { return loop(); }
								});
							};
							loop().then(function () {
								RSS.toast(total + ' done' + (failed ? ', ' + failed + ' need attention' : '') + '.', failed ? 'warn' : 'success', 8000);
								errs.slice(0, 3).forEach(function (er) { RSS.toast(er, 'error'); });
								showJob(id); loadJobs();
							}).catch(RSS.fail).then(function () { RSS.busy(b, false); });
						});
					} });
				};
				detail.body.appendChild(h('div', { class: 'rss-row' },
					bulk('apply_clean', 'Apply all clean suggestions', 'primary', 'Applies every pending suggestion of this job that has no flagged facts. Flagged ones stay for manual review. Each change can be rolled back.'),
					bulk('reject_pending', 'Reject all pending', 'ghost', 'Discards all pending suggestions of this job (unused new images are deleted).'),
					bulk('rollback_applied', 'Roll back this job', 'ghost', 'Restores the previous values for every change this job applied (skips fields edited since).'),
					h('a', { class: 'rss-btn is-ghost is-sm', href: B.adminPost + '?action=rar_studio_job_report&job=' + id + '&_wpnonce=' + B.exportNonce }, icon('download'), h('span', { text: 'CSV report' }))));
				detail.body.appendChild(itemsBox);
				detail.list = RSS.draftList({ query: function () { return { job_id: id, status: 'pending', per_page: 30 }; }, showObject: true, emptyTitle: 'No suggestions waiting in this job', onChange: function () { loadJobs(); } });
				detail.body.appendChild(h('h4', { class: 'rss-subhead', text: 'Suggestions waiting for review (first 30)' }));
				detail.body.appendChild(detail.list);
				detail.list.load();
			}).catch(function (e) { clear(detail.body).appendChild(RSS.empty('alert', 'Could not load job', e.message)); });
		};

		Promise.all([RSS.api('/jobs'), B.wc ? RSS.api('/taxonomies') : Promise.resolve(tax), RSS.api('/ai')]).then(function (res) {
			ops = res[0].operations; tax = res[1]; ai = res[2];
			clear(opSel);
			Object.keys(ops).forEach(function (k) {
				if (!B.wc && ops[k].target === 'product') { return; }
				opSel.appendChild(h('option', { value: k, text: ops[k].label + (ops[k].ai && !ai.enabled ? ' — AI is OFF' : '') }));
			});
			var pre = params.get('op');
			if (pre && ops[pre]) { opSel.value = pre; }
			tax.categories.forEach(function (c) { cat.appendChild(h('option', { value: c.id, text: c.name + ' (' + c.count + ')' })); });
			(tax.brands || []).forEach(function (c) { brand.appendChild(h('option', { value: c.id, text: c.name + ' (' + c.count + ')' })); });
			renderForm();
			renderJobs(res[0].jobs);
			if (res[0].jobs.some(function (j) { return j.status === 'running'; })) { startPolling(); }
			if (res[0].jobs.length) { showJob(res[0].jobs[0].id); }
			else { detail.body.appendChild(RSS.empty('layers', 'Open a job to review its results', '')); }
		}).catch(RSS.fail);
	};

	/* ========================================================== ai providers */

	RSS.pages.ai = function (main) {
		var master = RSS.section('master', 'AI API switch', 'OFF by default. Nothing is ever sent to an AI provider while it is OFF.');
		var explain = RSS.section('billing', 'Subscription vs API — what costs money?', '');
		var providers = RSS.section('providers', 'Providers', 'Add a key, choose a model and test the connection (the test is free — it lists models, no tokens used).');
		var limits = RSS.section('limits', 'Limits & budget', 'Hard stops that protect you from unexpected bills.');
		var usage = RSS.section('usage', 'Usage history', 'Token counts come from the providers; costs are estimates from your prices.');
		[master, explain, providers, limits, usage].forEach(function (s) { main.appendChild(s); });

		explain.body.appendChild(h('div', { class: 'rss-compare-grid' },
			h('div', { class: 'rss-plan' }, h('span', { class: 'rss-plan-ico' }, icon('leaf')), h('h4', { text: 'Free local engine' }), h('p', { text: 'Formatting, SEO metadata, tags, alt text and image processing on your own server. ৳0.' })),
			h('div', { class: 'rss-plan' }, h('span', { class: 'rss-plan-ico' }, icon('copy')), h('h4', { text: 'Your chat subscription' }), h('p', { text: 'ChatGPT Plus/Pro, Gemini or Claude Pro/Max work in their own apps. Use the “Subscription” button: copy prompt → paste answer. No API key, no extra bill.' })),
			h('div', { class: 'rss-plan' }, h('span', { class: 'rss-plan-ico' }, icon('spark')), h('h4', { text: 'Developer API' }), h('p', { text: 'Automated requests need an API key from platform.openai.com, aistudio.google.com or console.anthropic.com. API usage is billed separately from subscriptions; some Gemini models have a free tier with limits.' }))));

		var status = null;
		var render = function () {
			clear(master.body); clear(providers.body); clear(limits.body);
			var on = !!status.enabled;
			var pill = $('#rss-ai-pill');
			if (pill) { pill.className = 'rss-pill ' + (on ? 'is-on' : 'is-off'); pill.lastChild.textContent = on ? 'AI API ON' : 'AI API OFF · free mode'; }
			var mcard = h('div', { class: 'rss-master is-' + (on ? 'on' : 'off') },
				h('div', { class: 'rss-master-state' }, h('span', { class: 'rss-master-dot' }), h('div', {}, h('strong', { text: on ? 'AI API is ON' : 'AI API is OFF' }), h('small', { text: on ? 'Enabled ' + (status.acknowledged_at ? new Date(status.acknowledged_at * 1000).toLocaleString() : '') + ' with cost acknowledgement.' : 'Free local tools and the subscription prompt still work.' }))));
			if (B.admin) {
				var b = RSS.btn(on ? 'Turn OFF' : 'Turn ON…', { kind: on ? 'ghost' : 'primary', icon: on ? 'stop' : 'play' });
				b.addEventListener('click', function () {
					var go = function () {
						RSS.busy(b, true);
						RSS.api('/ai/master', { method: 'POST', body: { enable: !on, acknowledge: !on } }).then(function (s) { status = Object.assign(status, s); render(); RSS.toast(on ? 'AI API turned OFF.' : 'AI API turned ON.', 'success'); }).catch(RSS.fail).then(function () { RSS.busy(b, false); });
					};
					if (on) { go(); return; }
					RSS.confirm({ title: 'Turn on the AI API?', text: 'Requests are only sent when someone clicks an AI action or starts an AI bulk job. Limits: ' + status.limits.daily_limit + ' requests/day, ' + RSS.money(status.limits.monthly_budget) + '/month (estimated).', require: 'I understand API requests may be billed by the AI provider, separately from any chat subscription.', confirm: 'Turn ON' }).then(function (yes) { if (yes) { go(); } });
				});
				mcard.appendChild(b);
			}
			master.body.appendChild(mcard);
			if (!status.encryption) { master.body.appendChild(h('div', { class: 'rss-callout is-warn' }, icon('alert'), h('p', { text: 'Encryption (libsodium) is unavailable: put API keys in wp-config.php constants.' }))); }

			var grid = h('div', { class: 'rss-providers' });
			Object.keys(status.providers).forEach(function (pid) {
				var p = status.providers[pid];
				var modelInput = h('input', { type: 'text', class: 'rss-input', value: p.model, list: 'rss-models-' + pid, 'aria-label': p.label + ' model' });
				var dl = h('datalist', { id: 'rss-models-' + pid });
				var imgModel = h('input', { type: 'text', class: 'rss-input', value: p.image_model, placeholder: 'Image model (optional)', 'aria-label': p.label + ' image model' });
				var pin = h('input', { type: 'number', class: 'rss-input', min: '0', step: '0.01', value: p.price_in, 'aria-label': 'Input price per million tokens' });
				var pout = h('input', { type: 'number', class: 'rss-input', min: '0', step: '0.01', value: p.price_out, 'aria-label': 'Output price per million tokens' });
				var enabled = RSS.toggle('Use ' + p.label, p.enabled);
				var free = RSS.toggle('My project uses the free tier', p.free_tier, null, 'Costs are recorded as $0. Free tiers have rate limits.');
				var keyInfo = p.key_source === 'constant' ? 'From wp-config.php (' + p.key_constant + ') ' + p.key_mask
					: p.key_source === 'database' ? 'Stored encrypted ' + p.key_mask
						: p.key_source === 'unreadable' ? 'Stored key cannot be decrypted (site salts changed) — enter it again'
							: 'No key yet';
				var keyField = h('input', { type: 'password', class: 'rss-input', autocomplete: 'off', placeholder: p.key_source === 'constant' ? 'Managed in wp-config.php' : 'Paste API key', disabled: p.key_source === 'constant' || null, 'aria-label': p.label + ' API key' });
				var card = h('div', { class: 'rss-provider' + (p.ready ? ' is-ready' : '') },
					h('header', {}, h('strong', { text: p.label }), RSS.badge(p.ready ? 'Ready' : p.enabled ? 'Needs key' : 'Off', p.ready ? 'success' : p.enabled ? 'warn' : 'neutral'), pid === status.primary ? RSS.badge('Primary', 'info') : null),
					B.admin ? enabled : null,
					h('div', { class: 'rss-keyrow' }, icon('key'), h('span', { class: 'rss-muted', text: keyInfo })));
				if (B.admin) {
					var saveKey = RSS.btn('Save key', { small: true, kind: 'primary' });
					var delKey = p.key_source === 'database' ? RSS.btn('Remove', { small: true, icon: 'trash' }) : null;
					saveKey.addEventListener('click', function () {
						if (!keyField.value.trim()) { RSS.toast('Paste a key first.', 'warn'); return; }
						RSS.busy(saveKey, true);
						RSS.api('/ai/key', { method: 'POST', body: { provider: pid, key: keyField.value.trim() } }).then(function (s) { keyField.value = ''; status = Object.assign(status, s); render(); RSS.toast('Key saved (encrypted).', 'success'); }).catch(RSS.fail).then(function () { RSS.busy(saveKey, false); });
					});
					if (delKey) {
						delKey.addEventListener('click', function () {
							RSS.confirm({ title: 'Remove the stored key?', confirm: 'Remove', danger: true }).then(function (yes) {
								if (!yes) { return; }
								RSS.api('/ai/key', { method: 'POST', body: { provider: pid, key: '' } }).then(function (s) { status = Object.assign(status, s); render(); RSS.toast('Key removed.', 'success'); }).catch(RSS.fail);
							});
						});
					}
					if (p.key_source !== 'constant') { card.appendChild(h('div', { class: 'rss-row is-tight' }, keyField, saveKey, delKey)); }
					var test = RSS.btn('Test & load models', { small: true, icon: 'refresh', disabled: p.key_source === 'none' || null });
					test.addEventListener('click', function () {
						RSS.busy(test, true, 'Testing…');
						RSS.api('/ai/models', { method: 'POST', body: { provider: pid } }).then(function (r) {
							clear(dl); r.models.forEach(function (m) { dl.appendChild(h('option', { value: m })); });
							RSS.toast(p.label + ': connected in ' + r.ms + ' ms · ' + r.models.length + ' models available. Pick one in the Model box.', 'success', 7000);
						}).catch(RSS.fail).then(function () { RSS.busy(test, false); });
					});
					card.appendChild(RSS.field('Text model', h('div', { class: 'rss-row is-tight' }, modelInput, dl, test), pid === 'gemini' ? 'Flash-Lite models are the cheapest; some have a free tier.' : pid === 'openai' ? 'Mini / nano models are the cheapest.' : 'Haiku models are the cheapest.'));
					if (pid !== 'claude') { card.appendChild(RSS.field('Image model (background scenes)', imgModel)); }
					card.appendChild(h('div', { class: 'rss-form-grid is-2' }, RSS.field('Input $ / 1M tokens', pin), RSS.field('Output $ / 1M tokens', pout)));
					card.appendChild(h('small', { class: 'rss-help', text: 'Reference prices checked October 2026 — confirm on the provider’s pricing page.' }));
					card.appendChild(free);
					card.collect = function () { return { enabled: enabled.input.checked ? 1 : 0, model: modelInput.value, image_model: imgModel.value, price_in: Number(pin.value), price_out: Number(pout.value), free_tier: free.input.checked ? 1 : 0 }; };
				} else {
					card.appendChild(h('p', { class: 'rss-muted', text: 'Model: ' + p.model }));
				}
				grid.appendChild(card);
				card.dataset.pid = pid;
			});
			providers.body.appendChild(grid);

			if (B.admin) {
				var L = status.limits;
				var primary = RSS.select(Object.keys(status.providers).map(function (k) { return { value: k, label: status.providers[k].label }; }), status.primary, { class: 'rss-input' });
				var daily = h('input', { type: 'number', class: 'rss-input', min: '1', max: '500', value: L.daily_limit });
				var perMin = h('input', { type: 'number', class: 'rss-input', min: '1', max: '60', value: L.per_minute });
				var budget = h('input', { type: 'number', class: 'rss-input', min: '0', step: '0.5', value: L.monthly_budget });
				var maxTok = h('input', { type: 'number', class: 'rss-input', min: '200', max: '8000', value: L.max_output_tokens });
				var imgPrice = h('input', { type: 'number', class: 'rss-input', min: '0', step: '0.01', value: L.image_price });
				var fallback = RSS.toggle('Fallback to the next ready provider', L.fallback, null, 'Only for temporary errors (rate limit, server error). Off = one provider per request.');
				var imgFeat = RSS.toggle('Image features (AI background scenes)', status.image_features, null, 'Off by default. Product pixels are never sent for editing — only a text scene description.');
				var save = RSS.btn('Save provider settings', { kind: 'primary', icon: 'check' });
				save.addEventListener('click', function () {
					var prov = {};
					$$('.rss-provider', providers.body).forEach(function (c) { if (c.collect) { prov[c.dataset.pid] = c.collect(); } });
					RSS.busy(save, true, 'Saving…');
					RSS.api('/settings', { method: 'POST', body: { ai: { primary: primary.value, daily_limit: Number(daily.value), per_minute: Number(perMin.value), monthly_budget: Number(budget.value), max_output_tokens: Number(maxTok.value), image_price: Number(imgPrice.value), fallback: fallback.input.checked ? 1 : 0, image_features: imgFeat.input.checked ? 1 : 0, providers: prov } } })
						.then(function () { RSS.toast('Saved.', 'success'); return load(); }).catch(RSS.fail).then(function () { RSS.busy(save, false); });
				});
				limits.body.appendChild(h('div', { class: 'rss-form-grid' },
					RSS.field('Primary provider', primary),
					RSS.field('Requests per day', daily, 'Counts every attempt, including failures.'),
					RSS.field('Requests per minute', perMin),
					RSS.field('Monthly budget (USD, estimated)', budget, '0 = block all paid requests.'),
					RSS.field('Max output tokens per request', maxTok),
					RSS.field('Estimated $ per AI image', imgPrice)));
				limits.body.appendChild(h('div', { class: 'rss-toggles' }, fallback, imgFeat));
				limits.body.appendChild(h('div', { class: 'rss-row rss-sticky-save' }, save, h('small', { class: 'rss-muted', text: 'Saves the provider cards above and these limits.' })));
			} else {
				limits.body.appendChild(h('p', { class: 'rss-muted', text: 'Only administrators can change providers and limits.' }));
			}

			var u = status.usage;
			clear(usage.body);
			usage.body.appendChild(h('div', { class: 'rss-kpis' },
				RSS.kpi('Today', u.today_requests + ' / ' + u.daily_limit, 'requests', 'clock'),
				RSS.kpi('This month', RSS.money(u.month_cost), 'of ' + RSS.money(u.monthly_budget) + ' budget', 'gauge', u.month_cost > u.monthly_budget * 0.8 ? 'warn' : ''),
				RSS.kpi('Providers used', String(Object.keys(u.providers).length), 'this month', 'spark')));
			var maxReq = Math.max.apply(null, u.days.map(function (d) { return d.requests; }).concat([1]));
			usage.body.appendChild(h('div', { class: 'rss-bars', role: 'img', 'aria-label': 'Requests per day for the last 14 days' }, u.days.map(function (d) {
				return h('div', { class: 'rss-bar', title: d.date + ': ' + d.requests + ' requests, ' + RSS.money(d.cost) }, h('span', { style: { height: Math.max(2, 100 * d.requests / maxReq) + '%' } }), h('small', { text: d.date.slice(8) }));
			})));
			if (Object.keys(u.providers).length) {
				usage.body.appendChild(h('div', { class: 'rss-table-wrap' }, h('table', { class: 'rss-table' },
					h('thead', {}, h('tr', {}, ['Provider', 'Requests', 'OK', 'Errors', 'Blocked', 'Tokens in / out', 'Est. cost'].map(function (x) { return h('th', { scope: 'col', text: x }); }))),
					h('tbody', {}, Object.keys(u.providers).map(function (k) { var p = u.providers[k]; return h('tr', {}, h('td', { text: k }), h('td', { text: p.requests }), h('td', { text: p.ok }), h('td', { text: p.errors }), h('td', { text: p.blocked }), h('td', { text: RSS.num(p.input_tokens) + ' / ' + RSS.num(p.output_tokens) }), h('td', { text: RSS.money(p.cost) })); })))));
			}
			if (u.recent && u.recent.length) {
				usage.body.appendChild(h('details', { class: 'rss-details' }, h('summary', { text: 'Last ' + u.recent.length + ' requests' }), h('div', { class: 'rss-table-wrap' }, h('table', { class: 'rss-table is-compact' },
					h('thead', {}, h('tr', {}, ['Time', 'Provider · model', 'Task', 'Status', 'Tokens', 'Cost', 'ms', 'Message'].map(function (x) { return h('th', { scope: 'col', text: x }); }))),
					h('tbody', {}, u.recent.map(function (r) { return h('tr', {}, h('td', { text: RSS.when(r.created_at) }), h('td', { text: r.provider + ' · ' + r.model }), h('td', { text: r.task }), h('td', {}, RSS.badge(r.status, r.status === 'ok' ? 'success' : r.status === 'blocked' ? 'warn' : r.status === 'error' ? 'danger' : 'neutral')), h('td', { text: r.input_tokens + ' / ' + r.output_tokens }), h('td', { text: RSS.money(r.cost) }), h('td', { text: r.duration_ms }), h('td', {}, h('small', { text: r.message }))); }))))));
			} else if (!Object.keys(u.providers).length) {
				usage.body.appendChild(RSS.empty('spark', 'No API requests yet', 'Usage appears here once AI features are used.'));
			}
		};
		var load = function () { return RSS.api('/ai').then(function (s) { status = s; render(); }).catch(function (e) { clear(master.body).appendChild(RSS.empty('alert', 'Could not load AI settings', e.message)); }); };
		load();
	};

	/* ============================================================= settings */

	RSS.pages.settings = function (main) {
		var general = RSS.section('general', 'General', '');
		var brand = RSS.section('brand', 'Brand voice', 'Used in every AI and subscription prompt.');
		var seo = RSS.section('seo', 'SEO plugin & limits', '');
		var safety = RSS.section('safety', 'Safety', '');
		var images = RSS.section('images', 'Image defaults', 'Used by Image Studio and image bulk jobs.');
		var bulk = RSS.section('bulk', 'Bulk processing', 'Keep these low on shared hosting.');
		var tpl = RSS.section('templates', 'Prompt templates', 'Advanced. Placeholders in {braces} are filled in automatically.');
		[general, brand, seo, safety, images, bulk, tpl].forEach(function (s) { main.appendChild(s); });
		var bar = h('div', { class: 'rss-savebar' });
		main.appendChild(bar);
		RSS.api('/settings').then(function (res) {
			var s = res.settings;
			var ro = !res.can_admin;
			var c = {};
			var input = function (path, type, attrs) {
				var el = h(type === 'textarea' ? 'textarea' : 'input', Object.assign({ class: 'rss-input', type: type === 'textarea' ? null : type, disabled: ro || null }, attrs || {}));
				var parts = path.split('.'); var v = s; parts.forEach(function (p) { v = v ? v[p] : ''; });
				el.value = v === undefined || v === null ? '' : v;
				c[path] = el;
				return el;
			};
			var toggle = function (path, label, help) {
				var parts = path.split('.'); var v = s; parts.forEach(function (p) { v = v ? v[p] : 0; });
				var t = RSS.toggle(label, !!Number(v), null, help);
				if (ro) { t.input.disabled = true; }
				c[path] = { get value() { return t.input.checked ? 1 : 0; } };
				return t;
			};
			var sel = function (path, options) {
				var parts = path.split('.'); var v = s; parts.forEach(function (p) { v = v ? v[p] : ''; });
				var el = RSS.select(options, v, { class: 'rss-input', disabled: ro || null });
				c[path] = el;
				return el;
			};
			if (ro) { general.body.appendChild(h('div', { class: 'rss-callout is-info' }, icon('info'), h('p', { text: 'Read-only: only administrators can change settings.' }))); }
			general.body.appendChild(h('div', { class: 'rss-form-grid' },
				RSS.field('Default writing language', sel('general.language', [{ value: 'en', label: 'English' }, { value: 'bn', label: 'বাংলা (Bangla)' }, { value: 'bilingual', label: 'Bangla + English' }])),
				RSS.field('Who may run AI API requests', sel('general.ai_roles', [{ value: 'administrator', label: 'Administrators only' }, { value: 'shop_manager', label: 'Administrators and shop managers' }])),
				RSS.field('Keep activity logs (days)', input('general.log_retention_days', 'number', { min: 7, max: 365 }))));
			general.body.appendChild(h('div', { class: 'rss-toggles' }, toggle('general.delete_on_uninstall', 'Delete all plugin data when the plugin is deleted', 'Off = suggestions, history, logs and settings are kept if you reinstall.')));
			brand.body.appendChild(h('div', { class: 'rss-form-grid' }, RSS.field('Brand / shop name', input('brand.name', 'text')), RSS.field('Audience', input('brand.audience', 'text'))));
			brand.body.appendChild(RSS.field('Voice and style', input('brand.voice', 'textarea', { rows: 2 })));
			brand.body.appendChild(RSS.field('Extra instructions for AI', input('brand.instructions', 'textarea', { rows: 3, placeholder: 'e.g. Use Bangladeshi English spelling. Mention "Cash on Delivery available" only if the product text says so.' })));
			brand.body.appendChild(RSS.field('Banned words or claims (comma or new line)', input('brand.banned', 'textarea', { rows: 2, placeholder: 'e.g. cure, guaranteed, 100% original' }), 'Any suggestion containing these is flagged and cannot be applied without confirmation.'));
			var adapters = [{ value: 'auto', label: 'Detect automatically' + (res.seo.active ? ' (' + res.seo.label + ')' : '') }];
			Object.keys(res.adapters).forEach(function (k) { adapters.push({ value: k, label: res.adapters[k].label + (res.adapters[k].detected ? ' — active' : ' — not active') }); });
			adapters.push({ value: 'none', label: 'None (disable SEO writing)' });
			seo.body.appendChild(h('div', { class: 'rss-form-grid' },
				RSS.field('SEO plugin', sel('seo.adapter', adapters), res.seo.warning || 'Yoast SEO, Rank Math and SEOPress are supported. Developers can add more with a filter.'),
				RSS.field('SEO title pattern', input('seo.title_pattern', 'text'), 'Placeholders: {name} {site} {brand} {category}'),
				RSS.field('SEO title max characters', input('seo.title_max', 'number', { min: 30, max: 80 })),
				RSS.field('Meta description max characters', input('seo.meta_max', 'number', { min: 70, max: 320 }))));
			safety.body.appendChild(h('div', { class: 'rss-toggles' }, toggle('guard.strict', 'Strict fact guard', 'Numbers, certifications, health claims, prices and guarantees that are not in your product data must be confirmed before applying. Recommended.')));
			safety.body.appendChild(h('ul', { class: 'rss-bullets' },
				h('li', { text: 'Prices, stock, SKU, variations, shipping, orders and payments are never written by this plugin.' }),
				h('li', { text: 'Every change is stored with its previous value and can be rolled back.' }),
				h('li', { text: 'API keys are encrypted or read from wp-config.php and never shown or logged.' }),
				h('li', { text: 'Emergency stop: add define( \'RAR_STUDIO_DISABLE\', true ); to wp-config.php.' })));
			images.body.appendChild(h('div', { class: 'rss-form-grid' },
				RSS.field('Size (px)', input('image.size', 'number', { min: 100, max: 2400 })),
				RSS.field('Format', sel('image.format', [{ value: 'jpeg', label: 'JPEG' }, { value: 'webp', label: 'WebP' }, { value: 'png', label: 'PNG' }])),
				RSS.field('Quality (40–100)', input('image.quality', 'number', { min: 40, max: 100 })),
				RSS.field('Padding (%)', input('image.padding', 'number', { min: 0, max: 30 })),
				RSS.field('Canvas', sel('image.canvas', [{ value: 'white', label: 'White' }, { value: 'transparent', label: 'Transparent' }, { value: 'color', label: 'Colour' }, { value: 'original', label: 'Extend original background' }])),
				RSS.field('Canvas colour', input('image.background', 'color')),
				RSS.field('Background tolerance', input('image.tolerance', 'number', { min: 4, max: 60 }))));
			images.body.appendChild(h('div', { class: 'rss-toggles' }, toggle('image.trim', 'Trim empty borders'), toggle('image.remove_bg', 'Remove plain backgrounds'), toggle('image.sharpen', 'Light sharpening'), toggle('image.upscale', 'Allow upscaling')));
			bulk.body.appendChild(h('div', { class: 'rss-form-grid' },
				RSS.field('Default batch size', input('bulk.batch_size', 'number', { min: 1, max: 50 }), 'Items per background step.'),
				RSS.field('Time per step (seconds)', input('bulk.time_budget', 'number', { min: 5, max: 25 }), 'Stays well below typical 30-second hosting limits.')));
			var tplSel = RSS.select(Object.keys(res.templates).map(function (k) { return { value: k, label: res.templates[k].label + (res.templates[k].custom ? ' (customised)' : '') }; }), 'system', { class: 'rss-input' });
			var tplText = h('textarea', { class: 'rss-input rss-code', rows: '12', disabled: ro || null });
			var tplEdits = {};
			var showTpl = function () { var t = res.templates[tplSel.value]; tplText.value = tplEdits[tplSel.value] !== undefined ? tplEdits[tplSel.value] : t.value; };
			tplText.addEventListener('input', function () { tplEdits[tplSel.value] = tplText.value; });
			tplSel.addEventListener('change', showTpl);
			var reset = RSS.btn('Reset to default', { small: true, icon: 'undo', disabled: ro, onClick: function () { tplEdits[tplSel.value] = ''; tplText.value = res.templates[tplSel.value].default; RSS.toast('Will reset when you save.', 'info'); } });
			tpl.body.appendChild(h('div', { class: 'rss-row' }, tplSel, reset));
			tpl.body.appendChild(tplText);
			tpl.body.appendChild(h('p', { class: 'rss-help', text: 'The fact rules in the System template protect you from invented claims — keep them.' }));
			showTpl();
			if (!ro) {
				var save = RSS.btn('Save settings', { kind: 'primary', icon: 'check' });
				save.addEventListener('click', function () {
					var out = {};
					Object.keys(c).forEach(function (path) {
						var parts = path.split('.'); var node = out;
						for (var i = 0; i < parts.length - 1; i++) { node[parts[i]] = node[parts[i]] || {}; node = node[parts[i]]; }
						node[parts[parts.length - 1]] = c[path].value;
					});
					out.templates = tplEdits;
					RSS.busy(save, true, 'Saving…');
					RSS.api('/settings', { method: 'POST', body: out }).then(function () { RSS.toast('Settings saved.', 'success'); }).catch(RSS.fail).then(function () { RSS.busy(save, false); });
				});
				bar.appendChild(save);
				bar.appendChild(h('small', { class: 'rss-muted', text: 'AI keys and the AI switch are managed in AI Providers.' }));
			}
		}).catch(function (e) { main.appendChild(RSS.empty('alert', 'Could not load settings', e.message)); });
	};

	/* ================================================================= logs */

	RSS.pages.logs = function (main) {
		var sec = RSS.section('log', 'Activity log', 'What changed, who did it and when.', {
			actions: h('div', { class: 'rss-row is-tight' },
				h('a', { class: 'rss-btn is-ghost is-sm', href: B.adminPost + '?action=rar_studio_logs_export&_wpnonce=' + B.exportNonce }, icon('download'), h('span', { text: 'Export CSV' })),
				B.admin ? RSS.btn('Clear', { small: true, icon: 'trash', onClick: function () {
					RSS.confirm({ title: 'Clear the activity log?', text: 'All entries are deleted. A new entry records that the log was cleared.', confirm: 'Clear log', danger: true }).then(function (yes) {
						if (yes) { RSS.api('/logs', { method: 'DELETE' }).then(function () { RSS.toast('Log cleared.', 'success'); load(); }).catch(RSS.fail); }
					});
				} }) : null)
		});
		main.appendChild(sec);
		var page = 1;
		var level = RSS.select([{ value: '', label: 'All levels' }, { value: 'info', label: 'Info' }, { value: 'warning', label: 'Warning' }, { value: 'error', label: 'Error' }, { value: 'security', label: 'Security' }], '', { class: 'rss-input', 'aria-label': 'Level' });
		var q = h('input', { type: 'search', class: 'rss-input', placeholder: 'Search messages…', 'aria-label': 'Search' });
		var out = h('div', {});
		sec.body.appendChild(h('div', { class: 'rss-row' }, level, q));
		sec.body.appendChild(out);
		var timer = null;
		var load = function () {
			clear(out).appendChild(RSS.skeleton(6));
			RSS.api('/logs', { query: { level: level.value, search: q.value, page: page } }).then(function (res) {
				clear(out);
				if (!res.items.length) { out.appendChild(RSS.empty('clock', 'No log entries', '')); return; }
				out.appendChild(h('div', { class: 'rss-table-wrap' }, h('table', { class: 'rss-table' },
					h('thead', {}, h('tr', {}, ['Time', 'Level', 'Event', 'Item', 'User', 'Details'].map(function (x) { return h('th', { scope: 'col', text: x }); }))),
					h('tbody', {}, res.items.map(function (r) {
						return h('tr', {},
							h('td', {}, h('small', { text: RSS.when(r.created_at) })),
							h('td', {}, RSS.badge(r.level, r.level === 'error' ? 'danger' : r.level === 'warning' ? 'warn' : r.level === 'security' ? 'ai' : 'neutral')),
							h('td', {}, h('strong', { text: r.message }), h('small', { class: 'rss-muted', text: r.action })),
							h('td', { text: r.object_id ? r.object_type + ' #' + r.object_id : '—' }),
							h('td', { text: r.user || 'system' }),
							h('td', {}, Object.keys(r.context || {}).length ? h('code', { class: 'rss-ctx', text: JSON.stringify(r.context) }) : null));
					})))));
				var pages = Math.max(1, Math.ceil(res.total / 30));
				out.appendChild(h('div', { class: 'rss-pager' },
					RSS.btn('Previous', { small: true, disabled: page <= 1, onClick: function () { page--; load(); } }),
					h('span', { text: 'Page ' + page + ' of ' + pages + ' · ' + res.total + ' entries' }),
					RSS.btn('Next', { small: true, disabled: page >= pages, onClick: function () { page++; load(); } })));
			}).catch(function (e) { clear(out).appendChild(RSS.empty('alert', 'Could not load logs', e.message)); });
		};
		level.addEventListener('change', function () { page = 1; load(); });
		q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { page = 1; load(); }, 300); });
		load();
	};

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', RSS.start); } else { RSS.start(); }
})(window.RSS);
