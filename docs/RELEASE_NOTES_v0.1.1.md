# RAR WP Commerce Studio AI — v0.1.1 Release

**Release date:** 2026-10-17

**Classification:** Latest official distribution for the implemented v0.1.x single-product scope. This is a versioned release, not a claim that the broader AI commerce roadmap or full live-store end-to-end QA is complete.

## Downloads
- [WordPress installable plugin ZIP](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/download/v0.1.1/rar-wp-commerce-studio-ai.zip)
- [ZIP SHA-256 checksum file](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/download/v0.1.1/rar-wp-commerce-studio-ai.zip.sha256)
- [Latest Release](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/latest)
- [Source code ZIP (entire repository)](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/archive/refs/heads/main.zip)

## Release highlights
1. Reworked premium dark admin dashboard, responsive form controls, compact cards and sticky ScrollSpy sidebar.
2. Added **Dashboard | Settings | Deactivate** in Installed Plugins (WordPress core Deactivate is preserved).
3. Kept the previous free SEO/image/manual-subscription functions, product safety and rollback behavior.
4. API continues to be **OFF by default** with explicit provider opt-in, separate cost acknowledgement and a request-attempt limiter. Plus/Pro subscriptions do not automatically fund API requests.
5. CSS/JS cache version bumped with plugin version to avoid old-style cache retention.
6. CI lints PHP, runs offline smoke tests, checks JavaScript and packages an installable ZIP. SHA-256 helps verify downloads.

## Updating an existing NABIAD install
1. Confirm the existing current database and uploads backup (and that it can be restored).
2. Download the **installable plugin ZIP** above (not the GitHub Code → Download ZIP source archive).
3. In WordPress: Plugins → Add New → Upload Plugin → select the ZIP → Install → **Replace current with uploaded**.
4. Verify plugin row shows **Dashboard | Settings | Deactivate**.
5. Open the Studio Dashboard and confirm new styling, navigation and API settings (API state is preserved from previous settings, not forcibly reset on upgrade).
6. Use a non-critical product to verify read-only load, free SEO preview and any later manual apply/restore.
7. If the plugin causes an unexpected issue, deactivate it and restore the previous plugin build or the verified backup.

## Automated validation
- WordPress-facing PHP syntax check.
- Offline PHP smoke: API provider default-OFF, daily attempt bound, SEO output, rollback handler and plugin row shortcuts.
- JavaScript syntax check.
- ZIP and checksum packaging.
- Actual WordPress/WooCommerce/Yoast integration, layout screenshots, AVIF derivative testing and external paid API connectivity still require controlled live or staging review.

## Limitations
- Single-product operations only; no bulk queue or AI image background removal.
- Local image tool makes an 800×800 white-canvas JPEG, not WebP.
- Generated text is a suggestion, not a guarantee of factual accuracy or SEO ranking.
- API calls may charge separately even with an existing ChatGPT/Claude subscription; daily attempt count is not a currency budget.
- No automatic migration of settings or WordPress auto-updater channel is included.
- Release does not automatically update the live NABIAD site.
