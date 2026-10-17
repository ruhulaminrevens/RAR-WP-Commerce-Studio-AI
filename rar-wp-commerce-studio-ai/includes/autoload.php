<?php
/**
 * PSR-4 style autoloader for the RarStudio namespace.
 *
 * @package RarStudio
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'RarStudio\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		if ( ! preg_match( '#^[A-Za-z0-9/]+$#', $relative ) ) {
			return;
		}
		$file = RAR_STUDIO_DIR . 'includes/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
