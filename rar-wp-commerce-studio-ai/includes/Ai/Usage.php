<?php
/**
 * API usage ledger: reservations, limits, token and cost tracking.
 * Costs are ESTIMATES from the prices entered in AI Providers; the provider's
 * own billing dashboard is authoritative.
 *
 * @package RarStudio
 */

namespace RarStudio\Ai;

use RarStudio\Settings;
use RarStudio\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Usage ledger.
 */
final class Usage {

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rar_studio_usage';
	}

	/**
	 * UTC timestamp (MySQL format) for the start of today / this month in the site timezone.
	 *
	 * @param string $period day|month.
	 * @return string
	 */
	public static function period_start( $period ) {
		$now   = current_datetime();
		$start = 'month' === $period ? $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'm' ), 1 )->setTime( 0, 0 ) : $now->setTime( 0, 0 );
		return gmdate( 'Y-m-d H:i:s', $start->getTimestamp() );
	}

	/**
	 * Estimated cost in USD.
	 *
	 * @param string $provider Provider.
	 * @param int    $in       Input tokens.
	 * @param int    $out      Output tokens.
	 * @return float
	 */
	public static function cost( $provider, $in, $out ) {
		$cfg = (array) Settings::get( 'ai.providers.' . $provider, array() );
		if ( ! empty( $cfg['free_tier'] ) ) {
			return 0.0;
		}
		return round( ( (float) ( $cfg['price_in'] ?? 0 ) * $in + (float) ( $cfg['price_out'] ?? 0 ) * $out ) / 1000000, 6 );
	}

	/**
	 * Rough token estimate for a request (4 chars ≈ 1 token; images ≈ 1,000 tokens).
	 *
	 * @param array $request Request.
	 * @return array{in:int,out:int}
	 */
	public static function estimate_tokens( array $request ) {
		$chars = strlen( (string) ( $request['system'] ?? '' ) ) + strlen( (string) ( $request['prompt'] ?? '' ) );
		return array(
			'in'  => (int) ceil( $chars / 3.5 ) + 1000 * count( (array) ( $request['images'] ?? array() ) ),
			'out' => (int) ( $request['max_tokens'] ?? 1200 ),
		);
	}

	/**
	 * Reserve a request slot, enforcing per-minute, daily and monthly-budget limits.
	 *
	 * @param string $provider Provider.
	 * @param string $model    Model.
	 * @param string $task     Task.
	 * @param float  $estimate Estimated cost (counted against the budget while in flight).
	 * @param array  $ctx      object_type, object_id.
	 * @return int|\WP_Error Reservation ID.
	 */
	public static function reserve( $provider, $model, $task, $estimate, array $ctx = array() ) {
		global $wpdb;
		$table = self::table();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'provider'    => $provider,
				'model'       => Util::limit( $model, 100 ),
				'task'        => Util::limit( sanitize_key( $task ), 40 ),
				'status'      => 'reserved',
				'cost'        => (float) $estimate,
				'user_id'     => get_current_user_id(),
				'object_type' => Util::limit( sanitize_key( $ctx['object_type'] ?? '' ), 20 ),
				'object_id'   => absint( $ctx['object_id'] ?? 0 ),
				'created_at'  => Util::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new \WP_Error( 'rar_ai_ledger', __( 'Could not record the API request; it was not sent.', 'rar-wp-commerce-studio-ai' ) );
		}
		$counted = "status IN ('reserved','ok','error')";
		// phpcs:disable WordPress.DB
		$today  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$counted} AND created_at >= %s", self::period_start( 'day' ) ) );
		$minute = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$counted} AND created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
		$month  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(cost),0) FROM {$table} WHERE {$counted} AND created_at >= %s", self::period_start( 'month' ) ) );
		// phpcs:enable
		$block = '';
		if ( $today > (int) Settings::get( 'ai.daily_limit', 20 ) ) {
			$block = __( 'Daily API request limit reached. Raise it in AI Providers → Limits, or try tomorrow.', 'rar-wp-commerce-studio-ai' );
		} elseif ( $minute > (int) Settings::get( 'ai.per_minute', 6 ) ) {
			$block = __( 'Too many API requests in the last minute. Wait a moment and try again.', 'rar-wp-commerce-studio-ai' );
		} elseif ( $estimate > 0 && $month > (float) Settings::get( 'ai.monthly_budget', 5 ) ) {
			$block = __( 'Monthly API budget reached (estimated). Raise the budget in AI Providers → Limits to continue.', 'rar-wp-commerce-studio-ai' );
		}
		if ( $block ) {
			$wpdb->update( $table, array( 'status' => 'blocked', 'cost' => 0, 'message' => Util::limit( $block, 255 ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
			return new \WP_Error( 'rar_ai_limit', $block, array( 'status' => 429 ) );
		}
		return $id;
	}

	/**
	 * Close a reservation with real token counts.
	 *
	 * @param int    $id       Reservation.
	 * @param string $status   ok|error.
	 * @param int    $in       Input tokens.
	 * @param int    $out      Output tokens.
	 * @param float  $cost     Cost.
	 * @param string $message  Message.
	 * @param int    $duration Milliseconds.
	 * @return void
	 */
	public static function finish( $id, $status, $in, $out, $cost, $message = '', $duration = 0 ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'        => in_array( $status, array( 'ok', 'error' ), true ) ? $status : 'error',
				'input_tokens'  => absint( $in ),
				'output_tokens' => absint( $out ),
				'cost'          => (float) $cost,
				'message'       => Util::limit( \RarStudio\Logger::scrub( array( 'm' => (string) $message ) )['m'], 255 ),
				'duration_ms'   => absint( $duration ),
			),
			array( 'id' => absint( $id ) )
		);
	}

	/**
	 * Usage summary for dashboards.
	 *
	 * @return array
	 */
	public static function summary() {
		global $wpdb;
		$table = self::table();
		$day   = self::period_start( 'day' );
		$month = self::period_start( 'month' );
		// phpcs:disable WordPress.DB
		$by_provider = $wpdb->get_results( $wpdb->prepare( "SELECT provider, COUNT(*) AS requests, SUM(CASE WHEN status='ok' THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) AS errors, SUM(CASE WHEN status='blocked' THEN 1 ELSE 0 END) AS blocked, COALESCE(SUM(input_tokens),0) AS input_tokens, COALESCE(SUM(output_tokens),0) AS output_tokens, COALESCE(SUM(CASE WHEN status IN ('ok','error','reserved') THEN cost ELSE 0 END),0) AS cost FROM {$table} WHERE created_at >= %s GROUP BY provider", $month ), ARRAY_A );
		$today       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status IN ('reserved','ok','error') AND created_at >= %s", $day ) );
		$recent      = $wpdb->get_results( "SELECT id, provider, model, task, status, input_tokens, output_tokens, cost, duration_ms, message, object_type, object_id, created_at FROM {$table} ORDER BY id DESC LIMIT 50", ARRAY_A );
		$daily_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT created_at, cost, status FROM {$table} WHERE created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS ) ), ARRAY_A );
		// phpcs:enable
		$days = array();
		for ( $i = 13; $i >= 0; $i-- ) {
			$key          = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$days[ $key ] = array(
				'date'     => $key,
				'requests' => 0,
				'cost'     => 0.0,
			);
		}
		foreach ( (array) $daily_rows as $row ) {
			if ( 'blocked' === $row['status'] ) {
				continue;
			}
			$key = wp_date( 'Y-m-d', strtotime( $row['created_at'] . ' UTC' ) );
			if ( isset( $days[ $key ] ) ) {
				++$days[ $key ]['requests'];
				$days[ $key ]['cost'] += (float) $row['cost'];
			}
		}
		$month_cost = 0.0;
		$providers  = array();
		foreach ( (array) $by_provider as $row ) {
			$month_cost                   += (float) $row['cost'];
			$providers[ $row['provider'] ] = array(
				'requests'      => (int) $row['requests'],
				'ok'            => (int) $row['ok'],
				'errors'        => (int) $row['errors'],
				'blocked'       => (int) $row['blocked'],
				'input_tokens'  => (int) $row['input_tokens'],
				'output_tokens' => (int) $row['output_tokens'],
				'cost'          => round( (float) $row['cost'], 4 ),
			);
		}
		return array(
			'today_requests' => $today,
			'daily_limit'    => (int) Settings::get( 'ai.daily_limit', 20 ),
			'month_cost'     => round( $month_cost, 4 ),
			'monthly_budget' => (float) Settings::get( 'ai.monthly_budget', 5 ),
			'providers'      => $providers,
			'days'           => array_values( $days ),
			'recent'         => (array) $recent,
		);
	}
}
