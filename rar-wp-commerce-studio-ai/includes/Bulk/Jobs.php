<?php
/**
 * Bulk job queue for shared hosting: small batches, a per-tick time and
 * memory budget, an atomic lock per job, WP-Cron plus the open admin page
 * as drivers, pause/resume/retry/cancel and CSV reports. Bulk jobs only
 * create drafts — applying is a separate, selective step.
 *
 * @package RarStudio
 */

namespace RarStudio\Bulk;

use RarStudio\Ai\Gateway;
use RarStudio\Content\Analyzer;
use RarStudio\Content\Generator;
use RarStudio\Content\LocalWriter;
use RarStudio\Drafts;
use RarStudio\Image\Studio;
use RarStudio\Logger;
use RarStudio\Settings;
use RarStudio\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Jobs.
 */
final class Jobs {

	const MAX_ITEMS = 5000;

	/**
	 * Operation catalogue.
	 *
	 * @return array id => [label, target, ai, description]
	 */
	public static function operations() {
		return array(
			'seo_local'         => array(
				'label'  => __( 'SEO title, meta & keyphrase (free)', 'rar-wp-commerce-studio-ai' ),
				'target' => 'product',
				'ai'     => false,
			),
			'content_local'     => array(
				'label'  => __( 'Product content formatting (free)', 'rar-wp-commerce-studio-ai' ),
				'target' => 'product',
				'ai'     => false,
			),
			'content_ai'        => array(
				'label'  => __( 'Product content & SEO with AI (API)', 'rar-wp-commerce-studio-ai' ),
				'target' => 'product',
				'ai'     => true,
			),
			'alt_local'         => array(
				'label'  => __( 'Image alt text (free)', 'rar-wp-commerce-studio-ai' ),
				'target' => 'attachment',
				'ai'     => false,
			),
			'alt_ai'            => array(
				'label'  => __( 'Image alt text with AI vision (API)', 'rar-wp-commerce-studio-ai' ),
				'target' => 'attachment',
				'ai'     => true,
			),
			'image_standardize' => array(
				'label'  => __( 'Standardise product images (free)', 'rar-wp-commerce-studio-ai' ),
				'target' => 'product',
				'ai'     => false,
			),
			'post_seo_local'    => array(
				'label'  => __( 'Posts/pages: excerpt & SEO (free)', 'rar-wp-commerce-studio-ai' ),
				'target' => 'post',
				'ai'     => false,
			),
		);
	}

	/**
	 * Tables.
	 *
	 * @param string $which jobs|items.
	 * @return string
	 */
	public static function table( $which = 'jobs' ) {
		global $wpdb;
		return $wpdb->prefix . ( 'items' === $which ? 'rar_studio_job_items' : 'rar_studio_jobs' );
	}

	/**
	 * Resolve target IDs from filters.
	 *
	 * @param string $target  product|attachment|post.
	 * @param array  $filters Filters.
	 * @return int[]
	 */
	public static function resolve_targets( $target, array $filters ) {
		$limit = max( 1, min( self::MAX_ITEMS, absint( $filters['limit'] ?? 500 ) ) );
		if ( ! empty( $filters['ids'] ) ) {
			$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', is_array( $filters['ids'] ) ? $filters['ids'] : preg_split( '/[\s,]+/', (string) $filters['ids'] ) ) ) ) ), 0, $limit );
			$out = array();
			foreach ( $ids as $id ) {
				$type = get_post_type( $id );
				if ( ( 'product' === $target && 'product' === $type ) || ( 'attachment' === $target && 'attachment' === $type ) || ( 'post' === $target && in_array( $type, array( 'post', 'page' ), true ) ) ) {
					$out[] = $id;
				}
			}
			return $out;
		}
		if ( 'attachment' === $target ) {
			$args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			);
			if ( ! empty( $filters['missing_alt'] ) ) {
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
			if ( ! empty( $filters['product_images'] ) && Util::wc_active() ) {
				// Featured + gallery images of the selected products.
				$products = self::resolve_targets( 'product', array_merge( $filters, array( 'product_images' => 0 ) ) );
				$ids      = array();
				foreach ( $products as $pid ) {
					$product = wc_get_product( $pid );
					if ( ! $product ) {
						continue;
					}
					$ids = array_merge( $ids, array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) );
				}
				$ids = array_values( array_unique( array_filter( $ids ) ) );
				if ( ! empty( $filters['missing_alt'] ) ) {
					$ids = array_values(
						array_filter(
							$ids,
							static function ( $att ) {
								return '' === trim( (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) );
							}
						)
					);
				}
				return array_slice( $ids, 0, $limit );
			}
			$q = new \WP_Query( $args );
			return array_map( 'intval', $q->posts );
		}
		if ( 'post' === $target ) {
			$q = new \WP_Query(
				array(
					'post_type'      => in_array( $filters['post_type'] ?? '', array( 'post', 'page' ), true ) ? $filters['post_type'] : array( 'post', 'page' ),
					'post_status'    => in_array( $filters['status'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true ) ? $filters['status'] : array( 'publish', 'draft', 'pending', 'private' ),
					's'              => sanitize_text_field( $filters['search'] ?? '' ),
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			return array_map( 'intval', $q->posts );
		}
		if ( ! Util::wc_active() ) {
			return array();
		}
		$args = array(
			'post_type'      => 'product',
			'post_status'    => in_array( $filters['status'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true ) ? $filters['status'] : array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);
		if ( ! empty( $filters['search'] ) ) {
			$args['s'] = sanitize_text_field( $filters['search'] );
		}
		$tax = array();
		if ( ! empty( $filters['category'] ) ) {
			$tax[] = array(
				'taxonomy' => 'product_cat',
				'terms'    => array( absint( $filters['category'] ) ),
			);
		}
		if ( ! empty( $filters['brand'] ) && taxonomy_exists( 'product_brand' ) ) {
			$tax[] = array(
				'taxonomy' => 'product_brand',
				'terms'    => array( absint( $filters['brand'] ) ),
			);
		}
		if ( $tax ) {
			$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( ! empty( $filters['stock_status'] ) && in_array( $filters['stock_status'], array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'   => '_stock_status',
					'value' => $filters['stock_status'],
				),
			);
		}
		if ( ! empty( $filters['issue'] ) ) {
			$health = Analyzer::catalog_health();
			$issue  = sanitize_key( $filters['issue'] );
			if ( isset( $health['ids'][ $issue ] ) ) {
				$args['post__in'] = $health['ids'][ $issue ] ? $health['ids'][ $issue ] : array( 0 );
			}
		}
		$q = new \WP_Query( $args );
		return array_map( 'intval', $q->posts );
	}

	/**
	 * Create a job.
	 *
	 * @param string $operation Operation.
	 * @param array  $filters   Target filters.
	 * @param array  $options   fields, language, image preset, include_gallery, confirm_ai.
	 * @return array|\WP_Error
	 */
	public static function create( $operation, array $filters, array $options ) {
		global $wpdb;
		$ops = self::operations();
		if ( ! isset( $ops[ $operation ] ) ) {
			return new \WP_Error( 'rar_job', __( 'Unknown bulk operation.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		if ( $ops[ $operation ]['ai'] ) {
			$reason = Gateway::unavailable_reason();
			if ( $reason ) {
				return new \WP_Error( 'rar_ai_off', $reason, array( 'status' => 403 ) );
			}
			if ( empty( $options['confirm_ai'] ) ) {
				return new \WP_Error( 'rar_ai_ack', __( 'Confirm the estimated API cost to start an AI bulk job.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
			}
		}
		$ids = self::resolve_targets( $ops[ $operation ]['target'], $filters );
		if ( ! $ids ) {
			return new \WP_Error( 'rar_job_empty', __( 'No items match these filters.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$clean = array(
			'fields'          => array_values( array_map( 'sanitize_key', (array) ( $options['fields'] ?? array() ) ) ),
			'language'        => in_array( $options['language'] ?? '', array( 'en', 'bn', 'bilingual' ), true ) ? $options['language'] : Settings::get( 'general.language', 'en' ),
			'instruction'     => Util::limit( sanitize_textarea_field( (string) ( $options['instruction'] ?? '' ) ), 500 ),
			'image'           => \RarStudio\Image\Processor::sanitize_options( array_merge( (array) Settings::get( 'image', array() ), (array) ( $options['image'] ?? array() ) ) ),
			'include_gallery' => empty( $options['include_gallery'] ) ? 0 : 1,
			'batch_size'      => max( 1, min( 50, absint( $options['batch_size'] ?? Settings::get( 'bulk.batch_size', 10 ) ) ) ),
			'filters'         => $filters,
		);
		if ( 'seo_local' === $operation ) {
			$clean['fields'] = array( 'seo_title', 'seo_description', 'seo_focus' );
		} elseif ( 'post_seo_local' === $operation ) {
			$clean['fields'] = array( 'excerpt', 'seo_title', 'seo_description', 'seo_focus' );
		} elseif ( in_array( $operation, array( 'content_local', 'content_ai' ), true ) && ! $clean['fields'] ) {
			return new \WP_Error( 'rar_job_fields', __( 'Choose at least one field.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$now = Util::now();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'operation'  => $operation,
				'status'     => 'running',
				'options'    => wp_json_encode( $clean ),
				'total'      => count( $ids ),
				'created_by' => get_current_user_id(),
				'created_at' => $now,
				'updated_at' => $now,
				'started_at' => $now,
			)
		);
		$job_id = (int) $wpdb->insert_id;
		if ( ! $job_id ) {
			return new \WP_Error( 'rar_db', __( 'Could not create the job.', 'rar-wp-commerce-studio-ai' ) );
		}
		$type = $ops[ $operation ]['target'];
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $oid ) {
				$values[] = $wpdb->prepare( '(%d,%s,%d,%s,%s)', $job_id, 'post' === $type ? get_post_type( $oid ) : $type, $oid, 'pending', $now );
			}
			$wpdb->query( 'INSERT INTO ' . self::table( 'items' ) . ' (job_id, object_type, object_id, status, updated_at) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
		}
		Logger::log( 'job_created', sprintf( 'Bulk job #%d created: %s (%d items).', $job_id, $ops[ $operation ]['label'], count( $ids ) ), array( 'operation' => $operation ), 'info' );
		self::schedule();
		return self::get( $job_id );
	}

	/**
	 * Job details.
	 *
	 * @param int $id Job ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Format a job row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function format( array $row ) {
		global $wpdb;
		$ops    = self::operations();
		$counts = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM ' . Drafts::table() . ' WHERE job_id = %d GROUP BY status', (int) $row['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$drafts = array();
		foreach ( (array) $counts as $c ) {
			$drafts[ $c['status'] ] = (int) $c['c'];
		}
		$user = get_userdata( (int) $row['created_by'] );
		return array(
			'id'          => (int) $row['id'],
			'operation'   => $row['operation'],
			'label'       => $ops[ $row['operation'] ]['label'] ?? $row['operation'],
			'ai'          => ! empty( $ops[ $row['operation'] ]['ai'] ),
			'status'      => $row['status'],
			'options'     => Util::json_array( $row['options'] ),
			'total'       => (int) $row['total'],
			'processed'   => (int) $row['processed'],
			'succeeded'   => (int) $row['succeeded'],
			'failed'      => (int) $row['failed'],
			'skipped'     => (int) $row['skipped'],
			'progress'    => $row['total'] ? (int) floor( 100 * $row['processed'] / $row['total'] ) : 0,
			'drafts'      => $drafts,
			'created_by'  => $user ? $user->display_name : '',
			'created_by_id' => (int) $row['created_by'],
			'created_at'  => $row['created_at'],
			'started_at'  => $row['started_at'],
			'finished_at' => $row['finished_at'],
			'last_error'  => (string) $row['last_error'],
		);
	}

	/**
	 * List jobs.
	 *
	 * @param int $limit Max.
	 * @return array[]
	 */
	public static function all( $limit = 30 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', max( 1, min( 100, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'format' ), (array) $rows );
	}

	/**
	 * Items of a job.
	 *
	 * @param int $job_id Job.
	 * @param int $page   Page.
	 * @return array{items:array,total:int}
	 */
	public static function items( $job_id, $page = 1 ) {
		global $wpdb;
		$per   = 50;
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'items' ) . ' WHERE job_id = %d', $job_id ) ); // phpcs:ignore WordPress.DB
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'items' ) . ' WHERE job_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', $job_id, $per, ( max( 1, $page ) - 1 ) * $per ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'          => (int) $row['id'],
				'object_type' => $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'title'       => get_the_title( (int) $row['object_id'] ),
				'status'      => $row['status'],
				'attempts'    => (int) $row['attempts'],
				'message'     => (string) $row['message'],
				'drafts'      => count( Util::json_array( $row['result'] ) ),
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Change job state.
	 *
	 * @param int    $id     Job ID.
	 * @param string $action pause|resume|cancel|retry.
	 * @return array|\WP_Error
	 */
	public static function control( $id, $action ) {
		global $wpdb;
		$job = self::get( $id );
		if ( ! $job ) {
			return new \WP_Error( 'rar_job', __( 'Job not found.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 404 ) );
		}
		$t = self::table();
		switch ( $action ) {
			case 'pause':
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'paused', updated_at = %s WHERE id = %d AND status = 'running'", Util::now(), $id ) ); // phpcs:ignore WordPress.DB
				break;
			case 'resume':
				if ( $job['ai'] && Gateway::unavailable_reason() ) {
					return new \WP_Error( 'rar_ai_off', Gateway::unavailable_reason(), array( 'status' => 403 ) );
				}
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'running', last_error = '', updated_at = %s WHERE id = %d AND status = 'paused'", Util::now(), $id ) ); // phpcs:ignore WordPress.DB
				self::schedule();
				break;
			case 'cancel':
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'cancelled', finished_at = %s, updated_at = %s WHERE id = %d AND status IN ('running','paused','queued')", Util::now(), Util::now(), $id ) ); // phpcs:ignore WordPress.DB
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'items' ) . " SET status = 'skipped', message = %s WHERE job_id = %d AND status = 'pending'", __( 'Cancelled', 'rar-wp-commerce-studio-ai' ), $id ) ); // phpcs:ignore WordPress.DB
				self::recount( $id );
				break;
			case 'retry':
				if ( $job['ai'] && Gateway::unavailable_reason() ) {
					return new \WP_Error( 'rar_ai_off', Gateway::unavailable_reason(), array( 'status' => 403 ) );
				}
				$n = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'items' ) . " SET status = 'pending', message = '' WHERE job_id = %d AND status = 'failed'", $id ) ); // phpcs:ignore WordPress.DB
				if ( $n ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'running', finished_at = NULL, last_error = '', updated_at = %s WHERE id = %d", Util::now(), $id ) ); // phpcs:ignore WordPress.DB
					self::recount( $id );
					self::schedule();
				}
				break;
			default:
				return new \WP_Error( 'rar_job', __( 'Unknown action.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		Logger::log( 'job_' . $action, sprintf( 'Bulk job #%d: %s.', $id, $action ), array(), 'info' );
		return self::get( $id );
	}

	/**
	 * Recalculate counters from items.
	 *
	 * @param int $id Job ID.
	 * @return void
	 */
	public static function recount( $id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM ' . self::table( 'items' ) . ' WHERE job_id = %d GROUP BY status', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$c    = array(
			'pending' => 0,
			'done'    => 0,
			'failed'  => 0,
			'skipped' => 0,
		);
		foreach ( (array) $rows as $row ) {
			$c[ $row['status'] ] = (int) $row['c'];
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'processed'  => $c['done'] + $c['failed'] + $c['skipped'],
				'succeeded'  => $c['done'],
				'failed'     => $c['failed'],
				'skipped'    => $c['skipped'],
				'updated_at' => Util::now(),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Schedule a WP-Cron tick (backup driver when the admin page is closed).
	 *
	 * @param int $delay Seconds.
	 * @return void
	 */
	public static function schedule( $delay = 15 ) {
		if ( ! wp_next_scheduled( 'rar_studio_bulk_tick' ) ) {
			wp_schedule_single_event( time() + $delay, 'rar_studio_bulk_tick' );
		}
	}

	/**
	 * Process running jobs within the time budget.
	 *
	 * @param int $only_job Restrict to one job (0 = any).
	 * @return array Summary.
	 */
	public static function tick( $only_job = 0 ) {
		global $wpdb;
		$budget  = (int) Settings::get( 'bulk.time_budget', 15 );
		$started = microtime( true );
		$done    = 0;
		$t       = self::table();
		$jobs    = $only_job
			? $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE id = %d AND status = 'running'", $only_job ) ) // phpcs:ignore WordPress.DB
			: $wpdb->get_col( "SELECT id FROM {$t} WHERE status = 'running' ORDER BY id ASC LIMIT 5" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $jobs as $job_id ) {
			$job_id = (int) $job_id;
			$token  = wp_generate_password( 20, false );
			$locked = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET lock_token = %s, lock_expires = %d WHERE id = %d AND status = 'running' AND lock_expires < %d", $token, time() + $budget + 30, $job_id, time() ) ); // phpcs:ignore WordPress.DB
			if ( 1 !== $locked ) {
				continue; // Another request is processing this job.
			}
			$job = self::get( $job_id );
			// Work runs with the permissions of the person who created the job (also under WP-Cron).
			$previous_user = get_current_user_id();
			wp_set_current_user( (int) $job['created_by_id'] );
			if ( ! Util::can_use_studio() || ( $job['ai'] && ! Util::can_use_ai() ) ) {
				wp_set_current_user( $previous_user );
				$wpdb->update( $t, array( 'status' => 'paused', 'last_error' => __( 'The user who created this job no longer has permission to run it.', 'rar-wp-commerce-studio-ai' ), 'lock_token' => '', 'lock_expires' => 0 ), array( 'id' => $job_id ) ); // phpcs:ignore WordPress.DB
				continue;
			}
			$batch = (int) ( $job['options']['batch_size'] ?? 10 );
			$items = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'items' ) . " WHERE job_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d", $job_id, $batch ), ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( (array) $items as $item ) {
				if ( microtime( true ) - $started > $budget || ! \RarStudio\Image\Processor::memory_ok( 2000000 ) ) {
					break;
				}
				$result = self::process_item( $job, $item );
				++$done;
				if ( 'pause' === $result['status'] ) {
					$wpdb->update( $t, array( 'status' => 'paused', 'last_error' => $result['message'], 'updated_at' => Util::now() ), array( 'id' => $job_id ) ); // phpcs:ignore WordPress.DB
					Logger::log( 'job_paused', sprintf( 'Bulk job #%d paused: %s', $job_id, $result['message'] ), array(), 'warning' );
					break;
				}
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					self::table( 'items' ),
					array(
						'status'     => $result['status'],
						'attempts'   => (int) $item['attempts'] + 1,
						'message'    => Util::limit( $result['message'], 500 ),
						'result'     => wp_json_encode( $result['drafts'] ),
						'updated_at' => Util::now(),
					),
					array( 'id' => (int) $item['id'] )
				);
			}
			self::recount( $job_id );
			$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'items' ) . " WHERE job_id = %d AND status = 'pending'", $job_id ) ); // phpcs:ignore WordPress.DB
			if ( ! $left ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'completed', finished_at = %s WHERE id = %d AND status = 'running'", Util::now(), $job_id ) ); // phpcs:ignore WordPress.DB
				$final = self::get( $job_id );
				if ( $final && 'completed' === $final['status'] ) {
					Logger::log( 'job_completed', sprintf( 'Bulk job #%d finished: %d done, %d failed, %d skipped.', $job_id, $final['succeeded'], $final['failed'], $final['skipped'] ), array(), 'info' );
				}
			}
			wp_set_current_user( $previous_user );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET lock_token = '', lock_expires = 0 WHERE id = %d AND lock_token = %s", $job_id, $token ) ); // phpcs:ignore WordPress.DB
			if ( microtime( true ) - $started > $budget ) {
				break;
			}
		}
		$running = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'running'" ); // phpcs:ignore WordPress.DB
		if ( $running ) {
			self::schedule( 30 );
		}
		return array(
			'processed' => $done,
			'running'   => $running,
			'seconds'   => round( microtime( true ) - $started, 2 ),
		);
	}

	/**
	 * Process one item.
	 *
	 * @param array $job  Job.
	 * @param array $item Item row.
	 * @return array status (done|failed|skipped|pause), message, drafts[]
	 */
	private static function process_item( array $job, array $item ) {
		$oid  = (int) $item['object_id'];
		$type = (string) $item['object_type'];
		$opt  = $job['options'];
		$out  = array(
			'status'  => 'done',
			'message' => '',
			'drafts'  => array(),
		);
		if ( ! get_post( $oid ) ) {
			return array_merge( $out, array( 'status' => 'skipped', 'message' => __( 'Item no longer exists.', 'rar-wp-commerce-studio-ai' ) ) );
		}
		$gen_opts = array(
			'language'    => $opt['language'] ?? 'en',
			'instruction' => $opt['instruction'] ?? '',
			'job_id'      => $job['id'],
		);
		try {
			switch ( $job['operation'] ) {
				case 'seo_local':
				case 'content_local':
				case 'post_seo_local':
					$res = Generator::generate( $type, $oid, (array) $opt['fields'], 'local', $gen_opts );
					break;
				case 'content_ai':
					$res = Generator::generate( $type, $oid, (array) $opt['fields'], 'ai', $gen_opts );
					break;
				case 'alt_local':
					$skipped = array();
					$values  = LocalWriter::generate( 'attachment', $oid, array( 'alt' ), array() );
					$res     = array(
						'drafts'  => Generator::to_drafts( 'attachment', $oid, $values, __( 'Free local engine', 'rar-wp-commerce-studio-ai' ), array( 'job_id' => $job['id'] ), $skipped ),
						'skipped' => $skipped,
					);
					if ( ! $values ) {
						$res['skipped']['alt'] = __( 'No meaningful name found (file name is generic). Use AI vision or write it manually.', 'rar-wp-commerce-studio-ai' );
					}
					break;
				case 'alt_ai':
					$res = Generator::ai_alt( $oid, array( 'job_id' => $job['id'], 'language' => $opt['language'] ?? 'en' ) );
					break;
				case 'image_standardize':
					$res = self::standardize_product( $oid, $job );
					break;
				default:
					$res = new \WP_Error( 'rar_job', 'Unknown operation' );
			}
		} catch ( \Throwable $e ) {
			$res = new \WP_Error( 'rar_exception', $e->getMessage() );
		}
		if ( is_wp_error( $res ) ) {
			if ( in_array( $res->get_error_code(), array( 'rar_ai_limit', 'rar_ai_off', 'rar_ai_http_401', 'rar_ai_http_403', 'rar_ai_http_429' ), true ) ) {
				return array_merge( $out, array( 'status' => 'pause', 'message' => $res->get_error_message() ) );
			}
			return array_merge( $out, array( 'status' => 'failed', 'message' => $res->get_error_message() ) );
		}
		$ids = array();
		foreach ( (array) ( $res['drafts'] ?? array() ) as $draft ) {
			if ( is_array( $draft ) && ! empty( $draft['id'] ) ) {
				$ids[] = (int) $draft['id'];
			}
		}
		$out['drafts'] = $ids;
		if ( ! $ids ) {
			$out['status']  = 'skipped';
			$reasons        = array_unique( array_values( (array) ( $res['skipped'] ?? array() ) ) );
			$out['message'] = $reasons ? implode( ' ', $reasons ) : __( 'Nothing to change.', 'rar-wp-commerce-studio-ai' );
		} else {
			/* translators: %d: drafts */
			$out['message'] = sprintf( _n( '%d draft ready for review.', '%d drafts ready for review.', count( $ids ), 'rar-wp-commerce-studio-ai' ), count( $ids ) );
		}
		return $out;
	}

	/**
	 * Image standardisation for one product (featured + optional gallery).
	 *
	 * @param int   $product_id Product.
	 * @param array $job        Job.
	 * @return array|\WP_Error
	 */
	private static function standardize_product( $product_id, array $job ) {
		$images = Studio::product_images( $product_id );
		if ( ! $images ) {
			return array(
				'drafts'  => array(),
				'skipped' => array( 'image' => __( 'Product has no images.', 'rar-wp-commerce-studio-ai' ) ),
			);
		}
		$drafts  = array();
		$skipped = array();
		foreach ( $images as $image ) {
			if ( 'gallery' === $image['role'] && empty( $job['options']['include_gallery'] ) ) {
				continue;
			}
			if ( $image['derivative'] ) {
				$skipped[ 'img' . $image['id'] ] = __( 'Already a Studio image.', 'rar-wp-commerce-studio-ai' );
				continue;
			}
			$res = Studio::create(
				$image['id'],
				(array) $job['options']['image'],
				array(
					'product_id' => $product_id,
					'role'       => $image['role'],
					'job_id'     => $job['id'],
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( ! empty( $res['draft'] ) ) {
				$drafts[] = $res['draft'];
			}
		}
		return array(
			'drafts'  => $drafts,
			'skipped' => $skipped,
		);
	}

	/**
	 * Apply / reject / roll back a job's drafts in chunks.
	 *
	 * @param int    $job_id Job.
	 * @param string $action apply_clean|reject_pending|rollback_applied.
	 * @param int    $limit  Max drafts per call.
	 * @return array done, failed, remaining, errors[]
	 */
	public static function drafts_action( $job_id, $action, $limit = 40 ) {
		global $wpdb;
		$status = 'rollback_applied' === $action ? 'applied' : 'pending';
		$ids    = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Drafts::table() . ' WHERE job_id = %d AND status = %s ORDER BY id ASC LIMIT %d', $job_id, $status, $limit * 3 ) ); // phpcs:ignore WordPress.DB
		$done   = 0;
		$failed = 0;
		$errors = array();
		$tried  = 0;
		foreach ( (array) $ids as $id ) {
			if ( $tried >= $limit ) {
				break;
			}
			$draft = Drafts::get( (int) $id );
			if ( 'apply_clean' === $action && $draft['blockers'] ) {
				continue; // Flagged drafts need individual review.
			}
			++$tried;
			if ( 'apply_clean' === $action ) {
				$res = Drafts::apply( (int) $id );
			} elseif ( 'reject_pending' === $action ) {
				$res = Drafts::reject( (int) $id );
			} else {
				$res = Drafts::rollback( (int) $id );
			}
			if ( is_wp_error( $res ) ) {
				++$failed;
				if ( count( $errors ) < 10 ) {
					$errors[] = sprintf( '#%d %s: %s', (int) $id, $draft['object_name'], $res->get_error_message() );
				}
			} else {
				++$done;
			}
		}
		if ( 'apply_clean' === $action ) {
			$remaining = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Drafts::table() . " WHERE job_id = %d AND status = 'pending' AND (flags IS NULL OR flags NOT LIKE %s)", $job_id, '%"severity":"block"%' ) ); // phpcs:ignore WordPress.DB
		} else {
			$remaining = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Drafts::table() . ' WHERE job_id = %d AND status = %s', $job_id, $status ) ); // phpcs:ignore WordPress.DB
		}
		// Items that failed stay counted as remaining; stop the loop when nothing moved.
		if ( 0 === $done ) {
			$remaining = 0;
		}
		Logger::log( 'job_drafts_' . $action, sprintf( 'Bulk job #%d: %s — %d done, %d failed.', $job_id, $action, $done, $failed ), array(), $failed ? 'warning' : 'info' );
		return array(
			'done'      => $done,
			'failed'    => $failed,
			'remaining' => $remaining,
			'errors'    => $errors,
		);
	}

	/**
	 * CSV report rows.
	 *
	 * @param int $job_id Job.
	 * @return string CSV.
	 */
	public static function report_csv( $job_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'items' ) . ' WHERE job_id = %d ORDER BY id ASC', $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$fh   = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $fh, array( 'item_id', 'object_type', 'object_id', 'title', 'status', 'attempts', 'drafts', 'message', 'updated_at_utc' ), ',', '"', '' );
		foreach ( (array) $rows as $row ) {
			$title = (string) get_the_title( (int) $row['object_id'] );
			// Neutralise spreadsheet formula injection.
			if ( preg_match( '/^[=+\-@]/', $title ) ) {
				$title = "'" . $title;
			}
			fputcsv( $fh, array( $row['id'], $row['object_type'], $row['object_id'], $title, $row['status'], $row['attempts'], count( Util::json_array( $row['result'] ) ), $row['message'], $row['updated_at'] ), ',', '"', '' );
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return "\xEF\xBB\xBF" . $csv;
	}
}
