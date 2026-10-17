<?php
/**
 * Plugin settings: defaults, read, sanitised update.
 *
 * @package RarStudio
 */

namespace RarStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Central settings store (single non-autoloaded option).
 */
final class Settings {

	const OPTION = 'rar_studio_settings_v1';

	/**
	 * Cached merged settings for the request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Supported AI providers.
	 *
	 * @return string[]
	 */
	public static function providers() {
		return array( 'gemini', 'openai', 'claude' );
	}

	/**
	 * Default settings. Every outbound/AI switch is OFF.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'general'   => array(
				'language'            => 'en',
				'ai_roles'            => 'administrator',
				'log_retention_days'  => 90,
				'delete_on_uninstall' => 0,
			),
			'brand'     => array(
				'name'         => function_exists( 'get_bloginfo' ) ? wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) : '',
				'voice'        => 'Clear, honest, friendly and helpful. Short sentences. No hype.',
				'audience'     => 'Online shoppers',
				'banned'       => '',
				'instructions' => '',
			),
			'seo'       => array(
				'adapter'       => 'auto',
				'title_pattern' => '{name} | {site}',
				'title_max'     => 60,
				'meta_max'      => 155,
			),
			'guard'     => array(
				'strict' => 1,
			),
			'image'     => array(
				'size'       => 800,
				'format'     => 'jpeg',
				'quality'    => 86,
				'padding'    => 6,
				'canvas'     => 'white',
				'background' => '#ffffff',
				'remove_bg'  => 0,
				'tolerance'  => 18,
				'trim'       => 1,
				'sharpen'    => 0,
				'upscale'    => 0,
			),
			'bulk'      => array(
				'batch_size'  => 10,
				'time_budget' => 15,
			),
			'ai'        => array(
				'enabled'           => 0,
				'acknowledged_at'   => 0,
				'acknowledged_by'   => 0,
				'primary'           => 'gemini',
				'fallback'          => 0,
				'daily_limit'       => 20,
				'per_minute'        => 6,
				'monthly_budget'    => 5.0,
				'max_output_tokens' => 1500,
				'image_features'    => 0,
				'image_price'       => 0.04,
				'providers'         => array(
					'gemini' => array(
						'enabled'     => 0,
						'model'       => 'gemini-3.5-flash-lite',
						'image_model' => 'gemini-3.1-flash-lite-image',
						'price_in'    => 0.30,
						'price_out'   => 2.50,
						'free_tier'   => 0,
						'key'         => '',
					),
					'openai' => array(
						'enabled'     => 0,
						'model'       => 'gpt-5-nano',
						'image_model' => 'gpt-image-1',
						'price_in'    => 0.05,
						'price_out'   => 0.40,
						'free_tier'   => 0,
						'key'         => '',
					),
					'claude' => array(
						'enabled'     => 0,
						'model'       => 'claude-haiku-5-5',
						'image_model' => '',
						'price_in'    => 0.10,
						'price_out'   => 0.50,
						'free_tier'   => 0,
						'key'         => '',
					),
				),
			),
			'templates' => array(),
		);
	}

	/**
	 * Recursive merge: stored values override defaults, unknown keys dropped.
	 *
	 * @param array $defaults Defaults.
	 * @param array $stored   Stored values.
	 * @return array
	 */
	private static function merge( array $defaults, array $stored ) {
		$out = $defaults;
		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}
			if ( is_array( $value ) && is_array( $stored[ $key ] ) && 'templates' !== $key ) {
				$out[ $key ] = self::merge( $value, $stored[ $key ] );
			} elseif ( ! is_array( $value ) || 'templates' === $key ) {
				$out[ $key ] = $stored[ $key ];
			}
		}
		return $out;
	}

	/**
	 * All settings.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * Dotted-path getter, e.g. get( 'ai.providers.gemini.model' ).
	 *
	 * @param string $path    Path.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $path, $default = null ) {
		$node = self::all();
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return $default;
			}
			$node = $node[ $part ];
		}
		return $node;
	}

	/**
	 * Persist a full settings array (already sanitised).
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	public static function save( array $settings ) {
		$settings = self::merge( self::defaults(), $settings );
		update_option( self::OPTION, $settings, false );
		self::$cache = null;
	}

	/**
	 * Reset request cache (tests / after external writes).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Settings safe to send to the browser (keys removed).
	 *
	 * @return array
	 */
	public static function public_view() {
		$all = self::all();
		foreach ( self::providers() as $provider ) {
			$all['ai']['providers'][ $provider ]['key'] = '';
		}
		return $all;
	}

	/**
	 * Sanitise a partial update coming from the Settings / Providers screens.
	 * AI master switch and API keys are NOT changed here (separate audited actions).
	 *
	 * @param array $input Raw input.
	 * @return array Updated full settings.
	 */
	public static function sanitize_update( array $input ) {
		$s = self::all();

		if ( isset( $input['general'] ) && is_array( $input['general'] ) ) {
			$g = $input['general'];
			if ( isset( $g['language'] ) && in_array( $g['language'], array( 'en', 'bn', 'bilingual' ), true ) ) {
				$s['general']['language'] = $g['language'];
			}
			if ( isset( $g['ai_roles'] ) && in_array( $g['ai_roles'], array( 'administrator', 'shop_manager' ), true ) ) {
				$s['general']['ai_roles'] = $g['ai_roles'];
			}
			if ( isset( $g['log_retention_days'] ) ) {
				$s['general']['log_retention_days'] = max( 7, min( 365, absint( $g['log_retention_days'] ) ) );
			}
			if ( isset( $g['delete_on_uninstall'] ) ) {
				$s['general']['delete_on_uninstall'] = empty( $g['delete_on_uninstall'] ) ? 0 : 1;
			}
		}

		if ( isset( $input['brand'] ) && is_array( $input['brand'] ) ) {
			$b = $input['brand'];
			foreach ( array( 'name' => 120, 'audience' => 200 ) as $k => $max ) {
				if ( isset( $b[ $k ] ) ) {
					$s['brand'][ $k ] = Util::limit( sanitize_text_field( (string) $b[ $k ] ), $max );
				}
			}
			foreach ( array( 'voice' => 600, 'banned' => 1000, 'instructions' => 1500 ) as $k => $max ) {
				if ( isset( $b[ $k ] ) ) {
					$s['brand'][ $k ] = Util::limit( sanitize_textarea_field( (string) $b[ $k ] ), $max );
				}
			}
		}

		if ( isset( $input['seo'] ) && is_array( $input['seo'] ) ) {
			$seo = $input['seo'];
			if ( isset( $seo['adapter'] ) && in_array( $seo['adapter'], array( 'auto', 'yoast', 'rankmath', 'seopress', 'none' ), true ) ) {
				$s['seo']['adapter'] = $seo['adapter'];
			}
			if ( isset( $seo['title_pattern'] ) ) {
				$pattern                     = Util::limit( sanitize_text_field( (string) $seo['title_pattern'] ), 80 );
				$s['seo']['title_pattern'] = false !== strpos( $pattern, '{name}' ) ? $pattern : '{name} | {site}';
			}
			if ( isset( $seo['title_max'] ) ) {
				$s['seo']['title_max'] = max( 30, min( 80, absint( $seo['title_max'] ) ) );
			}
			if ( isset( $seo['meta_max'] ) ) {
				$s['seo']['meta_max'] = max( 70, min( 320, absint( $seo['meta_max'] ) ) );
			}
		}

		if ( isset( $input['guard']['strict'] ) ) {
			$s['guard']['strict'] = empty( $input['guard']['strict'] ) ? 0 : 1;
		}

		if ( isset( $input['image'] ) && is_array( $input['image'] ) ) {
			$s['image'] = Image\Processor::sanitize_options( array_merge( $s['image'], $input['image'] ) );
		}

		if ( isset( $input['bulk'] ) && is_array( $input['bulk'] ) ) {
			if ( isset( $input['bulk']['batch_size'] ) ) {
				$s['bulk']['batch_size'] = max( 1, min( 50, absint( $input['bulk']['batch_size'] ) ) );
			}
			if ( isset( $input['bulk']['time_budget'] ) ) {
				$s['bulk']['time_budget'] = max( 5, min( 25, absint( $input['bulk']['time_budget'] ) ) );
			}
		}

		if ( isset( $input['ai'] ) && is_array( $input['ai'] ) ) {
			$ai = $input['ai'];
			if ( isset( $ai['primary'] ) && in_array( $ai['primary'], self::providers(), true ) ) {
				$s['ai']['primary'] = $ai['primary'];
			}
			if ( isset( $ai['fallback'] ) ) {
				$s['ai']['fallback'] = empty( $ai['fallback'] ) ? 0 : 1;
			}
			if ( isset( $ai['image_features'] ) ) {
				$s['ai']['image_features'] = empty( $ai['image_features'] ) ? 0 : 1;
			}
			if ( isset( $ai['daily_limit'] ) ) {
				$s['ai']['daily_limit'] = max( 1, min( 500, absint( $ai['daily_limit'] ) ) );
			}
			if ( isset( $ai['per_minute'] ) ) {
				$s['ai']['per_minute'] = max( 1, min( 60, absint( $ai['per_minute'] ) ) );
			}
			if ( isset( $ai['monthly_budget'] ) ) {
				$s['ai']['monthly_budget'] = round( max( 0, min( 1000, (float) $ai['monthly_budget'] ) ), 2 );
			}
			if ( isset( $ai['max_output_tokens'] ) ) {
				$s['ai']['max_output_tokens'] = max( 200, min( 8000, absint( $ai['max_output_tokens'] ) ) );
			}
			if ( isset( $ai['image_price'] ) ) {
				$s['ai']['image_price'] = round( max( 0, min( 5, (float) $ai['image_price'] ) ), 4 );
			}
			if ( isset( $ai['providers'] ) && is_array( $ai['providers'] ) ) {
				foreach ( self::providers() as $p ) {
					if ( empty( $ai['providers'][ $p ] ) || ! is_array( $ai['providers'][ $p ] ) ) {
						continue;
					}
					$in = $ai['providers'][ $p ];
					if ( isset( $in['enabled'] ) ) {
						$s['ai']['providers'][ $p ]['enabled'] = empty( $in['enabled'] ) ? 0 : 1;
					}
					if ( isset( $in['free_tier'] ) ) {
						$s['ai']['providers'][ $p ]['free_tier'] = empty( $in['free_tier'] ) ? 0 : 1;
					}
					foreach ( array( 'model', 'image_model' ) as $mk ) {
						if ( isset( $in[ $mk ] ) ) {
							$model = trim( sanitize_text_field( (string) $in[ $mk ] ) );
							if ( '' === $model || preg_match( '/^[A-Za-z0-9._:\/-]{2,100}$/D', $model ) ) {
								$s['ai']['providers'][ $p ][ $mk ] = $model;
							}
						}
					}
					foreach ( array( 'price_in', 'price_out' ) as $pk ) {
						if ( isset( $in[ $pk ] ) ) {
							$s['ai']['providers'][ $p ][ $pk ] = round( max( 0, min( 500, (float) $in[ $pk ] ) ), 4 );
						}
					}
				}
			}
		}

		if ( isset( $input['templates'] ) && is_array( $input['templates'] ) ) {
			foreach ( $input['templates'] as $id => $text ) {
				$id = sanitize_key( $id );
				if ( ! array_key_exists( $id, Content\Prompts::defaults() ) ) {
					continue;
				}
				$text = (string) $text;
				if ( '' === trim( $text ) ) {
					unset( $s['templates'][ $id ] );
				} else {
					// Templates are prompt text (may mention <h2>, <p>…); they are never rendered as HTML.
					$s['templates'][ $id ] = Util::limit( str_replace( "\0", '', wp_check_invalid_utf8( $text ) ), 6000 );
				}
			}
		}

		self::save( $s );
		return self::all();
	}
}
