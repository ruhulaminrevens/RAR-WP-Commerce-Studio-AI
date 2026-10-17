# RAR WP Commerce Studio AI 1.0.0

Free-first AI studio for WooCommerce and WordPress — product content, SEO, product images and bulk work, with every change previewed, approved and reversible. GPT, Gemini and Claude are optional and **OFF by default**.

**Download:** `rar-wp-commerce-studio-ai.zip` below (upload in WordPress → Plugins → Add New → Upload Plugin). Verify with `rar-wp-commerce-studio-ai.zip.sha256`.

## Highlights

- Product Studio, Image Studio, Content Studio, SEO Studio, Bulk Manager, AI Providers, Settings, Activity Logs — premium dark/light UI, phone friendly.
- Review-first: current vs suggested, word diff, edit, approve, roll back — one change or a whole bulk job.
- Fact guard blocks unverified numbers, certifications, health claims, guarantees and prices.
- Free local engine (English / বাংলা / bilingual) and subscription copy-paste mode — no API bill.
- Image standardisation with GD: trim, centre, white/transparent canvas, 300–2400 px, JPEG/WebP/PNG, plain-background removal, before/after; originals kept, product pixels never repainted.
- Yoast SEO, Rank Math, SEOPress.
- Background bulk queue safe for shared hosting.
- Gemini / OpenAI / Claude with encrypted keys, limits, monthly budget and usage history.
- Never touches price, stock, SKU, variations, shipping, orders, payments; HPOS compatible; nothing loads on the shop front end.

## Upgrading from 0.1.x

1. Back up database + uploads.
2. Upload the ZIP → **Replace current with uploaded**.
3. Settings and restore points migrate automatically; the AI switch is turned OFF. Menu is now **Commerce Studio**.

Going back to 0.1.1: upload the 0.1.1 ZIP from the releases page the same way. Its old settings are still there; 1.0 history stays in its own tables (unused by 0.1.1), and 0.1.x restore points that were moved into 1.0 history are no longer shown by 0.1.1 — roll changes back in 1.0 first if you need them.

## Tested

Unit 60/60 · Integration on WordPress 7.1 + WooCommerce 11.2, HPOS on/off 200/200 · Browser screens 36/36 · Browser flows 39/39 · PHPCompatibility 7.4–8.5 clean. CI (green) adds PHP 7.4–8.4, MariaDB, real Yoast SEO, WordPress 6.4 + WooCommerce 8.0 and the latest stack.

## Known limits

- AI providers were tested with mocked responses only; test your own key on one product first.
- Rank Math / SEOPress and your theme/page-builder combination should be checked on staging.
- Background removal works on plain backgrounds only (by design — it refuses busy photos rather than guessing).
- Reference model prices are estimates; check provider pricing.

Docs: [README](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI#readme) · [Install & rollback](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/blob/main/docs/INSTALL-AND-DEPLOY.md) · [User guide](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/blob/main/docs/USER-GUIDE.md) · [Changelog](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/blob/main/CHANGELOG.md)
