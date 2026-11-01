<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiReviewSummarizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Scheduled jobs are always removed (they would fail without the plugin).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'dev3rs_generate_product', 'dev3rs_nightly_sweep', 'dev3rs_bulk_tick' ) as $dev3rs_hook ) {
		as_unschedule_all_actions( $dev3rs_hook, null, 'dev3rs' );
	}
}
foreach ( array( 'dev3rs_generate_product', 'dev3rs_nightly_sweep', 'dev3rs_bulk_tick', 'dev3rs_daily' ) as $dev3rs_hook ) {
	wp_unschedule_hook( $dev3rs_hook );
}

$dev3rs_settings = get_option( 'dev3rs_settings', array() );
if ( empty( $dev3rs_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dev3rs_usage" );
// Core tables added with the shared core v2 (AI cache, background jobs, embeddings).
foreach ( array( 'core_cache', 'core_jobs', 'core_embeddings' ) as $dev3rs_core_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dev3rs_{$dev3rs_core_table}" );
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_dev3rs_summary', '_dev3rs_locked')" );
foreach ( array( 'dev3rs_settings', 'dev3rs_license', 'dev3rs_db_version', 'dev3rs_bulk_job', 'dev3rs_bulk_lock' ) as $dev3rs_option ) {
	delete_option( $dev3rs_option );
}
delete_transient( 'dev3rs_models' );
delete_transient( 'dev3rs_sweep_check' );
delete_site_transient( 'dev3rs_update_info' );
