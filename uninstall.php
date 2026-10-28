<?php
/**
 * Fired when the plugin is uninstalled.
 * Guarantees zero residue left in the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-db.php';

// Drop the obvious custom table
Lean_BLC_DB::drop_table();

// Remove all plugin options
delete_option( 'lean_blc_settings' );
delete_option( 'lean_blc_stats' );
delete_option( 'lean_blc_dismiss_notice' );
delete_option( 'lean_blc_url_timestamps' );
delete_option( 'lean_blc_version' );
delete_option( 'lean_blc_scan_lock' );

// Remove per-user notice dismissals
delete_metadata( 'user', 0, 'lean_blc_dismissed_scan', '', true );

// Remove any scheduled cron tasks
wp_clear_scheduled_hook( 'lean_blc_scheduled_scan' );
