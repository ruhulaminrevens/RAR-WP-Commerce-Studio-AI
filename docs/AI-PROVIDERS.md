# AI providers, costs & limits

## তিনটি পথ / Three ways to get text

| | Free local | Subscription | AI API |
| --- | --- | --- | --- |
| খরচ | ০ | আপনার existing plan (ChatGPT Plus/Pro, Gemini, Claude Pro/Max) | Provider bill per token / image |
| Internet call from your site | না | না | হ্যাঁ, HTTPS-only |
| কী পাঠানো হয় | — | আপনি নিজে prompt copy করেন | Name, descriptions, attributes, SKU, categories (price কখনো নয়) |
| Bulk | ✅ | ✗ (one item at a time) | ✅ with limits |
| Setup | কিছু না | কিছু না | API key + master switch |

**Subscription ≠ API.** ChatGPT Plus/Pro, Claude Pro বা Gemini app subscription দিয়ে website থেকে programmatic call করা যায় না — সেটা provider-এর আলাদা developer API account ও billing। তাই plugin "Subscription" mode দেয়: prompt copy → app-এ paste → উত্তর plugin-এ paste → normal review।

## Turning the API on (৫ ধাপ)

1. **AI Providers → Providers** — provider card-এ key paste করুন (বা `wp-config.php`-এ constant)। Key encrypted (libsodium, WordPress salts দিয়ে) save হয়; screen-এ শুধু masked দেখায়।
2. **Test & load models** — free; শুধু model list আনে, কোনো token খরচ নেই।
3. Model বাছুন, প্রয়োজনে price (US$ per 1M tokens) ঠিক করুন, **Enable** করুন।
4. **Limits & budget** ঠিক করুন।
5. **AI API switch** ON — "I understand API requests may be billed by the AI provider, separately from any chat subscription" tick করে। কে কখন ON করেছে log হয়।

Who may use AI: Administrators by default; Settings → General can allow Shop Managers. Key, switch and provider settings are Administrator-only.

```php
// wp-config.php (optional — overrides the saved key)
define( 'RAR_STUDIO_GEMINI_API_KEY', '...' );
define( 'RAR_STUDIO_OPENAI_API_KEY', '...' );
define( 'RAR_STUDIO_CLAUDE_API_KEY', '...' );
// optional own encryption key for saved keys (32+ chars)
define( 'RAR_STUDIO_ENCRYPTION_KEY', '...' );
```

If you later change WordPress salts or `RAR_STUDIO_ENCRYPTION_KEY`, saved keys can no longer be decrypted — paste them again.

## Default models

Chosen as the lowest-cost models that handle product copy well. Prices are **reference values stored in Settings** for estimates; provider prices change — check the official pages and edit the numbers.

| Provider | Text model | Input / output US$ per 1M tokens (reference) | Image model (optional) |
| --- | --- | --- | --- |
| Google Gemini | `gemini-3.5-flash-lite` | 0.30 / 2.50 | `gemini-3.1-flash-lite-image` |
| OpenAI | `gpt-5-nano` | 0.05 / 0.40 | `gpt-image-1` |
| Anthropic Claude | `claude-haiku-5-5` | 0.10 / 0.50 | — |

Official pricing: [Gemini](https://ai.google.dev/gemini-api/docs/pricing) · [OpenAI](https://openai.com/api/pricing/) · [Claude](https://docs.claude.com/en/docs/about-claude/pricing)

Typical product (title + short + long description + SEO fields): roughly 1,500 input + 900 output tokens → well under US$0.01 with these models. The plugin shows an estimate before every AI run and bulk job.

**Free tier:** some providers (notably Gemini) offer a limited free quota depending on account, country and model. Tick **Free tier** on the provider card to record those requests at US$0 — limits still apply. No model is guaranteed free.

## Limits & budget (hard stops)

| Setting | Default | Effect |
| --- | --- | --- |
| Daily requests | 20 | Further requests refused until midnight (site time zone) |
| Per minute | 6 | Smooths bursts; bulk jobs pause instead of failing |
| Monthly budget | US$5.00 | Estimated spend this month; refuse when reached |
| Max output tokens | 1,500 | Per request |
| Image features | OFF | AI vision alt text and background scene need this as well |
| Fallback provider | OFF | If ON, a failed request is tried once on the next enabled provider |

Each request is **reserved before** it is sent (so requests running at the same time count toward the limits) and then finished with the real token counts the provider returns. Bulk AI jobs pause automatically on limit, budget, invalid key (401/403) or rate limit (429).

## What is never sent

Prices, sale prices, stock, customer or order data, API keys of other providers, WordPress user data. Requests use HTTPS with redirects disabled; errors are logged with secrets redacted.

## Adding another provider

Implement `RarStudio\Ai\Provider` (see `includes/Ai/Gemini.php`) and register it:

```php
add_filter( 'rar_studio_ai_providers', function ( $providers ) {
	$providers['myprovider'] = new My_Provider();
	return $providers;
} );
```
