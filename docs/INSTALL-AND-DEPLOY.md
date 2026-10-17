# Install, deploy & rollback

এই guide ধরে নেয় আপনার site WordPress + WooCommerce, shared hosting (যেমন Hostinger) — Node বা SSH লাগবে না।

## 1. Before you start (একবার)

| Check | কেন |
| --- | --- |
| Full **database** backup + `wp-content/uploads` backup (hPanel → Backups, বা UpdraftPlus) | Rollback-এর শেষ নিরাপত্তা |
| Backup restore একবার staging-এ test করা | Backup কাজ করে কিনা নিশ্চিত |
| PHP 7.4+ (8.2/8.3 recommended), GD extension | Image Studio-র জন্য GD লাগে |
| WordPress 6.4+, WooCommerce 8.0+ | Plugin header requirements |
| Cache plugin (LiteSpeed ইত্যাদি) — wp-admin cache বন্ধ থাকা | Admin screen পুরনো JS দেখাবে না |

## 2. Staging first

1. hPanel → **WordPress → Staging** (বা যেকোনো copy site) তৈরি করুন।
2. Staging-এ ZIP install → Activate।
3. Dashboard → **System check** সব সবুজ কিনা দেখুন (PHP, memory, GD, WebP, HTTPS, WooCommerce, HPOS, SEO plugin, WP-Cron, encryption)।
4. একটি product-এ: **Generate free preview → Review → Approve & apply → History → Roll back**।
5. Image Studio-তে একটি ছবি: **Create new images → compare → Apply → Roll back** — original ছবি Media Library-তে থাকে।
6. Bulk Manager-এ **5টি product** দিয়ে "SEO title, meta & keyphrase (free)" job → **Apply all clean suggestions** → **Roll back this job**।
7. Front end-এ shop, product page, cart, checkout ঠিক আছে কিনা দেখুন — একটি test order (staging-এ)।

## 3. Live (nabiad.com) — only after staging passes

1. Fresh backup নিন (database + uploads)।
2. **Plugins → Add New → Upload Plugin** → `rar-wp-commerce-studio-ai.zip`।
   0.1.x installed থাকলে → **Replace current with uploaded**। Slug একই, তাই delete করবেন না।
3. Activate → Dashboard → **Scan catalog** (read-only, কিছু বদলায় না)।
4. একটি **approved, non-critical** product বেছে নিন, preview দেখুন, approve করার আগে মিলিয়ে দেখুন।
5. প্রথম সপ্তাহে bulk job ছোট রাখুন (Settings → Bulk processing → batch size 5–10)।
6. AI API চালু করবেন শুধু তখনই যখন key, limit ও budget ঠিক করা আছে (AI Providers page)।

Upgrade from 0.1.x does: migrate settings, turn **AI OFF**, convert saved SEO restore points and image restore points into History entries (rollback still works). Old menu "RAR Commerce Studio" becomes **Commerce Studio**; the page URL `admin.php?page=rar-wp-commerce-studio-ai` is unchanged.

## 4. Rollback options

| Situation | Do this |
| --- | --- |
| One wrong change | Product Studio → **History & rollback** → Roll back. Image, Content and SEO Studio: the applied card in the review list → **Roll back** |
| A whole bulk job | Bulk Manager → open job → **Roll back this job** |
| Plugin misbehaves in wp-admin | Add `define( 'RAR_STUDIO_DISABLE', true );` to `wp-config.php` — plugin stops loading, nothing deleted |
| Need the previous plugin version | Roll back any 1.0 changes you want undone first, then upload the older ZIP from [Releases](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/releases) with **Replace current with uploaded** (0.1.1 does not read 1.0 history) |
| Site broken (unlikely: plugin does not run on the front end) | hPanel File Manager → rename `wp-content/plugins/rar-wp-commerce-studio-ai` → site loads without it. Restore backup if needed |

Deactivating keeps all data. Deleting the plugin also keeps data unless **Settings → General → "Delete all plugin data when the plugin is deleted"** is ON.

## 5. What is stored

| Where | What |
| --- | --- |
| Option `rar_studio_settings_v1` | Settings (keys encrypted) |
| Tables `wp_rar_studio_drafts`, `_jobs`, `_job_items`, `_usage`, `_log` | Suggestions & history, bulk queue, AI usage ledger, activity log |
| Media Library | New images created by Image Studio (marked as Studio images); originals untouched |

Log retention defaults to 90 days (Settings → General).

## 6. Verify the download

```bash
sha256sum -c rar-wp-commerce-studio-ai.zip.sha256
```

Windows PowerShell: `Get-FileHash rar-wp-commerce-studio-ai.zip -Algorithm SHA256` and compare with the `.sha256` file.
