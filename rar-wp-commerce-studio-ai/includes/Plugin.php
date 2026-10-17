<?php
/**
 * Bootstrap: hooks, admin menu, assets, plugin-row links, WooCommerce
 * product-screen integration, cron and downloads.
 *
 * @package RarStudio
 */

namespace RarStudio;

use RarStudio\Admin\Page;
use RarStudio\Bulk\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	/**
	 * Admin pages: key => [menu label, page title].
	 *
	 * @return array
	 */
	public static function pages() {
		return array(
			'dashboard' => array( __( 'Dashboard', 'rar-wp-commerce-studio-ai' ), __( 'Dashboard', 'rar-wp-commerce-studio-ai' ) ),
			'products'  => array( __( 'Product Studio', 'rar-wp-commerce-studio-ai' ), __( 'Product Studio', 'rar-wp-commerce-studio-ai' ) ),
			'images'    => array( __( 'Image Studio', 'rar-wp-commerce-studio-ai' ), __( 'Image Studio', 'rar-wp-commerce-studio-ai' ) ),
			'content'   => array( __( 'Content Studio', 'rar-wp-commerce-studio-ai' ), __( 'Content Studio', 'rar-wp-commerce-studio-ai' ) ),
			'seo'       => array( __( 'SEO Studio', 'rar-wp-commerce-studio-ai' ), __( 'SEO Studio', 'rar-wp-commerce-studio-ai' ) ),
			'bulk'      => array( __( 'Bulk Manager', 'rar-wp-commerce-studio-ai' ), __( 'Bulk Manager', 'rar-wp-commerce-studio-ai' ) ),
			'ai'        => array( __( 'AI Providers', 'rar-wp-commerce-studio-ai' ), __( 'AI Providers', 'rar-wp-commerce-studio-ai' ) ),
			'settings'  => array( __( 'Settings', 'rar-wp-commerce-studio-ai' ), __( 'Settings', 'rar-wp-commerce-studio-ai' ) ),
			'logs'      => array( __( 'Activity Logs', 'rar-wp-commerce-studio-ai' ), __( 'Activity Logs', 'rar-wp-commerce-studio-ai' ) ),
		);
	}

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RAR_STUDIO_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'rar_studio_bulk_tick', array( __CLASS__, 'cron_tick' ) );
		add_action( 'rar_studio_daily', array( __CLASS__, 'daily' ) );
		add_action( 'admin_post_rar_studio_job_report', array( __CLASS__, 'download_job_report' ) );
		add_action( 'admin_post_rar_studio_logs_export', array( __CLASS__, 'download_logs' ) );
		// A permanently deleted item takes its drafts (and unused generated images) with it.
		add_action( 'deleted_post', array( Drafts::class, 'purge_object' ), 10, 2 );

		// WooCommerce product screens.
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'product_metabox' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 20, 2 );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
	}

	/**
	 * Translations.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'rar-wp-commerce-studio-ai', false, dirname( plugin_basename( RAR_STUDIO_FILE ) ) . '/languages' );
	}

	/**
	 * Menu + submenus. The top-level slug is unchanged from v0.1.x.
	 *
	 * @return void
	 */
	public static function menu() {
		$cap = Util::studio_cap();
		if ( ! Util::can_use_studio() ) {
			return;
		}
		if ( ! current_user_can( $cap ) ) {
			$cap = 'manage_options';
		}
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1l2.2 4.9L17 8l-4.8 2.1L10 15l-2.2-4.9L3 8l4.8-2.1L10 1zm6 11l1 2.2 2 .8-2 .9-1 2.1-1-2.1-2-.9 2-.8 1-2.2z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		add_menu_page( __( 'Commerce Studio AI', 'rar-wp-commerce-studio-ai' ), __( 'Commerce Studio', 'rar-wp-commerce-studio-ai' ), $cap, RAR_STUDIO_SLUG, array( Page::class, 'render' ), $icon, 56 );
		foreach ( self::pages() as $key => $labels ) {
			add_submenu_page( RAR_STUDIO_SLUG, $labels[1] . ' · Commerce Studio', $labels[0], $cap, 'dashboard' === $key ? RAR_STUDIO_SLUG : 'rar-studio-' . $key, array( Page::class, 'render' ) );
		}
	}

	/**
	 * Current Studio page key from the request ('' when not a Studio page).
	 *
	 * @return string
	 */
	public static function current_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( RAR_STUDIO_SLUG === $page ) {
			return 'dashboard';
		}
		if ( 0 === strpos( $page, 'rar-studio-' ) ) {
			$key = substr( $page, strlen( 'rar-studio-' ) );
			return array_key_exists( $key, self::pages() ) ? $key : '';
		}
		return '';
	}

	/**
	 * Body class used to scope full-bleed styling.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		if ( self::current_page() ) {
			$theme    = get_user_meta( get_current_user_id(), 'rar_studio_theme', true );
			$classes .= ' rss-admin rss-theme-' . ( 'light' === $theme ? 'light' : 'dark' );
		}
		return $classes;
	}

	/**
	 * Enqueue assets only on Studio pages (and a tiny style on product edit).
	 *
	 * @param string $hook Hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		$page = self::current_page();
		if ( ! $page ) {
			if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && 'product' === get_post_type() ) {
				wp_enqueue_style( 'rar-studio-metabox', RAR_STUDIO_URL . 'assets/css/metabox.css', array(), RAR_STUDIO_VERSION );
			}
			return;
		}
		wp_enqueue_style( 'rar-studio', RAR_STUDIO_URL . 'assets/css/studio.css', array(), RAR_STUDIO_VERSION );
		wp_enqueue_script( 'rar-studio-core', RAR_STUDIO_URL . 'assets/js/studio-core.js', array(), RAR_STUDIO_VERSION, true );
		wp_enqueue_script( 'rar-studio-studios', RAR_STUDIO_URL . 'assets/js/studio-studios.js', array( 'rar-studio-core' ), RAR_STUDIO_VERSION, true );
		wp_enqueue_script( 'rar-studio', RAR_STUDIO_URL . 'assets/js/studio-admin.js', array( 'rar-studio-studios' ), RAR_STUDIO_VERSION, true );
		if ( in_array( $page, array( 'images', 'products' ), true ) ) {
			wp_enqueue_media();
		}
		$theme = get_user_meta( get_current_user_id(), 'rar_studio_theme', true );
		wp_add_inline_script(
			'rar-studio-core',
			'window.RSS_BOOT = ' . wp_json_encode(
				array(
					'page'      => $page,
					'rest'      => esc_url_raw( rest_url( Rest::NS ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'version'   => RAR_STUDIO_VERSION,
					'theme'     => 'light' === $theme ? 'light' : 'dark',
					'admin'     => current_user_can( 'manage_options' ),
					'canAi'     => Util::can_use_ai(),
					'wc'        => Util::wc_active(),
					'brand'     => taxonomy_exists( 'product_brand' ),
					'urls'      => array_combine(
						array_keys( self::pages() ),
						array_map(
							static function ( $k ) {
								return Util::page_url( $k );
							},
							array_keys( self::pages() )
						)
					),
					'adminPost' => admin_url( 'admin-post.php' ),
					'exportNonce' => wp_create_nonce( 'rar_studio_export' ),
					'settings'  => array(
						'language' => Settings::get( 'general.language', 'en' ),
						'image'    => Settings::get( 'image', array() ),
						'seo'      => array(
							'title_max' => (int) Settings::get( 'seo.title_max', 60 ),
							'meta_max'  => (int) Settings::get( 'seo.meta_max', 155 ),
						),
						'bulk'     => Settings::get( 'bulk', array() ),
					),
					'initial'   => array(
						'id'   => isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						'type' => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						'ids'  => isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Plugins screen: Dashboard | Settings | Deactivate.
	 *
	 * @param array $actions Existing links.
	 * @return array
	 */
	public static function action_links( $actions ) {
		if ( ! Util::can_use_studio() ) {
			return $actions;
		}
		$links = array(
			'rar_studio_dashboard' => '<a href="' . esc_url( Util::page_url( 'dashboard' ) ) . '">' . esc_html__( 'Dashboard', 'rar-wp-commerce-studio-ai' ) . '</a>',
		);
		if ( current_user_can( 'manage_options' ) ) {
			$links['rar_studio_settings'] = '<a href="' . esc_url( Util::page_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'rar-wp-commerce-studio-ai' ) . '</a>';
		}
		return array_merge( $links, $actions );
	}

	/**
	 * Product edit screen box.
	 *
	 * @return void
	 */
	public static function product_metabox() {
		if ( Util::can_use_studio() ) {
			add_meta_box( 'rar-studio-box', __( 'Commerce Studio', 'rar-wp-commerce-studio-ai' ), array( __CLASS__, 'render_metabox' ), 'product', 'side', 'default' );
		}
	}

	/**
	 * Metabox content: quick health + links.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public static function render_metabox( $post ) {
		$id      = (int) $post->ID;
		$words   = Util::word_count( Util::plain( $post->post_content ) );
		$keys    = Seo\Manager::keys();
		$meta    = $keys ? (string) get_post_meta( $id, $keys['description'], true ) : '';
		$thumb   = (int) get_post_thumbnail_id( $id );
		$alt     = $thumb ? (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) : '';
		$pending = Drafts::query(
			array(
				'object_type' => 'product',
				'object_id'   => $id,
				'status'      => 'pending',
				'per_page'    => 1,
			)
		)['total'];
		$checks  = array(
			array( $words >= 80, sprintf( /* translators: %d: words */ __( 'Description: %d words', 'rar-wp-commerce-studio-ai' ), $words ) ),
			array( '' !== trim( $post->post_excerpt ), __( 'Short description', 'rar-wp-commerce-studio-ai' ) ),
			array( ! $keys || '' !== $meta, $keys ? __( 'Meta description', 'rar-wp-commerce-studio-ai' ) : __( 'No SEO plugin detected', 'rar-wp-commerce-studio-ai' ) ),
			array( $thumb && '' !== $alt, $thumb ? __( 'Image alt text', 'rar-wp-commerce-studio-ai' ) : __( 'No product image', 'rar-wp-commerce-studio-ai' ) ),
		);
		echo '<ul class="rss-mb-list">';
		foreach ( $checks as $c ) {
			echo '<li class="' . ( $c[0] ? 'ok' : 'todo' ) . '"><span aria-hidden="true">' . ( $c[0] ? '✓' : '!' ) . '</span> ' . esc_html( $c[1] ) . '</li>';
		}
		echo '</ul>';
		if ( $pending ) {
			/* translators: %d: count */
			echo '<p class="rss-mb-pending">' . esc_html( sprintf( _n( '%d suggestion waiting for review', '%d suggestions waiting for review', $pending, 'rar-wp-commerce-studio-ai' ), $pending ) ) . '</p>';
		}
		echo '<p class="rss-mb-actions"><a class="button button-primary" href="' . esc_url( Util::page_url( 'products', array( 'id' => $id ) ) ) . '">' . esc_html__( 'Open in Product Studio', 'rar-wp-commerce-studio-ai' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( Util::page_url( 'images', array( 'id' => $id ) ) ) . '">' . esc_html__( 'Image Studio', 'rar-wp-commerce-studio-ai' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Suggestions are previews until you approve them. Prices, stock and SKU are never changed.', 'rar-wp-commerce-studio-ai' ) . '</p>';
	}

	/**
	 * "Studio" link on product/post/page rows.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! Util::can_use_studio() || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		if ( 'product' === $post->post_type ) {
			$actions['rar_studio'] = '<a href="' . esc_url( Util::page_url( 'products', array( 'id' => $post->ID ) ) ) . '">' . esc_html__( 'Studio', 'rar-wp-commerce-studio-ai' ) . '</a>';
		} elseif ( in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			$actions['rar_studio'] = '<a href="' . esc_url( Util::page_url( 'seo', array( 'id' => $post->ID, 'type' => $post->post_type ) ) ) . '">' . esc_html__( 'SEO Studio', 'rar-wp-commerce-studio-ai' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Products list bulk action.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		if ( Util::can_use_studio() ) {
			$actions['rar_studio_bulk'] = __( 'Optimise with Commerce Studio', 'rar-wp-commerce-studio-ai' );
		}
		return $actions;
	}

	/**
	 * Send selected products to the Bulk Manager (nothing runs until the user starts a job).
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      IDs.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect, $action, $ids ) {
		if ( 'rar_studio_bulk' !== $action || ! Util::can_use_studio() ) {
			return $redirect;
		}
		return Util::page_url( 'bulk', array( 'ids' => implode( ',', array_slice( array_map( 'absint', (array) $ids ), 0, 500 ) ) ) );
	}

	/**
	 * WP-Cron driver.
	 *
	 * @return void
	 */
	public static function cron_tick() {
		Jobs::tick();
	}

	/**
	 * Daily housekeeping.
	 *
	 * @return void
	 */
	public static function daily() {
		global $wpdb;
		Logger::prune();
		// Close reservations left open by a crashed request.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Ai\Usage::table() . " SET status = 'error', message = 'Abandoned (request did not finish)' WHERE status = 'reserved' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		// Usage rows older than 13 months are no longer needed.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Ai\Usage::table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * CSV download of a job report.
	 *
	 * @return void
	 */
	public static function download_job_report() {
		if ( ! Util::can_use_studio() ) {
			wp_die( esc_html__( 'Not allowed.', 'rar-wp-commerce-studio-ai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rar_studio_export' );
		$job = isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0;
		if ( ! $job || ! Jobs::get( $job ) ) {
			wp_die( esc_html__( 'Job not found.', 'rar-wp-commerce-studio-ai' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="commerce-studio-job-' . $job . '.csv"' );
		echo Jobs::report_csv( $job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * CSV export of the activity log.
	 *
	 * @return void
	 */
	public static function download_logs() {
		if ( ! Util::can_use_studio() ) {
			wp_die( esc_html__( 'Not allowed.', 'rar-wp-commerce-studio-ai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rar_studio_export' );
		$fh = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $fh, array( 'id', 'time_utc', 'level', 'action', 'object_type', 'object_id', 'user', 'message' ), ',', '"', '' );
		$page = 1;
		do {
			$res = Logger::query( array( 'page' => $page, 'per_page' => 200 ) );
			foreach ( $res['items'] as $row ) {
				$msg = preg_match( '/^[=+\-@]/', $row['message'] ) ? "'" . $row['message'] : $row['message'];
				fputcsv( $fh, array( $row['id'], $row['created_at'], $row['level'], $row['action'], $row['object_type'], $row['object_id'], $row['user'], $msg ), ',', '"', '' );
			}
			++$page;
		} while ( count( $res['items'] ) === 200 && $page < 50 );
		rewind( $fh );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="commerce-studio-activity-log.csv"' );
		echo "\xEF\xBB\xBF" . stream_get_contents( $fh ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
