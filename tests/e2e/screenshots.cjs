/**
 * Captures the README screenshots from a real local test site.
 *   BASE=http://127.0.0.1:8890 IDS='{"oud":..}' OUT=docs/screenshots node tests/e2e/screenshots.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');
let playwright;
try { playwright = require('playwright'); } catch (e) { playwright = require(process.env.PLAYWRIGHT_PATH || '/opt/npm-tools/node_modules/playwright'); }
const BASE = process.env.BASE || 'http://127.0.0.1:8890';
const IDS = JSON.parse(process.env.IDS || '{}');
const OUT = process.env.OUT || 'docs/screenshots';
fs.mkdirSync(OUT, { recursive: true });
const admin = (p, q) => BASE + '/wp-admin/admin.php?page=' + (p === 'dashboard' ? 'rar-wp-commerce-studio-ai' : 'rar-studio-' + p) + (q ? '&' + q : '');
// Hide notices that only exist because the test site runs an unbuilt WooCommerce source checkout.
const CLEAN = '.notice, .error, div.updated, .update-nag, .rss-toasts { display: none !important; }';

(async () => {
	const browser = await playwright.chromium.launch({ executablePath: fs.existsSync('/opt/google/chrome/chrome') ? '/opt/google/chrome/chrome' : undefined });
	const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
	const page = await ctx.newPage();
	await page.goto(BASE + '/wp-login.php');
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'admin123');
	await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
	const setTheme = (theme) => page.evaluate(async ({ base, theme }) => {
		const n = await (await fetch(base + '/wp-admin/admin-ajax.php?action=rest-nonce')).text();
		await fetch(base + '/?rest_route=/rar-studio/v1/prefs', { method: 'POST', headers: { 'X-WP-Nonce': n, 'Content-Type': 'application/json' }, body: JSON.stringify({ theme }) });
	}, { base: BASE, theme });
	const shot = async (name, opts) => {
		await page.addStyleTag({ content: CLEAN });
		await page.waitForTimeout(500);
		await page.screenshot(Object.assign({ path: path.join(OUT, name + '.jpg'), type: 'jpeg', quality: 82 }, opts || {}));
		console.log('saved ' + name);
	};
	const scrollTo = (sel) => page.locator(sel).first().evaluate((el) => window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 140));

	// Integration tests may leave non-default image settings behind; use the defaults for the shots.
	await page.evaluate(async (base) => {
		const n = await (await fetch(base + '/wp-admin/admin-ajax.php?action=rest-nonce')).text();
		await fetch(base + '/?rest_route=/rar-studio/v1/settings', { method: 'POST', headers: { 'X-WP-Nonce': n, 'Content-Type': 'application/json' }, body: JSON.stringify({ image: { size: 800, format: 'jpeg', quality: 86, padding: 6, canvas: 'white', background: '#ffffff', remove_bg: 0, trim: 1, upscale: 0 } }) });
	}, BASE);
	await setTheme('dark');
	// Dashboard (after a catalog scan).
	await page.goto(admin('dashboard'), { waitUntil: 'networkidle' });
	const scan = page.locator('button', { hasText: /Scan catalog|Rescan now/ }).first();
	if (await scan.count()) { await scan.click(); await page.waitForTimeout(1500); }
	await page.goto(admin('dashboard'), { waitUntil: 'networkidle' });
	await shot('01-dashboard');

	// Product Studio: generate + review.
	await page.goto(admin('products', 'id=' + IDS.oud), { waitUntil: 'networkidle' });
	await page.locator('#rss-sec-generate input[value="tags"]').check().catch(() => {});
	await page.locator('#rss-sec-generate input[value="title"]').check().catch(() => {});
	await page.click('text=Generate free preview');
	await page.waitForSelector('#rss-sec-review .rss-draft.is-pending');
	await page.waitForTimeout(800);
	await scrollTo('#rss-sec-generate');
	await shot('02-product-studio-generate');
	const titleCard = page.locator('#rss-sec-review .rss-draft', { hasText: 'Product title' }).first();
	await titleCard.locator('details summary').click().catch(() => {});
	await scrollTo('#rss-sec-review .rss-draft:has-text("Product title")');
	await shot('03-product-review-diff');

	// Fact guard example.
	const metaCard = page.locator('#rss-sec-review .rss-draft.is-pending', { hasText: 'Meta description' }).first();
	await metaCard.locator('textarea').fill('Certified organic Arabian Oud in a 50ml bottle with free delivery across Bangladesh.');
	await metaCard.locator('button', { hasText: 'Save edit' }).click();
	await page.waitForSelector('#rss-sec-review .rss-draft.has-blockers');
	await page.waitForTimeout(600);
	await scrollTo('#rss-sec-review .rss-draft.has-blockers');
	await shot('04-fact-guard');

	// Image Studio compare (light theme).
	await setTheme('light');
	await page.goto(admin('images', 'id=' + IDS.oud), { waitUntil: 'networkidle' });
	await page.locator('.rss-imgtile').nth(1).click();
	await page.locator('.rss-switch', { hasText: 'Remove plain background' }).click();
	await page.click('text=Create new images');
	await page.waitForSelector('#rss-sec-results .rss-draft.is-pending .rss-compare', { timeout: 60000 });
	await page.waitForTimeout(1200);
	await scrollTo('#rss-sec-preset');
	await shot('05-image-studio-settings');
	await scrollTo('#rss-sec-results');
	await shot('06-image-before-after');

	// SEO Studio.
	await setTheme('dark');
	await page.goto(admin('seo', 'type=product&id=' + IDS.oud), { waitUntil: 'networkidle' });
	await scrollTo('#rss-sec-score');
	await shot('07-seo-checks');
	await scrollTo('#rss-sec-serp');
	await shot('08-seo-serp-preview');

	// Bulk Manager.
	await page.goto(admin('bulk'), { waitUntil: 'networkidle' });
	await page.selectOption('#rss-op', 'seo_local');
	await page.fill('#rss-sec-create input[type=number]', '50');
	await page.click('text=Preview items');
	await page.waitForSelector('.rss-preview strong');
	const before = await page.locator('#rss-sec-jobs tbody tr').count();
	await page.click('text=Start job');
	await page.waitForFunction((n) => {
		const rows = document.querySelectorAll('#rss-sec-jobs tbody tr');
		return rows.length > n && /completed/.test(rows[0].textContent);
	}, before, { timeout: 90000 });
	await page.waitForTimeout(1500);
	await scrollTo('#rss-sec-jobs');
	await shot('09-bulk-manager');

	// AI Providers.
	await page.goto(admin('ai'), { waitUntil: 'networkidle' });
	await shot('10-ai-providers');
	await scrollTo('#rss-sec-providers');
	await shot('11-ai-provider-cards');

	// Settings + plugin row links.
	await page.goto(admin('settings'), { waitUntil: 'networkidle' });
	await shot('12-settings');
	await page.goto(BASE + '/wp-admin/plugins.php', { waitUntil: 'networkidle' });
	const row = page.locator('tr[data-slug="rar-wp-commerce-studio-ai"]');
	await page.addStyleTag({ content: CLEAN });
	if (await row.count()) {
		await row.screenshot({ path: path.join(OUT, '13-plugin-row-links.jpg'), type: 'jpeg', quality: 85 });
		console.log('saved 13-plugin-row-links');
	}

	// Phone.
	const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, storageState: await ctx.storageState() });
	const pp = await phone.newPage();
	await pp.goto(admin('products', 'id=' + IDS.oud), { waitUntil: 'networkidle' });
	await pp.addStyleTag({ content: CLEAN });
	await pp.waitForTimeout(600);
	await pp.screenshot({ path: path.join(OUT, '14-mobile-product.jpg'), type: 'jpeg', quality: 78 });
	await pp.click('[data-rss-open-nav]');
	await pp.waitForTimeout(400);
	await pp.screenshot({ path: path.join(OUT, '15-mobile-navigation.jpg'), type: 'jpeg', quality: 78 });
	console.log('saved mobile');
	await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
