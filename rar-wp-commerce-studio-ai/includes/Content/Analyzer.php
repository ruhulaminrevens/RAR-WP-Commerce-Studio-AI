<?php
/**
 * Local content & SEO analysis: lengths, keyphrase placement, headings,
 * readability, links, image alt coverage, internal-link suggestions and
 * catalog health (thin / duplicate / missing data). No network, no cost.
 *
 * @package RarStudio
 */

namespace RarStudio\Content;

use RarStudio\Fields;
use RarStudio\Seo\Manager as Seo;
use RarStudio\Settings;
use RarStudio\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Analyzer.
 */
final class Analyzer {

	/**
	 * Visible HTML of an Elementor page assembled from its widget settings.
	 *
	 * @param int $id Post ID.
	 * @return string
	 */
	public static function elementor_html( $id ) {
		$data = json_decode( (string) get_post_meta( (int) $id, '_elementor_data', true ), true );
		if ( ! is_array( $data ) ) {
			return (string) get_post_field( 'post_content', (int) $id );
		}
		$html = '';
		$walk = static function ( $elements ) use ( &$walk, &$html ) {
			foreach ( (array) $elements as $el ) {
				$settings = (array) ( $el['settings'] ?? array() );
				$widget   = (string) ( $el['widgetType'] ?? '' );
				if ( 'heading' === $widget && ! empty( $settings['title'] ) ) {
					$tag   = preg_match( '/^h[1-6]$/', (string) ( $settings['header_size'] ?? '' ) ) ? $settings['header_size'] : 'h2';
					$html .= '<' . $tag . '>' . wp_strip_all_tags( (string) $settings['title'] ) . '</' . $tag . '>';
				}
				foreach ( array( 'editor', 'text', 'description_text', 'title_text', 'html' ) as $key ) {
					if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) && 'heading' !== $widget ) {
						$html .= '<div>' . wp_kses_post( $settings[ $key ] ) . '</div>';
					}
				}
				if ( 'image' === $widget && ! empty( $settings['image']['id'] ) ) {
					$html .= wp_get_attachment_image( (int) $settings['image']['id'], 'thumbnail' );
				}
				if ( ! empty( $el['elements'] ) ) {
					$walk( $el['elements'] );
				}
			}
		};
		$walk( $data );
		return $html;
	}

	/**
	 * Heading outline.
	 *
	 * @param string $html HTML.
	 * @return array[] level, text
	 */
	public static function headings( $html ) {
		preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', (string) $html, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $h ) {
			$out[] = array(
				'level' => (int) $h[1],
				'text'  => Util::limit( Util::line( $h[2] ), 140 ),
			);
		}
		return $out;
	}

	/**
	 * Readability metrics (Flesch Reading Ease only for English text).
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	public static function readability( $html ) {
		$plain      = Util::plain( $html );
		$words      = Util::word_count( $plain );
		$sentences  = Util::sentences( $plain );
		$count_s    = max( 1, count( $sentences ) );
		$long       = 0;
		foreach ( $sentences as $s ) {
			if ( Util::word_count( $s ) > 25 ) {
				++$long;
			}
		}
		$paragraphs = preg_split( '/\n+/u', $plain );
		$long_p     = 0;
		foreach ( (array) $paragraphs as $p ) {
			if ( Util::word_count( $p ) > 150 ) {
				++$long_p;
			}
		}
		$is_latin = ! preg_match( '/[\x{0980}-\x{09FF}]/u', $plain );
		$flesch   = null;
		if ( $is_latin && $words >= 30 ) {
			preg_match_all( '/[a-z]+/i', $plain, $wm );
			$syllables = 0;
			foreach ( $wm[0] as $w ) {
				$syllables += self::syllables( $w );
			}
			$flesch = round( 206.835 - 1.015 * ( $words / $count_s ) - 84.6 * ( $syllables / max( 1, $words ) ), 1 );
			$flesch = max( 0, min( 100, $flesch ) );
		}
		return array(
			'words'              => $words,
			'sentences'          => count( $sentences ),
			'avg_sentence_words' => $words ? round( $words / $count_s, 1 ) : 0,
			'long_sentences_pct' => $sentences ? round( 100 * $long / $count_s ) : 0,
			'long_paragraphs'    => $long_p,
			'flesch'             => $flesch,
			'reading_minutes'    => max( 1, (int) ceil( $words / 200 ) ),
		);
	}

	/**
	 * English syllable estimate.
	 *
	 * @param string $word Word.
	 * @return int
	 */
	private static function syllables( $word ) {
		$word = strtolower( $word );
		if ( strlen( $word ) <= 3 ) {
			return 1;
		}
		$word = preg_replace( '/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word );
		$word = preg_replace( '/^y/', '', $word );
		return max( 1, (int) preg_match_all( '/[aeiouy]{1,2}/', $word ) );
	}

	/**
	 * Links and images in content.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	public static function links_images( $html ) {
		$home     = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal = 0;
		$external = 0;
		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', (string) $html, $links );
		foreach ( $links[1] as $href ) {
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $host || $host === $home ) {
				++$internal;
			} else {
				++$external;
			}
		}
		preg_match_all( '/<img\s[^>]*>/i', (string) $html, $imgs );
		$missing = 0;
		foreach ( $imgs[0] as $img ) {
			if ( ! preg_match( '/\balt=["\'][^"\']+["\']/i', $img ) ) {
				++$missing;
			}
		}
		return array(
			'internal'          => $internal,
			'external'          => $external,
			'images'            => count( $imgs[0] ),
			'images_missing_alt' => $missing,
		);
	}

	/**
	 * Full SEO report for one object.
	 *
	 * @param string $type Type.
	 * @param int    $id   ID.
	 * @return array
	 */
	public static function seo_report( $type, $id ) {
		$facts     = Fields::facts( $type, $id );
		$html      = (string) ( $facts['description_html'] ?? '' );
		$adapter   = Seo::active();
		$title     = $adapter ? Seo::get( $id, 'title' ) : '';
		$meta      = $adapter ? Seo::get( $id, 'description' ) : '';
		$focus     = $adapter ? Seo::get( $id, 'focus' ) : '';
		$title_max = (int) Settings::get( 'seo.title_max', 60 );
		$meta_max  = (int) Settings::get( 'seo.meta_max', 155 );
		$effective = '' !== $title ? self::expand_vars( $title, $facts ) : (string) ( $facts['name'] ?? '' );
		$plain     = Util::plain( $html );
		$first     = Util::sub( Util::line( $plain ), 0, 600 );
		$slug      = (string) get_post_field( 'post_name', $id );
		$headings  = self::headings( $html );
		$read      = self::readability( $html );
		$links     = self::links_images( $html );
		$checks    = array();

		$add = static function ( $id, $status, $label, $detail = '' ) use ( &$checks ) {
			$checks[] = array(
				'id'     => $id,
				'status' => $status,
				'label'  => $label,
				'detail' => $detail,
			);
		};

		if ( ! $adapter ) {
			$add( 'seo_plugin', 'warn', __( 'No SEO plugin detected', 'rar-wp-commerce-studio-ai' ), __( 'Install Yoast SEO, Rank Math or SEOPress, or select one in Settings → SEO, to manage titles and meta descriptions.', 'rar-wp-commerce-studio-ai' ) );
		}
		$tl = Util::len( $effective );
		$add( 'title_length', '' === $title ? 'warn' : ( $tl >= 30 && $tl <= $title_max ? 'pass' : 'warn' ), sprintf( /* translators: 1: length 2: max */ __( 'SEO title length %1$d / %2$d', 'rar-wp-commerce-studio-ai' ), $tl, $title_max ), '' === $title ? __( 'Not set — the SEO plugin will use its default pattern.', 'rar-wp-commerce-studio-ai' ) : '' );
		$ml = Util::len( $meta );
		$add( 'meta_length', '' === $meta ? 'fail' : ( $ml >= 70 && $ml <= $meta_max ? 'pass' : 'warn' ), sprintf( /* translators: 1: length 2: max */ __( 'Meta description length %1$d / %2$d', 'rar-wp-commerce-studio-ai' ), $ml, $meta_max ), '' === $meta ? __( 'Missing — search engines will pick random text.', 'rar-wp-commerce-studio-ai' ) : '' );
		if ( '' === $focus ) {
			$add( 'focus', 'warn', __( 'No focus keyphrase set', 'rar-wp-commerce-studio-ai' ) );
		} else {
			$f = Util::lower( $focus );
			$add( 'focus_title', false !== Util::ipos( $effective, $f ) ? 'pass' : 'warn', __( 'Keyphrase in SEO title', 'rar-wp-commerce-studio-ai' ) );
			$add( 'focus_meta', false !== Util::ipos( $meta, $f ) ? 'pass' : 'warn', __( 'Keyphrase in meta description', 'rar-wp-commerce-studio-ai' ) );
			$add( 'focus_intro', false !== Util::ipos( $first, $f ) ? 'pass' : 'warn', __( 'Keyphrase in the first paragraph', 'rar-wp-commerce-studio-ai' ) );
			$slug_words = str_replace( '-', ' ', urldecode( $slug ) );
			$add( 'focus_slug', false !== Util::ipos( $slug_words, str_replace( '-', ' ', sanitize_title( $f ) ) ) || false !== Util::ipos( $slug_words, $f ) ? 'pass' : 'warn', __( 'Keyphrase in URL slug', 'rar-wp-commerce-studio-ai' ) );
		}
		$h1 = count(
			array_filter(
				$headings,
				static function ( $h ) {
					return 1 === $h['level'];
				}
			)
		);
		$add( 'h1', 0 === $h1 ? 'pass' : 'warn', 0 === $h1 ? __( 'No extra H1 in content (theme shows the title)', 'rar-wp-commerce-studio-ai' ) : sprintf( /* translators: %d: count */ __( '%d H1 heading(s) in content — use H2/H3 instead', 'rar-wp-commerce-studio-ai' ), $h1 ) );
		$prev    = 1;
		$skipped = false;
		foreach ( $headings as $h ) {
			if ( $h['level'] > $prev + 1 ) {
				$skipped = true;
			}
			$prev = $h['level'];
		}
		$add( 'heading_order', $skipped ? 'warn' : 'pass', $skipped ? __( 'Heading levels are skipped (e.g. H2 → H4)', 'rar-wp-commerce-studio-ai' ) : __( 'Heading order is logical', 'rar-wp-commerce-studio-ai' ) );
		$min_words = 'product' === $type ? 80 : 300;
		$add( 'length', $read['words'] >= $min_words ? 'pass' : ( $read['words'] >= $min_words / 2 ? 'warn' : 'fail' ), sprintf( /* translators: 1: words 2: target */ __( 'Content length %1$d words (target %2$d+)', 'rar-wp-commerce-studio-ai' ), $read['words'], $min_words ) );
		if ( $read['words'] >= 30 ) {
			$add( 'sentences', $read['long_sentences_pct'] <= 25 ? 'pass' : 'warn', sprintf( /* translators: %d: percent */ __( '%d%% of sentences are longer than 25 words', 'rar-wp-commerce-studio-ai' ), $read['long_sentences_pct'] ) );
			$add( 'paragraphs', 0 === $read['long_paragraphs'] ? 'pass' : 'warn', 0 === $read['long_paragraphs'] ? __( 'Paragraph length is fine', 'rar-wp-commerce-studio-ai' ) : sprintf( /* translators: %d: count */ __( '%d paragraph(s) longer than 150 words', 'rar-wp-commerce-studio-ai' ), $read['long_paragraphs'] ) );
		}
		$add( 'alt', 0 === $links['images_missing_alt'] ? 'pass' : 'warn', sprintf( /* translators: 1: missing 2: total */ __( 'Images without alt text in content: %1$d of %2$d', 'rar-wp-commerce-studio-ai' ), $links['images_missing_alt'], $links['images'] ) );
		if ( 'product' === $type ) {
			$thumb = (int) get_post_thumbnail_id( $id );
			$add( 'featured_alt', ! $thumb ? 'fail' : ( '' !== (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) ? 'pass' : 'warn' ), ! $thumb ? __( 'No product image', 'rar-wp-commerce-studio-ai' ) : __( 'Product image alt text', 'rar-wp-commerce-studio-ai' ) );
		}
		$add( 'internal_links', $links['internal'] > 0 ? 'pass' : 'warn', sprintf( /* translators: %d: count */ __( '%d internal link(s) in content', 'rar-wp-commerce-studio-ai' ), $links['internal'] ) );

		$weights = array(
			'pass' => 1,
			'warn' => 0.5,
			'fail' => 0,
		);
		$total   = 0;
		foreach ( $checks as $c ) {
			$total += $weights[ $c['status'] ];
		}
		return array(
			'score'       => $checks ? (int) round( 100 * $total / count( $checks ) ) : 0,
			'checks'      => $checks,
			'adapter'     => $adapter,
			'seo'         => array(
				'title'       => $title,
				'description' => $meta,
				'focus'       => $focus,
				'effective'   => $effective,
			),
			'serp'        => array(
				'title' => Util::trim_words( $effective, 70 ),
				'url'   => (string) get_permalink( $id ),
				'desc'  => '' !== $meta ? Util::trim_words( $meta, 160 ) : Util::trim_words( Util::line( $plain ), 160 ),
			),
			'headings'    => $headings,
			'readability' => $read,
			'links'       => $links,
			'elementor'   => Fields::is_elementor( $id ),
			'keywords'    => LocalWriter::keywords( (string) ( $facts['name'] ?? '' ), $plain, 10 ),
		);
	}

	/**
	 * Replace common SEO-plugin variables for preview purposes.
	 *
	 * @param string $title Title template.
	 * @param array  $facts Facts.
	 * @return string
	 */
	private static function expand_vars( $title, array $facts ) {
		$map = array(
			'%%title%%'    => (string) ( $facts['name'] ?? '' ),
			'%%sitename%%' => (string) ( $facts['site'] ?? '' ),
			'%%sep%%'      => '-',
			'%%page%%'     => '',
			'%title%'      => (string) ( $facts['name'] ?? '' ),
			'%sitename%'   => (string) ( $facts['site'] ?? '' ),
			'%sep%'        => '-',
			'%page%'       => '',
		);
		return trim( preg_replace( '/\s+/', ' ', strtr( $title, $map ) ) );
	}

	/**
	 * Internal link suggestions from published content.
	 *
	 * @param string $type Type.
	 * @param int    $id   ID.
	 * @param int    $max  Max suggestions.
	 * @return array[] title, url, type, anchor, in_content
	 */
	public static function internal_links( $type, $id, $max = 8 ) {
		$facts    = Fields::facts( $type, $id );
		$plain    = Util::lower( Util::plain( $facts['description_html'] ?? '' ) );
		$keywords = LocalWriter::keywords( (string) ( $facts['name'] ?? '' ), $plain, 6 );
		$focus    = Seo::active() ? Seo::get( $id, 'focus' ) : '';
		if ( $focus ) {
			array_unshift( $keywords, Util::lower( $focus ) );
		}
		$existing = array();
		preg_match_all( '/href=["\']([^"\']+)["\']/i', (string) ( $facts['description_html'] ?? '' ), $m );
		foreach ( $m[1] as $href ) {
			$existing[ untrailingslashit( $href ) ] = true;
		}
		$types = array( 'post', 'page' );
		if ( Util::wc_active() ) {
			$types[] = 'product';
		}
		$scores = array();
		foreach ( array_slice( array_unique( $keywords ), 0, 5 ) as $kw ) {
			$query = new \WP_Query(
				array(
					's'                      => $kw,
					'post_type'              => $types,
					'post_status'            => 'publish',
					'posts_per_page'         => 6,
					'post__not_in'           => array( (int) $id ),
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $query->posts as $pid ) {
				$scores[ $pid ] = ( $scores[ $pid ] ?? 0 ) + 1;
			}
		}
		arsort( $scores );
		$out = array();
		foreach ( array_slice( array_keys( $scores ), 0, $max * 2 ) as $pid ) {
			$url = get_permalink( $pid );
			if ( ! $url || isset( $existing[ untrailingslashit( $url ) ] ) ) {
				continue;
			}
			$title  = get_the_title( $pid );
			$anchor = '';
			$cands  = array( Util::lower( Util::line( $title ) ) );
			foreach ( $keywords as $kw ) {
				if ( false !== Util::ipos( Util::lower( $title ), $kw ) ) {
					$cands[] = $kw;
				}
			}
			foreach ( $cands as $cand ) {
				if ( Util::len( $cand ) >= 3 && false !== Util::ipos( $plain, $cand ) ) {
					$anchor = $cand;
					break;
				}
			}
			$out[] = array(
				'id'         => (int) $pid,
				'title'      => $title,
				'url'        => $url,
				'type'       => get_post_type( $pid ),
				'anchor'     => $anchor,
				'in_content' => '' !== $anchor,
			);
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Wrap the first plain-text occurrence of $anchor (outside links/headings) in a link.
	 *
	 * @param string $html   HTML.
	 * @param string $anchor Anchor phrase.
	 * @param string $url    URL.
	 * @return string|null New HTML or null when not found.
	 */
	public static function insert_link( $html, $anchor, $url ) {
		$parts = preg_split( '/(<[^>]+>)/u', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$depth = 0;
		$done  = false;
		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[0] ) {
				if ( preg_match( '/^<(a|h[1-6]|script|style|code|pre|button)\b/i', $part ) ) {
					++$depth;
				} elseif ( preg_match( '/^<\/(a|h[1-6]|script|style|code|pre|button)>/i', $part ) ) {
					$depth = max( 0, $depth - 1 );
				}
				continue;
			}
			if ( $done || $depth > 0 ) {
				continue;
			}
			if ( preg_match( '/(?<![\p{L}\p{M}])(' . preg_quote( $anchor, '/' ) . ')(?![\p{L}\p{M}])/iu', $part, $m, PREG_OFFSET_CAPTURE ) ) {
				$parts[ $i ] = substr( $part, 0, $m[1][1] ) . '<a href="' . esc_url( $url ) . '">' . $m[1][0] . '</a>' . substr( $part, $m[1][1] + strlen( $m[1][0] ) );
				$done        = true;
			}
		}
		return $done ? implode( '', $parts ) : null;
	}

	/**
	 * Catalog health scan (cached 6 hours). Scans up to 3,000 products in SQL batches.
	 *
	 * @param bool $refresh Force a rescan.
	 * @return array
	 */
	public static function catalog_health( $refresh = false ) {
		$cached = get_option( 'rar_studio_catalog_health' );
		if ( ! $refresh && is_array( $cached ) && ( time() - (int) ( $cached['scanned_at'] ?? 0 ) ) < 6 * HOUR_IN_SECONDS ) {
			return $cached;
		}
		global $wpdb;
		$keys    = Seo::keys();
		$offset  = 0;
		$stats   = array(
			'products'          => 0,
			'thin'              => array(),
			'no_short'          => array(),
			'no_image'          => array(),
			'image_no_alt'      => array(),
			'no_meta'           => array(),
			'no_seo_title'      => array(),
			'duplicates'        => array(),
		);
		$hashes  = array();
		$simhash = array();
		$thumbs  = array();
		do {
			// phpcs:ignore WordPress.DB
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_content, post_excerpt FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish','draft','pending','private') ORDER BY ID ASC LIMIT 300 OFFSET %d", $offset ), ARRAY_A );
			$ids  = wp_list_pluck( (array) $rows, 'ID' );
			if ( $ids ) {
				update_meta_cache( 'post', $ids );
			}
			foreach ( (array) $rows as $row ) {
				$id = (int) $row['ID'];
				++$stats['products'];
				$text  = Util::line( $row['post_content'] );
				$words = Util::word_count( $text );
				if ( $words < 40 && ! \RarStudio\Fields::is_elementor( $id ) ) {
					$stats['thin'][] = $id;
				}
				if ( '' === trim( Util::line( $row['post_excerpt'] ) ) ) {
					$stats['no_short'][] = $id;
				}
				$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
				if ( ! $thumb ) {
					$stats['no_image'][] = $id;
				} else {
					$thumbs[ $id ] = $thumb;
				}
				if ( $keys ) {
					if ( '' === (string) get_post_meta( $id, $keys['description'], true ) ) {
						$stats['no_meta'][] = $id;
					}
					if ( '' === (string) get_post_meta( $id, $keys['title'], true ) ) {
						$stats['no_seo_title'][] = $id;
					}
				}
				if ( $words >= 15 ) {
					$norm = Util::lower( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) );
					$hash = md5( $norm );
					$hashes[ $hash ][] = $id;
					if ( 1 === count( $hashes[ $hash ] ) ) {
						$simhash[ $id ] = self::simhash( $norm );
					}
				}
			}
			$offset += 300;
		} while ( count( (array) $rows ) === 300 && $offset < 3000 );

		if ( $thumbs ) {
			update_meta_cache( 'post', array_values( $thumbs ) );
			foreach ( $thumbs as $pid => $att ) {
				if ( '' === trim( (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) ) ) {
					$stats['image_no_alt'][] = $pid;
				}
			}
		}

		$groups = array();
		foreach ( $hashes as $ids ) {
			if ( count( $ids ) > 1 ) {
				$groups[] = array(
					'type' => 'exact',
					'ids'  => $ids,
				);
			}
		}
		// Near-duplicates: simhash Hamming distance ≤ 3 within 16-bit buckets.
		$buckets = array();
		foreach ( $simhash as $id => $hash ) {
			for ( $band = 0; $band < 4; $band++ ) {
				$buckets[ $band . ':' . substr( $hash, $band * 4, 4 ) ][] = $id;
			}
		}
		$pairs = array();
		foreach ( $buckets as $ids ) {
			$n = count( $ids );
			if ( $n < 2 || $n > 60 ) {
				continue;
			}
			for ( $i = 0; $i < $n; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$a = min( $ids[ $i ], $ids[ $j ] );
					$b = max( $ids[ $i ], $ids[ $j ] );
					if ( isset( $pairs[ $a . '-' . $b ] ) ) {
						continue;
					}
					if ( self::hamming( $simhash[ $a ], $simhash[ $b ] ) <= 3 ) {
						$pairs[ $a . '-' . $b ] = array( $a, $b );
					}
				}
			}
		}
		foreach ( array_slice( $pairs, 0, 200 ) as $pair ) {
			$groups[] = array(
				'type' => 'near',
				'ids'  => $pair,
			);
		}
		$stats['duplicates'] = array_slice( $groups, 0, 200 );

		$n      = max( 1, $stats['products'] );
		$issues = count( $stats['thin'] ) + count( $stats['no_short'] ) + count( $stats['no_image'] ) + count( $stats['image_no_alt'] ) + ( $keys ? count( $stats['no_meta'] ) : 0 );
		$checks = $keys ? 5 : 4;
		$result = array(
			'scanned_at' => time(),
			'products'   => $stats['products'],
			'score'      => $stats['products'] ? max( 0, (int) round( 100 - 100 * $issues / ( $n * $checks ) ) ) : 0,
			'seo_plugin' => (bool) $keys,
			'counts'     => array(
				'thin'         => count( $stats['thin'] ),
				'no_short'     => count( $stats['no_short'] ),
				'no_image'     => count( $stats['no_image'] ),
				'image_no_alt' => count( $stats['image_no_alt'] ),
				'no_meta'      => count( $stats['no_meta'] ),
				'no_seo_title' => count( $stats['no_seo_title'] ),
				'duplicates'   => count( $stats['duplicates'] ),
			),
			'ids'        => array(
				'thin'         => array_slice( $stats['thin'], 0, 3000 ),
				'no_short'     => array_slice( $stats['no_short'], 0, 3000 ),
				'no_image'     => array_slice( $stats['no_image'], 0, 3000 ),
				'image_no_alt' => array_slice( $stats['image_no_alt'], 0, 3000 ),
				'no_meta'      => array_slice( $stats['no_meta'], 0, 3000 ),
				'no_seo_title' => array_slice( $stats['no_seo_title'], 0, 3000 ),
			),
			'duplicates' => $stats['duplicates'],
		);
		update_option( 'rar_studio_catalog_health', $result, false );
		return $result;
	}

	/**
	 * 64-bit simhash as 16 hex chars over word 3-shingles.
	 *
	 * @param string $text Normalised text.
	 * @return string
	 */
	public static function simhash( $text ) {
		$words = preg_split( '/\s+/u', trim( $text ) );
		$v     = array_fill( 0, 64, 0 );
		$count = count( $words );
		for ( $i = 0; $i < max( 1, $count - 2 ); $i++ ) {
			$shingle = implode( ' ', array_slice( $words, $i, 3 ) );
			$h       = md5( $shingle );
			for ( $part = 0; $part < 2; $part++ ) {
				$chunk = hexdec( substr( $h, $part * 8, 8 ) );
				for ( $bit = 0; $bit < 32; $bit++ ) {
					$v[ $part * 32 + $bit ] += ( ( $chunk >> $bit ) & 1 ) ? 1 : -1;
				}
			}
		}
		$hex = '';
		for ( $nibble = 0; $nibble < 16; $nibble++ ) {
			$val = 0;
			for ( $b = 0; $b < 4; $b++ ) {
				if ( $v[ $nibble * 4 + $b ] > 0 ) {
					$val |= 1 << $b;
				}
			}
			$hex .= dechex( $val );
		}
		return $hex;
	}

	/**
	 * Hamming distance between two hex simhashes.
	 *
	 * @param string $a Hash.
	 * @param string $b Hash.
	 * @return int
	 */
	public static function hamming( $a, $b ) {
		$d = 0;
		for ( $i = 0; $i < 16; $i++ ) {
			$x = hexdec( $a[ $i ] ) ^ hexdec( $b[ $i ] );
			while ( $x ) {
				$d += $x & 1;
				$x >>= 1;
			}
		}
		return $d;
	}
}
