<?php
/**
 * SEO plugin adapters (Yoast SEO, Rank Math, SEOPress) behind one interface.
 * Other plugins can register adapters with the `rar_studio_seo_adapters` filter.
 *
 * @package RarStudio
 */

namespace RarStudio\Seo;

use RarStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * SEO adapter manager.
 */
final class Manager {

	/**
	 * Registered adapters.
	 *
	 * Each adapter: label, detected (bool), keys => [title, description, focus]
	 * Optional callbacks: get( $post_id, $field ), set( $post_id, $field, $value ).
	 *
	 * @return array
	 */
	public static function adapters() {
		$adapters = array(
			'yoast'    => array(
				'label'    => 'Yoast SEO',
				'detected' => defined( 'WPSEO_VERSION' ),
				'keys'     => array(
					'title'       => '_yoast_wpseo_title',
					'description' => '_yoast_wpseo_metadesc',
					'focus'       => '_yoast_wpseo_focuskw',
				),
			),
			'rankmath' => array(
				'label'    => 'Rank Math',
				'detected' => defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ),
				'keys'     => array(
					'title'       => 'rank_math_title',
					'description' => 'rank_math_description',
					'focus'       => 'rank_math_focus_keyword',
				),
			),
			'seopress' => array(
				'label'    => 'SEOPress',
				'detected' => defined( 'SEOPRESS_VERSION' ),
				'keys'     => array(
					'title'       => '_seopress_titles_title',
					'description' => '_seopress_titles_desc',
					'focus'       => '_seopress_analysis_target_kw',
				),
			),
		);
		/**
		 * Filter SEO adapters. Add an entry with label, detected and keys
		 * (or get/set callbacks) to support another SEO plugin.
		 *
		 * @param array $adapters Adapters.
		 */
		return (array) apply_filters( 'rar_studio_seo_adapters', $adapters );
	}

	/**
	 * Active adapter id or '' when no SEO plugin is configured/detected.
	 *
	 * @return string
	 */
	public static function active() {
		$choice   = (string) Settings::get( 'seo.adapter', 'auto' );
		$adapters = self::adapters();
		if ( 'none' === $choice ) {
			return '';
		}
		if ( 'auto' !== $choice ) {
			return isset( $adapters[ $choice ] ) ? $choice : '';
		}
		foreach ( $adapters as $id => $adapter ) {
			if ( ! empty( $adapter['detected'] ) ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Status for the UI.
	 *
	 * @return array
	 */
	public static function status() {
		$detected = array();
		foreach ( self::adapters() as $id => $a ) {
			if ( ! empty( $a['detected'] ) ) {
				$detected[] = $id;
			}
		}
		$active   = self::active();
		$adapters = self::adapters();
		return array(
			'active'   => $active,
			'label'    => $active ? $adapters[ $active ]['label'] : '',
			'detected' => $detected,
			'choice'   => Settings::get( 'seo.adapter', 'auto' ),
			'warning'  => $active && empty( $adapters[ $active ]['detected'] ) ? 'The selected SEO plugin is not active. Values are saved to its fields and take effect once the plugin is activated.' : '',
		);
	}

	/**
	 * Read an SEO field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   title|description|focus.
	 * @param string $adapter Adapter id (defaults to active).
	 * @return string
	 */
	public static function get( $post_id, $field, $adapter = '' ) {
		$adapter  = $adapter ? $adapter : self::active();
		$adapters = self::adapters();
		if ( ! $adapter || ! isset( $adapters[ $adapter ] ) ) {
			return '';
		}
		$a = $adapters[ $adapter ];
		if ( isset( $a['get'] ) && is_callable( $a['get'] ) ) {
			return (string) call_user_func( $a['get'], $post_id, $field );
		}
		if ( empty( $a['keys'][ $field ] ) ) {
			return '';
		}
		return (string) get_post_meta( (int) $post_id, $a['keys'][ $field ], true );
	}

	/**
	 * Write an SEO field. Empty value removes the meta (restores "not set").
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   title|description|focus.
	 * @param string $value   Value.
	 * @param string $adapter Adapter id.
	 * @return true|\WP_Error
	 */
	public static function set( $post_id, $field, $value, $adapter = '' ) {
		$adapter  = $adapter ? $adapter : self::active();
		$adapters = self::adapters();
		if ( ! $adapter || ! isset( $adapters[ $adapter ] ) ) {
			return new \WP_Error( 'rar_no_seo_plugin', __( 'No SEO plugin is active or selected. Choose one in Settings → SEO.', 'rar-wp-commerce-studio-ai' ) );
		}
		$a     = $adapters[ $adapter ];
		$value = sanitize_text_field( (string) $value );
		if ( isset( $a['set'] ) && is_callable( $a['set'] ) ) {
			$result = call_user_func( $a['set'], $post_id, $field, $value );
			return is_wp_error( $result ) ? $result : true;
		}
		if ( empty( $a['keys'][ $field ] ) ) {
			return new \WP_Error( 'rar_seo_field', __( 'This SEO plugin does not support that field.', 'rar-wp-commerce-studio-ai' ) );
		}
		if ( '' === $value ) {
			delete_post_meta( (int) $post_id, $a['keys'][ $field ] );
		} else {
			update_post_meta( (int) $post_id, $a['keys'][ $field ], $value );
		}
		return true;
	}

	/**
	 * Meta keys for the active adapter (used for bulk "missing" filters).
	 *
	 * @return array
	 */
	public static function keys() {
		$adapter  = self::active();
		$adapters = self::adapters();
		return $adapter && ! empty( $adapters[ $adapter ]['keys'] ) ? $adapters[ $adapter ]['keys'] : array();
	}
}
