<?php
/**
 * Draft store: every proposed change is a draft with the original value.
 * Lifecycle: pending → applied → rolled_back, or pending → rejected/superseded.
 *
 * @package RarStudio
 */

namespace RarStudio;

use RarStudio\Content\FactGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Review / apply / rollback engine.
 */
final class Drafts {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rar_studio_drafts';
	}

	/**
	 * Create a pending draft. Returns null when the proposal equals the current value.
	 *
	 * @param string $type     Object type.
	 * @param int    $id       Object ID.
	 * @param string $field    Field.
	 * @param string $proposed Proposed value.
	 * @param string $source   Source label (local, ai:gemini:model, manual:chatgpt…).
	 * @param array  $args     job_id, meta, guard (bool, default true).
	 * @return array|null|\WP_Error
	 */
	public static function create( $type, $id, $field, $proposed, $source, array $args = array() ) {
		global $wpdb;
		$id       = absint( $id );
		$original = Fields::get( $type, $id, $field );
		if ( is_wp_error( $original ) ) {
			return $original;
		}
		$defs     = Fields::definitions( $type );
		$kind     = $defs[ $field ]['kind'];
		$proposed = (string) $proposed;
		if ( in_array( $kind, array( 'text', 'terms' ), true ) ) {
			$proposed = trim( preg_replace( '/[ \t]+/u', ' ', $proposed ) );
		} elseif ( 'html' === $kind ) {
			$proposed = trim( wp_kses_post( $proposed ) );
		}
		if ( trim( (string) $original ) === trim( $proposed ) ) {
			return null;
		}
		$flags = array();
		if ( ( $args['guard'] ?? true ) && in_array( $kind, array( 'text', 'html', 'terms' ), true ) ) {
			$facts = Fields::facts( 'attachment' === $type && ! empty( $args['meta']['product_id'] ) ? 'product' : $type, 'attachment' === $type && ! empty( $args['meta']['product_id'] ) ? (int) $args['meta']['product_id'] : $id );
			$flags = FactGuard::check( $proposed, Fields::source_text( $facts ), (string) $original );
		}

		$now = Util::now();
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'object_type' => $type,
				'object_id'   => $id,
				'field'       => $field,
				'original'    => (string) $original,
				'proposed'    => $proposed,
				'source'      => Util::limit( sanitize_text_field( $source ), 160 ),
				'status'      => 'pending',
				'flags'       => wp_json_encode( $flags ),
				'meta'        => wp_json_encode( isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array() ),
				'fingerprint' => Util::hash( (string) $original ),
				'job_id'      => absint( $args['job_id'] ?? 0 ),
				'created_by'  => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'rar_db', __( 'Could not save the draft.', 'rar-wp-commerce-studio-ai' ) );
		}
		$new_id = (int) $wpdb->insert_id;

		// Older pending drafts for the same field are superseded by the newest one.
		$old = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d AND field = %s AND status = %s AND id <> %d', $type, $id, $field, 'pending', $new_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $old as $row ) {
			$wpdb->update( self::table(), array( 'status' => 'superseded', 'updated_at' => Util::now() ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB
			self::cleanup_derivative( $row );
		}
		return self::get( $new_id );
	}

	/**
	 * Fetch one draft.
	 *
	 * @param int $id Draft ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Normalise a DB row for PHP/JS consumers.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function format( array $row ) {
		$defs  = Fields::definitions( $row['object_type'] );
		$flags = Util::json_array( $row['flags'] );
		$meta  = Util::json_array( $row['meta'] );
		$out   = array(
			'id'          => (int) $row['id'],
			'object_type' => $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'object_name' => get_the_title( (int) $row['object_id'] ),
			'field'       => $row['field'],
			'label'       => isset( $defs[ $row['field'] ] ) ? $defs[ $row['field'] ]['label'] : $row['field'],
			'kind'        => isset( $defs[ $row['field'] ] ) ? $defs[ $row['field'] ]['kind'] : 'text',
			'original'    => (string) $row['original'],
			'proposed'    => (string) $row['proposed'],
			'source'      => $row['source'],
			'status'      => $row['status'],
			'flags'       => $flags,
			'blockers'    => FactGuard::has_blockers( $flags ),
			'meta'        => $meta,
			'job_id'      => (int) $row['job_id'],
			'created_at'  => $row['created_at'],
			'applied_at'  => $row['applied_at'],
		);
		if ( in_array( $out['kind'], array( 'image', 'gallery' ), true ) ) {
			$out['original_images'] = self::image_refs( $out['original'] );
			$out['proposed_images'] = self::image_refs( $out['proposed'] );
		}
		return $out;
	}

	/**
	 * Thumbnail info for image IDs (for previews).
	 *
	 * @param string $value Comma list of IDs.
	 * @return array
	 */
	private static function image_refs( $value ) {
		$out = array();
		foreach ( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) as $att ) {
			$src   = wp_get_attachment_image_src( $att, 'medium' );
			$full  = wp_get_attachment_image_src( $att, 'full' );
			$file  = get_attached_file( $att );
			$out[] = array(
				'id'     => $att,
				'url'    => $src ? $src[0] : '',
				'full'   => $full ? $full[0] : '',
				'width'  => $full ? (int) $full[1] : 0,
				'height' => $full ? (int) $full[2] : 0,
				'bytes'  => ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0,
				'mime'   => (string) get_post_mime_type( $att ),
			);
		}
		return $out;
	}

	/**
	 * Query drafts.
	 *
	 * @param array $args object_type, object_id, status, job_id, page, per_page.
	 * @return array{items:array,total:int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$params[] = sanitize_key( $args['object_type'] );
		}
		if ( ! empty( $args['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$params[] = absint( $args['object_id'] );
		}
		if ( ! empty( $args['job_id'] ) ) {
			$where[]  = 'job_id = %d';
			$params[] = absint( $args['job_id'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$statuses = array_intersect( (array) $args['status'], array( 'pending', 'applied', 'rejected', 'rolled_back', 'superseded' ) );
			if ( $statuses ) {
				$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
				$params  = array_merge( $params, array_values( $statuses ) );
			}
		}
		$per_page = max( 1, min( 200, absint( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$sql      = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$sql}", $params ) : 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$sql}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::format( $row );
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Atomically move a draft from one status to another.
	 *
	 * @param int    $id   Draft ID.
	 * @param string $from Expected status.
	 * @param string $to   New status.
	 * @return bool
	 */
	private static function transition( $id, $from, $to ) {
		global $wpdb;
		$rows = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET status = %s, updated_at = %s WHERE id = %d AND status = %s', $to, Util::now(), $id, $from ) ); // phpcs:ignore WordPress.DB
		return 1 === (int) $rows;
	}

	/**
	 * Edit a pending draft's proposed value (re-checked by the fact guard).
	 *
	 * @param int    $id    Draft ID.
	 * @param string $value New value.
	 * @return array|\WP_Error
	 */
	public static function update_proposed( $id, $value ) {
		global $wpdb;
		$draft = self::get( $id );
		if ( ! $draft || 'pending' !== $draft['status'] ) {
			return new \WP_Error( 'rar_draft', __( 'Only pending drafts can be edited.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 409 ) );
		}
		if ( ! Util::can_edit_object( $draft['object_type'], $draft['object_id'] ) ) {
			return new \WP_Error( 'rar_forbidden', __( 'You cannot edit this item.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 403 ) );
		}
		if ( in_array( $draft['kind'], array( 'image', 'gallery' ), true ) ) {
			return new \WP_Error( 'rar_draft', __( 'Image drafts cannot be edited as text.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$value = 'html' === $draft['kind'] ? trim( wp_kses_post( (string) $value ) ) : trim( sanitize_textarea_field( (string) $value ) );
		$facts = Fields::facts( $draft['object_type'], $draft['object_id'] );
		$flags = FactGuard::check( $value, Fields::source_text( $facts ), $draft['original'] );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'proposed'   => $value,
				'flags'      => wp_json_encode( $flags ),
				'source'     => Util::limit( preg_replace( '/ \+ edited$/', '', $draft['source'] ) . ' + edited', 160 ),
				'updated_at' => Util::now(),
			),
			array( 'id' => (int) $id )
		);
		return self::get( $id );
	}

	/**
	 * Apply a pending draft.
	 *
	 * @param int   $id   Draft ID.
	 * @param array $opts ack (bool) – user verified flagged facts; force (bool) – ignore newer edits.
	 * @return array|\WP_Error
	 */
	public static function apply( $id, array $opts = array() ) {
		global $wpdb;
		$draft = self::get( $id );
		if ( ! $draft ) {
			return new \WP_Error( 'rar_draft', __( 'Draft not found.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 404 ) );
		}
		if ( ! Util::can_edit_object( $draft['object_type'], $draft['object_id'] ) ) {
			return new \WP_Error( 'rar_forbidden', __( 'You cannot edit this item.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 403 ) );
		}
		if ( 'pending' !== $draft['status'] ) {
			return new \WP_Error( 'rar_draft_state', __( 'This draft is no longer pending.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 409 ) );
		}
		if ( $draft['blockers'] && empty( $opts['ack'] ) ) {
			return new \WP_Error( 'rar_guard_ack', __( 'This draft contains facts that are not in your product data. Check them and confirm "I verified these facts" to apply.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 409 ) );
		}
		$current = Fields::get( $draft['object_type'], $draft['object_id'], $draft['field'] );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT fingerprint FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( empty( $opts['force'] ) && $row && ! hash_equals( (string) $row['fingerprint'], Util::hash( (string) $current ) ) ) {
			return new \WP_Error( 'rar_stale', __( 'This field changed after the preview was made. Generate a new preview, or apply anyway to overwrite the newer value.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 409, 'current' => (string) $current ) );
		}
		if ( ! self::transition( $id, 'pending', 'applying' ) ) {
			return new \WP_Error( 'rar_draft_state', __( 'This draft is already being applied.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 409 ) );
		}
		$result = Fields::set( $draft['object_type'], $draft['object_id'], $draft['field'], $draft['proposed'] );
		if ( is_wp_error( $result ) ) {
			self::transition( $id, 'applying', 'pending' );
			return $result;
		}
		// If the applied value differs from what was stored (sanitisation), keep the real value for rollback checks.
		$stored = Fields::get( $draft['object_type'], $draft['object_id'], $draft['field'] );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'     => 'applied',
				'proposed'   => is_wp_error( $stored ) ? $draft['proposed'] : (string) $stored,
				'original'   => (string) $current,
				'applied_by' => get_current_user_id(),
				'applied_at' => Util::now(),
				'updated_at' => Util::now(),
			),
			array( 'id' => (int) $id )
		);
		Logger::log(
			'draft_applied',
			sprintf( '%s applied (%s).', $draft['label'], $draft['source'] ),
			array(
				'draft'   => $id,
				'field'   => $draft['field'],
				'flagged' => count( $draft['flags'] ),
				'acked'   => ! empty( $opts['ack'] ),
				'forced'  => ! empty( $opts['force'] ),
			),
			'info',
			$draft['object_type'],
			$draft['object_id']
		);
		do_action( 'rar_studio_draft_applied', self::get( $id ) );
		return self::get( $id );
	}

	/**
	 * Roll back an applied draft to its original value.
	 *
	 * @param int   $id   Draft ID.
	 * @param array $opts force (bool) – overwrite edits made after applying.
	 * @return array|\WP_Error
	 */
	public static function rollback( $id, array $opts = array() ) {
		global $wpdb;
		$draft = self::get( $id );
		if ( ! $draft ) {
			return new \WP_Error( 'rar_draft', __( 'Draft not found.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 404 ) );
		}
		if ( ! Util::can_edit_object( $draft['object_type'], $draft['object_id'] ) ) {
			return new \WP_Error( 'rar_forbidden', __( 'You cannot edit this item.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 403 ) );
		}
		if ( 'applied' !== $draft['status'] ) {
			return new \WP_Error( 'rar_draft_state', __( 'Only applied changes can be rolled back.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 409 ) );
		}
		$current = Fields::get( $draft['object_type'], $draft['object_id'], $draft['field'] );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( empty( $opts['force'] ) && trim( (string) $current ) !== trim( $draft['proposed'] ) ) {
			return new \WP_Error( 'rar_conflict', __( 'This field was edited after the change was applied. Rolling back would overwrite that newer edit. Use "Roll back anyway" to confirm.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 409, 'current' => (string) $current ) );
		}
		if ( ! self::transition( $id, 'applied', 'rolling_back' ) ) {
			return new \WP_Error( 'rar_draft_state', __( 'Rollback already in progress.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 409 ) );
		}
		$result = Fields::set( $draft['object_type'], $draft['object_id'], $draft['field'], $draft['original'] );
		if ( is_wp_error( $result ) ) {
			self::transition( $id, 'rolling_back', 'applied' );
			return $result;
		}
		$wpdb->update( self::table(), array( 'status' => 'rolled_back', 'updated_at' => Util::now() ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		Logger::log( 'draft_rolled_back', sprintf( '%s restored to the previous value.', $draft['label'] ), array( 'draft' => $id, 'forced' => ! empty( $opts['force'] ) ), 'info', $draft['object_type'], $draft['object_id'] );
		return self::get( $id );
	}

	/**
	 * Reject a pending draft (image derivatives that are unused are deleted).
	 *
	 * @param int $id Draft ID.
	 * @return array|\WP_Error
	 */
	public static function reject( $id ) {
		$draft = self::get( $id );
		if ( ! $draft ) {
			return new \WP_Error( 'rar_draft', __( 'Draft not found.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 404 ) );
		}
		if ( ! Util::can_edit_object( $draft['object_type'], $draft['object_id'] ) ) {
			return new \WP_Error( 'rar_forbidden', __( 'You cannot edit this item.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 403 ) );
		}
		if ( ! self::transition( $id, 'pending', 'rejected' ) ) {
			return new \WP_Error( 'rar_draft_state', __( 'This draft is no longer pending.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $row ) {
			self::cleanup_derivative( $row );
		}
		return self::get( $id );
	}

	/**
	 * Delete an unused derivative image created by the Studio.
	 *
	 * @param array $row Raw DB row.
	 * @return void
	 */
	public static function cleanup_derivative( array $row ) {
		global $wpdb;
		$meta = Util::json_array( $row['meta'] ?? '' );
		$ids  = array_map( 'absint', (array) ( $meta['derivatives'] ?? array() ) );
		if ( ! empty( $meta['derivative'] ) ) {
			$ids[] = absint( $meta['derivative'] );
		}
		foreach ( array_unique( array_filter( $ids ) ) as $att ) {
			if ( ! get_post_meta( $att, '_rar_studio_derivative', true ) || Image\Studio::attachment_in_use( $att ) ) {
				continue;
			}
			// Still referenced by another pending draft of the same item (e.g. a newer gallery draft)?
			$pending = $wpdb->get_col( $wpdb->prepare( 'SELECT proposed FROM ' . self::table() . " WHERE object_type = %s AND object_id = %d AND status = 'pending' AND id <> %d", $row['object_type'], (int) $row['object_id'], (int) $row['id'] ) ); // phpcs:ignore WordPress.DB
			$used    = false;
			foreach ( (array) $pending as $value ) {
				if ( in_array( (string) $att, explode( ',', (string) $value ), true ) ) {
					$used = true;
					break;
				}
			}
			if ( ! $used ) {
				wp_delete_attachment( $att, true );
			}
		}
	}

	/**
	 * Remove the drafts of a permanently deleted post, product, page or attachment.
	 * Unused generated images of its pending drafts are removed too; applied images stay.
	 *
	 * @param int           $post_id Deleted post ID.
	 * @param \WP_Post|null $post    Deleted post object (WP 5.5+).
	 * @return void
	 */
	public static function purge_object( $post_id, $post = null ) {
		global $wpdb;
		$post_id = absint( $post_id );
		$type    = $post instanceof \WP_Post ? $post->post_type : '';
		if ( ! $post_id || ! in_array( $type, array( 'product', 'post', 'page', 'attachment' ), true ) ) {
			return;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d', $type, $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d', $type, $post_id ) ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $row ) {
			if ( 'pending' === $row['status'] ) {
				self::cleanup_derivative( $row );
			}
		}
	}

	/**
	 * Counts by status (dashboard).
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS c FROM ' . self::table() . ' GROUP BY status', ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array(
			'pending'     => 0,
			'applied'     => 0,
			'rejected'    => 0,
			'rolled_back' => 0,
		);
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['c'];
		}
		return $out;
	}
}
