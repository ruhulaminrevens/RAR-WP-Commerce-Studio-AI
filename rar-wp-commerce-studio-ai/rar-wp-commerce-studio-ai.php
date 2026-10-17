<?php
/**
 * Plugin Name:       RAR WP Commerce Studio AI
 * Plugin URI:        https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI
 * Description:       Free-first AI studio for WooCommerce and WordPress: product content, SEO, image standardisation, bulk workflows and optional GPT / Gemini / Claude APIs (OFF by default). Every change is previewed, approved and reversible.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            RAR
 * Author URI:        https://github.com/ruhulaminrevens
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rar-wp-commerce-studio-ai
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 *
 * @package RarStudio
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'RAR_STUDIO_VERSION' ) ) {
	return;
}

define( 'RAR_STUDIO_VERSION', '1.0.0' );
define( 'RAR_STUDIO_DB_VERSION', '1.0.0' );
define( 'RAR_STUDIO_FILE', __FILE__ );
define( 'RAR_STUDIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAR_STUDIO_URL', plugin_dir_url( __FILE__ ) );
define( 'RAR_STUDIO_SLUG', 'rar-wp-commerce-studio-ai' );

require_once RAR_STUDIO_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'RarStudio\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RarStudio\\Installer', 'deactivate' ) );

/*
 * WooCommerce feature compatibility. The plugin never reads or writes orders,
 * so it is compatible with High-Performance Order Storage (HPOS).
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RAR_STUDIO_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', RAR_STUDIO_FILE, true );
		}
	}
);

/*
 * Emergency switch: define( 'RAR_STUDIO_DISABLE', true ); in wp-config.php
 * stops every hook of this plugin without deactivating it.
 */
if ( ! ( defined( 'RAR_STUDIO_DISABLE' ) && RAR_STUDIO_DISABLE ) ) {
	add_action( 'plugins_loaded', array( 'RarStudio\\Plugin', 'boot' ) );
}
