<?php
/**
 * AI gateway: the ONLY path to outbound AI requests. Enforces the master
 * switch (default OFF), role permission, per-provider enable, key presence,
 * limits/budget, fallback, usage logging and activity logging.
 *
 * @package RarStudio
 */

namespace RarStudio\Ai;

use RarStudio\Crypto;
use RarStudio\Logger;
use RarStudio\Settings;
use RarStudio\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Gateway.
 */
final class Gateway {

	/**
	 * Provider status for the UI (no secrets).
	 *
	 * @return array
	 */
	public static function status() {
		$out = array(
			'enabled'         => (int) Settings::get( 'ai.enabled', 0 ),
			'acknowledged_at' => (int) Settings::get( 'ai.acknowledged_at', 0 ),
			'primary'         => (string) Settings::get( 'ai.primary', 'gemini' ),
			'can_use'         => Util::can_use_ai(),
			'image_features'  => (int) Settings::get( 'ai.image_features', 0 ),
			'encryption'      => Crypto::available(),
			'providers'       => array(),
		);
		foreach ( Registry::all() as $id => $provider ) {
			$cfg                     = $provider->config();
			$source                  = $provider->key_source();
			$key                     = in_array( $source, array( 'constant', 'database' ), true ) ? $provider->key() : '';
			$out['providers'][ $id ] = array(
				'id'           => $id,
				'label'        => $provider->label(),
				'enabled'      => (int) ( $cfg['enabled'] ?? 0 ),
				'model'        => (string) ( $cfg['model'] ?? '' ),
				'image_model'  => (string) ( $cfg['image_model'] ?? '' ),
				'price_in'     => (float) ( $cfg['price_in'] ?? 0 ),
				'price_out'    => (float) ( $cfg['price_out'] ?? 0 ),
				'free_tier'    => (int) ( $cfg['free_tier'] ?? 0 ),
				'key_source'   => $source,
				'key_mask'     => $key ? Crypto::mask( $key ) : '',
				'key_constant' => $provider->key_constant(),
				'ready'        => (int) ( ! empty( $cfg['enabled'] ) && '' !== $key ),
				'image'        => method_exists( $provider, 'generate_image' ) && in_array( $id, array( 'gemini', 'openai' ), true ),
			);
		}
		return $out;
	}

	/**
	 * Can an AI request be made right now? Returns '' or a reason.
	 *
	 * @return string
	 */
	public static function unavailable_reason() {
		if ( ! Util::can_use_ai() ) {
			return __( 'Your role is not allowed to run AI API requests (Settings → General).', 'rar-wp-commerce-studio-ai' );
		}
		if ( ! Settings::get( 'ai.enabled', 0 ) ) {
			return __( 'AI API is OFF. An administrator can enable it in AI Providers.', 'rar-wp-commerce-studio-ai' );
		}
		if ( ! self::ready_providers() ) {
			return __( 'No AI provider is enabled with a working key.', 'rar-wp-commerce-studio-ai' );
		}
		return '';
	}

	/**
	 * Enabled providers with a key, primary first.
	 *
	 * @param string $kind text|image.
	 * @return string[]
	 */
	public static function ready_providers( $kind = 'text' ) {
		$primary = (string) Settings::get( 'ai.primary', 'gemini' );
		$ready   = array();
		foreach ( Registry::all() as $id => $provider ) {
			$cfg = $provider->config();
			if ( empty( $cfg['enabled'] ) || '' === $provider->key() ) {
				continue;
			}
			if ( 'image' === $kind && ( '' === (string) ( $cfg['image_model'] ?? '' ) || ! in_array( $id, array( 'gemini', 'openai' ), true ) ) ) {
				continue;
			}
			$ready[] = $id;
		}
		usort(
			$ready,
			static function ( $a, $b ) use ( $primary ) {
				return ( $b === $primary ) <=> ( $a === $primary );
			}
		);
		return $ready;
	}

	/**
	 * Estimated cost of a request before sending (for confirmations).
	 *
	 * @param array  $request  Request.
	 * @param string $provider Provider (default primary-ready).
	 * @return array provider, model, input_tokens, output_tokens, cost
	 */
	public static function estimate( array $request, $provider = '' ) {
		$ready    = self::ready_providers();
		$provider = $provider ? $provider : ( $ready ? $ready[0] : (string) Settings::get( 'ai.primary' ) );
		$tokens   = Usage::estimate_tokens( $request );
		return array(
			'provider'      => $provider,
			'model'         => (string) Settings::get( 'ai.providers.' . $provider . '.model', '' ),
			'input_tokens'  => $tokens['in'],
			'output_tokens' => $tokens['out'],
			'cost'          => Usage::cost( $provider, $tokens['in'], $tokens['out'] ),
		);
	}

	/**
	 * Run a text/vision request.
	 *
	 * @param string $task    Task id (product_content, seo, alt_text…).
	 * @param array  $request system, prompt, images, json.
	 * @param array  $ctx     provider, object_type, object_id.
	 * @return array|\WP_Error text, provider, model, input_tokens, output_tokens, cost.
	 */
	public static function run( $task, array $request, array $ctx = array() ) {
		$reason = self::unavailable_reason();
		if ( $reason ) {
			return new \WP_Error( 'rar_ai_off', $reason, array( 'status' => 403 ) );
		}
		$order = self::ready_providers();
		if ( ! empty( $ctx['provider'] ) ) {
			if ( ! in_array( $ctx['provider'], $order, true ) ) {
				return new \WP_Error( 'rar_ai_provider', __( 'That provider is not enabled or has no key.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
			}
			$order = array_values( array_unique( array_merge( array( $ctx['provider'] ), $order ) ) );
		}
		if ( ! Settings::get( 'ai.fallback', 0 ) ) {
			$order = array_slice( $order, 0, 1 );
		}
		$request['max_tokens'] = (int) Settings::get( 'ai.max_output_tokens', 1500 );
		$last_error            = null;
		foreach ( $order as $pid ) {
			$provider = Registry::get( $pid );
			$model    = (string) Settings::get( 'ai.providers.' . $pid . '.model', '' );
			if ( '' === $model ) {
				$last_error = new \WP_Error( 'rar_ai_model', __( 'No model selected for this provider.', 'rar-wp-commerce-studio-ai' ) );
				continue;
			}
			$request['model'] = $model;
			$tokens           = Usage::estimate_tokens( $request );
			$reservation      = Usage::reserve( $pid, $model, $task, Usage::cost( $pid, $tokens['in'], $tokens['out'] ), $ctx );
			if ( is_wp_error( $reservation ) ) {
				Logger::log( 'ai_blocked', $reservation->get_error_message(), array( 'provider' => $pid, 'task' => $task ), 'warning', $ctx['object_type'] ?? '', $ctx['object_id'] ?? 0 );
				return $reservation;
			}
			$started = microtime( true );
			$result  = $provider->generate( $request );
			$ms      = (int) round( ( microtime( true ) - $started ) * 1000 );
			if ( is_wp_error( $result ) ) {
				Usage::finish( $reservation, 'error', 0, 0, 0, $result->get_error_message(), $ms );
				Logger::log( 'ai_error', $result->get_error_message(), array( 'provider' => $pid, 'model' => $model, 'task' => $task ), 'error', $ctx['object_type'] ?? '', $ctx['object_id'] ?? 0 );
				$last_error = $result;
				$data       = $result->get_error_data();
				if ( empty( $data['retryable'] ) ) {
					return $result;
				}
				continue;
			}
			$cost = Usage::cost( $pid, $result['input_tokens'], $result['output_tokens'] );
			Usage::finish( $reservation, 'ok', $result['input_tokens'], $result['output_tokens'], $cost, '', $ms );
			Logger::log(
				'ai_request',
				sprintf( '%s · %s · %s', $provider->label(), $model, $task ),
				array(
					'in'   => $result['input_tokens'],
					'out'  => $result['output_tokens'],
					'cost' => $cost,
					'ms'   => $ms,
				),
				'info',
				$ctx['object_type'] ?? '',
				$ctx['object_id'] ?? 0
			);
			return array_merge(
				$result,
				array(
					'provider' => $pid,
					'cost'     => $cost,
				)
			);
		}
		return $last_error ? $last_error : new \WP_Error( 'rar_ai_provider', __( 'No AI provider is available.', 'rar-wp-commerce-studio-ai' ) );
	}

	/**
	 * Generate a background scene image (never the product itself).
	 *
	 * @param string $prompt Scene description.
	 * @param array  $ctx    object_type, object_id.
	 * @return array|\WP_Error bytes, mime, provider, cost.
	 */
	public static function image( $prompt, array $ctx = array() ) {
		$reason = self::unavailable_reason();
		if ( $reason ) {
			return new \WP_Error( 'rar_ai_off', $reason, array( 'status' => 403 ) );
		}
		if ( ! Settings::get( 'ai.image_features', 0 ) ) {
			return new \WP_Error( 'rar_ai_off', __( 'AI image features are OFF (AI Providers → Image features).', 'rar-wp-commerce-studio-ai' ), array( 'status' => 403 ) );
		}
		$order = self::ready_providers( 'image' );
		if ( ! $order ) {
			return new \WP_Error( 'rar_ai_provider', __( 'No enabled provider has an image model set (Gemini or OpenAI).', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$pid         = $order[0];
		$model       = (string) Settings::get( 'ai.providers.' . $pid . '.image_model', '' );
		$cost        = Settings::get( 'ai.providers.' . $pid . '.free_tier', 0 ) ? 0.0 : (float) Settings::get( 'ai.image_price', 0.04 );
		$reservation = Usage::reserve( $pid, $model, 'bg_scene', $cost, $ctx );
		if ( is_wp_error( $reservation ) ) {
			return $reservation;
		}
		$started = microtime( true );
		$result  = Registry::get( $pid )->generate_image( $prompt, $model );
		$ms      = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $result ) ) {
			Usage::finish( $reservation, 'error', 0, 0, 0, $result->get_error_message(), $ms );
			Logger::log( 'ai_error', $result->get_error_message(), array( 'provider' => $pid, 'task' => 'bg_scene' ), 'error', $ctx['object_type'] ?? '', $ctx['object_id'] ?? 0 );
			return $result;
		}
		Usage::finish( $reservation, 'ok', 0, 0, $cost, '', $ms );
		Logger::log( 'ai_request', sprintf( '%s · %s · background scene', Registry::get( $pid )->label(), $model ), array( 'cost' => $cost ), 'info', $ctx['object_type'] ?? '', $ctx['object_id'] ?? 0 );
		return array_merge(
			$result,
			array(
				'provider' => $pid,
				'cost'     => $cost,
			)
		);
	}

	/**
	 * Extract a JSON object from model output (handles code fences and chatter).
	 *
	 * @param string $text Model text.
	 * @return array|\WP_Error
	 */
	public static function parse_json( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false !== $start && false !== $end && $end > $start ) {
				$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			}
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'rar_ai_json', __( 'The AI answer was not valid JSON. Try again, or use a different model.', 'rar-wp-commerce-studio-ai' ) );
		}
		return $data;
	}

	/**
	 * Store or remove an API key (encrypted). Constants in wp-config.php take priority.
	 *
	 * @param string $provider Provider.
	 * @param string $key      Plain key ('' removes).
	 * @return true|\WP_Error
	 */
	public static function save_key( $provider, $key ) {
		if ( ! in_array( $provider, Settings::providers(), true ) ) {
			return new \WP_Error( 'rar_ai_provider', __( 'Unknown provider.', 'rar-wp-commerce-studio-ai' ) );
		}
		$key = trim( (string) $key );
		$s   = Settings::all();
		if ( '' === $key ) {
			$s['ai']['providers'][ $provider ]['key'] = '';
			Settings::save( $s );
			Logger::log( 'ai_key_removed', sprintf( 'API key removed for %s.', $provider ), array(), 'security' );
			return true;
		}
		if ( ! preg_match( '/^[\x21-\x7E]{16,300}$/', $key ) ) {
			return new \WP_Error( 'rar_ai_key', __( 'That does not look like an API key.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$encrypted = Crypto::encrypt( $key );
		if ( '' === $encrypted ) {
			return new \WP_Error( 'rar_ai_key', __( 'Encryption is unavailable on this server. Put the key in wp-config.php instead.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 500 ) );
		}
		$s['ai']['providers'][ $provider ]['key'] = $encrypted;
		Settings::save( $s );
		Logger::log( 'ai_key_saved', sprintf( 'API key saved (encrypted) for %s.', $provider ), array(), 'security' );
		return true;
	}

	/**
	 * Turn the master switch on/off. Enabling requires cost acknowledgement.
	 *
	 * @param bool $enable      Enable.
	 * @param bool $acknowledge Cost acknowledgement.
	 * @return true|\WP_Error
	 */
	public static function set_master( $enable, $acknowledge ) {
		$s = Settings::all();
		if ( $enable ) {
			if ( ! $acknowledge ) {
				return new \WP_Error( 'rar_ai_ack', __( 'Confirm that API requests may be billed by the provider before enabling.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
			}
			$s['ai']['enabled']         = 1;
			$s['ai']['acknowledged_at'] = time();
			$s['ai']['acknowledged_by'] = get_current_user_id();
		} else {
			$s['ai']['enabled'] = 0;
		}
		Settings::save( $s );
		Logger::log( $enable ? 'ai_enabled' : 'ai_disabled', $enable ? 'AI API master switch turned ON (cost acknowledged).' : 'AI API master switch turned OFF.', array(), 'security' );
		return true;
	}
}
