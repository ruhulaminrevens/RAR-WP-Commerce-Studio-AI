/**
 * End-to-end user flows in a real browser (Playwright + Chromium).
 * Requires a LOCAL/STAGING site seeded with tests/integration/seed.php.
 *
 *   BASE=http://127.0.0.1:8890 IDS='{"oud":44,...}' node tests/e2e/flows.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');
let playwright;
try { playwright = require('playwright'); } catch (e) { playwright = require(process.env.PLAYWRIGHT_PATH || '/opt/npm-tools/node_modules/playwright'); }

const BASE = process.env.BASE || 'http://127.0.0.1:8890';
const IDS = JSON.parse(process.env.IDS || '{}');
const OUT = process.env.OUT || path.join(process.cwd(), 'shots');
fs.mkdirSync(OUT, { recursive: true });
let pass = 0; const fails = [];
function ok(cond, label) { if (cond) { pass++; console.log('  ✓ ' + label); } else { fails.push(label); console.log('  ✗ ' + label); } }
const admin = (p, q) => BASE + '/wp-admin/admin.php?page=' + (p === 'dashboard' ? 'rar-wp-commerce-studio-ai' : 'rar-studio-' + p) + (q ? '&' + q : '');

async function login(page) {
	await page.goto(BASE + '/wp-login.php');
	await page.fill('#user_login', process.env.WP_USER || 'admin');
	await page.fill('#user_pass', process.env.WP_PASS || 'admin123');
	await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
}
async function toast(page, re, timeout) {
	try { await page.waitForSelector('.rss-toast', { timeout: timeout || 15000 }); } catch (e) { return ''; }
	const deadline = Date.now() + (timeout || 15000);
	while (Date.now() < deadline) {
		const texts = await page.$$eval('.rss-toast', (els) => els.map((e) => e.textContent));
		const hit = texts.find((t) => re.test(t));
		if (hit) { return hit; }
		await page.waitForTimeout(200);
	}
	return (await page.$$eval('.rss-toast', (els) => els.map((e) => e.textContent))).join(' | ');
}
async function clearToasts(page) { await page.evaluate(() => document.querySelectorAll('.rss-toast').forEach((t) => t.remove())); }

(async () => {
	const browser = await playwright.chromium.launch({ executablePath: fs.existsSync('/opt/google/chrome/chrome') ? '/opt/google/chrome/chrome' : undefined });
	const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
	const page = await ctx.newPage();
	const jsErrors = [];
	page.on('pageerror', (e) => jsErrors.push(e.message));
	await login(page);

	console.log('\n== Product Studio: free preview → apply → rollback');
	await page.goto(admin('products', 'id=' + IDS.oud), { waitUntil: 'networkidle' });
	await page.waitForSelector('.rss-gen');
	await page.click('text=Generate free preview');
	let t = await toast(page, /ready for review/);
	ok(/ready for review/.test(t), 'free preview creates suggestions: ' + t.slice(0, 80));
	await page.waitForSelector('#rss-sec-review .rss-draft.is-pending');
	const seoCard = page.locator('#rss-sec-review .rss-draft.is-pending', { hasText: 'SEO title' }).first();
	ok(await seoCard.count() === 1, 'SEO title draft card shown');
	const proposed = await seoCard.locator('textarea').inputValue();
	await clearToasts(page);
	await seoCard.locator('button', { hasText: 'Approve & apply' }).click();
	t = await toast(page, /applied/);
	ok(/applied/.test(t), 'apply shows confirmation');
	await page.waitForSelector('#rss-sec-history .rss-draft.is-applied');
	const meta = await page.evaluate(async ({ base, id }) => {
		const r = await fetch(base + '/?rest_route=/rar-studio/v1/objects/product/' + id, { headers: { 'X-WP-Nonce': window.RSS_BOOT.nonce } });
		return (await r.json()).fields.seo_title.value;
	}, { base: BASE, id: IDS.oud });
	ok(meta === proposed, 'SEO title saved to product');
	await clearToasts(page);
	await page.locator('#rss-sec-history .rss-draft.is-applied', { hasText: 'SEO title' }).first().locator('button', { hasText: 'Roll back' }).click();
	await page.waitForSelector('.rss-modal');
	await page.locator('.rss-modal button', { hasText: 'Roll back' }).click();
	t = await toast(page, /restored/);
	ok(/restored/.test(t), 'rollback restores previous value');

	console.log('\n== Fact guard in the UI');
	const card = page.locator('#rss-sec-review .rss-draft.is-pending', { hasText: 'Meta description' }).first();
	await card.locator('textarea').fill('Certified organic oud in a 50ml bottle with free delivery.');
	await clearToasts(page);
	await card.locator('button', { hasText: 'Approve & apply' }).click();
	t = await toast(page, /facts not in the product data|Review the flags/);
	ok(/facts/.test(t), 'edited text with invented facts is stopped');
	const flagged = page.locator('#rss-sec-review .rss-draft.has-blockers', { hasText: 'Meta description' }).first();
	await flagged.waitFor();
	const flagText = await flagged.locator('.rss-flags').innerText();
	ok(/50ml/i.test(flagText) && /organic/i.test(flagText), 'flags list 50ml and organic');
	await clearToasts(page);
	await flagged.locator('button', { hasText: 'Approve & apply' }).click();
	t = await toast(page, /I verified/);
	ok(/I verified/.test(t), 'apply requires "I verified these facts"');
	await flagged.locator('.rss-ack input').check();
	await clearToasts(page);
	await flagged.locator('button', { hasText: 'Approve & apply' }).click();
	t = await toast(page, /applied/);
	ok(/applied/.test(t), 'acknowledged draft applies');
	await page.locator('#rss-sec-history .rss-draft.is-applied', { hasText: 'Meta description' }).first().locator('button', { hasText: 'Roll back' }).click();
	await page.locator('.rss-modal button', { hasText: 'Roll back' }).click();
	await toast(page, /restored/);

	console.log('\n== Subscription workflow (copy prompt → paste answer)');
	await page.locator('.rss-seg-btn', { hasText: 'Subscription' }).click();
	await page.click('text=Create prompt');
	await page.waitForSelector('.rss-manual textarea[readonly]');
	const prompt = await page.locator('.rss-manual textarea[readonly]').inputValue();
	ok(/Use ONLY facts/.test(prompt) && /ARABIAN OUD/i.test(prompt) && !/2450/.test(prompt), 'prompt has fact rules + product data, no price');
	await page.locator('.rss-manual textarea').nth(1).fill('```json\n{"seo_focus":"arabian oud perfume", "seo_title":"Arabian Oud Perfume 100ml"}\n```');
	await clearToasts(page);
	await page.click('text=Import answer as suggestions');
	t = await toast(page, /imported/);
	ok(/imported/.test(t), 'pasted answer imported as suggestions');
	// The review list re-renders right after the toast; wait for it instead of reading it at once.
	const subCard = await page.locator('#rss-sec-review .rss-draft', { hasText: 'Subscription' }).first().waitFor({ timeout: 8000 }).then(() => true).catch(() => false);
	ok(subCard, 'imported drafts marked as Subscription');
	await page.screenshot({ path: path.join(OUT, 'flow-product-review.png'), fullPage: false });

	console.log('\n== Keyboard product search');
	await page.goto(admin('products'), { waitUntil: 'networkidle' });
	await page.focus('.rss-picker input');
	await page.keyboard.type('RSS-SPRAY');
	await page.waitForSelector('.rss-picker-item');
	await page.keyboard.press('Enter');
	await page.waitForSelector('.rss-object h4');
	ok(/body spray/i.test(await page.locator('.rss-object h4').innerText()), 'SKU search + Enter loads product');

	console.log('\n== Image Studio: new image → compare → apply → rollback');
	await page.goto(admin('images', 'id=' + IDS.oud), { waitUntil: 'networkidle' });
	await page.waitForSelector('.rss-imgtile.is-selected');
	await page.locator('.rss-switch', { hasText: 'Remove plain background' }).click();
	await clearToasts(page);
	await page.click('text=Create new images');
	t = await toast(page, /ready for review/, 60000);
	ok(/ready for review/.test(t), 'derivative created');
	const imgDraft = page.locator('#rss-sec-results .rss-draft.is-pending', { hasText: 'Featured image' }).first();
	await imgDraft.waitFor();
	ok(await imgDraft.locator('.rss-compare').count() === 1, 'before/after compare slider shown');
	await page.screenshot({ path: path.join(OUT, 'flow-image-compare.png') });
	await clearToasts(page);
	await imgDraft.locator('button', { hasText: 'Approve & apply' }).click();
	t = await toast(page, /applied/);
	ok(/applied/.test(t), 'new featured image applied');
	await page.locator('#rss-sec-results .rss-draft.is-applied', { hasText: 'Featured image' }).first().locator('button', { hasText: 'Roll back' }).click();
	await page.locator('.rss-modal button', { hasText: 'Roll back' }).click();
	t = await toast(page, /restored/);
	ok(/restored/.test(t), 'original featured image restored');

	console.log('\n== Bulk Manager');
	await page.goto(admin('bulk'), { waitUntil: 'networkidle' });
	await page.selectOption('#rss-op', 'seo_local');
	await page.fill('#rss-sec-create textarea', [IDS.oud, IDS.spray, IDS.attar, IDS.dup1].join(','));
	await page.click('text=Preview items');
	await page.waitForSelector('.rss-preview strong');
	ok(/4 item/.test(await page.locator('.rss-preview strong').innerText()), 'preview counts 4 items');
	await clearToasts(page);
	await page.click('text=Start job');
	t = await toast(page, /started/);
	ok(/started/.test(t), 'job started');
	await page.waitForFunction(() => /completed/.test(document.querySelector('#rss-sec-jobs tbody tr')?.textContent || ''), null, { timeout: 60000 });
	ok(true, 'job completed while the page was open');
	await page.waitForSelector('#rss-sec-detail .rss-draft.is-pending');
	await clearToasts(page);
	await page.locator('#rss-sec-detail button', { hasText: 'Apply all clean suggestions' }).click();
	await page.locator('.rss-modal button', { hasText: 'Apply all clean suggestions' }).click();
	t = await toast(page, /done/, 30000);
	ok(/\d+ done/.test(t), 'apply all clean: ' + t.slice(0, 60));
	await clearToasts(page);
	await page.locator('#rss-sec-detail button', { hasText: 'Roll back this job' }).click();
	await page.locator('.rss-modal button', { hasText: 'Roll back this job' }).click();
	t = await toast(page, /done/, 30000);
	ok(/\d+ done/.test(t), 'job rolled back: ' + t.slice(0, 60));
	await page.screenshot({ path: path.join(OUT, 'flow-bulk.png'), fullPage: true });

	console.log('\n== AI Providers switch');
	await page.goto(admin('ai'), { waitUntil: 'networkidle' });
	await page.waitForSelector('.rss-master');
	await page.click('text=Turn ON…');
	await page.waitForSelector('.rss-modal');
	const confirmBtn = page.locator('.rss-modal button', { hasText: 'Turn ON' });
	ok(await confirmBtn.isDisabled(), 'ON button disabled until cost acknowledgement is ticked');
	await page.check('#rss-confirm-req');
	await confirmBtn.click();
	t = await toast(page, /turned ON/);
	ok(/turned ON/.test(t), 'AI API turned ON');
	ok(/ON/.test(await page.locator('#rss-ai-pill').innerText()), 'sidebar pill shows ON');
	await clearToasts(page);
	await page.click('text=Turn OFF');
	t = await toast(page, /turned OFF/);
	ok(/turned OFF/.test(t), 'AI API turned OFF');

	console.log('\n== Settings persist');
	await page.goto(admin('settings'), { waitUntil: 'networkidle' });
	const voice = page.locator('#rss-sec-brand textarea').first();
	await voice.fill('Warm, honest and simple. Test ' + Date.now());
	const val = await voice.inputValue();
	await clearToasts(page);
	await page.click('text=Save settings');
	t = await toast(page, /saved/);
	await page.reload({ waitUntil: 'networkidle' });
	ok(await page.locator('#rss-sec-brand textarea').first().inputValue() === val, 'brand voice saved and reloaded');

	console.log('\n== Theme + mobile navigation');
	const before = await page.getAttribute('#rss-app', 'data-theme');
	await page.click('#rss-theme');
	await page.waitForTimeout(400);
	await page.reload({ waitUntil: 'networkidle' });
	const after = await page.getAttribute('#rss-app', 'data-theme');
	ok(before !== after, 'theme switch persists after reload (' + before + ' → ' + after + ')');
	await page.click('#rss-theme');
	await page.waitForTimeout(300);
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto(admin('products', 'id=' + IDS.oud), { waitUntil: 'networkidle' });
	ok(!(await page.isVisible('.rss-side .rss-nav-link.is-active')) || (await page.locator('.rss-side').boundingBox()).x < 0, 'sidebar hidden on phone');
	await page.click('[data-rss-open-nav]');
	await page.waitForTimeout(350);
	ok((await page.locator('.rss-side').boundingBox()).x >= 0, 'burger opens sidebar');
	await page.keyboard.press('Escape');
	await page.waitForTimeout(350);
	ok((await page.locator('.rss-side').boundingBox()).x < 0, 'Escape closes sidebar');
	await page.setViewportSize({ width: 1366, height: 900 });

	console.log('\n== HTTP-level security');
	const noNonce = await page.evaluate(async (base) => (await fetch(base + '/?rest_route=/rar-studio/v1/bootstrap', { credentials: 'same-origin' })).status, BASE);
	ok(noNonce === 401 || noNonce === 403, 'REST without nonce refused (' + noNonce + ')');
	const badNonce = await page.evaluate(async (base) => (await fetch(base + '/?rest_route=/rar-studio/v1/bootstrap', { credentials: 'same-origin', headers: { 'X-WP-Nonce': 'abc' } })).status, BASE);
	ok(badNonce === 403, 'REST with forged nonce refused (' + badNonce + ')');
	const csv = await page.request.get(BASE + '/wp-admin/admin-post.php?action=rar_studio_logs_export');
	ok(csv.status() === 403 || /expired/i.test(await csv.text()), 'CSV export without nonce refused');
	const anon = await browser.newContext();
	const ap = await anon.newPage();
	const anonStatus = await ap.evaluate(async (base) => (await fetch(base + '/?rest_route=/rar-studio/v1/logs')).status, BASE).catch(async () => { await ap.goto(BASE); return ap.evaluate(async (base) => (await fetch(base + '/?rest_route=/rar-studio/v1/logs')).status, BASE); });
	ok(anonStatus === 401, 'logged-out visitor gets 401 (' + anonStatus + ')');
	await anon.close();

	// ScrollSpy follows the section in view, including jumps and the last section.
	await page.goto(admin('settings'), { waitUntil: 'networkidle' });
	const activeSec = () => page.evaluate(() => (document.querySelector('.rss-subnav-link.is-active') || {}).dataset?.sec || '');
	const secIds = await page.evaluate(() => Array.from(document.querySelectorAll('.rss-subnav-link')).map((a) => a.dataset.sec));
	ok((await activeSec()) === secIds[0], 'ScrollSpy starts on the first section');
	await page.evaluate((id) => { const el = document.getElementById('rss-sec-' + id); window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 100); }, secIds[2]);
	await page.waitForTimeout(300);
	ok((await activeSec()) === secIds[2], 'ScrollSpy highlights the section scrolled to (' + (await activeSec()) + ')');
	await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
	await page.waitForTimeout(300);
	ok((await activeSec()) === secIds[secIds.length - 1], 'ScrollSpy highlights the last section at page bottom');

	ok(jsErrors.length === 0, 'no JavaScript errors during flows' + (jsErrors.length ? ': ' + jsErrors.join(' | ') : ''));
	await browser.close();
	console.log(`\n${pass}/${pass + fails.length} flow checks passed`);
	if (fails.length) { console.log('FAILURES:\n - ' + fails.join('\n - ')); process.exit(1); }
})().catch((e) => { console.error(e); process.exit(2); });
