# Changelog

All notable changes to **RAR WP Commerce Studio AI** are tracked here.

## [1.0.0] — 2026-10-17

Upgrade of the 0.1.1 plugin (same slug, same admin URL) into a full studio. Upload with "Replace current with uploaded".

### Added
- **Seven studios** in one premium dark/light admin app with sticky sidebar, section ScrollSpy, collapsible phone navigation, toasts, confirmations and empty/loading/error states: Dashboard, Product Studio, Image Studio, Content Studio, SEO Studio, Bulk Manager, AI Providers, Settings, Activity Logs.
- **Review-first drafts** for every change: current vs suggested, word-level diff, HTML preview, edit, approve/reject, batch actions, history and rollback, stale-suggestion and rollback-conflict detection.
- **Fact guard** for numbers, sizes, certifications, ingredient/health claims, guarantees, prices and banned words not present in the product data (English and Bangla digits).
- **Free local engine** for titles, short/long descriptions, spec tables, tags, existing categories/brand, SEO title/meta/keyphrase, excerpts and alt text — English, Bangla or bilingual.
- **Image Studio** (PHP GD): trim, centre, white/transparent/colour canvas, 100–2400 px, JPEG/WebP/PNG, quality, padding, sharpen, plain-background removal that refuses busy backgrounds, gallery, before/after slider, alt text; originals are never modified. Optional AI background scene composited behind the real product photo.
- **Content Studio** for posts, pages and media: excerpts, SEO, AI/subscription rewrites and translations, internal-link suggestions, article drafts, Media Library alt text; Elementor content is protected.
- **SEO Studio** with checks, Google-style preview, headings and readability; adapters for Yoast SEO, Rank Math and SEOPress (`rar_studio_seo_adapters`).
- **Bulk Manager**: background queue (WP-Cron + page polling), batch size and time budget, pause/resume/cancel/retry, CSV reports, apply all clean / reject all / roll back job, products-list bulk action.
- **AI gateway** for Gemini, OpenAI and Claude (`rar_studio_ai_providers`): master switch OFF by default with cost acknowledgement, per-provider enable, encrypted keys or `wp-config.php` constants, free model listing, daily/minute limits, monthly budget, reservations, usage history, optional fallback, vision alt text and image generation.
- **Subscription workflow**: copy a prompt for ChatGPT/Gemini/Claude apps, paste the answer back as suggestions.
- Catalog health scan (thin, missing data, duplicates and near-duplicates), system check, activity log with export, product edit-screen box and row links, plugin row links **Dashboard | Settings**.
- Brand voice, banned words and editable prompt templates.
- Tests: 60 unit, 200 integration (WooCommerce, HPOS on/off), 36 screen and 39 flow browser checks; new CI with PHP 7.4–8.4, MariaDB, oldest and latest WordPress/WooCommerce, Playwright and release packaging.

### Changed
- Admin menu label "RAR Commerce Studio" → **Commerce Studio**; top-level URL unchanged.
- Settings moved to `rar_studio_settings_v1`; 0.1.x settings, SEO restore points and image restore points are migrated; the AI switch is forced OFF after upgrade.
- Single-request API attempt counter replaced by per-request reservations, budgets and token accounting.

### Removed
- `assets/admin.css`, `assets/admin.js` and `tests/smoke.php` (replaced by the new app and test suites).

### Safety
- Never writes price, stock, SKU, variations, shipping, orders or payments; declares HPOS and Cart/Checkout Blocks compatibility; loads nothing on the front end; emergency stop `RAR_STUDIO_DISABLE`; deleting the plugin keeps data unless enabled in Settings.

## [0.1.1] — 2026-10-17
- Redesigned WordPress Studio admin interface: scoped professional dark styling, improved typography, cards, product tools, provider settings, responsive layout and sticky ScrollSpy sidebar.
- Added **Dashboard | Settings** links to the WordPress Installed Plugins entry, preserving the existing **Deactivate** action.
- Retained existing single-product local SEO preview/apply/restore and local image draft/apply/restore tools.
- Retained explicit, default-OFF OpenAI/Gemini/Anthropic developer API integration and manual subscription prompt workflow.
- Bumped the plugin header and cache version to 0.1.1 for predictable CSS/JS refresh.
- Improved smoke checks to validate plugin-row links and release version.
- Added official GitHub Release ZIP, SHA-256 checksum publication, up-to-date README and release documentation.

**Compatibility caveat:** PHP syntax and offline smoke tests are CI-verified; the redesigned v0.1.1 UI, API adapters, and image-processing workflow have not yet been validated end-to-end against the live WooCommerce site. v0.1.0 Pilot was previously activated on NABIAD; GitHub release does not install v0.1.1 automatically.

## [0.1.0-pilot] — 2026-10-17
- Initial WordPress admin plugin and free-first product optimization workflow.
- Local Yoast SEO template, explicit apply/rollback.
- Separate white-background image copy + manual featured-image swap/rollback.
- Manual ChatGPT/Claude/Gemini subscription prompt.
- Optional API providers (OFF by default), cost acknowledgement, attempt limit.
- GitHub CI, developer docs and test harness.

[1.0.0]: https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/tag/v1.0.0
[0.1.1]: https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/tag/v0.1.1
[0.1.0-pilot]: https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/tag/v0.1.0-pilot
