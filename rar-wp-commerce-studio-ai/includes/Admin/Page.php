<?php
/**
 * Admin shell: sidebar navigation, top bar and the mount point that
 * assets/js/studio.js fills for each module.
 *
 * @package RarStudio
 */

namespace RarStudio\Admin;

use RarStudio\Plugin;
use RarStudio\Settings;
use RarStudio\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Page renderer.
 */
final class Page {

	/**
	 * Inline SVG icon (24×24 stroke icons drawn for this plugin).
	 *
	 * @param string $name Icon.
	 * @return string
	 */
	public static function icon( $name ) {
		$paths = array(
			'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
			'products'  => '<path d="M3 7.5 12 3l9 4.5-9 4.5L3 7.5Z"/><path d="M3 7.5V16.5L12 21l9-4.5V7.5"/><path d="M12 12v9"/>',
			'images'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-8 9"/>',
			'content'   => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 13h7M9 17h5"/>',
			'seo'       => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/><path d="M8.5 11.5l1.8 1.8 3.4-3.6"/>',
			'bulk'      => '<rect x="3" y="4" width="18" height="4" rx="1"/><rect x="3" y="10" width="18" height="4" rx="1"/><rect x="3" y="16" width="18" height="4" rx="1"/>',
			'ai'        => '<path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/><path d="M19 15l.8 1.9 1.9.8-1.9.8L19 20.4l-.8-1.9-1.9-.8 1.9-.8z"/>',
			'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
			'logs'      => '<path d="M4 6h16M4 12h16M4 18h10"/><circle cx="19" cy="18" r="2"/>',
			'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
			'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
			'moon'      => '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5Z"/>',
		);
		$d = isset( $paths[ $name ] ) ? $paths[ $name ] : '';
		return '<svg class="rss-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
	}

	/**
	 * Render a Studio page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! Util::can_use_studio() ) {
			wp_die( esc_html__( 'You do not have permission to open Commerce Studio.', 'rar-wp-commerce-studio-ai' ), '', array( 'response' => 403 ) );
		}
		$page  = Plugin::current_page();
		$pages = Plugin::pages();
		$theme = 'light' === get_user_meta( get_current_user_id(), 'rar_studio_theme', true ) ? 'light' : 'dark';
		$ai_on = (int) Settings::get( 'ai.enabled', 0 );
		$descriptions = array(
			'dashboard' => __( 'Catalog health, review queue, jobs and AI usage at a glance.', 'rar-wp-commerce-studio-ai' ),
			'products'  => __( 'Generate, compare, approve and roll back product content, catalog data and SEO.', 'rar-wp-commerce-studio-ai' ),
			'images'    => __( 'Standardise product photos without altering the product — originals are always kept.', 'rar-wp-commerce-studio-ai' ),
			'content'   => __( 'Posts, pages and media: summaries, readability rewrites, translations, internal links and alt text.', 'rar-wp-commerce-studio-ai' ),
			'seo'       => __( 'SEO checks, live search preview and metadata for any product, post or page.', 'rar-wp-commerce-studio-ai' ),
			'bulk'      => __( 'Process hundreds of items safely in small background batches. Results wait for your review.', 'rar-wp-commerce-studio-ai' ),
			'ai'        => __( 'Connect GPT, Gemini and Claude. OFF by default, with limits, budgets and usage history.', 'rar-wp-commerce-studio-ai' ),
			'settings'  => __( 'Brand voice, prompt templates, SEO plugin, image defaults and safety.', 'rar-wp-commerce-studio-ai' ),
			'logs'      => __( 'Every change, API request and security event, with filters and CSV export.', 'rar-wp-commerce-studio-ai' ),
		);
		?>
		<div class="wrap rss-wrap">
		<h1 class="screen-reader-text"><?php echo esc_html( $pages[ $page ][1] . ' – Commerce Studio AI' ); ?></h1>
		<div id="rss-app" class="rss" data-page="<?php echo esc_attr( $page ); ?>" data-theme="<?php echo esc_attr( $theme ); ?>">
			<div class="rss-scrim" data-rss-close-nav hidden></div>
			<aside class="rss-side" id="rss-side" aria-label="<?php esc_attr_e( 'Commerce Studio navigation', 'rar-wp-commerce-studio-ai' ); ?>">
				<div class="rss-brand">
					<span class="rss-brand-mark" aria-hidden="true">R</span>
					<span class="rss-brand-text"><strong>Commerce Studio</strong><small>RAR · AI <?php echo esc_html( RAR_STUDIO_VERSION ); ?></small></span>
				</div>
				<nav class="rss-nav" aria-label="<?php esc_attr_e( 'Modules', 'rar-wp-commerce-studio-ai' ); ?>">
					<?php
					$groups = array(
						__( 'Workspace', 'rar-wp-commerce-studio-ai' ) => array( 'dashboard', 'products', 'images', 'content', 'seo', 'bulk' ),
						__( 'Configure', 'rar-wp-commerce-studio-ai' ) => array( 'ai', 'settings', 'logs' ),
					);
					foreach ( $groups as $label => $keys ) {
						echo '<div class="rss-nav-group"><span class="rss-nav-label">' . esc_html( $label ) . '</span>';
						foreach ( $keys as $key ) {
							$active = $key === $page;
							echo '<a class="rss-nav-link' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( Util::page_url( $key ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . self::icon( $key ) . '<span>' . esc_html( $pages[ $key ][0] ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							if ( $active ) {
								echo '<div class="rss-subnav" id="rss-subnav" aria-label="' . esc_attr__( 'On this page', 'rar-wp-commerce-studio-ai' ) . '"></div>';
							}
						}
						echo '</div>';
					}
					?>
				</nav>
				<div class="rss-side-foot">
					<span class="rss-pill <?php echo $ai_on ? 'is-on' : 'is-off'; ?>" id="rss-ai-pill"><i></i><?php echo $ai_on ? esc_html__( 'AI API ON', 'rar-wp-commerce-studio-ai' ) : esc_html__( 'AI API OFF · free mode', 'rar-wp-commerce-studio-ai' ); ?></span>
				</div>
			</aside>
			<div class="rss-shell">
				<header class="rss-top">
					<button type="button" class="rss-icon-btn rss-burger" data-rss-open-nav aria-controls="rss-side" aria-expanded="false" aria-label="<?php esc_attr_e( 'Open navigation', 'rar-wp-commerce-studio-ai' ); ?>"><?php echo self::icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					<div class="rss-top-title">
						<span class="rss-eyebrow"><?php esc_html_e( 'Commerce Studio AI', 'rar-wp-commerce-studio-ai' ); ?></span>
						<h2><?php echo esc_html( $pages[ $page ][1] ); ?></h2>
						<p><?php echo esc_html( $descriptions[ $page ] ); ?></p>
					</div>
					<div class="rss-top-actions">
						<button type="button" class="rss-icon-btn" id="rss-theme" data-tip="<?php esc_attr_e( 'Switch light / dark', 'rar-wp-commerce-studio-ai' ); ?>" aria-label="<?php esc_attr_e( 'Switch light or dark mode', 'rar-wp-commerce-studio-ai' ); ?>"><span class="rss-when-dark"><?php echo self::icon( 'sun' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span class="rss-when-light"><?php echo self::icon( 'moon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></button>
					</div>
				</header>
				<main class="rss-main" id="rss-main" tabindex="-1">
					<div class="rss-loading" role="status"><span class="rss-spinner"></span><?php esc_html_e( 'Loading…', 'rar-wp-commerce-studio-ai' ); ?></div>
					<noscript><div class="rss-card"><p><?php esc_html_e( 'Commerce Studio needs JavaScript enabled in your browser.', 'rar-wp-commerce-studio-ai' ); ?></p></div></noscript>
				</main>
				<footer class="rss-foot">
					<span>RAR WP Commerce Studio AI <?php echo esc_html( RAR_STUDIO_VERSION ); ?></span>
					<span><?php esc_html_e( 'Every change is a preview until you approve it · prices, stock, SKU and orders are never touched', 'rar-wp-commerce-studio-ai' ); ?></span>
				</footer>
			</div>
			<div class="rss-toasts" id="rss-toasts" role="status" aria-live="polite"></div>
		</div>
		</div>
		<?php
	}
}
