<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_BLC_Scanner {

	private $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

	/**
	 * Retrieve plugin settings with safe defaults.
	 */
	public function get_settings() {
		$settings = get_option( 'lean_blc_settings', array() );

		return array(
			'requests_per_minute' => ! empty( $settings['requests_per_minute'] ) ? min( 60, max( 5, intval( $settings['requests_per_minute'] ) ) ) : 10,
			'timeout'             => ! empty( $settings['timeout'] ) ? min( 30, max( 2, intval( $settings['timeout'] ) ) ) : 15,
			'cron_interval_hours' => ! empty( $settings['cron_interval_hours'] ) ? min( 168, max( 1, intval( $settings['cron_interval_hours'] ) ) ) : 24,
			'cron_batch_size'     => ! empty( $settings['cron_batch_size'] ) ? min( 500, max( 5, intval( $settings['cron_batch_size'] ) ) ) : 30,
			'max_log_rows'        => ! empty( $settings['max_log_rows'] ) ? min( 1000, max( 20, intval( $settings['max_log_rows'] ) ) ) : 100,
			'check_images'        => isset( $settings['check_images'] ) ? ! empty( $settings['check_images'] ) : true,
			'auto_lookup_archive' => isset( $settings['auto_lookup_archive'] ) ? ! empty( $settings['auto_lookup_archive'] ) : true,
			'send_email_report'   => isset( $settings['send_email_report'] ) ? ! empty( $settings['send_email_report'] ) : true,
			'excluded_domains'    => ! empty( $settings['excluded_domains'] ) ? (string) $settings['excluded_domains'] : '',
			'post_types'          => ! empty( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : array( 'post', 'page' ),
		);
	}

	/**
	 * Parse excluded domains into an array of clean strings.
	 */
	public function get_excluded_domains_list( $raw_text ) {
		if ( empty( $raw_text ) ) {
			return array();
		}
		$lines = preg_split( '/[\r\n,]+/', $raw_text );
		$domains = array();
		foreach ( $lines as $line ) {
			$clean = strtolower( trim( $line ) );
			if ( ! empty( $clean ) ) {
				$domains[] = $clean;
			}
		}
		return $domains;
	}

	/**
	 * Check if a URL matches any excluded entry.
	 *
	 * A bare domain (no "/") matches that host and its subdomains, so "x.com" excludes
	 * "www.x.com" but not "dropbox.com". An entry containing "/" matches as a URL prefix
	 * after the scheme, e.g. "example.com/members/".
	 */
	public function is_url_excluded( $url, $excluded_domains ) {
		if ( empty( $excluded_domains ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$rest = strtolower( preg_replace( '#^https?://#i', '', $url ) );
		foreach ( $excluded_domains as $entry ) {
			$entry = preg_replace( '#^https?://#i', '', $entry );
			if ( false !== strpos( $entry, '/' ) ) {
				if ( 0 === strpos( $rest, $entry ) ) {
					return true;
				}
				continue;
			}
			$entry = ltrim( $entry, '.' );
			if ( '' !== $entry && ( $host === $entry || substr( $host, -strlen( $entry ) - 1 ) === '.' . $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Turn a raw href/src attribute value into the URL a browser would request.
	 * Post content stores "&" as "&amp;" inside attributes (the block editor always does).
	 */
	public function normalize_attribute_url( $raw ) {
		return esc_url_raw( trim( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Take the scan lock so a cron batch, a manual batch and a full scan never run at once.
	 * Returns false if another run holds it. A lock older than $ttl seconds is treated as
	 * left behind by a run that died and is taken over.
	 */
	private function acquire_lock( $ttl ) {
		if ( add_option( 'lean_blc_scan_lock', time(), '', false ) ) {
			return true;
		}
		$locked_at = (int) get_option( 'lean_blc_scan_lock' );
		if ( $locked_at && ( time() - $locked_at ) < $ttl ) {
			return false;
		}
		update_option( 'lean_blc_scan_lock', time(), false );
		return true;
	}

	private function release_lock() {
		delete_option( 'lean_blc_scan_lock' );
	}

	/**
	 * Raise the PHP time limit for a run, where the host allows it.
	 */
	private function extend_time_limit( $seconds ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( (int) $seconds );
		}
	}

	/**
	 * Extract all links and images across published content.
	 */
	public function extract_all_links() {
		$settings = $this->get_settings();
		$check_images = $settings['check_images'];
		$excluded = $this->get_excluded_domains_list( $settings['excluded_domains'] );

		$posts = get_posts(
			array(
				'post_type'      => $settings['post_types'],
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$extracted_links = array();
		$urls_to_posts = array();

		foreach ( $posts as $post_id ) {
			$content = get_post_field( 'post_content', $post_id );
			if ( empty( $content ) ) {
				continue;
			}

			// Extract <a> href
			if ( preg_match_all( '/<a\s[^>]*href=([\'"])(http[s]?:\/\/[^\'"\s]+)\1/i', $content, $matches ) ) {
				foreach ( $matches[2] as $url ) {
					$clean_url = $this->normalize_attribute_url( $url );
					if ( $this->is_safe_url( $clean_url ) && ! $this->is_url_excluded( $clean_url, $excluded ) ) {
						$extracted_links[] = array(
							'post_id' => $post_id,
							'url'     => $clean_url,
						);
						$urls_to_posts[ $clean_url ][] = $post_id;
					}
				}
			}

			// Extract <img> src if enabled
			if ( $check_images && preg_match_all( '/<img\s[^>]*src=([\'"])(http[s]?:\/\/[^\'"\s]+)\1/i', $content, $matches ) ) {
				foreach ( $matches[2] as $url ) {
					$clean_url = $this->normalize_attribute_url( $url );
					if ( $this->is_safe_url( $clean_url ) && ! $this->is_url_excluded( $clean_url, $excluded ) ) {
						$extracted_links[] = array(
							'post_id' => $post_id,
							'url'     => $clean_url,
						);
						$urls_to_posts[ $clean_url ][] = $post_id;
					}
				}
			}
		}

		foreach ( $urls_to_posts as $u => $ids ) {
			$urls_to_posts[ $u ] = array_unique( $ids );
		}

		return array(
			'links'         => $extracted_links,
			'urls_to_posts' => $urls_to_posts,
		);
	}

	/**
	 * Probe one URL, log the check, and record or clear its broken-link rows.
	 * Returns 'broken', 'redirect' or 'healthy'.
	 */
	private function check_url( $url, $post_ids, $settings ) {
		$status_info = $this->probe_url( $url, $settings['timeout'] );
		$result_type = $status_info['is_broken'] ? 'broken' : ( $status_info['is_redirect'] ? 'redirect' : 'healthy' );

		Lean_BLC_DB::log_entry(
			$url,
			$status_info['code'],
			$result_type,
			$status_info['duration_ms'],
			$status_info['message'],
			$settings['max_log_rows']
		);

		if ( $status_info['is_broken'] ) {
			$wayback_url = null;
			if ( $settings['auto_lookup_archive'] ) {
				$snapshot    = Lean_BLC_Wayback::lookup_snapshot( $url );
				$wayback_url = $snapshot ? $snapshot['url'] : null;
			}
			foreach ( $post_ids as $post_id ) {
				Lean_BLC_DB::save_broken_link( $post_id, $url, $status_info['code'], $status_info['message'], $wayback_url );
			}
		} else {
			Lean_BLC_DB::delete_by_url( $url );
		}

		return $result_type;
	}

	/**
	 * Incremental Cron Scan:
	 * Checks the oldest X links since last check (default 30 links per cron run).
	 * Returns the stats array, or a WP_Error if another run is in progress.
	 */
	public function run_cron_batch() {
		$settings           = $this->get_settings();
		$batch_size         = $settings['cron_batch_size'];
		$delay_microseconds = intval( ( 60 / $settings['requests_per_minute'] ) * 1000000 );
		$time_limit         = max( 600, $batch_size * ( $settings['timeout'] + 7 ) );

		if ( ! $this->acquire_lock( $time_limit ) ) {
			return new WP_Error( 'lean_blc_locked', 'Another link check is already running. Try again when it has finished.' );
		}
		$this->extend_time_limit( $time_limit );

		// 1. Extract current inventory of unique links
		$data          = $this->extract_all_links();
		$urls_to_posts = $data['urls_to_posts'];

		// 2. Load audit timestamp registry
		$timestamps = get_option( 'lean_blc_url_timestamps', array() );
		if ( ! is_array( $timestamps ) ) {
			$timestamps = array();
		}

		// 3. Sort URLs by oldest check timestamp (0 = never checked)
		$url_queue = array();
		foreach ( array_keys( $urls_to_posts ) as $url ) {
			$url_queue[ $url ] = isset( $timestamps[ $url ] ) ? intval( $timestamps[ $url ] ) : 0;
		}
		asort( $url_queue );

		// 4. Select the batch of oldest links
		$batch_urls = array_slice( array_keys( $url_queue ), 0, $batch_size );

		$counts = array(
			'broken'   => 0,
			'redirect' => 0,
			'healthy'  => 0,
		);

		// 5. Probe each URL with rate limiting and log the check
		foreach ( $batch_urls as $i => $url ) {
			if ( $i > 0 && $delay_microseconds > 0 ) {
				usleep( $delay_microseconds );
			}
			++$counts[ $this->check_url( $url, $urls_to_posts[ $url ], $settings ) ];
			$timestamps[ $url ] = time();
		}

		// 6. Prune timestamps of removed URLs (not autoloaded: it grows with the site)
		$timestamps = array_intersect_key( $timestamps, $urls_to_posts );
		update_option( 'lean_blc_url_timestamps', $timestamps, false );

		// 7. Update statistics
		$stats = array(
			'last_updated'   => current_time( 'mysql' ),
			'total_links'    => count( $data['links'] ),
			'unique_urls'    => count( $urls_to_posts ),
			'batch_checked'  => count( $batch_urls ),
			'healthy'        => $counts['healthy'],
			'redirected'     => $counts['redirect'],
			'broken'         => Lean_BLC_DB::get_count(),
			'cycle_progress' => count( array_filter( $timestamps ) ),
		);
		update_option( 'lean_blc_stats', $stats );

		$this->release_lock();

		// 8. Notification if new broken links found
		if ( $counts['broken'] > 0 && ! empty( $settings['send_email_report'] ) ) {
			$this->dispatch_wordpress_notifications( $stats, count( $batch_urls ), $counts['broken'] );
		}

		return $stats;
	}

	/**
	 * Run a full on-demand site scan (rate-limited).
	 * Broken links are saved as they are found, so a run cut short by a timeout keeps its results.
	 * Returns the stats array, or a WP_Error if another run is in progress.
	 */
	public function run_full_scan() {
		$settings           = $this->get_settings();
		$delay_microseconds = intval( ( 60 / $settings['requests_per_minute'] ) * 1000000 );

		$data          = $this->extract_all_links();
		$urls_to_posts = $data['urls_to_posts'];
		$unique_urls   = array_keys( $urls_to_posts );
		$time_limit    = max( 600, count( $unique_urls ) * ( $settings['timeout'] + 7 + 60 / $settings['requests_per_minute'] ) );

		if ( ! $this->acquire_lock( $time_limit ) ) {
			return new WP_Error( 'lean_blc_locked', 'Another link check is already running. Try again when it has finished.' );
		}
		$this->extend_time_limit( $time_limit );

		Lean_BLC_DB::clear_all();

		$counts = array(
			'broken'   => 0,
			'redirect' => 0,
			'healthy'  => 0,
		);
		$timestamps = array();

		foreach ( $unique_urls as $i => $url ) {
			if ( $i > 0 && $delay_microseconds > 0 ) {
				usleep( $delay_microseconds );
			}
			++$counts[ $this->check_url( $url, $urls_to_posts[ $url ], $settings ) ];
			$timestamps[ $url ] = time();
		}

		update_option( 'lean_blc_url_timestamps', $timestamps, false );

		$stats = array(
			'last_updated'   => current_time( 'mysql' ),
			'total_links'    => count( $data['links'] ),
			'unique_urls'    => count( $unique_urls ),
			'batch_checked'  => count( $unique_urls ),
			'healthy'        => $counts['healthy'],
			'redirected'     => $counts['redirect'],
			'broken'         => Lean_BLC_DB::get_count(),
			'cycle_progress' => count( $unique_urls ),
		);
		update_option( 'lean_blc_stats', $stats );

		$this->release_lock();

		if ( $counts['broken'] > 0 && ! empty( $settings['send_email_report'] ) ) {
			$this->dispatch_wordpress_notifications( $stats, count( $unique_urls ), $counts['broken'] );
		}

		return $stats;
	}

	/**
	 * Standard built-in WordPress notification (admin email).
	 */
	private function dispatch_wordpress_notifications( $stats, $batch_count = 0, $batch_broken = 0 ) {
		$to = get_option( 'admin_email' );
		$site_name = get_bloginfo( 'name' );
		$admin_url = admin_url( 'tools.php?page=lean-broken-links' );

		$subject = sprintf( '[%s] Link Health Alert: %d Dead Link(s) Detected', $site_name, intval( $stats['broken'] ) );
		$message = sprintf(
			"A link health check completed on %s.\n\nBatch Checked: %d links (%d failed in this run)\nTotal Active Broken Records: %d\n\nYou can review and repair these links with 1-click Wayback Machine snapshots here:\n%s\n",
			$site_name,
			intval( $batch_count ),
			intval( $batch_broken ),
			intval( $stats['broken'] ),
			esc_url( $admin_url )
		);

		wp_mail( $to, $subject, $message );
	}

	/**
	 * SSRF Protection: Validate URL does not target loopback or private networks.
	 */
	public function is_safe_url( $url ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( apply_filters( 'lean_blc_allow_local_ips', false ) ) {
			return true;
		}

		return (bool) wp_http_validate_url( $url );
	}

	/**
	 * Probe a single URL for HTTP health with realistic headers, byte fallback, and timing.
	 */
	public function probe_url( $url, $timeout = 15 ) {
		if ( ! $this->is_safe_url( $url ) ) {
			return array(
				'is_broken'   => true,
				'is_redirect' => false,
				'code'        => 0,
				'duration_ms' => 0,
				'message'     => 'Blocked: Target URL resolves to an internal address (SSRF Protection).',
			);
		}

		$args = array(
			'method'      => 'HEAD',
			'timeout'     => $timeout,
			'redirection' => 5,
			'user-agent'  => $this->user_agent,
			'sslverify'   => apply_filters( 'lean_blc_sslverify', true ),
		);

		// SSRF Protection: wp_safe_remote_request() also validates every redirect hop,
		// so an external link cannot redirect the scanner onto an internal address.
		$request = apply_filters( 'lean_blc_allow_local_ips', false ) ? 'wp_remote_request' : 'wp_safe_remote_request';

		$start_time = microtime( true );
		$response   = $request( $url, $args );

		// Fallback to GET with byte range if HEAD returned 405 or 403
		if ( ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code === 405 || $code === 403 ) {
				$args['method']  = 'GET';
				$args['headers'] = array( 'Range' => 'bytes=0-1024' );
				$response        = $request( $url, $args );
			}
		}

		$duration_ms = intval( round( ( microtime( true ) - $start_time ) * 1000 ) );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			// The URL itself passed is_safe_url(), so this error means a redirect hop failed validation.
			if ( 'wp_safe_remote_request' === $request && __( 'A valid URL was not provided.' ) === $message ) { // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own string.
				$message = 'Blocked: Redirects to an internal or disallowed address (SSRF Protection).';
			}
			return array(
				'is_broken'   => true,
				'is_redirect' => false,
				'code'        => 0,
				'duration_ms' => $duration_ms,
				'message'     => $message,
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$is_broken = ( $code >= 400 || $code === 0 );
		$is_redirect = ( $code >= 300 && $code < 400 );
		$msg = wp_remote_retrieve_response_message( $response );
		if ( empty( $msg ) ) {
			$msg = ( $code === 200 ) ? 'OK' : ( $is_redirect ? 'Redirect' : 'HTTP ' . $code );
		}

		return array(
			'is_broken'   => $is_broken,
			'is_redirect' => $is_redirect,
			'code'        => $code,
			'duration_ms' => $duration_ms,
			'message'     => $msg,
		);
	}
}
