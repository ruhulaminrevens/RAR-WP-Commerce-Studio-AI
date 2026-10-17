<?php
/**
 * Google Gemini adapter (Generative Language API, v1beta).
 *
 * @package RarStudio
 */

namespace RarStudio\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Gemini.
 */
class Gemini extends Provider {

	const BASE = 'https://generativelanguage.googleapis.com/v1beta/';

	/** @inheritDoc */
	public function id() {
		return 'gemini';
	}

	/** @inheritDoc */
	public function label() {
		return 'Google Gemini';
	}

	/** @inheritDoc */
	public function key_constant() {
		return 'RAR_STUDIO_GEMINI_API_KEY';
	}

	/**
	 * Model path segment (accepts "models/x" or "x").
	 *
	 * @param string $model Model.
	 * @return string
	 */
	private function model_path( $model ) {
		$model = preg_replace( '#^models/#', '', (string) $model );
		return 'models/' . rawurlencode( $model );
	}

	/** @inheritDoc */
	public function generate( array $request ) {
		$parts = array();
		foreach ( (array) ( $request['images'] ?? array() ) as $image ) {
			$parts[] = array(
				'inline_data' => array(
					'mime_type' => $image['mime'],
					'data'      => $image['data'],
				),
			);
		}
		$parts[] = array( 'text' => (string) $request['prompt'] );
		$body    = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => $parts,
				),
			),
			'generationConfig' => array(
				'maxOutputTokens' => (int) ( $request['max_tokens'] ?? 1200 ),
			),
		);
		if ( ! empty( $request['system'] ) ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => (string) $request['system'] ) ) );
		}
		if ( ! empty( $request['json'] ) ) {
			$body['generationConfig']['responseMimeType'] = 'application/json';
		}
		$data = $this->request( 'POST', self::BASE . $this->model_path( $request['model'] ) . ':generateContent', array( 'x-goog-api-key' => $this->key() ), $body, 60 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$text = '';
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( ! empty( $part['thought'] ) ) {
				continue; // Skip thinking summaries.
			}
			if ( isset( $part['text'] ) ) {
				$text .= $part['text'];
			}
		}
		if ( '' === trim( $text ) ) {
			$reason = (string) ( $data['candidates'][0]['finishReason'] ?? ( $data['promptFeedback']['blockReason'] ?? 'empty' ) );
			return new \WP_Error( 'rar_ai_empty', sprintf( /* translators: %s: reason */ __( 'Gemini returned no text (%s).', 'rar-wp-commerce-studio-ai' ), sanitize_text_field( $reason ) ) );
		}
		$usage = (array) ( $data['usageMetadata'] ?? array() );
		return array(
			'text'          => $text,
			'input_tokens'  => (int) ( $usage['promptTokenCount'] ?? 0 ),
			'output_tokens' => (int) ( $usage['candidatesTokenCount'] ?? 0 ) + (int) ( $usage['thoughtsTokenCount'] ?? 0 ),
			'model'         => (string) ( $data['modelVersion'] ?? $request['model'] ),
		);
	}

	/** @inheritDoc */
	public function list_models() {
		$data = $this->request( 'GET', self::BASE . 'models?pageSize=200', array( 'x-goog-api-key' => $this->key() ), array(), 20 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$out = array();
		foreach ( (array) ( $data['models'] ?? array() ) as $model ) {
			$methods = (array) ( $model['supportedGenerationMethods'] ?? array() );
			if ( in_array( 'generateContent', $methods, true ) ) {
				$out[] = preg_replace( '#^models/#', '', (string) $model['name'] );
			}
		}
		sort( $out );
		return $out;
	}

	/** @inheritDoc */
	public function generate_image( $prompt, $model ) {
		$body = array(
			'contents'         => array( array( 'parts' => array( array( 'text' => (string) $prompt ) ) ) ),
			'generationConfig' => array( 'responseModalities' => array( 'IMAGE' ) ),
		);
		$data = $this->request( 'POST', self::BASE . $this->model_path( $model ) . ':generateContent', array( 'x-goog-api-key' => $this->key() ), $body, 90 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			$inline = $part['inlineData'] ?? ( $part['inline_data'] ?? null );
			if ( is_array( $inline ) && ! empty( $inline['data'] ) ) {
				return array(
					'bytes' => base64_decode( (string) $inline['data'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					'mime'  => (string) ( $inline['mimeType'] ?? ( $inline['mime_type'] ?? 'image/png' ) ),
				);
			}
		}
		return new \WP_Error( 'rar_ai_empty', __( 'Gemini returned no image.', 'rar-wp-commerce-studio-ai' ) );
	}
}
