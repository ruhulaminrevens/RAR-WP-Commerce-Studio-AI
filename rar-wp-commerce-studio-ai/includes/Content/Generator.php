<?php
/**
 * Turns local / AI / pasted-subscription output into reviewable drafts.
 * Nothing here writes to products, posts or media directly.
 *
 * @package RarStudio
 */

namespace RarStudio\Content;

use RarStudio\Ai\Gateway;
use RarStudio\Drafts;
use RarStudio\Fields;
use RarStudio\Logger;
use RarStudio\Settings;
use RarStudio\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Draft generator.
 */
final class Generator {

	/**
	 * Fields the AI engine may write per object type.
	 *
	 * @param string $type Type.
	 * @return string[]
	 */
	public static function ai_fields( $type ) {
		switch ( $type ) {
			case 'product':
				$fields = array( 'title', 'short_description', 'description', 'tags', 'categories', 'seo_title', 'seo_description', 'seo_focus' );
				if ( taxonomy_exists( 'product_brand' ) ) {
					$fields[] = 'brand';
				}
				return $fields;
			case 'post':
			case 'page':
				return array( 'title', 'excerpt', 'content', 'seo_title', 'seo_description', 'seo_focus' );
			case 'attachment':
				return array( 'alt' );
		}
		return array();
	}

	/**
	 * Remove fields that cannot be written for this object (Elementor, no SEO plugin…).
	 *
	 * @param string $type   Type.
	 * @param int    $id     ID.
	 * @param array  $fields Fields.
	 * @param array  $skipped Collected reasons (by ref).
	 * @return array
	 */
	private static function writable( $type, $id, array $fields, array &$skipped ) {
		$out = array();
		foreach ( $fields as $field ) {
			if ( ! Fields::exists( $type, $field ) ) {
				continue;
			}
			$reason = Fields::locked_reason( $type, $id, $field );
			if ( $reason ) {
				$skipped[ $field ] = $reason;
				continue;
			}
			$out[] = $field;
		}
		return $out;
	}

	/**
	 * Create drafts from a field => value map.
	 *
	 * @param string $type    Type.
	 * @param int    $id      ID.
	 * @param array  $values  field => value.
	 * @param string $source  Source label.
	 * @param array  $args    job_id, meta.
	 * @param array  $skipped Reasons (by ref).
	 * @return array Created drafts.
	 */
	public static function to_drafts( $type, $id, array $values, $source, array $args, array &$skipped ) {
		$drafts = array();
		foreach ( $values as $field => $value ) {
			if ( ! is_scalar( $value ) ) {
				if ( is_array( $value ) && in_array( $field, array( 'tags', 'categories', 'brand' ), true ) ) {
					$value = implode( ', ', array_map( 'strval', $value ) );
				} else {
					$skipped[ $field ] = __( 'Unexpected value format.', 'rar-wp-commerce-studio-ai' );
					continue;
				}
			}
			if ( in_array( $field, array( 'categories', 'brand' ), true ) && '' !== trim( (string) $value ) ) {
				$check = Fields::resolve_existing_terms( (string) $value, 'categories' === $field ? 'product_cat' : 'product_brand' );
				if ( is_wp_error( $check ) ) {
					$skipped[ $field ] = $check->get_error_message();
					continue;
				}
			}
			$draft = Drafts::create( $type, $id, $field, (string) $value, $source, $args );
			if ( is_wp_error( $draft ) ) {
				$skipped[ $field ] = $draft->get_error_message();
			} elseif ( null === $draft ) {
				$skipped[ $field ] = __( 'No change — the suggestion equals the current value.', 'rar-wp-commerce-studio-ai' );
			} else {
				$drafts[] = $draft;
			}
		}
		return $drafts;
	}

	/**
	 * Generate drafts.
	 *
	 * @param string $type   Type.
	 * @param int    $id     ID.
	 * @param array  $fields Requested fields.
	 * @param string $engine local|ai.
	 * @param array  $opts   language, instruction, job_id, provider.
	 * @return array|\WP_Error drafts, skipped, usage
	 */
	public static function generate( $type, $id, array $fields, $engine, array $opts = array() ) {
		$skipped = array();
		$fields  = self::writable( $type, $id, array_values( array_unique( array_map( 'sanitize_key', $fields ) ) ), $skipped );
		if ( ! $fields ) {
			return array(
				'drafts'  => array(),
				'skipped' => $skipped,
				'usage'   => null,
			);
		}
		$args = array( 'job_id' => absint( $opts['job_id'] ?? 0 ) );
		if ( 'ai' !== $engine ) {
			$supported = LocalWriter::supported( $type );
			foreach ( array_diff( $fields, $supported ) as $field ) {
				$skipped[ $field ] = __( 'The free local engine cannot write this field. Use AI or the subscription prompt.', 'rar-wp-commerce-studio-ai' );
			}
			$values = LocalWriter::generate( $type, $id, array_intersect( $fields, $supported ), $opts );
			foreach ( array_diff( array_intersect( $fields, $supported ), array_keys( $values ) ) as $field ) {
				$skipped[ $field ] = __( 'Not enough existing information to improve this field locally.', 'rar-wp-commerce-studio-ai' );
			}
			return array(
				'drafts'  => self::to_drafts( $type, $id, $values, __( 'Free local engine', 'rar-wp-commerce-studio-ai' ), $args, $skipped ),
				'skipped' => $skipped,
				'usage'   => null,
			);
		}

		$allowed = self::ai_fields( $type );
		foreach ( array_diff( $fields, $allowed ) as $field ) {
			$skipped[ $field ] = __( 'Not available for AI.', 'rar-wp-commerce-studio-ai' );
		}
		$fields = array_values( array_intersect( $fields, $allowed ) );
		if ( ! $fields ) {
			return array(
				'drafts'  => array(),
				'skipped' => $skipped,
				'usage'   => null,
			);
		}
		$request = Prompts::content_request( $type, $id, $fields, $opts );
		$result  = Gateway::run(
			'product' === $type ? 'product_content' : 'post_content',
			$request,
			array(
				'object_type' => $type,
				'object_id'   => $id,
				'provider'    => $opts['provider'] ?? '',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$json = Gateway::parse_json( $result['text'] );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$values = array_intersect_key( $json, array_flip( $fields ) );
		$source = sprintf( 'AI · %s · %s', $result['provider'], $result['model'] );
		return array(
			'drafts'  => self::to_drafts( $type, $id, $values, $source, $args, $skipped ),
			'skipped' => $skipped,
			'usage'   => array(
				'provider'      => $result['provider'],
				'model'         => $result['model'],
				'input_tokens'  => $result['input_tokens'],
				'output_tokens' => $result['output_tokens'],
				'cost'          => $result['cost'],
			),
		);
	}

	/**
	 * Import a response pasted from a chat subscription (ChatGPT, Gemini, Claude apps).
	 *
	 * @param string $type   Type.
	 * @param int    $id     ID.
	 * @param array  $fields Fields that were requested.
	 * @param string $text   Pasted text.
	 * @param string $app    chatgpt|gemini|claude|other.
	 * @return array|\WP_Error
	 */
	public static function import( $type, $id, array $fields, $text, $app ) {
		$skipped = array();
		$fields  = self::writable( $type, $id, array_values( array_intersect( array_map( 'sanitize_key', $fields ), self::ai_fields( $type ) ) ), $skipped );
		$json    = Gateway::parse_json( (string) $text );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$apps   = array(
			'chatgpt' => 'ChatGPT',
			'gemini'  => 'Gemini',
			'claude'  => 'Claude',
		);
		$label  = $apps[ $app ] ?? __( 'chat app', 'rar-wp-commerce-studio-ai' );
		$values = array_intersect_key( $json, array_flip( $fields ) );
		if ( ! $values ) {
			return new \WP_Error( 'rar_import', __( 'The pasted answer has none of the requested keys.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		Logger::log( 'manual_import', sprintf( 'Imported %d field(s) pasted from %s.', count( $values ), $label ), array(), 'info', $type, $id );
		return array(
			'drafts'  => self::to_drafts( $type, $id, $values, sprintf( 'Subscription · %s (pasted)', $label ), array(), $skipped ),
			'skipped' => $skipped,
			'usage'   => null,
		);
	}

	/**
	 * Manual prompt for the subscription workflow.
	 *
	 * @param string $type   Type.
	 * @param int    $id     ID.
	 * @param array  $fields Fields.
	 * @param array  $opts   language, instruction.
	 * @return string
	 */
	public static function manual_prompt( $type, $id, array $fields, array $opts = array() ) {
		$skipped = array();
		$fields  = self::writable( $type, $id, array_values( array_intersect( array_map( 'sanitize_key', $fields ), self::ai_fields( $type ) ) ), $skipped );
		if ( ! $fields ) {
			return '';
		}
		return Prompts::manual_prompt( Prompts::content_request( $type, $id, $fields, $opts ) );
	}

	/**
	 * Create a NEW WordPress post as a draft from an article brief.
	 *
	 * @param string $brief  Brief.
	 * @param string $engine ai|manual.
	 * @param string $pasted Pasted JSON (manual).
	 * @param string $type   post|page.
	 * @return array|\WP_Error post_id, edit_url, usage
	 */
	public static function article( $brief, $engine, $pasted = '', $type = 'post' ) {
		$type    = in_array( $type, array( 'post', 'page' ), true ) ? $type : 'post';
		$request = array(
			'system' => Prompts::render( 'system', array() ),
			'prompt' => Prompts::render( 'article', array( 'brief' => Util::limit( sanitize_textarea_field( $brief ), 4000 ) ) ),
			'json'   => true,
		);
		$usage   = null;
		if ( 'ai' === $engine ) {
			$result = Gateway::run( 'article', $request, array( 'object_type' => $type ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$json  = Gateway::parse_json( $result['text'] );
			$usage = array(
				'provider' => $result['provider'],
				'model'    => $result['model'],
				'cost'     => $result['cost'],
			);
		} else {
			$json = Gateway::parse_json( $pasted );
		}
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		if ( empty( $json['title'] ) || empty( $json['content'] ) ) {
			return new \WP_Error( 'rar_article', __( 'The answer needs at least "title" and "content".', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $type,
					'post_status'  => 'draft',
					'post_title'   => sanitize_text_field( (string) $json['title'] ),
					'post_content' => wp_kses_post( (string) $json['content'] ),
					'post_excerpt' => sanitize_textarea_field( (string) ( $json['excerpt'] ?? '' ) ),
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$seo = array();
		if ( '' !== \RarStudio\Seo\Manager::active() ) {
			foreach ( array( 'seo_title', 'seo_description', 'seo_focus' ) as $field ) {
				if ( ! empty( $json[ $field ] ) ) {
					$seo[ $field ] = (string) $json[ $field ];
				}
			}
		}
		$skipped = array();
		$drafts  = self::to_drafts( $type, $post_id, $seo, 'ai' === $engine ? 'AI article' : 'Subscription article', array(), $skipped );
		Logger::log( 'article_created', sprintf( 'Draft %s created from a brief (not published).', $type ), array(), 'info', $type, $post_id );
		return array(
			'post_id'  => $post_id,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			'drafts'   => $drafts,
			'usage'    => $usage,
			'note'     => __( 'Saved as a WordPress draft — it is not published. Review it, check every [check] mark, then publish yourself.', 'rar-wp-commerce-studio-ai' ),
		);
	}

	/**
	 * AI vision alt text for one attachment.
	 *
	 * @param int   $attachment_id Attachment.
	 * @param array $opts          product_id, job_id, language.
	 * @return array|\WP_Error
	 */
	public static function ai_alt( $attachment_id, array $opts = array() ) {
		$image = \RarStudio\Image\Processor::thumbnail_base64( $attachment_id, 512 );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
		$product_id = absint( $opts['product_id'] ?? 0 );
		if ( ! $product_id ) {
			$product_id = LocalWriter::product_for_attachment( $attachment_id );
		}
		$name    = $product_id ? get_the_title( $product_id ) : get_the_title( $attachment_id );
		$request = array(
			'system' => Prompts::render( 'system', array() ),
			'prompt' => Prompts::render(
				'alt_text',
				array(
					'name'          => $name,
					'language_rule' => Prompts::language_rule( $opts['language'] ?? Settings::get( 'general.language', 'en' ) ),
				)
			),
			'images' => array( $image ),
			'json'   => true,
		);
		$result  = Gateway::run( 'alt_text', $request, array( 'object_type' => 'attachment', 'object_id' => $attachment_id ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$json = Gateway::parse_json( $result['text'] );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$skipped = array();
		$meta    = $product_id ? array( 'product_id' => $product_id ) : array();
		return array(
			'drafts'  => self::to_drafts( 'attachment', $attachment_id, array( 'alt' => Util::trim_words( (string) ( $json['alt'] ?? '' ), 125 ) ), sprintf( 'AI vision · %s · %s', $result['provider'], $result['model'] ), array( 'job_id' => absint( $opts['job_id'] ?? 0 ), 'meta' => $meta ), $skipped ),
			'skipped' => $skipped,
			'usage'   => array(
				'provider' => $result['provider'],
				'model'    => $result['model'],
				'cost'     => $result['cost'],
			),
		);
	}
}
