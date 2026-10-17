# RAR WP Commerce Studio AI

[![Latest release](https://img.shields.io/github/v/release/ruhulaminrevens/RAR-WP-Commerce-Studio-AI?label=release&color=1f9d74)](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/latest)
[![CI](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/actions/workflows/plugin-ci.yml/badge.svg)](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/actions/workflows/plugin-ci.yml)
![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b)
![WooCommerce 8.0+](https://img.shields.io/badge/WooCommerce-8.0%2B-7f54b3)
![PHP 7.4–8.4](https://img.shields.io/badge/PHP-7.4%E2%80%938.4-777bb4)
![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-lightgrey)

WooCommerce ও WordPress-এর জন্য **free-first AI studio** — product content, SEO, product image standardisation এবং bulk কাজ এক জায়গা থেকে। প্রতিটি পরিবর্তন আগে preview, তারপর আপনার approval, এবং সবসময় rollback করা যায়।

A free-first studio for WooCommerce and WordPress. GPT, Gemini and Claude are optional and **OFF by default**.

<p align="center">
  <a href="https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/latest/download/rar-wp-commerce-studio-ai.zip"><b>⬇ Download plugin ZIP — latest (v1.0.0)</b></a>
  &nbsp;·&nbsp;
  <a href="https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/latest/download/rar-wp-commerce-studio-ai.zip.sha256">SHA-256</a>
  &nbsp;·&nbsp;
  <a href="https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/latest">Release notes</a>
</p>

![Dashboard](docs/screenshots/01-dashboard.jpg)

---

## কী করতে পারে / What it does

| Studio | কাজ | Free? |
| --- | --- | --- |
| **Product Studio** | Title, short/long description, spec table, tags, categories, brand, SEO title/meta/keyphrase — English, বাংলা বা bilingual | ✅ Free local engine |
| **Image Studio** | Trim, centre, white/transparent/colour canvas, 300–2400 px, JPEG/WebP/PNG, compression, plain-background removal, gallery, alt text | ✅ Free (PHP GD) |
| **Content Studio** | Posts, pages, media: excerpt, SEO, readability, translation, internal links, new article drafts, Media Library alt text | ✅ Free + optional AI |
| **SEO Studio** | Checks, Google-style preview, keyphrase placement, headings, readability — **Yoast SEO, Rank Math, SEOPress** | ✅ Free |
| **Bulk Manager** | Hundreds of items in small background batches: pause, resume, retry, cancel, CSV report, apply all / roll back all | ✅ Free + optional AI |
| **AI Providers** | OpenAI, Google Gemini, Anthropic Claude — one master switch, encrypted keys, limits, monthly budget, usage history | 💰 Pay per use, OFF by default |
| **Subscription mode** | ChatGPT Plus/Pro, Gemini, Claude Pro/Max-এর app-এ prompt copy → উত্তর paste | ✅ No API bill |

Dashboard-এ catalog health score (thin content, missing meta / alt / image, duplicate ও near-duplicate products) এবং one-click "Fix in bulk"।

## নিরাপত্তা আগে / Safe by design

- **কিছুই নিজে থেকে live হয় না।** সব suggestion "Waiting for review" থাকে; আপনি Approve না করলে save হয় না।
- **Rollback:** আগের value রাখা থাকে — একটি change, একটি selection বা পুরো bulk job ফিরিয়ে নেওয়া যায়। কেউ পরে edit করলে rollback আগে সতর্ক করে।
- **Fact guard:** আপনার product data-তে নেই এমন সংখ্যা/size, certification, health claim, guarantee, price/offer ধরা পড়ে এবং "I verified these facts" ছাড়া apply হয় না।
- **Product photo সত্য থাকে:** শুধু resize/move — bottle, label, logo, লেখা বা রং বদলায় না। AI background scene শুধু পেছনের দৃশ্য বানায়, আসল ছবি উপরে অপরিবর্তিত বসে। Original file কখনো মুছে না।
- **WooCommerce অক্ষত:** price, stock, SKU, variations, shipping, orders, payments, checkout — plugin এগুলোতে লেখে না। HPOS ও Cart/Checkout Blocks compatible।
- **API default OFF:** master switch + cost acknowledgement, per-provider enable, daily ও per-minute limit, monthly budget, cost estimate before each run। Price কখনো AI-কে পাঠানো হয় না।
- Capabilities, nonces, sanitising/escaping, HTTPS-only provider calls, encrypted keys (libsodium) বা `wp-config.php` constants, logs-এ secrets redacted।

![Review with word-level changes](docs/screenshots/03-product-review-diff.jpg)
![Fact guard](docs/screenshots/04-fact-guard.jpg)

## Install (৫ মিনিট)

1. **Backup** নিন — database + `wp-content/uploads`।
2. [Plugin ZIP download](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases/latest/download/rar-wp-commerce-studio-ai.zip) করুন।
3. WordPress → **Plugins → Add New → Upload Plugin** → ZIP → **Install Now** → **Activate**।
   0.1.x আগে থেকে থাকলে **Replace current with uploaded** বেছে নিন (আগে delete করবেন না)।
4. Menu → **Commerce Studio** (বা Plugins page-এ **Dashboard | Settings**)।
5. Dashboard → **Scan catalog** → একটি non-critical product দিয়ে Product Studio-তে free preview → review → apply → rollback চেষ্টা করুন।

Upgrade from 0.1.x: settings, saved SEO restore points and image restore points are migrated into the new history; the AI switch is forced OFF after upgrade.

Full guide: **[docs/INSTALL-AND-DEPLOY.md](docs/INSTALL-AND-DEPLOY.md)** (staging → live, rollback, emergency stop).

## Free vs paid AI

| Mode | খরচ | কখন ব্যবহার করবেন |
| --- | --- | --- |
| **Free local** | ০ — কোনো internet call নেই | Formatting, SEO fields, tags, alt text, spec tables, image work |
| **Subscription** | আপনার existing ChatGPT / Gemini / Claude plan | Creative rewrite, translation — copy prompt, paste answer |
| **AI API** | Provider bill (per token / per image) | Bulk AI content, vision alt text, AI background scene |

> ChatGPT Plus/Pro বা Claude Pro subscription **API credit নয়** — plugin সেগুলো সরাসরি call করতে পারে না। এজন্যই Subscription mode।

Default models (cheapest capable, editable): Gemini `gemini-3.5-flash-lite`, OpenAI `gpt-5-nano`, Claude `claude-haiku-5-5`. Default limits: 20 requests/day, 6/minute, US$5/month budget. Keys can live in `wp-config.php`:

```php
define( 'RAR_STUDIO_GEMINI_API_KEY', '...' );
define( 'RAR_STUDIO_OPENAI_API_KEY', '...' );
define( 'RAR_STUDIO_CLAUDE_API_KEY', '...' );
```

Details: **[docs/AI-PROVIDERS.md](docs/AI-PROVIDERS.md)**

## Screenshots

| | |
| --- | --- |
| ![Generate](docs/screenshots/02-product-studio-generate.jpg) Product Studio — engine, language, fields | ![Image](docs/screenshots/06-image-before-after.jpg) Image Studio — before / after slider |
| ![SEO](docs/screenshots/07-seo-checks.jpg) SEO Studio — checks | ![SERP](docs/screenshots/08-seo-serp-preview.jpg) Google-style preview |
| ![Bulk](docs/screenshots/09-bulk-manager.jpg) Bulk Manager — background jobs | ![AI](docs/screenshots/11-ai-provider-cards.jpg) AI Providers — OFF by default |

Phone, light theme, settings and more: **[docs/USER-GUIDE.md](docs/USER-GUIDE.md)**. All screenshots are from a real local WordPress 7.1 + WooCommerce 11.2 test site with demo products.

## Compatibility

| | Supported |
| --- | --- |
| WordPress | 6.4 or newer (tested 7.1) |
| WooCommerce | 8.0 or newer (tested 11.2), HPOS on or off. Content/SEO/Image tools also work without WooCommerce |
| PHP | 7.4 – 8.4 (PHPCompatibility clean up to 8.5); GD required, Imagick optional |
| SEO plugins | Yoast SEO, Rank Math, SEOPress (filter `rar_studio_seo_adapters` for others) |
| Builders / themes | Elementor pages are analysed, never rewritten; scoped CSS works beside Woodmart and wp-admin |
| Hosting | Shared hosting friendly (Hostinger): no Node, no shell, no extra services; bulk runs in small WP-Cron batches |

Frontend: the plugin loads **nothing** on the shop front end.

## Tested

| Suite | Result |
| --- | --- |
| PHP lint + unit tests (offline, PHP 7.4–8.4 in CI) | 60 / 60 |
| Integration on real WordPress + WooCommerce (HPOS on and off) | 200 / 200 |
| Browser screens — 9 pages × desktop/phone × dark/light | 36 / 36 |
| Browser user flows (generate → review → apply → rollback, images, bulk, security) | 39 / 39 |
| PHPCompatibility 7.4–8.5, WordPress security sniffs | 0 errors, 0 warnings |

AI provider calls are tested with mocked HTTP responses; live keys were not used. See **[docs/TESTING.md](docs/TESTING.md)**.

## Documentation

- [Install, deploy & rollback](docs/INSTALL-AND-DEPLOY.md)
- [User guide (বাংলা + English)](docs/USER-GUIDE.md)
- [AI providers, costs & limits](docs/AI-PROVIDERS.md)
- [Security](SECURITY.md) · [Testing](docs/TESTING.md) · [Roadmap](docs/ROADMAP.md)
- [Changelog](CHANGELOG.md) · [Release notes v1.0.0](docs/RELEASE_NOTES_v1.0.0.md)

## Developers

```
rar-wp-commerce-studio-ai/      ← the installable plugin (this folder is the ZIP)
  includes/   Settings, Drafts, Fields, Rest, Plugin, Installer …
    Ai/        Provider, Gemini, OpenAI, Claude, Gateway, Usage, Registry
    Content/   LocalWriter, FactGuard, Prompts, Generator, Analyzer
    Image/     Processor (GD), Studio
    Bulk/      Jobs (queue)
    Seo/       Manager (Yoast / Rank Math / SEOPress)
  assets/     css, js (no build step), fonts (OFL)
tests/        unit, integration (wp eval-file), e2e (Playwright)
```

REST namespace `rar-studio/v1`. Extension filters: `rar_studio_ai_providers`, `rar_studio_seo_adapters`; action `rar_studio_draft_applied`. Emergency stop without deactivating: `define( 'RAR_STUDIO_DISABLE', true );`

## License

GPL-2.0-or-later. Bundled fonts (Instrument Sans, Hind Siliguri) are under the SIL Open Font License.

Made by [Ruhul Amin](https://github.com/ruhulaminrevens) for [NABIAD](https://www.nabiad.com) and any WooCommerce shop.
