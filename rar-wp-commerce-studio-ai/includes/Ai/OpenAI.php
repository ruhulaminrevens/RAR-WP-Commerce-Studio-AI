<?php
/**
 * OpenAI adapter (Chat Completions + Images).
 *
 * @package RarStudio
 */

namespace RarStudio\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI.
 */
class OpenAI extends Provider {

	const BASE = 'https://api.openai.com/v1/';

	/** @inheritDoc */
	public function id() {
		return 'openai';
	}

	/** @inheritDoc */
	public function label() {
		return 'OpenAI GPT';
	}

	/** @inheritDoc */
	public function key_constant() {
		return 'RAR_STUDIO_OPENAI_API_KEY';
	}

	/** @inheritDoc */
	public function generate( array $request ) {
		$content = array();
		foreach ( (array) ( $request['images'] ?? array() ) as $image ) {
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array( 'url' => 'data:' . $image['mime'] . ';base64,' . $image['data'] ),
			);
		}
		$content[] = array(
			'type' => 'text',
			'text' => (string) $request['prompt'],
		);
		$messages = array();
		if ( ! empty( $request['system'] ) ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => (string) $request['system'],
			);
		}
		$messages[] = array(
			'role'    => 'user',
			'content' => $content,
		);
		$body = array(
			'model'                 => (string) $request['model'],
			'messages'              => $messages,
			'max_completion_tokens' => (int) ( $request['max_tokens'] ?? 1200 ),
		);
		if ( ! empty( $request['json'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}
		$data = $this->request( 'POST', self::BASE . 'chat/completions', array( 'Authorization' => 'Bearer ' . $this->key() ), $body, 60 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$text = (string) ( $data['choices'][0]['message']['content'] ?? '' );
		if ( '' === trim( $text ) ) {
			$reason = (string) ( $data['choices'][0]['finish_reason'] ?? 'empty' );
			return new \WP_Error( 'rar_ai_empty', sprintf( /* translators: %s: reason */ __( 'OpenAI returned no text (%s). If the reason is "length", raise Max output tokens.', 'rar-wp-commerce-studio-ai' ), sanitize_text_field( $reason ) ) );
		}
		return array(
			'text'          => $text,
			'input_tokens'  => (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $data['usage']['completion_tokens'] ?? 0 ),
			'model'         => (string) ( $data['model'] ?? $request['model'] ),
		);
	}

	/** @inheritDoc */
	public function list_models() {
		$data = $this->request( 'GET', self::BASE . 'models', array( 'Authorization' => 'Bearer ' . $this->key() ), array(), 20 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$out = array();
		foreach ( (array) ( $data['data'] ?? array() ) as $model ) {
			$id = (string) ( $model['id'] ?? '' );
			if ( '' !== $id && ! preg_match( '/(embedding|whisper|tts|moderation|transcribe|realtime|audio|search)/i', $id ) ) {
				$out[] = $id;
			}
		}
		sort( $out );
		return $out;
	}

	/** @inheritDoc */
	public function generate_image( $prompt, $model ) {
		$body = array(
			'model'  => (string) $model,
			'prompt' => (string) $prompt,
			'n'      => 1,
			'size'   => '1024x1024',
		);
		if ( 0 === strpos( (string) $model, 'dall-e' ) ) {
			$body['response_format'] = 'b64_json';
		}
		$data = $this->request( 'POST', self::BASE . 'images/generations', array( 'Authorization' => 'Bearer ' . $this->key() ), $body, 120 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$b64 = (string) ( $data['data'][0]['b64_json'] ?? '' );
		if ( '' === $b64 ) {
			return new \WP_Error( 'rar_ai_empty', __( 'OpenAI returned no image data.', 'rar-wp-commerce-studio-ai' ) );
		}
		return array(
			'bytes' => base64_decode( $b64 ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			'mime'  => 'image/png',
		);
	}
}
