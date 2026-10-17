# Security

## Reporting

Please report security issues privately via [GitHub security advisories](https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI/security/advisories/new) — not in public issues. Include the version, steps to reproduce and impact.

## Supported versions

| Version | Supported |
| --- | --- |
| 1.0.x | ✅ |
| 0.1.x | ❌ — upgrade to 1.0 (settings and restore points migrate) |

## How the plugin protects your shop

- **Permissions:** Studio needs `manage_woocommerce` (or `edit_others_posts` without WooCommerce); every item is also checked with `edit_post`. AI use is Administrator-only unless allowed for Shop Managers. Keys, AI switch and settings are Administrator-only.
- **Requests:** all actions go through the REST API with WordPress nonces; CSV downloads use their own nonce. Logged-out requests get 401.
- **Input/output:** every setting is sanitised and range-clamped; output is escaped; SQL uses prepared statements; CSV cells that start with `= + - @` are neutralised.
- **Keys:** stored encrypted with libsodium (key derived from WordPress salts or `RAR_STUDIO_ENCRYPTION_KEY`), or read from `wp-config.php` constants. Never shown in full, never sent to the browser for non-admins, never written to logs (key/token patterns are redacted).
- **External calls:** only to fixed HTTPS provider endpoints, redirects disabled, timeouts and one retry; only when the master switch is ON and a user starts an AI action. Prices and customer/order data are never sent.
- **Data integrity:** writes go through WooCommerce/WordPress APIs; price, stock, SKU, variations, shipping, orders and payments are never written. Every change keeps the previous value; stale suggestions and rollbacks over newer edits are detected.
- **Emergency stop:** `define( 'RAR_STUDIO_DISABLE', true );` in `wp-config.php`.
