<?php
/**
 * Base class for AI provider adapters. New providers extend this class and
 * register through the `rar_studio_ai_providers` filter (see Registry).
 *
 * @package RarStudio
 */

namespace RarStudio\Ai;

use RarStudio\Crypto;
use RarStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Provider adapter contract + shared HTTP handling.
 */
abstract class Provider {

	/**
	 * Machine id (gemini, openai, claude…).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * wp-config.php constant holding the key.
	 *
	 * @return string
	 */
	abstract public function key_constant();

	/**
	 * Text (and optional image) generation.
	 *
	 * @param array $request system, prompt, images[ [mime,data(base64)] ], max_tokens, json(bool), model.
	 * @return array|\WP_Error text, input_tokens, output_tokens, model.
	 */
	abstract public function generate( array $request );

	/**
	 * List model IDs available to this key (free endpoint; no tokens used).
	 *
	 * @return string[]|\WP_Error
	 */
	abstract public function list_models();

	/**
	 * Generate an image (background scenes only). Default: unsupported.
	 *
	 * @param string $prompt Prompt.
	 * @param string $model  Model.
	 * @return array|\WP_Error bytes, mime.
	 */
	public function generate_image( $prompt, $model ) {
		unset( $prompt, $model );
		return new \WP_Error( 'rar_ai_unsupported', __( 'This provider does not support image generation in the Studio.', 'rar-wp-commerce-studio-ai' ) );
	}

	/**
	 * Does the adapter support image input (vision)?
	 *
	 * @return bool
	 */
	public function supports_vision() {
		return true;
	}

	/**
	 * Provider settings.
	 *
	 * @return array
	 */
	public function config() {
		return (array) Settings::get( 'ai.providers.' . $this->id(), array() );
	}

	/**
	 * Where the key comes from: constant | database | none | unreadable.
	 *
	 * @return string
	 */
	public function key_source() {
		$constant = $this->key_constant();
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return 'constant';
		}
		$stored = (string) ( $this->config()['key'] ?? '' );
		if ( '' === $stored ) {
			return 'none';
		}
		return '' === Crypto::decrypt( $stored ) ? 'unreadable' : 'database';
	}

	/**
	 * The API key (never returned to the browser or logged).
	 *
	 * @return string
	 */
	public function key() {
		$constant = $this->key_constant();
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return (string) constant( $constant );
		}
		return Crypto::decrypt( (string) ( $this->config()['key'] ?? '' ) );
	}

	/**
	 * Perform an HTTPS request with one retry on 429/5xx.
	 *
	 * @param string $method  GET|POST.
	 * @param string $url     Fixed provider URL (HTTPS).
	 * @param array  $headers Headers.
	 * @param array  $body    JSON body (POST).
	 * @param int    $timeout Seconds.
	 * @return array|\WP_Error Decoded JSON.
	 */
	protected function request( $method, $url, array $headers, array $body = array(), $timeout = 45 ) {
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return new \WP_Error( 'rar_ai_https', __( 'Provider requests must use HTTPS.', 'rar-wp-commerce-studio-ai' ) );
		}
		$args = array(
			'method'              => $method,
			'headers'             => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'timeout'             => $timeout,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => 25 * 1024 * 1024,
			'user-agent'          => 'RAR-Commerce-Studio/' . RAR_STUDIO_VERSION . '; ' . home_url( '/' ),
		);
		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( $body );
		}
		$attempt = 0;
		do {
			++$attempt;
			$response = wp_remote_request( $url, $args );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$retry    = ( is_wp_error( $response ) || 429 === $code || $code >= 500 ) && $attempt < 2;
			if ( $retry ) {
				$wait = 1;
				if ( ! is_wp_error( $response ) ) {
					$after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
					$wait  = max( 1, min( 4, $after ) );
				}
				if ( ! defined( 'RAR_STUDIO_TESTING' ) ) {
					sleep( $wait );
				}
			}
		} while ( $retry );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'rar_ai_network', sprintf( /* translators: %s: error */ __( 'Could not reach the provider: %s', 'rar-wp-commerce-studio-ai' ), $response->get_error_message() ), array( 'retryable' => true ) );
		}
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			return $this->http_error( $code, is_array( $data ) ? $data : array() );
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'rar_ai_json', __( 'The provider returned an unreadable response.', 'rar-wp-commerce-studio-ai' ) );
		}
		return $data;
	}

	/**
	 * Map HTTP failures to clear messages (never echoing the key).
	 *
	 * @param int   $code HTTP status.
	 * @param array $data Response body.
	 * @return \WP_Error
	 */
	protected function http_error( $code, array $data ) {
		$detail = '';
		if ( isset( $data['error']['message'] ) ) {
			$detail = (string) $data['error']['message'];
		} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			$detail = $data['error'];
		} elseif ( isset( $data['message'] ) ) {
			$detail = (string) $data['message'];
		}
		$detail = \RarStudio\Logger::scrub( array( 'd' => wp_strip_all_tags( $detail ) ) )['d'] ?? '';
		switch ( true ) {
			case 401 === $code || 403 === $code:
				$message = __( 'The API key was rejected (401/403). Check the key and its project permissions.', 'rar-wp-commerce-studio-ai' );
				break;
			case 404 === $code:
				$message = __( 'Model not found (404). Use "Load models" to pick a model your key can use.', 'rar-wp-commerce-studio-ai' );
				break;
			case 429 === $code:
				$message = __( 'Rate limit or quota reached (429). Free-tier quotas reset over time; paid usage needs billing on the provider account.', 'rar-wp-commerce-studio-ai' );
				break;
			case 400 === $code:
				$message = __( 'The provider rejected the request (400).', 'rar-wp-commerce-studio-ai' );
				break;
			case $code >= 500:
				$message = sprintf( /* translators: %d: status */ __( 'Provider server error (%d). Try again later.', 'rar-wp-commerce-studio-ai' ), $code );
				break;
			default:
				$message = sprintf( /* translators: %d: status */ __( 'Provider error HTTP %d.', 'rar-wp-commerce-studio-ai' ), $code );
		}
		if ( '' !== $detail ) {
			$message .= ' ' . \RarStudio\Util::limit( $detail, 220 );
		}
		return new \WP_Error( 'rar_ai_http_' . $code, $message, array( 'retryable' => 429 === $code || $code >= 500, 'status' => $code ) );
	}
}
