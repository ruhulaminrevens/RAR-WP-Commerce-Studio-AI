<?php
/**
 * Prompt templates (editable in Settings → Prompt templates) and builders
 * shared by the API path and the manual subscription path.
 *
 * @package RarStudio
 */

namespace RarStudio\Content;

use RarStudio\Fields;
use RarStudio\Settings;
use RarStudio\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt builder.
 */
final class Prompts {

	/**
	 * Default templates.
	 *
	 * @return array id => [label, text]
	 */
	public static function defaults() {
		return array(
			'system'   => array(
				'label' => 'System rules (all text tasks)',
				'text'  => "You are an expert eCommerce copywriter and SEO editor for {brand_name}.\nBrand voice: {voice}\nAudience: {audience}\n{instructions}\n\nSTRICT RULES:\n- Use ONLY facts found in the DATA section. Never invent specifications, ingredients, materials, sizes, certifications, health or medical claims, guarantees, awards, prices, discounts, delivery promises or numbers.\n- If a detail is unknown, leave it out. Do not guess.\n- Keep brand names, model numbers and units exactly as written.\n- No competitor names. No emojis.\n- Output valid JSON only, with exactly the requested keys.",
			),
			'product'  => array(
				'label' => 'Product content & SEO',
				'text'  => "TASK: {task}\nLANGUAGE: {language_rule}\n\nReturn one JSON object with these keys:\n{field_rules}\n\nDATA (product):\n{facts}",
			),
			'post'     => array(
				'label' => 'Post / page content & SEO',
				'text'  => "TASK: {task}\nLANGUAGE: {language_rule}\nKeep every fact, link, image and shortcode from the original content.\n\nReturn one JSON object with these keys:\n{field_rules}\n\nDATA (content):\n{facts}",
			),
			'alt_text' => array(
				'label' => 'Image alt text (vision)',
				'text'  => "Write alt text for this product image (max 125 characters).\nDescribe only what is visible: product type, colour, packaging and any readable brand or product name. No marketing words and no claims.\nLANGUAGE: {language_rule}\nProduct name for reference: {name}\nReturn JSON: {\"alt\": \"...\"}",
			),
			'article'  => array(
				'label' => 'New article draft from a brief',
				'text'  => "Write a helpful blog article draft from this brief.\nLANGUAGE: {language_rule}\nUse only facts given in the brief. Where a fact must be checked by a human, write [check].\nStructure: short intro, 3–6 sections with <h2>/<h3>, short paragraphs, a short conclusion. No prices or promises.\nReturn JSON with keys: title, content (HTML), excerpt (max 160 chars), seo_title (max {title_max} chars), seo_description (max {meta_max} chars), seo_focus (2–4 words).\n\nBRIEF:\n{brief}",
			),
			'bg_scene' => array(
				'label' => 'AI background scene (image)',
				'text'  => 'Professional e-commerce product photography background: {scene}. Empty scene with a clear, flat surface in the centre where a product can be placed. No products, no bottles, no packaging, no text, no letters, no logos, no people, no watermark. Soft even studio lighting, square 1:1 format.',
			),
		);
	}

	/**
	 * Template text (custom override or default).
	 *
	 * @param string $id Template id.
	 * @return string
	 */
	public static function template( $id ) {
		$custom = Settings::get( 'templates.' . $id );
		if ( is_string( $custom ) && '' !== trim( $custom ) ) {
			return $custom;
		}
		$defaults = self::defaults();
		return isset( $defaults[ $id ] ) ? $defaults[ $id ]['text'] : '';
	}

	/**
	 * Replace {placeholders}.
	 *
	 * @param string $id   Template id.
	 * @param array  $vars Variables.
	 * @return string
	 */
	public static function render( $id, array $vars ) {
		$brand = (array) Settings::get( 'brand', array() );
		$vars  = array_merge(
			array(
				'brand_name'    => $brand['name'] ?? '',
				'voice'         => $brand['voice'] ?? '',
				'audience'      => $brand['audience'] ?? '',
				'instructions'  => $brand['instructions'] ?? '',
				'language_rule' => self::language_rule( Settings::get( 'general.language', 'en' ) ),
				'title_max'     => (int) Settings::get( 'seo.title_max', 60 ),
				'meta_max'      => (int) Settings::get( 'seo.meta_max', 155 ),
			),
			$vars
		);
		$map = array();
		foreach ( $vars as $k => $v ) {
			$map[ '{' . $k . '}' ] = is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		}
		return trim( strtr( self::template( $id ), $map ) );
	}

	/**
	 * Language instruction.
	 *
	 * @param string $lang en|bn|bilingual.
	 * @return string
	 */
	public static function language_rule( $lang ) {
		switch ( $lang ) {
			case 'bn':
				return 'Write in natural, simple Bangla (Bengali script). Keep brand names, model numbers and units in their original form. If the source text is English, translate it faithfully.';
			case 'bilingual':
				return 'For description fields write a Bangla section followed by an English section with the same meaning. For titles, tags and SEO fields use English.';
		}
		return 'Write in clear, simple English. If the source text is in another language, translate it faithfully.';
	}

	/**
	 * Per-field output rules.
	 *
	 * @param string $type   Object type.
	 * @param array  $fields Fields.
	 * @param array  $facts  Facts (for allowed category/brand lists).
	 * @return string
	 */
	public static function field_rules( $type, array $fields, array $facts ) {
		$title_max = (int) Settings::get( 'seo.title_max', 60 );
		$meta_max  = (int) Settings::get( 'seo.meta_max', 155 );
		$rules     = array(
			'title'             => 'string. Clear ' . ( 'product' === $type ? 'product' : 'page' ) . ' title, max 80 characters. Keep brand, product name and size exactly.',
			'short_description' => 'string (HTML). One <p> of max 40 words, then an optional <ul> with 3–5 <li> facts.',
			'description'       => 'string (HTML). Short <p> paragraphs; <h3>Key features</h3> with a <ul>; <h3>Specifications</h3> with a <table> built only from the data.',
			'tags'              => 'string. 3–8 short comma-separated tags.',
			'seo_title'         => 'string. Max ' . $title_max . ' characters, include the main keyword near the start.',
			'seo_description'   => 'string. ' . min( 120, $meta_max ) . '–' . $meta_max . ' characters, plain text, includes the main keyword, no quotes.',
			'seo_focus'         => 'string. 2–4 word focus keyphrase that matches the title.',
			'excerpt'           => 'string. Plain-text summary, max 160 characters.',
			'content'           => 'string (HTML). Rewrite for readability: short paragraphs, logical <h2>/<h3> headings, lists where useful. Keep every fact, link, image and shortcode.',
		);
		if ( in_array( 'categories', $fields, true ) ) {
			$rules['categories'] = 'string. Comma-separated, choose ONLY from: ' . self::term_list( 'product_cat' ) . '. Return "" if none fit.';
		}
		if ( in_array( 'brand', $fields, true ) ) {
			$rules['brand'] = 'string. ONE brand, choose ONLY from: ' . self::term_list( 'product_brand' ) . '. Return "" if none fit.';
		}
		$lines = array();
		foreach ( $fields as $field ) {
			if ( isset( $rules[ $field ] ) ) {
				$lines[] = '- "' . $field . '": ' . $rules[ $field ];
			}
		}
		unset( $facts );
		return implode( "\n", $lines );
	}

	/**
	 * Existing term names for a taxonomy (max 120).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private static function term_list( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return '(none)';
		}
		$names = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 120,
				'fields'     => 'names',
			)
		);
		return is_array( $names ) && $names ? implode( ' | ', array_map( 'wp_strip_all_tags', $names ) ) : '(none)';
	}

	/**
	 * Facts compacted for a prompt (no prices, no internal IDs).
	 *
	 * @param array $facts Facts.
	 * @return array
	 */
	public static function compact_facts( array $facts ) {
		$keep = array( 'name', 'brand', 'short_description', 'description', 'excerpt', 'attributes', 'sku', 'weight', 'dimensions', 'categories', 'tags' );
		$out  = array();
		foreach ( $keep as $k ) {
			if ( ! empty( $facts[ $k ] ) ) {
				$out[ $k ] = is_string( $facts[ $k ] ) ? Util::limit( $facts[ $k ], 'description' === $k ? 6000 : 1500 ) : $facts[ $k ];
			}
		}
		if ( ! empty( $facts['description_html'] ) && 'product' !== ( $facts['type'] ?? '' ) ) {
			$out['content_html'] = Util::limit( $facts['description_html'], 12000 );
			unset( $out['description'] );
		}
		return $out;
	}

	/**
	 * Build the request for content generation.
	 *
	 * @param string $type   Object type.
	 * @param int    $id     Object ID.
	 * @param array  $fields Fields.
	 * @param array  $opts   language, instruction.
	 * @return array system, prompt, json
	 */
	public static function content_request( $type, $id, array $fields, array $opts = array() ) {
		$facts = Fields::facts( $type, $id );
		$lang  = $opts['language'] ?? Settings::get( 'general.language', 'en' );
		$task  = 'product' === $type ? 'Improve this product listing for an online shop.' : 'Improve this ' . $type . ' for readers and search engines.';
		if ( ! empty( $opts['instruction'] ) ) {
			$task .= ' Extra instruction from the editor: ' . Util::limit( sanitize_textarea_field( $opts['instruction'] ), 500 );
		}
		$vars = array(
			'task'          => $task,
			'language_rule' => self::language_rule( $lang ),
			'field_rules'   => self::field_rules( $type, $fields, $facts ),
			'facts'         => wp_json_encode( self::compact_facts( $facts ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
		);
		return array(
			'system' => self::render( 'system', $vars ),
			'prompt' => self::render( 'product' === $type ? 'product' : 'post', $vars ),
			'json'   => true,
		);
	}

	/**
	 * One self-contained prompt for ChatGPT / Gemini / Claude chat apps.
	 *
	 * @param array $request Request from content_request().
	 * @return string
	 */
	public static function manual_prompt( array $request ) {
		return trim( $request['system'] ) . "\n\n" . trim( $request['prompt'] ) . "\n\nReply with the JSON object only (no explanations), so it can be pasted back into RAR Commerce Studio.";
	}
}
