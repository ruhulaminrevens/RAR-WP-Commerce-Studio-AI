<?php
/**
 * Encryption for API keys stored in the database.
 *
 * Keys defined as wp-config.php constants always win and are never stored.
 * Database keys are encrypted with libsodium (bundled with WordPress via
 * sodium_compat) using a key derived from the site's AUTH salt, so a database
 * dump alone does not reveal them.
 *
 * @package RarStudio
 */

namespace RarStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Secretbox wrapper.
 */
final class Crypto {

	const PREFIX = 'rss1:';

	/**
	 * Derived 32-byte key.
	 *
	 * @return string
	 */
	private static function key() {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'rar-studio';
		if ( defined( 'RAR_STUDIO_ENCRYPTION_KEY' ) && is_string( RAR_STUDIO_ENCRYPTION_KEY ) && '' !== RAR_STUDIO_ENCRYPTION_KEY ) {
			$salt = RAR_STUDIO_ENCRYPTION_KEY;
		}
		return sodium_crypto_generichash( 'rar-studio-api-key|' . $salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Is encryption available?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_generichash' );
	}

	/**
	 * Encrypt a secret.
	 *
	 * @param string $plain Secret.
	 * @return string Empty string on failure.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || ! self::available() ) {
			return '';
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
			return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Decrypt a secret. Returns '' when the salt changed or data is corrupt.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, self::PREFIX ) || ! self::available() ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key()
			);
			return false === $plain ? '' : $plain;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Masked representation for UI, e.g. "••••7f2c".
	 *
	 * @param string $key Plain key.
	 * @return string
	 */
	public static function mask( $key ) {
		$key = (string) $key;
		if ( strlen( $key ) < 8 ) {
			return '••••';
		}
		return '••••' . substr( $key, -4 );
	}
}
