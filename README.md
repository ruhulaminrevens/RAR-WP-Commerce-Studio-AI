# RAR WP Commerce Studio AI

**Free-first • Optional AI API • WooCommerce SEO • Product Image Tools • Safe By Default**

Reusable WordPress admin plugin for WooCommerce stores, including [NABIAD](https://www.nabiad.com). It is intended to optimize one product at a time without silently modifying live catalog information.

> Version 0.1.0 is the initial working foundation (pilot). It is not production-certified. Take a verified database + uploads backup and complete staging tests before activating on a live store.

## Implemented features

| Feature | Default mode | Write behavior |
| --- | --- | --- |
| Product lookup by WooCommerce product ID | Free / local | Read-only |
| SEO title, meta description and focus keyphrase suggestion | Free / local | Preview only |
| Yoast SEO field update | Explicit approval | Three meta fields only; saves previous values |
| Restore SEO metadata | Explicit approval | One-step rollback |
| 800x800 white-background product image | Free / local (GD) | Creates separate JPEG attachment, original intact |
| Apply new featured image / restore prior featured image | Explicit approval | Product image ID only |
| ChatGPT/Gemini/Claude subscription prompt | Free / manual | Copy prompt and use your chat app; manual paste |
| Programmatic Gemini, OpenAI, Claude SEO preview | OFF | Per-provider opt-in, key, daily attempt limiter, manual request |
| API usage cap | 5 attempts / UTC day | Counts attempts, not dollar cost |
| Admin dashboard | Included | Sticky sidebar with ScrollSpy and mobile navigation |

## IMPORTANT: Plus/Pro subscriptions and APIs are different

Your existing ChatGPT Plus/Pro or Claude subscription generally cannot be connected as unlimited API credit. The plugin offers a free **manual prompt workflow** to use chat subscriptions in their official UI. Programmatic requests require developer API keys. Some Gemini text models have free-tier quotas, which depend on provider, model, country and account eligibility. No text or image API model is guaranteed free forever.

**API stays OFF on first install and until explicitly enabled.** Product name and descriptions are sent externally only after a permitted administrator initiates a particular API preview request.

## Installation: no coding required

1. Back up site database and WordPress uploads; preferably verify restore on staging.
2. Download GitHub repository ZIP and extract it.
3. Compress only the inner folder named **rar-wp-commerce-studio-ai** to a new ZIP (the folder itself must be ZIP root).
4. WordPress → Plugins → Add New → Upload Plugin → select that inner ZIP → Install → Activate.
5. WordPress left menu → **RAR Commerce Studio**. API remains OFF.
6. Copy actual numeric product ID from WooCommerce Products → Edit URL: post=PRODUCT_ID.
7. Load product → Generate FREE local SEO preview. Review before Apply; test Restore.
8. For image tools, test with a non-critical product, review the derivative, approve featured-image swap, then verify Restore.

GitHub Actions builds a separate installable plugin ZIP artifact after successful lint and smoke tests; artifact access/expiration depend on GitHub.

### সহজ বাংলা workflow
- Product ID load করে **Free local SEO preview** দিন।
- Preview না দেখে কখনো live SEO update করবেন না।
- **Approve & apply** করলে শুধু তিনটি Yoast SEO fields update হবে; Restore option আছে।
- Image Studio নতুন JPEG copy বানায়; existing product photo file unchanged থাকবে।
- Subscription workflow-তে prompt copy করে ChatGPT / Gemini / Claude app-এ run করুন।
- API চাইলে পরে আলাদা করে enable করবেন। API key না থাকলে কোনো provider request সম্ভব নয়।

## Optional API: OFF unless you enable it

For security, keys are loaded ONLY from WordPress wp-config.php constants; **not stored in the database**. Place the required lines above the WordPress stop-editing line. Never commit real keys to GitHub.

    define('RAR_STUDIO_GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY');
    define('RAR_STUDIO_OPENAI_API_KEY', 'YOUR_OPENAI_API_KEY');
    define('RAR_STUDIO_CLAUDE_API_KEY', 'YOUR_ANTHROPIC_API_KEY');

Then open **RAR Commerce Studio → Optional AI API**:
1. Choose provider and compatible model ID.
2. Enable that individual provider.
3. Set max 1–20 daily attempts.
4. Check BOTH **Enable outbound AI API requests** and **I understand requests may incur separate API charges**; Save Settings.
5. Load a product; press **Generate via selected API** only when ready.
6. Review output and approve separately; API generation by itself never publishes.

Each new settings save requires cost acknowledgement again. Failed calls count toward the attempt limit. The attempt limit is **not a monetary budget** and simultaneous requests may race on shared-hosting stores, so monitor provider usage and set API billing limits separately. AI models may be renamed or deprecated; default model IDs are examples, not pricing promises.

## Current safety characteristics

- WordPress nonce and manage_woocommerce / edit_post permission checks for product actions; manage_options for provider configuration.
- Fixed remote provider hosts and HTTPS requirement.
- Zero automatic outbound AI calls on install, page load or local tools.
- Writes limited to reviewed Yoast metadata and featured-image reference; no price/stock/order/SKU modifications.
- SEO preview expires after 30 minutes and rejects a changed product fingerprint.
- Original image bytes remain untouched; derivative is a new Media Library file.
- Local JPEG/PNG/WebP input via PHP GD; AVIF and other supported formats use WordPress image-editor/Imagick conversion fallback where available. Maximum source file 15 MB, maximum 16 million pixels.
- 800x800 output is JPEG on a white canvas (this first milestone does **not** output WebP).
- Separate derivative media may occupy hosting space and is not auto-deleted.

## Compatibility and constraints

WordPress 6.4+, PHP 7.4+, WooCommerce expected. Admin UI loads in WordPress backend only. Yoast SEO field writes are not the same as automatic full SEO schema optimization. Elementor templates and Woodmart theme files are not modified.

**Not yet included:** bulk background queue, product-description auto-apply, fully editable AI preview, automatic AI background removal, WebP output, gallery manager, page/post generation, spending dashboard or screenshots of verified production usage. See docs/ROADMAP.md.

## Developer test commands

    find rar-wp-commerce-studio-ai -type f -name '*.php' -print -exec php -l {} \;
    php tests/smoke.php
    node --check rar-wp-commerce-studio-ai/assets/admin.js

Passing a syntax/smoke check does not substitute for WordPress staging and real WooCommerce integration tests.

## File layout

- rar-wp-commerce-studio-ai/rar-wp-commerce-studio-ai.php — plugin, permissions, admin UI, local SEO/rollback, image, API adapters.
- rar-wp-commerce-studio-ai/assets/admin.css — responsive dashboard styling.
- rar-wp-commerce-studio-ai/assets/admin.js — navigation ScrollSpy and prompt copy.
- tests/smoke.php — offline PHP sanity checks.
- .github/workflows/plugin-ci.yml — CI plus installable plugin ZIP artifact.
- docs/ROADMAP.md — implementation status, backlog and risks.

**Repo:** https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI

GPL-2.0-or-later. No WordPress live deployment is implied by repository commits.
