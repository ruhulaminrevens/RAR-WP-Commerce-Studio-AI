<?php
/**
 * Free local generator. Uses only text the store owner already entered
 * (name, descriptions, attributes, SKU, weight, terms). It restructures,
 * shortens and formats — it never invents features or claims.
 *
 * @package RarStudio
 */

namespace RarStudio\Content;

use RarStudio\Fields;
use RarStudio\Settings;
use RarStudio\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Rule-based content writer (no network, no cost).
 */
final class LocalWriter {

	/**
	 * Fields the local engine can produce, per object type.
	 *
	 * @param string $type Object type.
	 * @return string[]
	 */
	public static function supported( $type ) {
		switch ( $type ) {
			case 'product':
				return array( 'title', 'short_description', 'description', 'tags', 'categories', 'brand', 'seo_title', 'seo_description', 'seo_focus' );
			case 'post':
			case 'page':
				return array( 'title', 'excerpt', 'seo_title', 'seo_description', 'seo_focus' );
			case 'attachment':
				return array( 'alt', 'title' );
		}
		return array();
	}

	/**
	 * Generate proposals.
	 *
	 * @param string $type   Object type.
	 * @param int    $id     Object ID.
	 * @param array  $fields Requested fields.
	 * @param array  $opts   language (en|bn|bilingual), context.
	 * @return array field => proposed string (only fields it could produce).
	 */
	public static function generate( $type, $id, array $fields, array $opts = array() ) {
		$facts = Fields::facts( $type, $id );
		if ( empty( $facts['name'] ) && 'attachment' !== $type ) {
			return array();
		}
		$lang = isset( $opts['language'] ) ? $opts['language'] : Settings::get( 'general.language', 'en' );
		$out  = array();
		foreach ( array_intersect( $fields, self::supported( $type ) ) as $field ) {
			$value = null;
			switch ( $field ) {
				case 'title':
					$value = 'attachment' === $type ? self::media_title( $id ) : self::title( $facts );
					break;
				case 'short_description':
					$value = self::short_description( $facts, $lang );
					break;
				case 'description':
					$value = self::description( $facts, $lang );
					break;
				case 'tags':
					$value = self::tags( $facts );
					break;
				case 'categories':
					$value = self::categories( $facts );
					break;
				case 'brand':
					$value = self::brand( $facts );
					break;
				case 'seo_title':
					$value = self::seo_title( $facts );
					break;
				case 'seo_description':
					$value = self::seo_description( $facts, $lang );
					break;
				case 'seo_focus':
					$value = self::focus( $facts );
					break;
				case 'excerpt':
					$value = self::excerpt( $facts );
					break;
				case 'alt':
					$value = self::alt( $id, $opts );
					break;
			}
			if ( null !== $value && '' !== trim( (string) $value ) ) {
				$out[ $field ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Section labels by language.
	 *
	 * @param string $key  Label key.
	 * @param string $lang Language.
	 * @return string
	 */
	public static function label( $key, $lang ) {
		$labels = array(
			'features'   => array( 'Key features', 'মূল বৈশিষ্ট্য' ),
			'specs'      => array( 'Specifications', 'স্পেসিফিকেশন' ),
			'sku'        => array( 'SKU', 'এসকেইউ' ),
			'weight'     => array( 'Weight', 'ওজন' ),
			'dimensions' => array( 'Dimensions', 'মাপ' ),
			'shop'       => array( 'Buy %1$s online from %2$s.', '%2$s থেকে অনলাইনে %1$s কিনুন।' ),
		);
		if ( ! isset( $labels[ $key ] ) ) {
			return $key;
		}
		if ( 'bn' === $lang ) {
			return $labels[ $key ][1];
		}
		if ( 'bilingual' === $lang && 'shop' !== $key ) {
			return $labels[ $key ][0] . ' / ' . $labels[ $key ][1];
		}
		return $labels[ $key ][0];
	}

	/**
	 * Is a string written in Latin script?
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function is_latin( $text ) {
		return ! preg_match( '/[\x{0980}-\x{09FF}]/u', (string) $text );
	}

	/**
	 * Smart title case for Latin product names (keeps units, codes, small words).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function title_case( $text ) {
		$small = array( 'a', 'an', 'and', 'as', 'at', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'with', 'x' );
		$units = array( 'ml', 'l', 'g', 'gm', 'kg', 'mg', 'oz', 'cm', 'mm', 'pcs', 'pc' );
		$words = preg_split( '/(\s+)/u', trim( $text ), -1, PREG_SPLIT_DELIM_CAPTURE );
		$first = true;
		foreach ( $words as $i => $word ) {
			if ( '' === trim( $word ) ) {
				continue;
			}
			$lower = Util::lower( $word );
			if ( preg_match( '/\d/', $word ) ) {
				$words[ $i ] = preg_replace_callback( '/(\d+(?:\.\d+)?)([a-z]+)$/i', static function ( $m ) use ( $units ) {
					return $m[1] . ( in_array( strtolower( $m[2] ), $units, true ) ? strtolower( $m[2] ) : strtoupper( $m[2] ) );
				}, $word );
			} elseif ( in_array( $lower, $units, true ) ) {
				$words[ $i ] = $lower;
			} elseif ( ! $first && in_array( $lower, $small, true ) ) {
				$words[ $i ] = $lower;
			} else {
				$words[ $i ] = function_exists( 'mb_convert_case' ) ? mb_convert_case( $lower, MB_CASE_TITLE, 'UTF-8' ) : ucfirst( $lower );
			}
			$first = false;
		}
		return implode( '', $words );
	}

	/**
	 * Cleaned-up product/post title.
	 *
	 * @param array $facts Facts.
	 * @return string
	 */
	public static function title( array $facts ) {
		$name = trim( preg_replace( '/\s+/u', ' ', (string) $facts['name'] ) );
		$name = preg_replace( '/\s+([,.;:!?])/u', '$1', $name );
		$name = preg_replace( '/\s*([|–—])\s*/u', ' $1 ', $name );
		$name = preg_replace( '/([!?.])\1+/u', '$1', $name );
		if ( self::is_latin( $name ) ) {
			$letters = preg_replace( '/[^A-Za-z]/', '', $name );
			if ( '' !== $letters ) {
				$upper = strlen( preg_replace( '/[^A-Z]/', '', $letters ) ) / strlen( $letters );
				if ( $upper > 0.8 || $upper < 0.05 ) {
					$name = self::title_case( $name );
				}
			}
		}
		if ( ( $facts['type'] ?? '' ) !== 'product' ) {
			return $name;
		}
		$brand = trim( (string) ( $facts['brand'] ?? '' ) );
		if ( $brand && false === strpos( $brand, ',' ) && false === Util::ipos( $name, $brand ) ) {
			$name = $brand . ' ' . $name;
		}
		$size = self::size_value( $facts );
		if ( $size && false === Util::ipos( str_replace( ' ', '', $name ), str_replace( ' ', '', $size ) ) ) {
			$name .= ' – ' . $size;
		}
		return Util::trim_words( $name, 150 );
	}

	/**
	 * Single size/volume attribute value, if any.
	 *
	 * @param array $facts Facts.
	 * @return string
	 */
	private static function size_value( array $facts ) {
		if ( empty( $facts['attributes'] ) || ! is_array( $facts['attributes'] ) ) {
			return '';
		}
		foreach ( $facts['attributes'] as $label => $value ) {
			if ( preg_match( '/^(size|volume|capacity|net\s*(weight|volume|content)|pack\s*size|সাইজ|পরিমাণ|ওজন)$/iu', trim( $label ) ) && false === strpos( $value, ',' ) ) {
				return trim( $value );
			}
		}
		return '';
	}

	/**
	 * Parse a plain description into paragraphs, feature bullets and spec pairs.
	 *
	 * @param string $plain Plain text with newlines.
	 * @return array{paragraphs:string[],features:string[],specs:array}
	 */
	public static function parse_description( $plain ) {
		$out = array(
			'paragraphs' => array(),
			'features'   => array(),
			'specs'      => array(),
		);
		foreach ( preg_split( '/\n+/u', (string) $plain ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^[\-\*•✓✔▪·●◦➤►–]+\s*(.+)$/u', $line, $m ) || preg_match( '/^\d{1,2}[.)]\s+(.+)$/u', $line, $m ) ) {
				$item = trim( $m[1] );
				if ( preg_match( '/^([^:：]{2,40})[:：]\s*(.{1,120})$/u', $item, $kv ) ) {
					$out['specs'][ trim( $kv[1] ) ] = trim( $kv[2] );
				} else {
					$out['features'][] = $item;
				}
				continue;
			}
			if ( Util::len( $line ) <= 90 && preg_match( '/^([^:：.]{2,40})[:：]\s*(.{1,80})$/u', $line, $kv ) ) {
				$out['specs'][ trim( $kv[1] ) ] = trim( $kv[2] );
				continue;
			}
			$out['paragraphs'][] = $line;
		}
		return $out;
	}

	/**
	 * Short description: lead sentences + up to four attribute bullets.
	 *
	 * @param array  $facts Facts.
	 * @param string $lang  Language.
	 * @return string
	 */
	public static function short_description( array $facts, $lang ) {
		$parsed = self::parse_description( $facts['description'] ?? '' );
		$lead   = '';
		$base   = $parsed['paragraphs'] ? implode( ' ', $parsed['paragraphs'] ) : (string) ( $facts['short_description'] ?? '' );
		foreach ( Util::sentences( $base ) as $sentence ) {
			if ( Util::len( $lead . ' ' . $sentence ) > 230 && '' !== $lead ) {
				break;
			}
			$lead = trim( $lead . ' ' . $sentence );
		}
		$lead    = Util::trim_words( $lead, 260 );
		$bullets = array();
		foreach ( array_slice( $parsed['features'], 0, 4 ) as $feature ) {
			$bullets[] = $feature;
		}
		if ( count( $bullets ) < 4 && ! empty( $facts['attributes'] ) ) {
			foreach ( $facts['attributes'] as $label => $value ) {
				if ( count( $bullets ) >= 4 ) {
					break;
				}
				$bullets[] = $label . ': ' . $value;
			}
		}
		if ( '' === $lead && ! $bullets ) {
			return '';
		}
		$html = '' !== $lead ? '<p>' . esc_html( $lead ) . '</p>' : '';
		if ( $bullets ) {
			$html .= "\n<ul>\n";
			foreach ( $bullets as $b ) {
				$html .= '<li>' . esc_html( $b ) . "</li>\n";
			}
			$html .= '</ul>';
		}
		unset( $lang );
		return $html;
	}

	/**
	 * Long description: existing paragraphs + "Key features" list + "Specifications" table.
	 *
	 * @param array  $facts Facts.
	 * @param string $lang  Language.
	 * @return string
	 */
	public static function description( array $facts, $lang ) {
		$html = (string) ( $facts['description_html'] ?? '' );
		if ( preg_match( '/<table/i', $html ) && preg_match( '/<h[2-4]/i', $html ) ) {
			return $html; // Already structured — nothing to improve locally.
		}
		$parsed = self::parse_description( $facts['description'] ?? '' );
		$specs  = $parsed['specs'];
		if ( ! empty( $facts['attributes'] ) ) {
			foreach ( $facts['attributes'] as $label => $value ) {
				if ( ! isset( $specs[ $label ] ) ) {
					$specs[ $label ] = $value;
				}
			}
		}
		if ( ! empty( $facts['weight'] ) && ! isset( $specs[ self::label( 'weight', $lang ) ] ) ) {
			$specs[ self::label( 'weight', $lang ) ] = $facts['weight'];
		}
		if ( ! empty( $facts['dimensions'] ) ) {
			$specs[ self::label( 'dimensions', $lang ) ] = $facts['dimensions'];
		}
		if ( ! empty( $facts['sku'] ) ) {
			$specs[ self::label( 'sku', $lang ) ] = $facts['sku'];
		}

		$paragraphs = $parsed['paragraphs'];
		if ( ! $paragraphs && ! empty( $facts['short_description'] ) ) {
			$paragraphs = array( Util::line( $facts['short_description'] ) );
		}
		if ( ! $paragraphs && ! $parsed['features'] && ! $specs ) {
			return '';
		}
		$out = '';
		foreach ( $paragraphs as $p ) {
			$out .= '<p>' . esc_html( $p ) . "</p>\n";
		}
		if ( $parsed['features'] ) {
			$out .= '<h3>' . esc_html( self::label( 'features', $lang ) ) . "</h3>\n<ul>\n";
			foreach ( $parsed['features'] as $f ) {
				$out .= '<li>' . esc_html( $f ) . "</li>\n";
			}
			$out .= "</ul>\n";
		}
		if ( $specs ) {
			$out .= '<h3>' . esc_html( self::label( 'specs', $lang ) ) . "</h3>\n<table class=\"rar-specs\">\n<tbody>\n";
			foreach ( $specs as $label => $value ) {
				$out .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . "</td></tr>\n";
			}
			$out .= "</tbody>\n</table>";
		}
		return trim( $out );
	}

	/**
	 * Stop words (English + Bangla).
	 *
	 * @return array
	 */
	public static function stopwords() {
		static $words = null;
		if ( null === $words ) {
			$list  = 'ml mlt ltr gm gram grams kg mg oz cm mm pcs pc a about above after again all also am an and any are as at be because been before being below between both but by can could did do does doing down during each few for from further had has have having he her here hers herself him himself his how i if in into is it its itself just me more most my myself no nor not now of off on once only or other our ours out over own same she should so some such than that the their theirs them then there these they this those through to too under until up very was we were what when where which while who whom why will with you your yours yourself use used using get gets made make new one two buy shop online best item items product products quality brand price pcs pack set size color colour available per each well very much many may also its it\'s great good high';
			$bn    = 'এবং ও বা যে যা এই সেই একটি এক এর এ তে থেকে জন্য সাথে সঙ্গে করে করা হয় হয়ে হবে আছে নেই না কিন্তু তবে যদি তাই আর এখন খুব অনেক সব সকল কোন কোনো কি কী আমাদের আপনার আপনি তার তাদের এটি এটা ওই উপর নিচে মধ্যে পর আগে দিয়ে দিন নিন পণ্য প্রোডাক্ট মিলি গ্রাম কেজি লিটার পিস';
			$words = array_flip( array_merge( explode( ' ', $list ), explode( ' ', $bn ) ) );
		}
		return $words;
	}

	/**
	 * Ranked keywords from text.
	 *
	 * @param string $name  Title (boosted).
	 * @param string $body  Body text.
	 * @param int    $limit Max keywords.
	 * @return string[]
	 */
	public static function keywords( $name, $body, $limit = 8 ) {
		$stop   = self::stopwords();
		$scores = array();
		$tokens = static function ( $text ) {
			preg_match_all( '/[\p{L}\p{M}][\p{L}\p{M}\'’\-]*/u', Util::lower( Util::plain( $text ) ), $m );
			return $m[0];
		};
		foreach ( $tokens( $name ) as $t ) {
			if ( isset( $stop[ $t ] ) || Util::len( $t ) < 3 ) {
				continue;
			}
			$scores[ $t ] = ( $scores[ $t ] ?? 0 ) + 3;
		}
		foreach ( $tokens( $body ) as $t ) {
			if ( isset( $stop[ $t ] ) || Util::len( $t ) < 3 ) {
				continue;
			}
			$scores[ $t ] = ( $scores[ $t ] ?? 0 ) + 1;
		}
		arsort( $scores );
		return array_slice( array_keys( $scores ), 0, $limit );
	}

	/**
	 * Tag suggestions: existing tags + new keyword tags (max 6 new).
	 *
	 * @param array $facts Facts.
	 * @return string
	 */
	public static function tags( array $facts ) {
		$existing = Fields::split_names( $facts['tags'] ?? '' );
		$lower    = array_map( array( Util::class, 'lower' ), $existing );
		$skip     = array( Util::lower( (string) ( $facts['site'] ?? '' ) ) );
		$stop     = self::stopwords();
		$body     = ( $facts['short_description'] ?? '' ) . ' ' . ( $facts['description'] ?? '' ) . ' ' . implode( ' ', (array) ( $facts['attributes'] ?? array() ) );
		$new      = array();
		$add      = static function ( $tag ) use ( &$new, &$lower, $skip ) {
			$key = Util::lower( $tag );
			if ( count( $new ) >= 6 || '' === trim( $tag ) || in_array( $key, $lower, true ) || in_array( $key, $skip, true ) ) {
				return;
			}
			$new[]   = $tag;
			$lower[] = $key;
		};
		if ( ! empty( $facts['brand'] ) && false === strpos( (string) $facts['brand'], ',' ) ) {
			$add( trim( (string) $facts['brand'] ) );
		}
		// Two-word phrases from the product name ("Body Spray").
		preg_match_all( '/[\p{L}\p{M}][\p{L}\p{M}\'’\-]*/u', Util::plain( (string) $facts['name'] ), $m );
		$name_words = $m[0];
		$used       = array();
		for ( $i = 0; $i < count( $name_words ) - 1; $i++ ) {
			$a = Util::lower( $name_words[ $i ] );
			$b = Util::lower( $name_words[ $i + 1 ] );
			if ( isset( $stop[ $a ] ) || isset( $stop[ $b ] ) || Util::len( $a ) < 3 || Util::len( $b ) < 3 || ! self::is_latin( $a . $b ) ) {
				continue;
			}
			if ( preg_match_all( '/' . preg_quote( $a . ' ' . $b, '/' ) . '/u', Util::lower( Util::line( $body ) ) ) >= 1 || 2 === count( $name_words ) ) {
				$add( ucwords( $a . ' ' . $b ) );
				$used[ $a ] = true;
				$used[ $b ] = true;
				++$i; // Non-overlapping phrases.
			}
		}
		foreach ( self::keywords( (string) $facts['name'], $body, 12 ) as $kw ) {
			if ( isset( $used[ $kw ] ) ) {
				continue;
			}
			$add( self::is_latin( $kw ) ? ucwords( $kw ) : $kw );
		}
		return implode( ', ', array_merge( $existing, $new ) );
	}

	/**
	 * Category suggestions — existing categories only.
	 *
	 * @param array $facts Facts.
	 * @return string
	 */
	public static function categories( array $facts ) {
		$current = Fields::split_names( $facts['categories'] ?? '' );
		$terms   = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		if ( ! is_array( $terms ) ) {
			return implode( ', ', $current );
		}
		$default = (int) get_option( 'default_product_cat', 0 );
		$haystack = ' ' . Util::lower( $facts['name'] . ' ' . ( $facts['short_description'] ?? '' ) . ' ' . implode( ' ', (array) ( $facts['attributes'] ?? array() ) ) ) . ' ';
		$matches = array();
		foreach ( $terms as $term ) {
			if ( (int) $term->term_id === $default ) {
				continue;
			}
			$name   = Util::lower( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) );
			$single = preg_replace( '/s$/', '', $name );
			$quoted = preg_quote( $single, '/' );
			if ( Util::len( $single ) >= 3 && preg_match( '/(^|[^\p{L}])' . $quoted . 's?([^\p{L}]|$)/u', $haystack ) ) {
				$matches[] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		if ( ! $matches ) {
			return implode( ', ', $current );
		}
		$default_term = $default ? get_term( $default, 'product_cat' ) : null;
		if ( $default_term && ! is_wp_error( $default_term ) ) {
			$current = array_values( array_diff( $current, array( html_entity_decode( $default_term->name, ENT_QUOTES, 'UTF-8' ) ) ) );
		}
		return implode( ', ', Fields::split_names( implode( ',', array_merge( $current, array_slice( $matches, 0, 3 ) ) ) ) );
	}

	/**
	 * Brand suggestion — existing brand terms only, only when none is set.
	 *
	 * @param array $facts Facts.
	 * @return string
	 */
	public static function brand( array $facts ) {
		$current = trim( (string) ( $facts['brand'] ?? '' ) );
		if ( '' !== $current || ! taxonomy_exists( 'product_brand' ) ) {
			return $current;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_brand',
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		if ( ! is_array( $terms ) ) {
			return '';
		}
		$haystack = ' ' . Util::lower( $facts['name'] . ' ' . ( $facts['description'] ?? '' ) ) . ' ';
		foreach ( $terms as $term ) {
			$name = Util::lower( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) );
			if ( Util::len( $name ) >= 2 && preg_match( '/(^|[^\p{L}])' . preg_quote( $name, '/' ) . '([^\p{L}]|$)/u', $haystack ) ) {
				return html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		return '';
	}

	/**
	 * SEO title from the configured pattern, trimmed to the limit.
	 *
	 * @param array $facts Facts.
	 * @return string
	 */
	public static function seo_title( array $facts ) {
		$max     = (int) Settings::get( 'seo.title_max', 60 );
		$pattern = (string) Settings::get( 'seo.title_pattern', '{name} | {site}' );
		$name    = self::title( array_merge( $facts, array( 'type' => 'post' ) ) );
		$cats    = Fields::split_names( $facts['categories'] ?? '' );
		$repl    = array(
			'{name}'     => $name,
			'{site}'     => (string) ( $facts['site'] ?? '' ),
			'{brand}'    => (string) ( $facts['brand'] ?? '' ),
			'{category}' => $cats ? $cats[0] : '',
		);
		$title = strtr( $pattern, $repl );
		$title = preg_replace( '/(\s*[|–—]\s*){2,}/u', ' | ', $title );
		$title = trim( preg_replace( '/^\s*[|–—]\s*|\s*[|–—]\s*$/u', '', trim( $title ) ) );
		if ( Util::len( $title ) <= $max ) {
			return $title;
		}
		return Util::trim_words( $name, $max );
	}

	/**
	 * Meta description from existing copy (or a neutral shop sentence).
	 *
	 * @param array  $facts Facts.
	 * @param string $lang  Language.
	 * @return string
	 */
	public static function seo_description( array $facts, $lang ) {
		$max  = (int) Settings::get( 'seo.meta_max', 155 );
		$base = trim( (string) ( $facts['short_description'] ?? '' ) );
		if ( '' === $base ) {
			$parsed = self::parse_description( $facts['description'] ?? '' );
			$base   = implode( ' ', $parsed['paragraphs'] );
		}
		if ( '' === $base && ! empty( $facts['excerpt'] ) ) {
			$base = (string) $facts['excerpt'];
		}
		$base = Util::line( $base );
		$text = '';
		foreach ( Util::sentences( $base ) as $sentence ) {
			if ( '' !== $text && Util::len( $text . ' ' . $sentence ) > $max ) {
				break;
			}
			$text = trim( $text . ' ' . $sentence );
		}
		$core = self::focus( $facts );
		if ( '' === $text ) {
			if ( 'product' !== ( $facts['type'] ?? '' ) ) {
				return '';
			}
			$text = sprintf( self::label( 'shop', 'bn' === $lang ? 'bn' : 'en' ), $facts['name'], $facts['site'] );
		} elseif ( $core && false === Util::ipos( $text, $core ) ) {
			$text = $facts['name'] . ': ' . $text;
		}
		return Util::trim_words( $text, $max );
	}

	/**
	 * Focus keyphrase: core name without sizes/numbers, max four words.
	 *
	 * @param array $facts Facts.
	 * @return string
	 */
	public static function focus( array $facts ) {
		$name  = Util::line( (string) ( $facts['name'] ?? '' ) );
		$name  = preg_replace( '/\s*[|–—(\[].*$/u', '', $name );
		$words = array();
		foreach ( preg_split( '/\s+/u', $name ) as $w ) {
			if ( '' === $w || preg_match( '/\d/u', Util::ascii_digits( $w ) ) ) {
				continue;
			}
			$words[] = $w;
			if ( count( $words ) >= 4 ) {
				break;
			}
		}
		$phrase = implode( ' ', $words );
		return self::is_latin( $phrase ) ? Util::lower( $phrase ) : $phrase;
	}

	/**
	 * Excerpt: first two sentences, max 160 characters.
	 *
	 * @param array $facts Facts.
	 * @return string
	 */
	public static function excerpt( array $facts ) {
		$text = '';
		foreach ( Util::sentences( Util::line( $facts['description'] ?? '' ) ) as $i => $sentence ) {
			if ( $i >= 2 || ( '' !== $text && Util::len( $text . ' ' . $sentence ) > 160 ) ) {
				break;
			}
			$text = trim( $text . ' ' . $sentence );
		}
		return Util::trim_words( $text, 160 );
	}

	/**
	 * Media title from a filename when it is meaningful.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function media_title( $id ) {
		$file = get_attached_file( $id );
		$base = $file ? pathinfo( $file, PATHINFO_FILENAME ) : get_the_title( $id );
		$base = preg_replace( '/(-|_)?(scaled|rotated|copy|final|edited|e\d{10,}|\d{2,5}x\d{2,5}|rar-studio.*)$/i', '', (string) $base );
		$base = preg_replace( '/^(img|dsc|image|photo|screenshot|whatsapp image)[-_ ]?\d*/i', '', $base );
		$base = trim( preg_replace( '/[-_]+/', ' ', $base ) );
		$base = trim( preg_replace( '/\b\d{4,}\b/', '', $base ) );
		if ( preg_match_all( '/\p{L}/u', $base ) < 3 ) {
			return '';
		}
		return self::is_latin( $base ) ? self::title_case( $base ) : $base;
	}

	/**
	 * Alt text: product name for product images, otherwise a clean filename.
	 *
	 * @param int   $id   Attachment ID.
	 * @param array $opts product_id, index.
	 * @return string
	 */
	public static function alt( $id, array $opts = array() ) {
		$product_id = absint( $opts['product_id'] ?? 0 );
		if ( ! $product_id ) {
			$product_id = self::product_for_attachment( $id );
		}
		if ( $product_id && Util::wc_active() ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$name  = Util::line( $product->get_name( 'edit' ) );
				$index = isset( $opts['index'] ) ? absint( $opts['index'] ) : self::gallery_index( $product, $id );
				return Util::trim_words( $index > 0 ? sprintf( '%s – %d', $name, $index + 1 ) : $name, 125 );
			}
		}
		return Util::trim_words( self::media_title( $id ), 125 );
	}

	/**
	 * Product that uses an attachment (parent, featured or gallery).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	public static function product_for_attachment( $attachment_id ) {
		global $wpdb;
		$parent = (int) wp_get_post_parent_id( $attachment_id );
		if ( $parent && 'product' === get_post_type( $parent ) ) {
			return $parent;
		}
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'product' AND pm.meta_key = '_thumbnail_id' AND pm.meta_value = %s LIMIT 1", (string) $attachment_id ) ); // phpcs:ignore WordPress.DB
		return $found;
	}

	/**
	 * Position of an attachment in the product gallery (0 = featured / not in gallery).
	 *
	 * @param \WC_Product $product       Product.
	 * @param int         $attachment_id Attachment.
	 * @return int
	 */
	private static function gallery_index( $product, $attachment_id ) {
		$pos = array_search( (int) $attachment_id, array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ), true );
		return false === $pos ? 0 : $pos + 1;
	}
}

