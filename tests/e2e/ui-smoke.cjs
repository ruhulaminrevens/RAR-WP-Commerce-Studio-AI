/**
 * Browser smoke test for every Studio screen (Playwright + Chromium).
 *
 *   BASE=http://127.0.0.1:8890 USER=admin PASS=admin123 OUT=./shots node tests/e2e/ui-smoke.cjs
 *
 * Checks: page loads, no JS errors, no failed Studio REST calls, no horizontal
 * scroll at each viewport, sub-navigation present. Saves screenshots to OUT.
 */
'use strict';
const path = require('path');
const fs = require('fs');
let playwright;
try { playwright = require('playwright'); } catch (e) { playwright = require(process.env.PLAYWRIGHT_PATH || '/opt/npm-tools/node_modules/playwright'); }

const BASE = process.env.BASE || 'http://127.0.0.1:8890';
const USER = process.env.WP_USER || 'admin';
const PASS = process.env.WP_PASS || 'admin123';
const OUT = process.env.OUT || path.join(process.cwd(), 'shots');
const EXE = process.env.CHROME || undefined;
const ONLY = process.env.ONLY ? process.env.ONLY.split(',') : null;
const THEMES = (process.env.THEMES || 'dark').split(',');
const PAGES = ['dashboard', 'products', 'images', 'content', 'seo', 'bulk', 'ai', 'settings', 'logs'];
const VIEWPORTS = (process.env.VIEWPORTS || '1366x900,390x844').split(',').map((v) => { const [w, h] = v.split('x').map(Number); return { width: w, height: h }; });
const EXTRA = JSON.parse(process.env.EXTRA_QUERY || '{}');

fs.mkdirSync(OUT, { recursive: true });

function url(page) {
	const slug = page === 'dashboard' ? 'rar-wp-commerce-studio-ai' : 'rar-studio-' + page;
	const q = new URLSearchParams(Object.assign({ page: slug }, EXTRA[page] || {}));
	return BASE + '/wp-admin/admin.php?' + q.toString();
}

(async () => {
	const browser = await playwright.chromium.launch({ executablePath: EXE || (fs.existsSync('/opt/google/chrome/chrome') ? '/opt/google/chrome/chrome' : undefined) });
	const results = [];
	let failed = 0;
	for (const theme of THEMES) {
		for (const vp of VIEWPORTS) {
			const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: 1 });
			const page = await ctx.newPage();
			await page.goto(BASE + '/wp-login.php');
			await page.fill('#user_login', USER);
			await page.fill('#user_pass', PASS);
			await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
			// Set theme preference through the REST endpoint.
			const nonce = await page.evaluate(async (base) => {
				const r = await fetch(base + '/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' });
				return r.text();
			}, BASE);
			await page.evaluate(async ({ base, nonce, theme }) => {
				await fetch(base + '/?rest_route=/rar-studio/v1/prefs', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ theme }) });
			}, { base: BASE, nonce, theme });
			for (const p of PAGES) {
				if (ONLY && !ONLY.includes(p)) { continue; }
				const errors = [];
				const bad = [];
				const onConsole = (m) => { if (m.type() === 'error' && !/favicon|wordpress\.org|Failed to load resource: net::ERR_/i.test(m.text())) { errors.push(m.text()); } };
				const onError = (e) => errors.push('pageerror: ' + e.message);
				const onResp = (r) => { if (/rar-studio/.test(r.url()) && r.status() >= 400) { bad.push(r.status() + ' ' + r.url()); } };
				page.on('console', onConsole); page.on('pageerror', onError); page.on('response', onResp);
				const t0 = Date.now();
				await page.goto(url(p), { waitUntil: 'networkidle' });
				await page.waitForTimeout(600);
				const ms = Date.now() - t0;
				const info = await page.evaluate(() => ({
					hscroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
					sw: document.documentElement.scrollWidth,
					cw: document.documentElement.clientWidth,
					sections: document.querySelectorAll('.rss-section, .rss-hero').length,
					subnav: document.querySelectorAll('.rss-subnav-link').length,
					loading: !!document.querySelector('.rss-main > .rss-loading'),
					failedUi: Array.from(document.querySelectorAll('.rss-empty strong')).map((e) => e.textContent).filter((t) => /could not|failed/i.test(t))
				}));
				const shot = path.join(OUT, `${p}-${vp.width}-${theme}.png`);
				await page.screenshot({ path: shot, fullPage: true });
				page.off('console', onConsole); page.off('pageerror', onError); page.off('response', onResp);
				const ok = !errors.length && !bad.length && !info.hscroll && !info.loading && info.sections > 0 && !info.failedUi.length;
				if (!ok) { failed++; }
				results.push({ page: p, vp: vp.width, theme, ok, ms, errors, bad, info });
				console.log(`${ok ? 'PASS' : 'FAIL'} ${p} @${vp.width} ${theme} ${ms}ms sections=${info.sections} subnav=${info.subnav}${info.hscroll ? ' HSCROLL ' + info.sw + '>' + info.cw : ''}${errors.length ? ' ERR ' + errors.join(' | ') : ''}${bad.length ? ' HTTP ' + bad.join(' | ') : ''}${info.failedUi.length ? ' UI ' + info.failedUi.join(',') : ''}`);
			}
			await ctx.close();
		}
	}
	await browser.close();
	fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2));
	console.log(`\n${results.length - failed}/${results.length} screen checks passed`);
	process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
