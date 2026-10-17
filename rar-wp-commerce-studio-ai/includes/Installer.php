<?php
/**
 * Activation, database tables and upgrades from v0.1.x.
 *
 * @package RarStudio
 */

namespace RarStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
final class Installer {

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_tables();
		self::migrate_legacy();
		update_option( 'rar_studio_db_version', RAR_STUDIO_DB_VERSION, false );
		if ( ! wp_next_scheduled( 'rar_studio_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rar_studio_daily' );
		}
	}

	/**
	 * Deactivation hook: stop scheduled work, keep data.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'rar_studio_bulk_tick' );
		wp_clear_scheduled_hook( 'rar_studio_daily' );
	}

	/**
	 * Run upgrades when the stored DB version is older (e.g. ZIP replaced in place).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'rar_studio_db_version' ) === RAR_STUDIO_DB_VERSION ) {
			return;
		}
		// Prevent two parallel requests from running the upgrade at once.
		if ( get_transient( 'rar_studio_upgrading' ) ) {
			return;
		}
		set_transient( 'rar_studio_upgrading', 1, 2 * MINUTE_IN_SECONDS );
		self::activate();
		delete_transient( 'rar_studio_upgrading' );
	}

	/**
	 * Create or update tables with dbDelta.
	 *
	 * @return void
	 */
	public static function install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$sql = array();

		$sql[] = "CREATE TABLE {$p}rar_studio_drafts (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
object_type varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
field varchar(64) NOT NULL DEFAULT '',
original longtext NULL,
proposed longtext NULL,
source varchar(160) NOT NULL DEFAULT '',
status varchar(20) NOT NULL DEFAULT 'pending',
flags longtext NULL,
meta longtext NULL,
fingerprint char(64) NOT NULL DEFAULT '',
job_id bigint(20) unsigned NOT NULL DEFAULT 0,
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
applied_by bigint(20) unsigned NOT NULL DEFAULT 0,
applied_at datetime NULL,
PRIMARY KEY  (id),
KEY object_ref (object_type,object_id),
KEY status (status),
KEY job_id (job_id)
) {$charset};";

		$sql[] = "CREATE TABLE {$p}rar_studio_jobs (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
operation varchar(40) NOT NULL DEFAULT '',
status varchar(20) NOT NULL DEFAULT 'queued',
options longtext NULL,
total int(10) unsigned NOT NULL DEFAULT 0,
processed int(10) unsigned NOT NULL DEFAULT 0,
succeeded int(10) unsigned NOT NULL DEFAULT 0,
failed int(10) unsigned NOT NULL DEFAULT 0,
skipped int(10) unsigned NOT NULL DEFAULT 0,
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
started_at datetime NULL,
finished_at datetime NULL,
last_error text NULL,
lock_token varchar(40) NOT NULL DEFAULT '',
lock_expires bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY status (status)
) {$charset};";

		$sql[] = "CREATE TABLE {$p}rar_studio_job_items (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
job_id bigint(20) unsigned NOT NULL DEFAULT 0,
object_type varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
status varchar(20) NOT NULL DEFAULT 'pending',
attempts smallint(5) unsigned NOT NULL DEFAULT 0,
message text NULL,
result longtext NULL,
updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
PRIMARY KEY  (id),
KEY job_status (job_id,status)
) {$charset};";

		$sql[] = "CREATE TABLE {$p}rar_studio_usage (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
provider varchar(20) NOT NULL DEFAULT '',
model varchar(100) NOT NULL DEFAULT '',
task varchar(40) NOT NULL DEFAULT '',
status varchar(20) NOT NULL DEFAULT 'reserved',
input_tokens int(10) unsigned NOT NULL DEFAULT 0,
output_tokens int(10) unsigned NOT NULL DEFAULT 0,
cost decimal(12,6) NOT NULL DEFAULT 0,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
object_type varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
duration_ms int(10) unsigned NOT NULL DEFAULT 0,
message varchar(255) NOT NULL DEFAULT '',
created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY provider_created (provider,created_at)
) {$charset};";

		$sql[] = "CREATE TABLE {$p}rar_studio_log (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
level varchar(10) NOT NULL DEFAULT 'info',
action varchar(60) NOT NULL DEFAULT '',
object_type varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
message text NULL,
context longtext NULL,
created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY level (level)
) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Convert v0.1.x settings and rollback metadata so earlier restore points stay usable.
	 *
	 * @return void
	 */
	public static function migrate_legacy() {
		global $wpdb;

		$legacy = get_option( 'rar_studio_settings' );
		if ( is_array( $legacy ) && ! get_option( 'rar_studio_migrated_from' ) ) {
			$s = Settings::all();
			foreach ( Settings::providers() as $p ) {
				if ( ! empty( $legacy[ $p . '_model' ] ) && preg_match( '/^[A-Za-z0-9._-]{3,80}$/D', (string) $legacy[ $p . '_model' ] ) ) {
					$s['ai']['providers'][ $p ]['model'] = (string) $legacy[ $p . '_model' ];
				}
				$s['ai']['providers'][ $p ]['enabled'] = empty( $legacy[ $p . '_enabled' ] ) ? 0 : 1;
			}
			if ( ! empty( $legacy['provider'] ) && in_array( $legacy['provider'], Settings::providers(), true ) ) {
				$s['ai']['primary'] = $legacy['provider'];
			}
			if ( ! empty( $legacy['daily_limit'] ) ) {
				$s['ai']['daily_limit'] = max( 1, min( 500, absint( $legacy['daily_limit'] ) ) );
			}
			// Safety: the master API switch is always OFF after an upgrade and must be re-acknowledged.
			$s['ai']['enabled'] = 0;
			Settings::save( $s );
			update_option( 'rar_studio_migrated_from', '0.1.x', false );
			Logger::log( 'upgrade', 'Upgraded from v0.1.x. Provider settings kept; the AI API master switch was turned OFF and must be re-enabled with cost acknowledgement.', array(), 'security' );
		}

		$table = $wpdb->prefix . 'rar_studio_drafts';
		$now   = Util::now();
		$map   = array(
			'_yoast_wpseo_title'    => 'seo_title',
			'_yoast_wpseo_metadesc' => 'seo_description',
			'_yoast_wpseo_focuskw'  => 'seo_focus',
		);

		// SEO snapshots → applied drafts (rollback restores the snapshot values).
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_rar_studio_seo_snapshot' LIMIT 2000" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			$snapshot = maybe_unserialize( $row->meta_value );
			if ( is_array( $snapshot ) ) {
				foreach ( $map as $meta_key => $field ) {
					if ( ! isset( $snapshot[ $meta_key ] ) ) {
						continue;
					}
					$original = empty( $snapshot[ $meta_key ]['exists'] ) ? '' : (string) $snapshot[ $meta_key ]['value'];
					$current  = (string) get_post_meta( (int) $row->post_id, $meta_key, true );
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$table,
						array(
							'object_type' => 'product',
							'object_id'   => (int) $row->post_id,
							'field'       => $field,
							'original'    => $original,
							'proposed'    => $current,
							'source'      => 'v0.1.x (migrated)',
							'status'      => 'applied',
							'fingerprint' => Util::hash( $original ),
							'meta'        => wp_json_encode( array( 'adapter' => 'yoast' ) ),
							'created_at'  => $now,
							'updated_at'  => $now,
							'applied_at'  => $now,
						)
					);
				}
			}
			delete_post_meta( (int) $row->post_id, '_rar_studio_seo_snapshot' );
		}

		// Previous featured image → applied featured_image draft.
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_rar_studio_image_previous_id' LIMIT 2000" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			$original = (string) absint( $row->meta_value );
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'object_type' => 'product',
					'object_id'   => (int) $row->post_id,
					'field'       => 'featured_image',
					'original'    => $original,
					'proposed'    => (string) absint( get_post_thumbnail_id( (int) $row->post_id ) ),
					'source'      => 'v0.1.x (migrated)',
					'status'      => 'applied',
					'fingerprint' => Util::hash( $original ),
					'created_at'  => $now,
					'updated_at'  => $now,
					'applied_at'  => $now,
				)
			);
			delete_post_meta( (int) $row->post_id, '_rar_studio_image_previous_id' );
		}

		// Unapplied 800×800 drafts → pending featured_image drafts.
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_rar_studio_image_draft_id' LIMIT 2000" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			$draft_attachment = absint( $row->meta_value );
			if ( $draft_attachment && get_post( $draft_attachment ) ) {
				$original = (string) absint( get_post_thumbnail_id( (int) $row->post_id ) );
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'object_type' => 'product',
						'object_id'   => (int) $row->post_id,
						'field'       => 'featured_image',
						'original'    => $original,
						'proposed'    => (string) $draft_attachment,
						'source'      => 'v0.1.x image draft (migrated)',
						'status'      => 'pending',
						'fingerprint' => Util::hash( $original ),
						'meta'        => wp_json_encode( array( 'derivative' => $draft_attachment ) ),
						'created_at'  => $now,
						'updated_at'  => $now,
					)
				);
			}
			delete_post_meta( (int) $row->post_id, '_rar_studio_image_draft_id' );
		}
	}
}
