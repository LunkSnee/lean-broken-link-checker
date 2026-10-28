<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_BLC_CLI {

	/**
	 * Run an incremental cron batch (cycles through the oldest checked links).
	 *
	 * ## EXAMPLES
	 *
	 *     wp link-check cron
	 */
	public function cron( $args, $assoc_args ) {
		WP_CLI::line( 'Executing incremental link check batch (oldest verified links first)...' );

		$scanner = new Lean_BLC_Scanner();
		$stats = $scanner->run_cron_batch();
		if ( is_wp_error( $stats ) ) {
			WP_CLI::error( $stats->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'Batch check completed! Batch Checked: %d | Broken in DB: %d | Progress: %d / %d URLs',
				$stats['batch_checked'],
				$stats['broken'],
				$stats['cycle_progress'],
				$stats['unique_urls']
			)
		);
	}

	/**
	 * Run a full on-demand link scan across all content.
	 *
	 * ## EXAMPLES
	 *
	 *     wp link-check scan
	 */
	public function scan( $args, $assoc_args ) {
		WP_CLI::line( 'Starting full rate-limited link health scan across published content...' );

		$scanner = new Lean_BLC_Scanner();
		$stats = $scanner->run_full_scan();
		if ( is_wp_error( $stats ) ) {
			WP_CLI::error( $stats->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'Scan finished! Total: %d | Healthy: %d | Redirects: %d | Broken: %d',
				$stats['total_links'],
				$stats['healthy'],
				$stats['redirected'],
				$stats['broken']
			)
		);

		if ( $stats['broken'] > 0 ) {
			$broken_items = Lean_BLC_DB::get_broken_links( 500, 0 );
			WP_CLI::line( "\nBroken Links Detected:" );
			$table_data = array();
			foreach ( $broken_items as $item ) {
				$table_data[] = array(
					'Post ID' => $item['post_id'],
					'Title'   => substr( get_the_title( $item['post_id'] ), 0, 30 ),
					'Status'  => $item['status_code'],
					'URL'     => $item['url'],
					'Archive' => ! empty( $item['wayback_url'] ) ? 'Yes' : 'No',
				);
			}
			WP_CLI\Utils\format_items( 'table', $table_data, array( 'Post ID', 'Title', 'Status', 'URL', 'Archive' ) );
		}
	}

	/**
	 * Display recent activity log entries.
	 *
	 * ## EXAMPLES
	 *
	 *     wp link-check log
	 */
	public function log( $args, $assoc_args ) {
		$logs = Lean_BLC_DB::get_logs( 50 );
		if ( empty( $logs ) ) {
			WP_CLI::line( 'No activity log entries found.' );
			return;
		}

		$table_data = array();
		foreach ( $logs as $entry ) {
			$table_data[] = array(
				'Time'    => $entry['checked_at'],
				'Status'  => $entry['status_code'],
				'Result'  => $entry['result'],
				'Latency' => $entry['response_time_ms'] . ' ms',
				'URL'     => substr( $entry['url'], 0, 45 ),
				'Message' => substr( $entry['message'], 0, 30 ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $table_data, array( 'Time', 'Status', 'Result', 'Latency', 'URL', 'Message' ) );
	}

	/**
	 * Display the latest scan report.
	 *
	 * ## EXAMPLES
	 *
	 *     wp link-check report
	 */
	public function report( $args, $assoc_args ) {
		$stats = get_option( 'lean_blc_stats', array() );
		if ( empty( $stats['last_updated'] ) ) {
			WP_CLI::warning( 'No scans have been executed yet. Run: wp link-check scan or wp link-check cron' );
			return;
		}

		WP_CLI::line( sprintf( "Last Scan: %s\nUnique URLs: %d | Broken: %d\n", $stats['last_updated'], $stats['unique_urls'], $stats['broken'] ) );

		$broken_items = Lean_BLC_DB::get_broken_links( 500, 0 );
		if ( ! empty( $broken_items ) ) {
			$table_data = array();
			foreach ( $broken_items as $item ) {
				$table_data[] = array(
					'Post ID' => $item['post_id'],
					'Status'  => $item['status_code'],
					'URL'     => $item['url'],
					'Archive' => ! empty( $item['wayback_url'] ) ? $item['wayback_url'] : 'None',
				);
			}
			WP_CLI\Utils\format_items( 'table', $table_data, array( 'Post ID', 'Status', 'URL', 'Archive' ) );
		} else {
			WP_CLI::success( 'No broken links recorded in the database.' );
		}
	}

	/**
	 * Replace a broken link in a post with a replacement URL.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ID of the post containing the broken link.
	 *
	 * <old_url>
	 * : The current dead link URL.
	 *
	 * <new_url>
	 * : The replacement URL (e.g. Wayback snapshot).
	 *
	 * Runs as the user given with --user, who must be able to edit the post.
	 *
	 * ## EXAMPLES
	 *
	 *     wp link-check fix 42 "http://dead.example.com" "https://web.archive.org/..." --user=admin
	 */
	public function fix( $args, $assoc_args ) {
		list( $post_id, $old_url, $new_url ) = $args;
		$res = Lean_BLC_Wayback::replace_link_in_post( intval( $post_id ), $old_url, $new_url );

		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		} else {
			WP_CLI::success( sprintf( 'Successfully replaced link in post %d.', $post_id ) );
		}
	}
}

WP_CLI::add_command( 'link-check', 'Lean_BLC_CLI' );
