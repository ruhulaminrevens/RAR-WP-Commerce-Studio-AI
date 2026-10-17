<?php
/**
 * Provider registry. Third-party code may add adapters:
 *
 *     add_filter( 'rar_studio_ai_providers', function ( $providers ) {
 *         $providers['mistral'] = new My_Mistral_Adapter(); // extends RarStudio\Ai\Provider
 *         return $providers;
 *     } );
 *
 * @package RarStudio
 */

namespace RarStudio\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Registry.
 */
final class Registry {

	/**
	 * Instances.
	 *
	 * @var Provider[]|null
	 */
	private static $providers = null;

	/**
	 * All providers keyed by id.
	 *
	 * @return Provider[]
	 */
	public static function all() {
		if ( null === self::$providers ) {
			$list = array(
				'gemini' => new Gemini(),
				'openai' => new OpenAI(),
				'claude' => new Claude(),
			);
			$list = (array) apply_filters( 'rar_studio_ai_providers', $list );
			foreach ( $list as $id => $provider ) {
				if ( ! $provider instanceof Provider ) {
					unset( $list[ $id ] );
				}
			}
			self::$providers = $list;
		}
		return self::$providers;
	}

	/**
	 * One provider.
	 *
	 * @param string $id Provider id.
	 * @return Provider|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Reset (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$providers = null;
	}
}
