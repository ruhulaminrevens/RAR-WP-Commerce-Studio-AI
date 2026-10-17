=== RAR WP Commerce Studio AI ===
Contributors: ruhulaminrevens
Tags: woocommerce, seo, ai, product images, bulk edit
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free-first AI studio for WooCommerce and WordPress: product content, SEO, image standardisation and bulk workflows. Optional GPT, Gemini and Claude APIs are OFF by default. Every change is previewed, approved and reversible.

== Description ==

Commerce Studio adds one workspace to wp-admin for the everyday catalog work of a WooCommerce shop.

* **Product Studio** – titles, short and long descriptions, specification tables, tags, categories, brand and SEO metadata. Side-by-side review, word-level changes, edit before applying, one-click rollback.
* **Image Studio** – trim, centre, white / transparent / colour canvas, 300–2400 px, JPEG / WebP / PNG, quality and padding, plain-background removal that refuses busy backgrounds, gallery support, alt text. Originals are never modified.
* **Content Studio** – posts, pages and media: excerpts, SEO fields, readability rewrites and translations (AI or subscription), internal-link drafts, new article drafts, alt text for the Media Library. Elementor pages are analysed but never rewritten.
* **SEO Studio** – checks, live Google-style preview, keyphrase placement, heading outline, readability. Works with Yoast SEO, Rank Math and SEOPress.
* **Bulk Manager** – background queue for hundreds of items in small batches: pause, resume, retry, cancel, CSV report, apply all clean suggestions, roll back a whole job.
* **AI Providers** – OpenAI, Google Gemini and Anthropic Claude behind one switch that is OFF by default, with encrypted keys, connection test, model list, daily/minute limits, monthly budget, cost estimates and usage history.
* **Subscription workflow** – use ChatGPT Plus/Pro, Gemini or Claude Pro/Max in their own apps: copy a prompt, paste the answer back. No API key, no extra bill.
* **Fact guard** – numbers, sizes, certifications, health claims, guarantees and prices that are not in your own product data are flagged and need explicit confirmation before they can be applied.

Prices, stock, SKU, variations, shipping, orders and payments are never written by this plugin. It declares compatibility with WooCommerce High-Performance Order Storage.

== Installation ==

1. Back up the database and the uploads folder.
2. Plugins → Add New → Upload Plugin → choose `rar-wp-commerce-studio-ai.zip` → Install → Activate.
   Upgrading from 0.1.x: choose "Replace current with uploaded".
3. Open Commerce Studio in the admin menu (or Plugins → Dashboard | Settings).
4. Optional: AI Providers → add a key → Test & load models → turn the AI API ON with cost acknowledgement.

Keys can also be defined in wp-config.php: `RAR_STUDIO_GEMINI_API_KEY`, `RAR_STUDIO_OPENAI_API_KEY`, `RAR_STUDIO_CLAUDE_API_KEY`.
Emergency stop without deactivating: `define( 'RAR_STUDIO_DISABLE', true );`

== Frequently Asked Questions ==

= Does it cost money? =
No for the free local engine, image tools, analysis and the subscription workflow. AI API requests are billed by the provider you choose; the plugin shows estimates and enforces your limits.

= Can I undo a change? =
Yes. Every applied change keeps the previous value. Roll back one change, a selection, or a whole bulk job.

= Will AI edit my product photos? =
No. Product pixels are only resized and moved. The optional AI background scene generates the backdrop only; the real product photo is placed on top unchanged.

== Changelog ==

= 1.0.0 =
Major upgrade of 0.1.1 (same slug, settings migrated): seven studios, REST-driven premium dark/light UI, drafts with rollback, fact guard, image pipeline, bulk queue, three AI providers with limits and budgets, activity log, migration of 0.1.x restore points. See CHANGELOG.md.

= 0.1.1 =
Dark dashboard, plugin-row links.
