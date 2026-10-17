# Development Roadmap & Handover

## Current baseline
- Version: 0.1.1 release candidate, branch release/v0.1.1-final. GitHub main is the release source of truth after merge.
- Repository: ruhulaminrevens/RAR-WP-Commerce-Studio-AI.
- Target: any WordPress + WooCommerce store, tested first against nabiad.com environment.
- API usage OFF at installation, even for free-tier models. Local tools and manual subscriptions are prioritized.
- Keys loaded from wp-config.php only. No credentials stored in WordPress options.

## Implemented
- [x] WordPress admin menu, professionally redesigned dark dashboard, sticky ScrollSpy and mobile navigation.
- [x] Plugin row Dashboard / Settings shortcuts (WordPress Deactivate retained).
- [x] Single product lookup by numeric ID, permission checks.
- [x] Deterministic local SEO preview, manual apply and one-step restoration.
- [x] Copyable product prompt for ChatGPT/Gemini/Claude chat subscriptions.
- [x] GD white-square product derivative (new JPEG attachment), with WordPress editor fallback for AVIF and other compatible inputs; approve/restore featured image.
- [x] Opt-in text API adapters for Gemini/OpenAI/Claude with explicit daily request limit.
- [x] Fixed provider URLs, nonce, HTTPS, no automatic external requests.
- [x] PHP compatibility, lint + offline smoke + JS syntax tests; ZIP & SHA-256 CI packaging and official GitHub release workflow.
- [x] NABIAD WordPress activation of 0.1.0 Pilot was verified; sample product inspected read-only without mutation.

## Validation required
- [x] Earlier main branch CI run passed for v0.1.0.
- [ ] Confirm v0.1.1 release commit CI and asset publication.
- [ ] Install the updated 0.1.1 release on NABIAD or staging, confirm Dashboard / Settings and redesigned UI.
- [ ] Load actual WC product and verify preview, apply, restore safely.
- [ ] Test PNG transparency, WebP and JPEG image derivatives, original preserved.
- [ ] Confirm that no outbound AI requests occur with API disabled.
- [ ] Test each provider only after developer keys and explicit usage consent are available.
- [ ] Test WordPress role restrictions and Yoast SEO indexing behavior.
- [ ] Confirm PHP 7.4 and PHP 8.2 release CI compatibility jobs pass; WordPress plugin/theme integration remains separate.
- [ ] Collect genuine screenshots once installed; never insert fabricated screenshots.

## Planned next milestones (not included in v0.1.0)
1. Product selector via searchable dropdown; WooCommerce editor meta box.
2. Editable, side-by-side, safe content/SEO preview and selective apply with revision history.
3. Local WebP/PNG output, media cleanup, batch resizing/compression, gallery management.
4. Background batch queue with Action Scheduler, cancellation, detailed progress and logs.
5. Better free-tier model availability checking, per-model caps, API estimated billing dashboard.
6. WordPress posts/pages workflow and Elementor content integration through safe builder APIs.
7. Branded bilingual content templates, brand voice, Bangla + English assistant.
8. E2E WordPress/WooCommerce/Yoast tests and security review; certified production-readiness remains pending after the versioned release.

## Risk and deployment notes
- Existing NABIAD Woodmart/Elementor/LiteSpeed and several WooCommerce plugins require staging integration checks.
- API text models and free tiers change; current defaults are not pricing or availability guarantees.
- Request count does not cap real billing; concurrent calls may exceed simple quota under race.
- Image derivatives create files/attachments in hosting; cleanup is not automated.
- Product media labels must not be reconstructed by AI; white canvas maintains original image pixels.
- Snapshot restoration is one-level; take full database and media backups before pilot.
- NABIAD currently has the v0.1.0 pilot activated; v0.1.1 publishing does NOT automatically update or test that installation.

## Safe next step
Obtain a green CI check, download CI-built installable ZIP, install on staging or a non-critical pilot environment, verify screen and one-product operations, then consider production activation. Do not auto-edit any existing product without user review.
