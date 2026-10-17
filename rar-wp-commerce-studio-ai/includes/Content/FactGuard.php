<?php
/**
 * Fact guard: flags numbers, measurements, certifications, health/guarantee
 * claims, prices and banned words that appear in generated text but NOT in
 * the store's own product data. Blocking issues require explicit
 * "I verified" acknowledgement before a draft can be applied.
 *
 * @package RarStudio
 */

namespace RarStudio\Content;

use RarStudio\Settings;
use RarStudio\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Generated-content verifier.
 */
final class FactGuard {

	/**
	 * Claim patterns: [regex, severity, label].
	 *
	 * @return array
	 */
	public static function claim_patterns() {
		return array(
			array( '/\b(certified|certificate|certification|approved|accredited|licen[cs]ed)\b/iu', 'block', 'certification claim' ),
			array( '/\b(fda|bsti|iso\s?\d*|gmp|halal|kosher|ecocert|usda|ce\s?mark(ed)?)\b/iu', 'block', 'certification / standard' ),
			array( '/\b(organic|vegan|cruelty[\s-]?free|paraben[\s-]?free|alcohol[\s-]?free|sulph?ate[\s-]?free|hypoallergenic|non[\s-]?toxic|chemical[\s-]?free)\b/iu', 'block', 'ingredient / formulation claim' ),
			array( '/\b(clinically|dermatologically|laboratory|lab)[\s-]?(tested|proven|approved)\b/iu', 'block', 'testing claim' ),
			array( '/\b(cures?|heals?|treats?|prevents?|therapeutic|medical[\s-]?grade|anti[\s-]?bacterial|antiseptic|kills?\s+\d*\s*%?\s*(of\s+)?germs)\b/iu', 'block', 'health / medical claim' ),
			array( '/\b(guarantee[sd]?|warrant(y|ies)|money[\s-]?back|lifetime)\b/iu', 'block', 'guarantee / warranty' ),
			array( '/\b(100\s?%\s?(original|authentic|genuine|natural|pure)|authentic|genuine)\b/iu', 'block', 'authenticity claim' ),
			array( '/(৳|\btk\.?\b|\btaka\b|\bbdt\b|\$|€|£|\busd\b|\bprice\b|\bdiscount\b|\b\d+\s?%\s?off\b|\bsale\b|\bfree\s+(delivery|shipping)\b)/iu', 'block', 'price / offer' ),
			array( '/\b(award[\s-]?winning|no\.?\s?1|#\s?1|number\s+one|best[\s-]?selling|world\'?s\s+best|best\s+in)\b/iu', 'warn', 'superlative claim' ),
			array( '/\b(long[\s-]?lasting|all[\s-]?day|24\s?h(ou)?rs?|waterproof|sweat[\s-]?proof)\b/iu', 'warn', 'performance claim' ),
			array( '/(সার্টিফাইড|অনুমোদিত|হালাল|অর্গানিক|প্রাকৃতিক|ভেষজ|ক্লিনিক্যালি|গ্যারান্টি|ওয়ারেন্টি|নিরাময়|রোগ\s?নিরাময়|জীবাণু|আসল|অরিজিনাল|১০০%|ফ্রি\s?ডেলিভারি|ছাড়|মূল্য|দাম|টাকা)/u', 'block', 'claim (Bangla)' ),
			array( '/(সেরা|এক\s?নম্বর|দীর্ঘস্থায়ী|সারাদিন)/u', 'warn', 'superlative / performance (Bangla)' ),
		);
	}

	/**
	 * Normalise text for comparison.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function norm( $text ) {
		$text = Util::lower( Util::ascii_digits( Util::plain( $text ) ) );
		$text = preg_replace( '/(\d),(\d{3})/u', '$1$2', $text );
		return (string) $text;
	}

	/**
	 * Numbers (with an optional unit) found in a text.
	 *
	 * @param string $text Normalised text.
	 * @return string[] tokens like "100ml", "3", "24h".
	 */
	private static function numbers( $text ) {
		preg_match_all( '/\d+(?:[.,]\d+)?(?:\s?(?:ml|mlt|ltr|litre|liter|l|gm|grams|gram|g|kg|mg|oz|cm|mm|m|inch|pcs|pc|pieces|pack|x|%|hrs|hr|hours|hour|h|days|day|months|month|years|year|মিলি|গ্রাম|কেজি|লিটার|পিস|ঘণ্টা|দিন|মাস|বছর)(?![\p{L}]))?/u', $text, $m );
		$out = array();
		foreach ( $m[0] as $token ) {
			$out[] = preg_replace( '/\s+/', '', $token );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Check a generated value against the source facts.
	 *
	 * @param string $generated Generated text/HTML.
	 * @param string $source    Store's own data (Fields::source_text()).
	 * @param string $original  The field's current value (also trusted).
	 * @return array List of issues: type, severity, text, label.
	 */
	public static function check( $generated, $source, $original = '' ) {
		$issues = array();
		$gen    = self::norm( $generated );
		$src    = self::norm( $source . "\n" . $original );
		if ( '' === trim( $gen ) ) {
			return $issues;
		}

		$src_numbers = self::numbers( $src );
		$src_bare    = array();
		foreach ( $src_numbers as $token ) {
			$src_bare[] = preg_replace( '/[^\d.,]/', '', $token );
		}
		foreach ( self::numbers( $gen ) as $token ) {
			$bare = preg_replace( '/[^\d.,]/', '', $token );
			if ( in_array( $token, $src_numbers, true ) || in_array( $bare, $src_bare, true ) ) {
				continue;
			}
			// Years and tiny list counters are still numbers the store did not state.
			$issues[] = array(
				'type'     => 'number',
				'severity' => 'block',
				'label'    => __( 'Number / measurement not in your product data', 'rar-wp-commerce-studio-ai' ),
				'text'     => $token,
			);
		}

		$strict = (int) Settings::get( 'guard.strict', 1 );
		foreach ( self::claim_patterns() as $pattern ) {
			if ( ! preg_match_all( $pattern[0], $gen, $m ) ) {
				continue;
			}
			foreach ( array_unique( array_map( 'trim', $m[0] ) ) as $hit ) {
				if ( '' === $hit || false !== Util::ipos( $src, $hit ) ) {
					continue;
				}
				$issues[] = array(
					'type'     => 'claim',
					'severity' => ( 'block' === $pattern[1] && $strict ) ? 'block' : 'warn',
					'label'    => $pattern[2],
					'text'     => $hit,
				);
			}
		}

		$banned = Settings::get( 'brand.banned', '' );
		foreach ( preg_split( '/[,\n]+/u', (string) $banned ) as $word ) {
			$word = trim( Util::lower( $word ) );
			if ( '' !== $word && false !== Util::ipos( $gen, $word ) ) {
				$issues[] = array(
					'type'     => 'banned',
					'severity' => 'block',
					'label'    => __( 'Banned word from your brand settings', 'rar-wp-commerce-studio-ai' ),
					'text'     => $word,
				);
			}
		}

		if ( preg_match( '/<script|javascript:|on\w+\s*=/i', (string) $generated ) ) {
			$issues[] = array(
				'type'     => 'unsafe',
				'severity' => 'block',
				'label'    => __( 'Unsafe markup removed on apply', 'rar-wp-commerce-studio-ai' ),
				'text'     => 'script',
			);
		}

		// De-duplicate.
		$seen = array();
		$out  = array();
		foreach ( $issues as $issue ) {
			$key = $issue['type'] . '|' . $issue['text'];
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$out[]        = $issue;
			}
		}
		return $out;
	}

	/**
	 * Does an issue list contain blocking issues?
	 *
	 * @param array $issues Issues.
	 * @return bool
	 */
	public static function has_blockers( array $issues ) {
		foreach ( $issues as $issue ) {
			if ( isset( $issue['severity'] ) && 'block' === $issue['severity'] ) {
				return true;
			}
		}
		return false;
	}
}

