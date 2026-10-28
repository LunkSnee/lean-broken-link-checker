<?php
/**
 * Plugin Name: Lean Broken Link Checker
 * Plugin URI: https://github.com/LunkSnee/lean-broken-link-checker
 * Description: Ultra-lightweight, zero-DB-bloat link scanner with Wayback Machine auto-reconnect, continuous cron cycling, and detailed activity logs. <a href="tools.php?page=lean-broken-links&tab=settings">Configure Settings</a>
 * Version: 1.3.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: LunkSnee
 * Author URI: https://github.com/LunkSnee
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lean-broken-link-checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEAN_BLC_VERSION', '1.3.0' );
define( 'LEAN_BLC_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEAN_BLC_URL', plugin_dir_url( __FILE__ ) );

require_once LEAN_BLC_DIR . 'includes/class-db.php';
require_once LEAN_BLC_DIR . 'includes/class-wayback.php';
require_once LEAN_BLC_DIR . 'includes/class-scanner.php';
require_once LEAN_BLC_DIR . 'includes/class-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once LEAN_BLC_DIR . 'includes/class-cli.php';
}

/**
 * Add settings action link to Plugins page.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'lean_blc_action_links' );
function lean_blc_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'tools.php?page=lean-broken-links&tab=settings' ) ) . '">' . __( 'Settings', 'lean-broken-link-checker' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

/**
 * Register dynamic cron schedule based on user-defined hours interval.
 */
add_filter( 'cron_schedules', 'lean_blc_custom_cron_schedules' );
function lean_blc_custom_cron_schedules( $schedules ) {
	$settings = get_option( 'lean_blc_settings', array() );
	$hours = ! empty( $settings['cron_interval_hours'] ) ? max( 1, intval( $settings['cron_interval_hours'] ) ) : 24;

	$schedules['lean_blc_custom_interval'] = array(
		'interval' => $hours * HOUR_IN_SECONDS,
		'display'  => sprintf( 'Every %d hours', $hours ),
	);

	return $schedules;
}

/**
 * Reschedule the cron event when settings change.
 */
function lean_blc_reschedule_cron( $hours ) {
	$timestamp = wp_next_scheduled( 'lean_blc_scheduled_scan' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'lean_blc_scheduled_scan' );
	}
	$hours = max( 1, intval( $hours ) );
	wp_schedule_event( time() + ( $hours * HOUR_IN_SECONDS ), 'lean_blc_custom_interval', 'lean_blc_scheduled_scan' );
}

/**
 * Activation hook: set up database tables, default options, and schedule cron event.
 */
function lean_blc_activate() {
	Lean_BLC_DB::create_table();

	$defaults = array(
		'cron_interval_hours' => 24,
		'cron_batch_size'     => 30,
		'requests_per_minute' => 10,
		'timeout'             => 15,
		'max_log_rows'        => 100,
		'check_images'        => true,
		'send_email_report'   => true,
		'auto_lookup_archive' => true,
		'excluded_domains'    => '',
		'post_types'          => array( 'post', 'page' ),
	);

	$current = get_option( 'lean_blc_settings' );
	if ( ! $current || ! is_array( $current ) ) {
		update_option( 'lean_blc_settings', $defaults );
	} else {
		if ( ! isset( $current['max_log_rows'] ) ) {
			$current['max_log_rows'] = 100;
			update_option( 'lean_blc_settings', $current );
		}
	}

	if ( ! wp_next_scheduled( 'lean_blc_scheduled_scan' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'lean_blc_custom_interval', 'lean_blc_scheduled_scan' );
	}

	update_option( 'lean_blc_version', LEAN_BLC_VERSION, false );
}
register_activation_hook( __FILE__, 'lean_blc_activate' );

/**
 * Upgrade routine: WordPress does not run the activation hook when a plugin is updated
 * (e.g. by uploading a newer zip), so re-run it once whenever the stored version differs.
 */
function lean_blc_maybe_upgrade() {
	if ( get_option( 'lean_blc_version' ) === LEAN_BLC_VERSION ) {
		return;
	}
	lean_blc_activate();

	// 1.3.0: the per-URL timestamp registry grows with the site; stop autoloading it.
	$timestamps = get_option( 'lean_blc_url_timestamps', null );
	if ( null !== $timestamps ) {
		delete_option( 'lean_blc_url_timestamps' );
		add_option( 'lean_blc_url_timestamps', $timestamps, '', false );
	}
}
add_action( 'plugins_loaded', 'lean_blc_maybe_upgrade' );

/**
 * Deactivation hook: clear scheduled cron events.
 */
function lean_blc_deactivate() {
	wp_clear_scheduled_hook( 'lean_blc_scheduled_scan' );
}
register_deactivation_hook( __FILE__, 'lean_blc_deactivate' );

/**
 * Cron execution handler: runs an incremental batch cycling through oldest checked links.
 */
add_action( 'lean_blc_scheduled_scan', 'lean_blc_run_cron_scan' );
function lean_blc_run_cron_scan() {
	$scanner = new Lean_BLC_Scanner();
	$scanner->run_cron_batch(); // Returns a WP_Error and does nothing if a run is already in progress.
}
