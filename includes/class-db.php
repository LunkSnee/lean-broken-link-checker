<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Table names are built from $wpdb->prefix and constants, never from input; every value is prepared.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class Lean_BLC_DB {

	/**
	 * Get the obvious, standard prefixed table name for broken links.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lean_broken_links';
	}

	/**
	 * Get the obvious table name for link check activity logs.
	 */
	public static function get_log_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lean_link_log';
	}

	/**
	 * Create the tables on activation.
	 */
	public static function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Broken links table
		$table_name = self::get_table_name();
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			url text NOT NULL,
			status_code smallint(5) NOT NULL DEFAULT 0,
			error_message varchar(255) NOT NULL DEFAULT '',
			wayback_url text DEFAULT NULL,
			detected_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status_code (status_code)
		) {$charset_collate};";
		dbDelta( $sql );

		// 2. Activity log table
		self::create_log_table();
	}

	/**
	 * Create activity log table.
	 */
	public static function create_log_table() {
		global $wpdb;

		$log_table = self::get_log_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_log = "CREATE TABLE {$log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url text NOT NULL,
			status_code smallint(5) NOT NULL DEFAULT 0,
			result varchar(20) NOT NULL DEFAULT '',
			response_time_ms int(10) unsigned NOT NULL DEFAULT 0,
			message varchar(255) NOT NULL DEFAULT '',
			checked_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY checked_at (checked_at),
			KEY result (result)
		) {$charset_collate};";
		dbDelta( $sql_log );
	}

	/**
	 * Clear all entries from broken links table before a new complete scan.
	 */
	public static function clear_all() {
		global $wpdb;
		$table_name = self::get_table_name();
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );
	}

	/**
	 * Save or update a detected broken link.
	 */
	public static function save_broken_link( $post_id, $url, $status_code, $error_message, $wayback_url = null ) {
		global $wpdb;
		$table_name = self::get_table_name();

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE post_id = %d AND url = %s",
				intval( $post_id ),
				$url
			)
		);

		if ( $existing_id ) {
			return $wpdb->update(
				$table_name,
				array(
					'status_code'   => intval( $status_code ),
					'error_message' => substr( sanitize_text_field( $error_message ), 0, 255 ),
					'wayback_url'   => $wayback_url ? esc_url_raw( $wayback_url ) : null,
					'detected_at'   => current_time( 'mysql' ),
				),
				array( 'id' => $existing_id ),
				array( '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		return $wpdb->insert(
			$table_name,
			array(
				'post_id'       => intval( $post_id ),
				'url'           => $url,
				'status_code'   => intval( $status_code ),
				'error_message' => substr( sanitize_text_field( $error_message ), 0, 255 ),
				'wayback_url'   => $wayback_url ? esc_url_raw( $wayback_url ) : null,
				'detected_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get one recorded broken link by post and URL, or null.
	 */
	public static function get_link( $post_id, $url ) {
		global $wpdb;
		$table_name = self::get_table_name();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE post_id = %d AND url = %s",
				intval( $post_id ),
				$url
			),
			ARRAY_A
		);
	}

	/**
	 * Get count of broken links currently recorded.
	 */
	public static function get_count() {
		global $wpdb;
		$table_name = self::get_table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
	}

	/**
	 * Get broken links for admin display.
	 */
	public static function get_broken_links( $limit = 200, $offset = 0 ) {
		global $wpdb;
		$table_name = self::get_table_name();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d OFFSET %d",
				intval( $limit ),
				intval( $offset )
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Remove a single link entry by post_id and url after repair or dismissal.
	 */
	public static function delete_link( $post_id, $url ) {
		global $wpdb;
		$table_name = self::get_table_name();

		return $wpdb->delete(
			$table_name,
			array(
				'post_id' => intval( $post_id ),
				'url'     => $url,
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Remove any broken link entries matching a URL when it recovers / tests healthy.
	 */
	public static function delete_by_url( $url ) {
		global $wpdb;
		$table_name = self::get_table_name();

		return $wpdb->delete(
			$table_name,
			array( 'url' => $url ),
			array( '%s' )
		);
	}

	/**
	 * Log a link check event and automatically prune old entries beyond the configured limit.
	 */
	public static function log_entry( $url, $status_code, $result, $response_time_ms, $message, $max_rows = 100 ) {
		global $wpdb;
		$table_name = self::get_log_table_name();

		$wpdb->insert(
			$table_name,
			array(
				'url'              => $url,
				'status_code'      => intval( $status_code ),
				'result'           => sanitize_key( $result ),
				'response_time_ms' => intval( $response_time_ms ),
				'message'          => substr( sanitize_text_field( $message ), 0, 255 ),
				'checked_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		// Prune older rows to keep only the most recent N rows
		$max_rows = max( 20, intval( $max_rows ) );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		if ( $count > ( $max_rows + 5 ) ) {
			$cutoff_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table_name} ORDER BY id DESC LIMIT 1 OFFSET %d",
					$max_rows - 1
				)
			);
			if ( $cutoff_id ) {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table_name} WHERE id < %d",
						intval( $cutoff_id )
					)
				);
			}
		}
	}

	/**
	 * Fetch recent activity logs.
	 */
	public static function get_logs( $limit = 100, $result_filter = '' ) {
		global $wpdb;
		$table_name = self::get_log_table_name();

		if ( ! empty( $result_filter ) && in_array( $result_filter, array( 'healthy', 'redirect', 'broken' ), true ) ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name} WHERE result = %s ORDER BY id DESC LIMIT %d",
					$result_filter,
					intval( $limit )
				),
				ARRAY_A
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d",
					intval( $limit )
				),
				ARRAY_A
			);
		}

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Truncate the activity log.
	 */
	public static function clear_logs() {
		global $wpdb;
		$table_name = self::get_log_table_name();
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );
	}

	/**
	 * Drop tables upon plugin uninstall.
	 */
	public static function drop_table() {
		global $wpdb;
		$table_name = self::get_table_name();
		$log_table = self::get_log_table_name();

		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$log_table}" );
	}
}
