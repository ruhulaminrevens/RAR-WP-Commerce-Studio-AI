<?php
/**
 * REST API for the Studio screens (namespace rar-studio/v1).
 * Every route has a permission callback; WordPress verifies the
 * X-WP-Nonce cookie nonce for logged-in requests.
 *
 * @package RarStudio
 */

namespace RarStudio;

use RarStudio\Ai\Gateway;
use RarStudio\Ai\Registry;
use RarStudio\Ai\Usage;
use RarStudio\Bulk\Jobs;
use RarStudio\Content\Analyzer;
use RarStudio\Content\Generator;
use RarStudio\Content\LocalWriter;
use RarStudio\Content\Prompts;
use RarStudio\Image\Processor;
use RarStudio\Image\Studio;
use RarStudio\Seo\Manager as Seo;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
final class Rest {

	const NS = 'rar-studio/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register() {
		$studio = array( __CLASS__, 'perm_studio' );
		$admin  = array( __CLASS__, 'perm_admin' );
		$type   = '(?P<type>product|post|page|attachment)';

		$routes = array(
			array( '/bootstrap', 'GET', 'bootstrap', $studio ),
			array( '/health/scan', 'POST', 'health_scan', $studio ),
			array( '/taxonomies', 'GET', 'taxonomies', $studio ),
			array( '/objects/search', 'GET', 'search', $studio ),
			array( '/objects/' . $type . '/(?P<id>\d+)', 'GET', 'object', $studio ),
			array( '/generate', 'POST', 'generate', $studio ),
			array( '/estimate', 'POST', 'estimate', $studio ),
			array( '/manual-prompt', 'POST', 'manual_prompt', $studio ),
			array( '/import', 'POST', 'import', $studio ),
			array( '/drafts', 'GET', 'drafts', $studio ),
			array( '/drafts/batch', 'POST', 'drafts_batch', $studio ),
			array( '/drafts/create', 'POST', 'drafts_create', $studio ),
			array( '/drafts/(?P<id>\d+)', 'POST', 'draft_update', $studio ),
			array( '/drafts/(?P<id>\d+)/(?P<action>apply|reject|rollback)', 'POST', 'draft_action', $studio ),
			array( '/images/process', 'POST', 'image_process', $studio ),
			array( '/images/scene', 'POST', 'image_scene', $studio ),
			array( '/images/alt', 'POST', 'image_alt', $studio ),
			array( '/images/(?P<id>\d+)', 'DELETE', 'image_delete', $studio ),
			array( '/media', 'GET', 'media', $studio ),
			array( '/content/' . $type . '/(?P<id>\d+)/links', 'GET', 'links', $studio ),
			array( '/content/link', 'POST', 'link_insert', $studio ),
			array( '/article', 'POST', 'article', $studio ),
			array( '/jobs', 'GET', 'jobs', $studio ),
			array( '/jobs', 'POST', 'job_create', $studio ),
			array( '/jobs/preview', 'POST', 'job_preview', $studio ),
			array( '/jobs/tick', 'POST', 'job_tick', $studio ),
			array( '/jobs/(?P<id>\d+)', 'GET', 'job', $studio ),
			array( '/jobs/(?P<id>\d+)/(?P<action>pause|resume|cancel|retry)', 'POST', 'job_control', $studio ),
			array( '/jobs/(?P<id>\d+)/drafts', 'POST', 'job_drafts', $studio ),
			array( '/ai', 'GET', 'ai_status', $studio ),
			array( '/ai/master', 'POST', 'ai_master', $admin ),
			array( '/ai/key', 'POST', 'ai_key', $admin ),
			array( '/ai/models', 'POST', 'ai_models', $admin ),
			array( '/settings', 'GET', 'settings_get', $studio ),
			array( '/settings', 'POST', 'settings_save', $admin ),
			array( '/logs', 'GET', 'logs', $studio ),
			array( '/logs', 'DELETE', 'logs_clear', $admin ),
			array( '/prefs', 'POST', 'prefs', $studio ),
		);
		foreach ( $routes as $r ) {
			register_rest_route(
				self::NS,
				$r[0],
				array(
					'methods'             => $r[1],
					'callback'            => array( __CLASS__, $r[2] ),
					'permission_callback' => $r[3],
				)
			);
		}
	}

	/**
	 * Studio users (shop managers and administrators).
	 *
	 * @return bool
	 */
	public static function perm_studio() {
		return Util::can_use_studio();
	}

	/**
	 * Administrators only.
	 *
	 * @return bool
	 */
	public static function perm_admin() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Convert results to REST responses.
	 *
	 * @param mixed $result Result or WP_Error.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function out( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			if ( empty( $data['status'] ) || ! is_int( $data['status'] ) || $data['status'] < 400 ) {
				$data['status'] = 400;
			}
			$result->add_data( $data );
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Guard object access.
	 *
	 * @param string $type Type.
	 * @param int    $id   ID.
	 * @return true|\WP_Error
	 */
	private static function can_object( $type, $id ) {
		if ( ! Util::can_edit_object( $type, $id ) ) {
			return new \WP_Error( 'rar_forbidden', __( 'You cannot edit this item, or it does not exist.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Fields array from a request.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return string[]
	 */
	private static function fields( $r ) {
		return array_values( array_filter( array_map( 'sanitize_key', (array) $r->get_param( 'fields' ) ) ) );
	}

	/**
	 * Language parameter.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return string
	 */
	private static function lang( $r ) {
		$lang = (string) $r->get_param( 'language' );
		return in_array( $lang, array( 'en', 'bn', 'bilingual' ), true ) ? $lang : (string) Settings::get( 'general.language', 'en' );
	}

	/**
	 * Environment info for the dashboard.
	 *
	 * @return array
	 */
	public static function environment() {
		$hpos = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return array(
			'plugin'      => RAR_STUDIO_VERSION,
			'php'         => PHP_VERSION,
			'wp'          => get_bloginfo( 'version' ),
			'wc'          => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'hpos'        => $hpos,
			'gd'          => Processor::supports( 'gd' ),
			'webp'        => Processor::supports( 'webp' ),
			'avif'        => Processor::supports( 'avif' ),
			'memory'      => (string) ini_get( 'memory_limit' ),
			'max_exec'    => (int) ini_get( 'max_execution_time' ),
			'https'       => is_ssl(),
			'cron'        => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			'seo'         => Seo::status(),
			'elementor'   => defined( 'ELEMENTOR_VERSION' ),
			'encryption'  => Crypto::available(),
		);
	}

	/**
	 * Dashboard bootstrap.
	 *
	 * @return \WP_REST_Response
	 */
	public static function bootstrap() {
		$health = get_option( 'rar_studio_catalog_health' );
		if ( is_array( $health ) ) {
			unset( $health['ids'] );
		}
		$usage = Usage::summary();
		unset( $usage['recent'] );
		return self::out(
			array(
				'health'      => is_array( $health ) ? $health : null,
				'drafts'      => Drafts::counts(),
				'jobs'        => Jobs::all( 5 ),
				'ai'          => Gateway::status(),
				'usage'       => $usage,
				'logs'        => Logger::query( array( 'per_page' => 8 ) )['items'],
				'environment' => self::environment(),
			)
		);
	}

	/**
	 * Rescan catalog health.
	 *
	 * @return \WP_REST_Response
	 */
	public static function health_scan() {
		$health = Analyzer::catalog_health( true );
		unset( $health['ids'] );
		return self::out( $health );
	}

	/**
	 * Categories and brands for filters.
	 *
	 * @return \WP_REST_Response
	 */
	public static function taxonomies() {
		$out = array(
			'categories' => array(),
			'brands'     => array(),
		);
		foreach ( array( 'categories' => 'product_cat', 'brands' => 'product_brand' ) as $key => $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'number'     => 500,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$out[ $key ][] = array(
					'id'    => (int) $term->term_id,
					'name'  => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'count' => (int) $term->count,
				);
			}
		}
		return self::out( $out );
	}

	/**
	 * Search products, posts, pages or media.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function search( $r ) {
		$type = (string) $r->get_param( 'type' );
		$type = in_array( $type, array( 'product', 'post', 'page', 'attachment', 'content' ), true ) ? $type : 'product';
		$q    = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		$ids  = array();
		if ( 'product' === $type && ! Util::wc_active() ) {
			return self::out( array() );
		}
		$post_types = 'content' === $type ? array( 'post', 'page' ) : array( $type );
		if ( ctype_digit( $q ) && get_post( (int) $q ) && in_array( get_post_type( (int) $q ), $post_types, true ) ) {
			$ids[] = (int) $q;
		}
		if ( 'product' === $type && '' !== $q ) {
			global $wpdb;
			$sku_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s LIMIT 10", '%' . $wpdb->esc_like( $q ) . '%' ) ); // phpcs:ignore WordPress.DB
			foreach ( $sku_ids as $sid ) {
				$parent = (int) wp_get_post_parent_id( (int) $sid );
				$ids[]  = $parent ? $parent : (int) $sid;
			}
		}
		$query = new \WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'attachment' === $type ? 'inherit' : array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'post_mime_type' => 'attachment' === $type ? 'image' : '',
				's'              => $q,
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'orderby'        => '' === $q ? 'modified' : 'relevance',
				'no_found_rows'  => true,
			)
		);
		$ids = array_slice( array_values( array_unique( array_merge( $ids, array_map( 'intval', $query->posts ) ) ) ), 0, 20 );
		$out = array();
		foreach ( $ids as $id ) {
			$ptype = get_post_type( $id );
			if ( ! in_array( $ptype, $post_types, true ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$thumb = 'attachment' === $ptype ? wp_get_attachment_image_src( $id, 'thumbnail' ) : wp_get_attachment_image_src( (int) get_post_thumbnail_id( $id ), 'thumbnail' );
			$item  = array(
				'id'     => $id,
				'type'   => $ptype,
				'title'  => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'status' => get_post_status( $id ),
				'image'  => $thumb ? $thumb[0] : '',
				'sku'    => '',
			);
			if ( 'product' === $ptype ) {
				$product     = wc_get_product( $id );
				$item['sku'] = $product ? (string) $product->get_sku() : '';
			}
			$out[] = $item;
		}
		return self::out( $out );
	}

	/**
	 * Full object payload for the studios.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function object( $r ) {
		$type = (string) $r['type'];
		$id   = absint( $r['id'] );
		$ok   = self::can_object( $type, $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$fields = array();
		foreach ( Fields::definitions( $type ) as $key => $def ) {
			$value          = Fields::get( $type, $id, $key );
			$fields[ $key ] = array_merge(
				$def,
				array(
					'value'  => is_wp_error( $value ) ? '' : $value,
					'locked' => Fields::locked_reason( $type, $id, $key ),
					'local'  => in_array( $key, LocalWriter::supported( $type ), true ),
					'ai'     => in_array( $key, Generator::ai_fields( $type ), true ),
				)
			);
		}
		$facts   = Fields::facts( $type, $id );
		$summary = array(
			'id'        => $id,
			'type'      => $type,
			'title'     => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'status'    => get_post_status( $id ),
			'edit_url'  => get_edit_post_link( $id, 'raw' ),
			'view_url'  => get_permalink( $id ),
			'elementor' => Fields::is_elementor( $id ),
		);
		if ( 'product' === $type ) {
			$product                 = wc_get_product( $id );
			$summary['sku']          = $product ? (string) $product->get_sku() : '';
			$summary['price_html']   = $product && '' !== $product->get_price() ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_price() ) ), ENT_QUOTES, 'UTF-8' ) : '';
			$summary['stock']        = $product ? (string) $product->get_stock_status() : '';
			$summary['stock_qty']    = $product && $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
			$summary['product_type'] = $product ? $product->get_type() : '';
			$summary['attributes']   = $facts['attributes'] ?? array();
			$summary['images']       = Studio::product_images( $id );
		}
		if ( 'attachment' === $type ) {
			$summary['image'] = Studio::describe( $id );
		}
		$report = 'attachment' === $type ? null : Analyzer::seo_report( $type, $id );
		$drafts = Drafts::query(
			array(
				'object_type' => $type,
				'object_id'   => $id,
				'status'      => array( 'pending', 'applied', 'rolled_back' ),
				'per_page'    => 60,
			)
		);
		return self::out(
			array(
				'summary' => $summary,
				'fields'  => $fields,
				'report'  => $report,
				'drafts'  => $drafts['items'],
			)
		);
	}

	/**
	 * Generate drafts (local or AI).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function generate( $r ) {
		$type = (string) $r->get_param( 'type' );
		$id   = absint( $r->get_param( 'id' ) );
		$ok   = self::can_object( $type, $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$engine = 'ai' === $r->get_param( 'engine' ) ? 'ai' : 'local';
		if ( 'attachment' === $type && 'ai' === $engine ) {
			return self::out( Generator::ai_alt( $id, array( 'language' => self::lang( $r ) ) ) );
		}
		return self::out(
			Generator::generate(
				$type,
				$id,
				self::fields( $r ),
				$engine,
				array(
					'language'    => self::lang( $r ),
					'instruction' => (string) $r->get_param( 'instruction' ),
					'provider'    => sanitize_key( (string) $r->get_param( 'provider' ) ),
				)
			)
		);
	}

	/**
	 * Cost estimate before an AI request.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function estimate( $r ) {
		$type = (string) $r->get_param( 'type' );
		$id   = absint( $r->get_param( 'id' ) );
		$ok   = self::can_object( $type, $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$fields  = array_values( array_intersect( self::fields( $r ), Generator::ai_fields( $type ) ) );
		$request = Prompts::content_request( $type, $id, $fields ? $fields : array( 'seo_title' ), array( 'language' => self::lang( $r ) ) );
		$request['max_tokens'] = (int) Settings::get( 'ai.max_output_tokens', 1500 );
		$est                   = Gateway::estimate( $request );
		$est['reason']         = Gateway::unavailable_reason();
		$est['free_tier']      = (int) Settings::get( 'ai.providers.' . $est['provider'] . '.free_tier', 0 );
		$usage                 = Usage::summary();
		$est['today']          = $usage['today_requests'];
		$est['daily_limit']    = $usage['daily_limit'];
		$est['month_cost']     = $usage['month_cost'];
		$est['budget']         = $usage['monthly_budget'];
		return self::out( $est );
	}

	/**
	 * Prompt for the manual subscription workflow.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function manual_prompt( $r ) {
		$type = (string) $r->get_param( 'type' );
		$id   = absint( $r->get_param( 'id' ) );
		$ok   = self::can_object( $type, $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$prompt = Generator::manual_prompt( $type, $id, self::fields( $r ), array( 'language' => self::lang( $r ), 'instruction' => (string) $r->get_param( 'instruction' ) ) );
		if ( '' === $prompt ) {
			return new \WP_Error( 'rar_prompt', __( 'Choose at least one field that can be written for this item.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		return self::out( array( 'prompt' => $prompt ) );
	}

	/**
	 * Import pasted subscription answer.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import( $r ) {
		$type = (string) $r->get_param( 'type' );
		$id   = absint( $r->get_param( 'id' ) );
		$ok   = self::can_object( $type, $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$text = (string) $r->get_param( 'text' );
		if ( strlen( $text ) > 100000 ) {
			return new \WP_Error( 'rar_import', __( 'The pasted text is too long.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		return self::out( Generator::import( $type, $id, self::fields( $r ), $text, sanitize_key( (string) $r->get_param( 'app' ) ) ) );
	}

	/**
	 * Draft list.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function drafts( $r ) {
		$status = $r->get_param( 'status' );
		$result = Drafts::query(
			array(
				'object_type' => (string) $r->get_param( 'object_type' ),
				'object_id'   => absint( $r->get_param( 'object_id' ) ),
				'job_id'      => absint( $r->get_param( 'job_id' ) ),
				'status'      => $status ? explode( ',', (string) $status ) : array(),
				'page'        => absint( $r->get_param( 'page' ) ),
				'per_page'    => absint( $r->get_param( 'per_page' ) ),
			)
		);
		// Only show suggestions for items this user may edit.
		$result['items'] = array_values(
			array_filter(
				$result['items'],
				static function ( $d ) {
					return current_user_can( 'edit_post', $d['object_id'] );
				}
			)
		);
		return self::out( $result );
	}

	/**
	 * Create drafts from values typed by the user (e.g. SEO Studio editor).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function drafts_create( $r ) {
		$type = (string) $r->get_param( 'type' );
		$id   = absint( $r->get_param( 'id' ) );
		$ok   = self::can_object( $type, $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$values = array();
		foreach ( (array) $r->get_param( 'values' ) as $field => $value ) {
			$field = sanitize_key( $field );
			if ( Fields::exists( $type, $field ) && is_scalar( $value ) && ! in_array( Fields::definitions( $type )[ $field ]['kind'], array( 'image', 'gallery' ), true ) ) {
				$values[ $field ] = (string) $value;
			}
		}
		if ( ! $values ) {
			return new \WP_Error( 'rar_values', __( 'Nothing to save.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$skipped = array();
		foreach ( array_keys( $values ) as $field ) {
			$locked = Fields::locked_reason( $type, $id, $field );
			if ( $locked ) {
				$skipped[ $field ] = $locked;
				unset( $values[ $field ] );
			}
		}
		return self::out(
			array(
				'drafts'  => Generator::to_drafts( $type, $id, $values, __( 'Manual edit', 'rar-wp-commerce-studio-ai' ), array(), $skipped ),
				'skipped' => $skipped,
			)
		);
	}

	/**
	 * Edit a draft.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function draft_update( $r ) {
		return self::out( Drafts::update_proposed( absint( $r['id'] ), (string) $r->get_param( 'proposed' ) ) );
	}

	/**
	 * Apply / reject / rollback.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function draft_action( $r ) {
		$id   = absint( $r['id'] );
		$opts = array(
			'ack'   => (bool) $r->get_param( 'ack' ),
			'force' => (bool) $r->get_param( 'force' ),
		);
		switch ( $r['action'] ) {
			case 'apply':
				return self::out( Drafts::apply( $id, $opts ) );
			case 'reject':
				return self::out( Drafts::reject( $id ) );
		}
		return self::out( Drafts::rollback( $id, $opts ) );
	}

	/**
	 * Batch draft actions (max 50).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function drafts_batch( $r ) {
		$ids    = array_slice( array_filter( array_map( 'absint', (array) $r->get_param( 'ids' ) ) ), 0, 50 );
		$action = (string) $r->get_param( 'action' );
		$ack    = (bool) $r->get_param( 'ack' );
		$out    = array();
		foreach ( $ids as $id ) {
			if ( 'apply' === $action ) {
				$res = Drafts::apply( $id, array( 'ack' => $ack ) );
			} elseif ( 'reject' === $action ) {
				$res = Drafts::reject( $id );
			} elseif ( 'rollback' === $action ) {
				$res = Drafts::rollback( $id );
			} else {
				$res = new \WP_Error( 'rar_action', 'Unknown action' );
			}
			$out[] = array(
				'id'    => $id,
				'ok'    => ! is_wp_error( $res ),
				'error' => is_wp_error( $res ) ? $res->get_error_message() : '',
				'code'  => is_wp_error( $res ) ? $res->get_error_code() : '',
			);
		}
		return self::out( $out );
	}

	/**
	 * Image options from a request merged with defaults.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	private static function image_options( $r ) {
		return Processor::sanitize_options( array_merge( (array) Settings::get( 'image', array() ), (array) $r->get_param( 'options' ) ) );
	}

	/**
	 * Check attachment + optional product access for image actions.
	 *
	 * @param int $att     Attachment.
	 * @param int $product Product.
	 * @return true|\WP_Error
	 */
	private static function can_image( $att, $product ) {
		if ( $product ) {
			$ok = self::can_object( 'product', $product );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		if ( ! wp_attachment_is_image( $att ) || ! current_user_can( 'edit_post', $att ) ) {
			return new \WP_Error( 'rar_forbidden', __( 'Image not found or not editable.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'rar_forbidden', __( 'You are not allowed to add media.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Process an image into a derivative (+draft).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function image_process( $r ) {
		$att     = absint( $r->get_param( 'attachment_id' ) );
		$product = absint( $r->get_param( 'product_id' ) );
		$ok      = self::can_image( $att, $product );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$role = in_array( $r->get_param( 'role' ), array( 'featured', 'gallery', 'media' ), true ) ? $r->get_param( 'role' ) : 'media';
		if ( $r->get_param( 'save_default' ) && current_user_can( 'manage_options' ) ) {
			Settings::sanitize_update( array( 'image' => (array) $r->get_param( 'options' ) ) );
		}
		return self::out(
			Studio::create(
				$att,
				self::image_options( $r ),
				array(
					'product_id' => $product,
					'role'       => $product ? $role : 'media',
				)
			)
		);
	}

	/**
	 * AI background scene (optional).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function image_scene( $r ) {
		$att     = absint( $r->get_param( 'attachment_id' ) );
		$product = absint( $r->get_param( 'product_id' ) );
		$ok      = self::can_image( $att, $product );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$scene = trim( (string) $r->get_param( 'scene' ) );
		if ( '' === $scene ) {
			return new \WP_Error( 'rar_scene', __( 'Describe the background scene.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$role = in_array( $r->get_param( 'role' ), array( 'featured', 'gallery' ), true ) ? $r->get_param( 'role' ) : 'media';
		return self::out( Studio::scene( $att, $scene, self::image_options( $r ), array( 'product_id' => $product, 'role' => $product ? $role : 'media' ) ) );
	}

	/**
	 * Alt text draft (local or AI) for one image.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function image_alt( $r ) {
		$att     = absint( $r->get_param( 'attachment_id' ) );
		$product = absint( $r->get_param( 'product_id' ) );
		$ok      = self::can_image( $att, $product );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( 'ai' === $r->get_param( 'engine' ) ) {
			return self::out( Generator::ai_alt( $att, array( 'product_id' => $product, 'language' => self::lang( $r ) ) ) );
		}
		$skipped = array();
		$values  = LocalWriter::generate( 'attachment', $att, array( 'alt' ), array( 'product_id' => $product ) );
		if ( ! $values ) {
			return new \WP_Error( 'rar_alt', __( 'No meaningful local alt text could be made (generic file name). Use AI vision or write it yourself.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		return self::out(
			array(
				'drafts'  => Generator::to_drafts( 'attachment', $att, $values, __( 'Free local engine', 'rar-wp-commerce-studio-ai' ), array( 'meta' => $product ? array( 'product_id' => $product ) : array() ), $skipped ),
				'skipped' => $skipped,
			)
		);
	}

	/**
	 * Delete an unused Studio derivative.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function image_delete( $r ) {
		$res = Studio::delete_derivative( absint( $r['id'] ) );
		return self::out( is_wp_error( $res ) ? $res : array( 'deleted' => true ) );
	}

	/**
	 * Media list (optionally only images missing alt).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function media( $r ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => 24,
			'paged'          => max( 1, absint( $r->get_param( 'page' ) ) ),
			'fields'         => 'ids',
		);
		if ( $r->get_param( 'missing_alt' ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_wp_attachment_image_alt',
					'value' => '',
				),
			);
		}
		if ( $r->get_param( 'q' ) ) {
			$args['s'] = sanitize_text_field( (string) $r->get_param( 'q' ) );
		}
		$q     = new \WP_Query( $args );
		$items = array();
		foreach ( $q->posts as $id ) {
			$items[] = Studio::describe( (int) $id );
		}
		return self::out(
			array(
				'items' => $items,
				'total' => (int) $q->found_posts,
				'pages' => (int) $q->max_num_pages,
			)
		);
	}

	/**
	 * Internal link suggestions.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function links( $r ) {
		$ok = self::can_object( (string) $r['type'], absint( $r['id'] ) );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return self::out( Analyzer::internal_links( (string) $r['type'], absint( $r['id'] ) ) );
	}

	/**
	 * Create a content draft that adds one internal link.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function link_insert( $r ) {
		$type = (string) $r->get_param( 'type' );
		$id   = absint( $r->get_param( 'id' ) );
		$ok   = self::can_object( $type, $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$field  = 'product' === $type ? 'description' : 'content';
		$locked = Fields::locked_reason( $type, $id, $field );
		if ( $locked ) {
			return new \WP_Error( 'rar_locked', $locked, array( 'status' => 409 ) );
		}
		$url    = esc_url_raw( (string) $r->get_param( 'url' ) );
		$target = url_to_postid( $url );
		if ( ! $target || 'publish' !== get_post_status( $target ) ) {
			return new \WP_Error( 'rar_link', __( 'Only links to published content on this site can be inserted.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$current = Fields::get( $type, $id, $field );
		$new     = Analyzer::insert_link( (string) $current, sanitize_text_field( (string) $r->get_param( 'anchor' ) ), get_permalink( $target ) );
		if ( null === $new ) {
			return new \WP_Error( 'rar_link', __( 'The anchor text was not found in plain text (outside headings and existing links).', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$skipped = array();
		return self::out(
			array(
				'drafts'  => Generator::to_drafts( $type, $id, array( $field => $new ), __( 'Internal link', 'rar-wp-commerce-studio-ai' ), array(), $skipped ),
				'skipped' => $skipped,
			)
		);
	}

	/**
	 * New article draft.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function article( $r ) {
		$type = 'page' === $r->get_param( 'post_type' ) ? 'page' : 'post';
		$cap  = 'page' === $type ? 'edit_pages' : 'edit_posts';
		if ( ! current_user_can( $cap ) ) {
			return new \WP_Error( 'rar_forbidden', __( 'You cannot create this content type.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 403 ) );
		}
		$engine = 'ai' === $r->get_param( 'engine' ) ? 'ai' : 'manual';
		$brief  = (string) $r->get_param( 'brief' );
		if ( 'ai' === $engine && Util::len( trim( $brief ) ) < 20 ) {
			return new \WP_Error( 'rar_article', __( 'Write a brief of at least 20 characters.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		if ( 'manual' === $engine && '' === trim( (string) $r->get_param( 'pasted' ) ) ) {
			$request = array(
				'system' => Prompts::render( 'system', array() ),
				'prompt' => Prompts::render( 'article', array( 'brief' => Util::limit( sanitize_textarea_field( $brief ), 4000 ), 'language_rule' => Prompts::language_rule( self::lang( $r ) ) ) ),
			);
			return self::out( array( 'prompt' => Prompts::manual_prompt( $request ) ) );
		}
		return self::out( Generator::article( $brief, $engine, (string) $r->get_param( 'pasted' ), $type ) );
	}

	/**
	 * Recent jobs.
	 *
	 * @return \WP_REST_Response
	 */
	public static function jobs() {
		return self::out(
			array(
				'jobs'       => Jobs::all( 30 ),
				'operations' => Jobs::operations(),
			)
		);
	}

	/**
	 * Preview target count (and AI estimate).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function job_preview( $r ) {
		$ops = Jobs::operations();
		$op  = (string) $r->get_param( 'operation' );
		if ( ! isset( $ops[ $op ] ) ) {
			return new \WP_Error( 'rar_job', __( 'Unknown bulk operation.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$ids = Jobs::resolve_targets( $ops[ $op ]['target'], (array) $r->get_param( 'filters' ) );
		$out = array(
			'count'  => count( $ids ),
			'sample' => array_map(
				static function ( $id ) {
					return array(
						'id'    => $id,
						'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
					);
				},
				array_slice( $ids, 0, 8 )
			),
		);
		if ( $ops[ $op ]['ai'] && $ids ) {
			$out['reason'] = Gateway::unavailable_reason();
			if ( 'alt_ai' === $op ) {
				$request = array(
					'system'     => Prompts::render( 'system', array() ),
					'prompt'     => Prompts::render( 'alt_text', array( 'name' => '' ) ),
					'images'     => array( array() ),
					'max_tokens' => 200,
				);
			} else {
				$fields                = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $r->get_param( 'options' )['fields'] ?? array() ) ), Generator::ai_fields( 'product' ) ) );
				$request               = Prompts::content_request( 'product', $ids[0], $fields ? $fields : array( 'seo_title' ), array() );
				$request['max_tokens'] = (int) Settings::get( 'ai.max_output_tokens', 1500 );
			}
			$est                  = Gateway::estimate( $request );
			$out['estimate']      = $est;
			$out['estimate_total'] = round( $est['cost'] * count( $ids ), 4 );
			$usage                = Usage::summary();
			$out['daily_left']    = max( 0, $usage['daily_limit'] - $usage['today_requests'] );
			$out['budget_left']   = max( 0, round( $usage['monthly_budget'] - $usage['month_cost'], 4 ) );
		}
		return self::out( $out );
	}

	/**
	 * Create a job.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function job_create( $r ) {
		$options = (array) $r->get_param( 'options' );
		return self::out( Jobs::create( (string) $r->get_param( 'operation' ), (array) $r->get_param( 'filters' ), $options ) );
	}

	/**
	 * Process a slice (called by the open Bulk page every few seconds).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function job_tick( $r ) {
		$summary         = Jobs::tick( absint( $r->get_param( 'job_id' ) ) );
		$summary['jobs'] = Jobs::all( 30 );
		return self::out( $summary );
	}

	/**
	 * Job with items.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function job( $r ) {
		$job = Jobs::get( absint( $r['id'] ) );
		if ( ! $job ) {
			return new \WP_Error( 'rar_job', __( 'Job not found.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 404 ) );
		}
		$job['items'] = Jobs::items( $job['id'], absint( $r->get_param( 'page' ) ) );
		return self::out( $job );
	}

	/**
	 * Pause / resume / cancel / retry.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function job_control( $r ) {
		return self::out( Jobs::control( absint( $r['id'] ), (string) $r['action'] ) );
	}

	/**
	 * Apply / reject / roll back a job's drafts (chunked).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function job_drafts( $r ) {
		$action = (string) $r->get_param( 'action' );
		if ( ! in_array( $action, array( 'apply_clean', 'reject_pending', 'rollback_applied' ), true ) ) {
			return new \WP_Error( 'rar_action', __( 'Unknown action.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		if ( ! Jobs::get( absint( $r['id'] ) ) ) {
			return new \WP_Error( 'rar_job', __( 'Job not found.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 404 ) );
		}
		return self::out( Jobs::drafts_action( absint( $r['id'] ), $action ) );
	}

	/**
	 * AI status + usage.
	 *
	 * @return \WP_REST_Response
	 */
	public static function ai_status() {
		$status          = Gateway::status();
		$status['usage'] = Usage::summary();
		$status['limits'] = array(
			'daily_limit'       => (int) Settings::get( 'ai.daily_limit' ),
			'per_minute'        => (int) Settings::get( 'ai.per_minute' ),
			'monthly_budget'    => (float) Settings::get( 'ai.monthly_budget' ),
			'max_output_tokens' => (int) Settings::get( 'ai.max_output_tokens' ),
			'fallback'          => (int) Settings::get( 'ai.fallback' ),
			'image_price'       => (float) Settings::get( 'ai.image_price' ),
		);
		if ( ! current_user_can( 'manage_options' ) ) {
			unset( $status['usage']['recent'] );
			foreach ( $status['providers'] as $id => $p ) {
				$status['providers'][ $id ]['key_mask'] = '';
			}
		}
		return self::out( $status );
	}

	/**
	 * Master switch.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function ai_master( $r ) {
		$res = Gateway::set_master( (bool) $r->get_param( 'enable' ), (bool) $r->get_param( 'acknowledge' ) );
		return self::out( is_wp_error( $res ) ? $res : Gateway::status() );
	}

	/**
	 * Save / remove a key.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function ai_key( $r ) {
		$res = Gateway::save_key( sanitize_key( (string) $r->get_param( 'provider' ) ), (string) $r->get_param( 'key' ) );
		return self::out( is_wp_error( $res ) ? $res : Gateway::status() );
	}

	/**
	 * List models / connection test (free endpoints, no tokens).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function ai_models( $r ) {
		$provider = Registry::get( sanitize_key( (string) $r->get_param( 'provider' ) ) );
		if ( ! $provider ) {
			return new \WP_Error( 'rar_ai_provider', __( 'Unknown provider.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		if ( '' === $provider->key() ) {
			return new \WP_Error( 'rar_ai_key', __( 'Add an API key first.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$started = microtime( true );
		$models  = $provider->list_models();
		$ms      = (int) round( ( microtime( true ) - $started ) * 1000 );
		Logger::log( is_wp_error( $models ) ? 'ai_test_failed' : 'ai_test_ok', sprintf( 'Connection test %s: %s', $provider->label(), is_wp_error( $models ) ? $models->get_error_message() : count( $models ) . ' models' ), array( 'ms' => $ms ), is_wp_error( $models ) ? 'warning' : 'info' );
		if ( is_wp_error( $models ) ) {
			return self::out( $models );
		}
		return self::out(
			array(
				'models' => array_slice( $models, 0, 300 ),
				'ms'     => $ms,
			)
		);
	}

	/**
	 * Settings (keys removed).
	 *
	 * @return \WP_REST_Response
	 */
	public static function settings_get() {
		$templates = array();
		foreach ( Prompts::defaults() as $id => $def ) {
			$templates[ $id ] = array(
				'label'   => $def['label'],
				'default' => $def['text'],
				'value'   => Prompts::template( $id ),
				'custom'  => '' !== (string) Settings::get( 'templates.' . $id, '' ),
			);
		}
		return self::out(
			array(
				'settings'  => Settings::public_view(),
				'templates' => $templates,
				'seo'       => Seo::status(),
				'adapters'  => array_map(
					static function ( $a ) {
						return array(
							'label'    => $a['label'],
							'detected' => ! empty( $a['detected'] ),
						);
					},
					Seo::adapters()
				),
				'can_admin' => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * Save settings.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function settings_save( $r ) {
		$input = (array) $r->get_json_params();
		unset( $input['ai']['enabled'], $input['ai']['acknowledged_at'], $input['ai']['acknowledged_by'] );
		if ( isset( $input['ai']['providers'] ) && is_array( $input['ai']['providers'] ) ) {
			foreach ( $input['ai']['providers'] as $p => $cfg ) {
				unset( $input['ai']['providers'][ $p ]['key'] );
			}
		}
		Settings::sanitize_update( $input );
		Logger::log( 'settings_saved', 'Settings updated.', array( 'sections' => implode( ',', array_keys( $input ) ) ), 'info' );
		return self::settings_get();
	}

	/**
	 * Logs.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function logs( $r ) {
		return self::out(
			Logger::query(
				array(
					'level'       => (string) $r->get_param( 'level' ),
					'search'      => (string) $r->get_param( 'search' ),
					'page'        => absint( $r->get_param( 'page' ) ),
					'per_page'    => 30,
					'object_type' => (string) $r->get_param( 'object_type' ),
					'object_id'   => absint( $r->get_param( 'object_id' ) ),
				)
			)
		);
	}

	/**
	 * Clear logs.
	 *
	 * @return \WP_REST_Response
	 */
	public static function logs_clear() {
		Logger::clear();
		Logger::log( 'logs_cleared', 'Activity log cleared.', array(), 'security' );
		return self::out( array( 'cleared' => true ) );
	}

	/**
	 * Per-user UI preferences.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function prefs( $r ) {
		$theme = in_array( $r->get_param( 'theme' ), array( 'dark', 'light' ), true ) ? $r->get_param( 'theme' ) : 'dark';
		update_user_meta( get_current_user_id(), 'rar_studio_theme', $theme );
		return self::out( array( 'theme' => $theme ) );
	}
}
