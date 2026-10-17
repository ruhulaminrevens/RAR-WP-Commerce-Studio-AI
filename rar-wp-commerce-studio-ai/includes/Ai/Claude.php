<?php
/**
 * Anthropic Claude adapter (Messages API).
 *
 * @package RarStudio
 */

namespace RarStudio\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Claude.
 */
class Claude extends Provider {

	const BASE = 'https://api.anthropic.com/v1/';

	/** @inheritDoc */
	public function id() {
		return 'claude';
	}

	/** @inheritDoc */
	public function label() {
		return 'Anthropic Claude';
	}

	/** @inheritDoc */
	public function key_constant() {
		return 'RAR_STUDIO_CLAUDE_API_KEY';
	}

	/**
	 * Common headers.
	 *
	 * @return array
	 */
	private function headers() {
		return array(
			'x-api-key'         => $this->key(),
			'anthropic-version' => '2023-06-01',
		);
	}

	/** @inheritDoc */
	public function generate( array $request ) {
		$content = array();
		foreach ( (array) ( $request['images'] ?? array() ) as $image ) {
			$content[] = array(
				'type'   => 'image',
				'source' => array(
					'type'       => 'base64',
					'media_type' => $image['mime'],
					'data'       => $image['data'],
				),
			);
		}
		$prompt = (string) $request['prompt'];
		if ( ! empty( $request['json'] ) ) {
			$prompt .= "\n\nRespond with the JSON object only.";
		}
		$content[] = array(
			'type' => 'text',
			'text' => $prompt,
		);
		$body = array(
			'model'      => (string) $request['model'],
			'max_tokens' => (int) ( $request['max_tokens'] ?? 1200 ),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
		);
		if ( ! empty( $request['system'] ) ) {
			$body['system'] = (string) $request['system'];
		}
		$data = $this->request( 'POST', self::BASE . 'messages', $this->headers(), $body, 60 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) $block['text'];
			}
		}
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'rar_ai_empty', sprintf( /* translators: %s: reason */ __( 'Claude returned no text (%s).', 'rar-wp-commerce-studio-ai' ), sanitize_text_field( (string) ( $data['stop_reason'] ?? 'empty' ) ) ) );
		}
		return array(
			'text'          => $text,
			'input_tokens'  => (int) ( $data['usage']['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $data['usage']['output_tokens'] ?? 0 ),
			'model'         => (string) ( $data['model'] ?? $request['model'] ),
		);
	}

	/** @inheritDoc */
	public function list_models() {
		$data = $this->request( 'GET', self::BASE . 'models?limit=100', $this->headers(), array(), 20 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$out = array();
		foreach ( (array) ( $data['data'] ?? array() ) as $model ) {
			if ( ! empty( $model['id'] ) ) {
				$out[] = (string) $model['id'];
			}
		}
		return $out;
	}
}
