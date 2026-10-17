# Roadmap & handover

## Current baseline

- **Version 1.0.0** — GitHub `main` is the source of truth; installable ZIP on the [latest release](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/latest).
- Same slug and admin URL as 0.1.x, so it upgrades in place.
- NABIAD (nabiad.com) runs the earlier **0.1.0 pilot**. Publishing 1.0.0 on GitHub does **not** update the live site.

## Done in 1.0.0

- [x] Premium dark/light admin app, sticky sidebar + ScrollSpy, phone navigation, plugin row Dashboard | Settings.
- [x] Product, Image, Content and SEO studios with review-first drafts, history and rollback.
- [x] Fact guard, brand voice, banned words, prompt templates, English/Bangla/bilingual.
- [x] GD image pipeline (sizes, formats, canvas, trim, plain-background removal, gallery, alt text); AI background scene.
- [x] Yoast SEO, Rank Math, SEOPress adapters; Elementor protection.
- [x] Bulk queue with pause/resume/retry/cancel, reports, apply/reject/rollback per job.
- [x] Gemini, OpenAI, Claude with master switch OFF, encrypted keys, limits, budget, usage ledger; subscription copy-paste mode.
- [x] Catalog health scan, system check, activity log.
- [x] 0.1.x migration; HPOS + Cart/Checkout Blocks compatibility; emergency stop; uninstall keeps data by default.
- [x] Unit, integration, browser tests; CI with release packaging.

## Before using on nabiad.com

- [ ] Fresh database + uploads backup (hPanel).
- [ ] Install 1.0.0 on staging; System check green; run the manual checklist in [TESTING.md](TESTING.md).
- [ ] Check with the live theme/plugins (Woodmart, Elementor, LiteSpeed Cache, Yoast) on staging.
- [ ] With approval: upgrade nabiad.com, test one approved non-critical product (preview → apply → rollback), confirm product page, cart and checkout.
- [ ] Only then: small bulk job (5–10 items), then larger batches.
- [ ] Optional, with approval and a key: one AI request on one product; check Usage history.

## Next ideas (not started)

1. Action Scheduler as an optional bulk runner when available.
2. Scheduled catalog health email.
3. More SEO adapters (All in One SEO, The SEO Framework).
4. Local edge-based background removal for slightly textured studio backgrounds.
5. WP-CLI commands for scans and bulk jobs.
6. Translations (`.pot` + Bangla `.po`).

## Risk notes

- AI model names and prices change; defaults are reference values.
- Background removal is intentionally conservative (plain backgrounds only).
- Very large catalogs: the health scan reads up to 3,000 products per run.
- WP-Cron depends on site traffic; on low-traffic sites keep the Bulk Manager page open or set a server cron for `wp-cron.php`.
