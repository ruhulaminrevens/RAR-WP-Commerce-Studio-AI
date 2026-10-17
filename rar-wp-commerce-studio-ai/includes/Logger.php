<?php
/**
 * Activity log (custom table). Never stores secrets or full content bodies.
 *
 * @package RarStudio
 */

namespace RarStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Activity logger.
 */
final class Logger {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rar_studio_log';
	}

	/**
	 * Remove secret-looking keys and long values from a context array.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	public static function scrub( array $context ) {
		$out = array();
		foreach ( $context as $k => $v ) {
			if ( preg_match( '/key|token|secret|password|authorization|auth|cookie/i', (string) $k ) ) {
				$out[ $k ] = '[redacted]';
				continue;
			}
			if ( is_array( $v ) ) {
				$out[ $k ] = self::scrub( $v );
			} elseif ( is_string( $v ) ) {
				$v         = preg_replace( '/(sk-[A-Za-z0-9_\-]{6,}|AIza[0-9A-Za-z_\-]{10,}|sk-ant-[A-Za-z0-9_\-]{6,})/', '[redacted]', $v );
				$out[ $k ] = Util::limit( $v, 300 );
			} elseif ( is_scalar( $v ) || null === $v ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $action      Machine action, e.g. draft_applied.
	 * @param string $message     Human message.
	 * @param array  $context     Extra context (scrubbed).
	 * @param string $level       info|warning|error|security.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return void
	 */
	public static function log( $action, $message, array $context = array(), $level = 'info', $object_type = '', $object_id = 0 ) {
		global $wpdb;
		if ( ! in_array( $level, array( 'info', 'warning', 'error', 'security' ), true ) ) {
			$level = 'info';
		}
		$message = preg_replace( '/(sk-[A-Za-z0-9_\-]{6,}|AIza[0-9A-Za-z_\-]{10,}|sk-ant-[A-Za-z0-9_\-]{6,})/', '[redacted]', (string) $message );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'level'       => $level,
				'action'      => Util::limit( sanitize_key( $action ), 60 ),
				'object_type' => Util::limit( sanitize_key( $object_type ), 20 ),
				'object_id'   => absint( $object_id ),
				'user_id'     => get_current_user_id(),
				'message'     => Util::limit( wp_strip_all_tags( $message ), 500 ),
				'context'     => $context ? wp_json_encode( self::scrub( $context ) ) : '',
				'created_at'  => Util::now(),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Query logs.
	 *
	 * @param array $args level, action, search, page, per_page.
	 * @return array{items:array,total:int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table    = self::table();
		$where    = array( '1=1' );
		$params   = array();
		$per_page = max( 1, min( 200, absint( $args['per_page'] ?? 30 ) ) );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		if ( ! empty( $args['level'] ) && in_array( $args['level'], array( 'info', 'warning', 'error', 'security' ), true ) ) {
			$where[]  = 'level = %s';
			$params[] = $args['level'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[]  = '(message LIKE %s OR action LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['object_type'] ) && ! empty( $args['object_id'] ) ) {
			$where[]  = 'object_type = %s AND object_id = %d';
			$params[] = sanitize_key( $args['object_type'] );
			$params[] = absint( $args['object_id'] );
		}
		$sql_where = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$list_sql  = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable
		$items = array();
		foreach ( (array) $rows as $row ) {
			$user    = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
			$items[] = array(
				'id'          => (int) $row['id'],
				'level'       => $row['level'],
				'action'      => $row['action'],
				'object_type' => $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'user'        => $user ? $user->display_name : '',
				'message'     => $row['message'],
				'context'     => Util::json_array( $row['context'] ),
				'created_at'  => $row['created_at'],
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Delete entries older than the retention period.
	 *
	 * @return int Rows removed.
	 */
	public static function prune() {
		global $wpdb;
		$days   = (int) Settings::get( 'general.log_retention_days', 90 );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete all log entries.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB
	}
}
