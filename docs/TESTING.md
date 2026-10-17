# Testing

Never run the integration or browser tests on a live shop — they create and change data. Both scripts refuse to run when `wp_get_environment_type()` is `production`.

## Suites

| Suite | Command | What it covers |
| --- | --- | --- |
| Unit (offline) | `php tests/unit/run.php` | Text helpers, Bangla digits, fact guard, local writer, crypto, settings sanitising, CSV safety — 60 checks with WordPress stubs |
| Integration | `wp eval-file tests/integration/seed.php` then `wp eval-file tests/integration/run.php` | Real WordPress + WooCommerce: install/upgrade from 0.1.x, field writes never touching price/stock/SKU/variations, drafts → apply → rollback/conflicts, SEO adapters, Elementor lock, AI gateway OFF/ON with mocked HTTP (keys, limits, budget, retries, fallback, JSON parsing), Image Studio (trim, background removal refusal, formats, memory guard, gallery, rollback), bulk queue (locks, pause on limits, retry, reports), REST permissions and nonces, logs redaction, deleting items — 200 checks |
| Browser screens | `BASE=http://127.0.0.1:8890 THEMES=dark,light node tests/e2e/ui-smoke.cjs` | All 9 pages × 1366 px and 390 px × dark and light: loads, no JS errors, no failed REST calls, no horizontal scroll, section navigation |
| Browser flows | `IDS='…' node tests/e2e/flows.cjs` | Generate → review → edit → fact guard → apply → rollback, subscription import, image create/compare/apply, SEO edit, bulk job lifecycle, ScrollSpy, forged nonce / logged-out refusal — 39 checks |
| Screenshots | `IDS='…' OUT=docs/screenshots node tests/e2e/screenshots.cjs` | Regenerates the README images |

`IDS` is the JSON printed by the seed script (`wp option get rss_seed_ids --format=json`). Login for the browser tests: `admin` / `admin123` (change with `WP_USER`, `WP_PASS`).

## Results for v1.0.0

Local: WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.3, SQLite; HPOS on and off.

| Check | Result |
| --- | --- |
| Unit | 60 / 60 |
| Integration, HPOS on | 200 / 200 |
| Integration, HPOS off | 200 / 200 |
| Browser screens (dark + light) | 36 / 36 |
| Browser flows | 39 / 39 |
| PHPCompatibility 7.4 – 8.5 | 0 errors, 0 warnings |
| WordPress security sniffs (escaping, nonces, sanitising, prepared SQL) | clean |
| Uninstall on a database copy | keeps data by default; removes 5 tables + options when enabled |

CI (GitHub Actions, all green for 1.0.0) repeats lint + unit on PHP 7.4 – 8.4, PHPCompatibility, the integration suite on MariaDB 10.11 with **real Yoast SEO** — oldest supported stack (PHP 7.4, WordPress 6.4, WooCommerce 8.0, Yoast 22.0) and the latest stack (WordPress 7.1, WooCommerce 11.x) on PHP 7.4, 8.3 and 8.4 with HPOS on and off — and both browser suites.

### Performance (local, PHP 8.3)

| Action | Time | Peak memory |
| --- | --- | --- |
| Studio admin page | 120 – 300 ms (same as WP Dashboard) | 14 – 22 MB |
| Free local generate (all product fields) | ~120 ms | — |
| Image 800 px JPEG | ~0.4 s | — |
| Image 1200 px WebP | ~0.8 s | — |
| Bulk tick, 10 items SEO | ~0.19 s | — |
| Shop front end | no plugin output, no measurable difference | — |

## Not covered by automated tests

- Real API calls to Gemini, OpenAI and Claude (all HTTP is mocked; no keys were used).
- Rank Math and SEOPress run only as meta-key adapters in tests (Yoast SEO is installed for real in CI).
- Your theme and plugin mix (Woodmart, Elementor, LiteSpeed Cache …) — check on staging.

## Manual checklist for a new site

1. System check all green on the Dashboard.
2. One product: free preview → apply → rollback; the product page still shows the right price, stock and variations.
3. One image: create → compare → apply → rollback; the original file is still in the Media Library.
4. Bulk: 5 products, free SEO job → apply all clean → roll back job.
5. AI OFF: the AI API engine shows "AI API is OFF (default)" and AI Providers → Usage history stays empty.
6. Cart and checkout: one test order on staging.
