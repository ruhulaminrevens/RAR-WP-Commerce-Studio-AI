<?php
/**
 * Uninstall cleanup. Data is kept unless the administrator enabled
 * "Delete all plugin data on uninstall" in Settings → General.
 *
 * @package RarStudio
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$rar_studio_settings = get_option( 'rar_studio_settings_v1', array() );
$rar_studio_delete   = is_array( $rar_studio_settings ) && ! empty( $rar_studio_settings['general']['delete_on_uninstall'] );

wp_clear_scheduled_hook( 'rar_studio_bulk_tick' );
wp_clear_scheduled_hook( 'rar_studio_daily' );

if ( ! $rar_studio_delete ) {
	return;
}

global $wpdb;
foreach ( array( 'rar_studio_drafts', 'rar_studio_jobs', 'rar_studio_job_items', 'rar_studio_usage', 'rar_studio_log' ) as $rar_studio_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $rar_studio_table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
foreach ( array( 'rar_studio_settings_v1', 'rar_studio_settings', 'rar_studio_db_version', 'rar_studio_catalog_health', 'rar_studio_migrated_from' ) as $rar_studio_option ) {
	delete_option( $rar_studio_option );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rar\_studio\_usage\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'rar_studio_theme'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
