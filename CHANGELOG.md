# Changelog

All notable changes to **RAR WP Commerce Studio AI** are tracked here.

## [0.1.1] — 2026-10-17 (current release)
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

[0.1.1]: https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/tag/v0.1.1
[0.1.0-pilot]: https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/tag/v0.1.0-pilot
