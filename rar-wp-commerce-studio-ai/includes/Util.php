<?php
/**
 * Small shared helpers (text, time, permissions).
 *
 * @package RarStudio
 */

namespace RarStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers.
 */
final class Util {

	/**
	 * Multibyte-safe length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function len( $text ) {
		$text = (string) $text;
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Multibyte-safe substring.
	 *
	 * @param string   $text  Text.
	 * @param int      $start Start.
	 * @param int|null $len   Length.
	 * @return string
	 */
	public static function sub( $text, $start, $len = null ) {
		$text = (string) $text;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, $start, $len, 'UTF-8' );
		}
		return null === $len ? substr( $text, $start ) : substr( $text, $start, $len );
	}

	/**
	 * Multibyte-safe lowercase.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	/**
	 * Case-insensitive multibyte strpos.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return int|false
	 */
	public static function ipos( $haystack, $needle ) {
		if ( '' === (string) $needle ) {
			return false;
		}
		if ( function_exists( 'mb_stripos' ) ) {
			return mb_stripos( (string) $haystack, (string) $needle, 0, 'UTF-8' );
		}
		return stripos( (string) $haystack, (string) $needle );
	}

	/**
	 * Hard character limit.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function limit( $text, $max ) {
		$text = (string) $text;
		return self::len( $text ) > $max ? self::sub( $text, 0, $max ) : $text;
	}

	/**
	 * Trim to a limit at a word boundary, without an ellipsis.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function trim_words( $text, $max ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( self::len( $text ) <= $max ) {
			return $text;
		}
		$cut   = self::sub( $text, 0, $max + 1 );
		$space = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ', 0, 'UTF-8' ) : strrpos( $cut, ' ' );
		if ( false !== $space && $space > $max * 0.6 ) {
			$cut = self::sub( $cut, 0, $space );
		} else {
			$cut = self::sub( $text, 0, $max );
		}
		return rtrim( $cut, " \t\n\r\0\x0B,;:-–—|" );
	}

	/**
	 * Plain text from HTML (block comments and shortcodes removed).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function plain( $html ) {
		$html = (string) $html;
		$html = preg_replace( '/<!--.*?-->/s', ' ', $html );
		$html = preg_replace( '/\[\/?[a-zA-Z0-9_\-]+[^\]]*\]/', ' ', $html );
		$html = preg_replace( '/<(br|\/p|\/li|\/h[1-6]|\/tr|\/div)[^>]*>/i', "$0\n", $html );
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		$text = preg_replace( "/\s*\n\s*/u", "\n", $text );
		return trim( (string) $text );
	}

	/**
	 * Single-line plain text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function line( $html ) {
		return trim( preg_replace( '/\s+/u', ' ', self::plain( $html ) ) );
	}

	/**
	 * Convert Bangla digits to ASCII digits.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function ascii_digits( $text ) {
		return strtr(
			(string) $text,
			array(
				'০' => '0',
				'১' => '1',
				'২' => '2',
				'৩' => '3',
				'৪' => '4',
				'৫' => '5',
				'৬' => '6',
				'৭' => '7',
				'৮' => '8',
				'৯' => '9',
			)
		);
	}

	/**
	 * Word count that works for Bangla and English.
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	public static function word_count( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return 0;
		}
		return (int) preg_match_all( '/[\p{L}\p{M}\p{N}][\p{L}\p{M}\p{N}\'’\-]*/u', $text );
	}

	/**
	 * Split plain text into sentences (handles the Bangla dari "।").
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	public static function sentences( $text ) {
		$text  = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		$parts = preg_split( '/(?<=[.!?।])\s+(?=[\p{Lu}\p{Bengali}0-9"“\'])/u', $text );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$p = trim( $p );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Stable hash of a value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function hash( $value ) {
		return hash( 'sha256', is_string( $value ) ? $value : (string) wp_json_encode( $value ) );
	}

	/**
	 * Whether the plugin can use WooCommerce.
	 *
	 * @return bool
	 */
	public static function wc_active() {
		return function_exists( 'wc_get_product' ) && class_exists( 'WooCommerce' );
	}

	/**
	 * Capability required to open the Studio.
	 *
	 * @return string
	 */
	public static function studio_cap() {
		return self::wc_active() ? 'manage_woocommerce' : 'edit_others_posts';
	}

	/**
	 * Can the current user open the Studio?
	 *
	 * @return bool
	 */
	public static function can_use_studio() {
		return current_user_can( self::studio_cap() ) || current_user_can( 'manage_options' );
	}

	/**
	 * Can the current user edit a specific object?
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object ID.
	 * @return bool
	 */
	public static function can_edit_object( $type, $id ) {
		$id = absint( $id );
		if ( ! $id || ! self::can_use_studio() ) {
			return false;
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return false;
		}
		$expected = array(
			'product'    => array( 'product' ),
			'post'       => array( 'post' ),
			'page'       => array( 'page' ),
			'attachment' => array( 'attachment' ),
		);
		if ( ! isset( $expected[ $type ] ) || ! in_array( $post->post_type, $expected[ $type ], true ) ) {
			return false;
		}
		return current_user_can( 'edit_post', $id );
	}

	/**
	 * Can the current user start AI (possibly billable) requests?
	 *
	 * @return bool
	 */
	public static function can_use_ai() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return 'shop_manager' === Settings::get( 'general.ai_roles' ) && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Admin page URL.
	 *
	 * @param string $page Page key.
	 * @param array  $args Extra args.
	 * @return string
	 */
	public static function page_url( $page = 'dashboard', array $args = array() ) {
		$slug = 'dashboard' === $page ? RAR_STUDIO_SLUG : 'rar-studio-' . $page;
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Decode JSON safely to an array.
	 *
	 * @param string|null $json JSON.
	 * @return array
	 */
	public static function json_array( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}
}
