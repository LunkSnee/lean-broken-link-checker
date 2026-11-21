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
	 * Lower-cased host of a URL ('' when it has none).
	 */
	private function get_host( $url ) {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * Reorder URLs round-robin across hostnames so no single host is probed
	 * back-to-back, while preserving the relative order within each host.
	 * Linear time: a host leaves the rotation once its URLs run out.
	 */
	public function interleave_urls_by_host( $urls ) {
		$buckets = array();
		foreach ( $urls as $url ) {
			$buckets[ $this->get_host( $url ) ][] = $url;
		}

		$interleaved = array();
		for ( $round = 0; ! empty( $buckets ); ++$round ) {
			$exhausted = array();
			foreach ( $buckets as $host => $host_urls ) {
				$interleaved[] = $host_urls[ $round ];
				if ( ! isset( $host_urls[ $round + 1 ] ) ) {
					$exhausted[] = $host;
				}
			}
			foreach ( $exhausted as $host ) {
				unset( $buckets[ $host ] );
			}
		}

		return $interleaved;
	}

	/**
	 * Parse an HTTP Retry-After header (delta-seconds or HTTP-date) into seconds, or null.
	 */
	public function parse_retry_after( $header ) {
		if ( is_array( $header ) ) {
			$header = reset( $header );
		}
		$header = trim( (string) $header );
		if ( '' === $header ) {
			return null;
		}
		if ( ctype_digit( $header ) ) {
			return intval( $header );
		}
		$timestamp = strtotime( $header );
		if ( false !== $timestamp ) {
			return max( 0, $timestamp - time() );
		}
		return null;
	}

	/**
	 * Seconds to leave a host alone after it answers 429: its Retry-After value,
	 * or one hour without one, kept between one minute and one day.
	 */
	private function get_backoff_seconds( $retry_after ) {
		if ( null === $retry_after ) {
			$retry_after = HOUR_IN_SECONDS;
		}
		return min( DAY_IN_SECONDS, max( MINUTE_IN_SECONDS, (int) $retry_after ) );
	}

	/**
	 * Hosts still backed off after a 429, as host => Unix time the backoff ends.
	 */
	private function load_host_backoff() {
		$backoff = get_option( 'lean_blc_host_backoff', array() );
		return is_array( $backoff ) ? $this->active_backoff( $backoff ) : array();
	}

	/**
	 * Store the backoffs that have not expired yet (not autoloaded), or drop the option when none remain.
	 */
	private function save_host_backoff( $backoff ) {
		$backoff = $this->active_backoff( $backoff );
		if ( empty( $backoff ) ) {
			delete_option( 'lean_blc_host_backoff' );
		} else {
			update_option( 'lean_blc_host_backoff', $backoff, false );
		}
	}

	private function active_backoff( $backoff ) {
		$now = time();
		return array_filter(
			$backoff,
			function ( $until ) use ( $now ) {
				return (int) $until > $now;
			}
		);
	}

	/**
	 * Probe one URL, log the check, and record or clear its broken-link rows.
	 * A rate-limited (429) URL keeps any broken-link rows it already has.
	 * Returns array( 'result' => 'healthy'|'redirect'|'rate_limited'|'broken', 'retry_after' => int|null ).
	 */
	private function check_url( $url, $post_ids, $settings ) {
		$status_info = $this->probe_url( $url, $settings['timeout'] );
		if ( ! empty( $status_info['is_rate_limited'] ) ) {
			$result_type = 'rate_limited';
		} elseif ( $status_info['is_broken'] ) {
			$result_type = 'broken';
		} elseif ( $status_info['is_redirect'] ) {
			$result_type = 'redirect';
		} else {
			$result_type = 'healthy';
		}

		Lean_BLC_DB::log_entry(
			$url,
			$status_info['code'],
			$result_type,
			$status_info['duration_ms'],
			$status_info['message'],
			$settings['max_log_rows']
		);

		if ( 'broken' === $result_type ) {
			$wayback_url = null;
			if ( $settings['auto_lookup_archive'] ) {
				$snapshot    = Lean_BLC_Wayback::lookup_snapshot( $url );
				$wayback_url = $snapshot ? $snapshot['url'] : null;
			}
			foreach ( $post_ids as $post_id ) {
				Lean_BLC_DB::save_broken_link( $post_id, $url, $status_info['code'], $status_info['message'], $wayback_url );
			}
		} elseif ( 'rate_limited' !== $result_type ) {
			Lean_BLC_DB::delete_by_url( $url );
		}

		return array(
			'result'      => $result_type,
			'retry_after' => isset( $status_info['retry_after'] ) ? $status_info['retry_after'] : null,
		);
	}

	/**
	 * Probe up to $limit URLs from $queue in order, spacing requests and interleaving hosts.
	 *
	 * URLs on a host in $backoff (host => Unix time) are deferred without using a slot, and
	 * a 429 adds its host to $backoff, so later URLs on that host are deferred too. Checked
	 * URLs get a fresh timestamp; rate-limited and deferred URLs keep theirs, so they stay
	 * first in line for when the host recovers.
	 * Returns counts per result plus 'deferred' and 'probed'.
	 */
	private function probe_urls( $queue, $limit, $urls_to_posts, $settings, &$timestamps, &$backoff ) {
		$delay_microseconds = intval( ( 60 / $settings['requests_per_minute'] ) * 1000000 );

		$counts = array(
			'broken'       => 0,
			'redirect'     => 0,
			'healthy'      => 0,
			'rate_limited' => 0,
			'deferred'     => 0,
			'probed'       => 0,
		);
		$total = count( $queue );
		$pos   = 0;

		while ( $counts['probed'] < $limit && $pos < $total ) {
			// Take the next URLs in queue order from hosts that are not backed off...
			$chunk = array();
			while ( count( $chunk ) < $limit - $counts['probed'] && $pos < $total ) {
				$url  = $queue[ $pos++ ];
				$host = $this->get_host( $url );
				if ( isset( $backoff[ $host ] ) && time() < $backoff[ $host ] ) {
					++$counts['deferred'];
					continue;
				}
				$chunk[] = $url;
			}

			// ...then spread them across hosts. A host that answers 429 here is skipped for
			// the rest of the chunk, and the next pass refills the freed slots.
			foreach ( $this->interleave_urls_by_host( $chunk ) as $url ) {
				$host = $this->get_host( $url );
				if ( isset( $backoff[ $host ] ) && time() < $backoff[ $host ] ) {
					++$counts['deferred'];
					continue;
				}
				if ( $counts['probed'] > 0 && $delay_microseconds > 0 ) {
					usleep( $delay_microseconds );
				}
				$check = $this->check_url( $url, $urls_to_posts[ $url ], $settings );
				++$counts['probed'];
				++$counts[ $check['result'] ];
				if ( 'rate_limited' === $check['result'] ) {
					$backoff[ $host ] = time() + $this->get_backoff_seconds( $check['retry_after'] );
				} else {
					$timestamps[ $url ] = time();
				}
			}
		}

		return $counts;
	}

	/**
	 * Incremental Cron Scan:
	 * Checks the oldest X links since last check (default 30 links per cron run).
	 * Returns the stats array, or a WP_Error if another run is in progress.
	 */
	public function run_cron_batch() {
		$settings   = $this->get_settings();
		$batch_size = $settings['cron_batch_size'];
		$time_limit = max( 600, $batch_size * ( $settings['timeout'] + 7 ) );

		if ( ! $this->acquire_lock( $time_limit ) ) {
			return new WP_Error( 'lean_blc_locked', 'Another link check is already running. Try again when it has finished.' );
		}
		$this->extend_time_limit( $time_limit );

		// 1. Extract current inventory of unique links, and drop records of links no longer in content
		$data          = $this->extract_all_links();
		$urls_to_posts = $data['urls_to_posts'];
		Lean_BLC_DB::prune_stale_links( $urls_to_posts );

		// 2. Load audit timestamp registry and host backoffs from earlier 429 responses
		$timestamps = get_option( 'lean_blc_url_timestamps', array() );
		if ( ! is_array( $timestamps ) ) {
			$timestamps = array();
		}
		$backoff = $this->load_host_backoff();

		// 3. Sort URLs by oldest check timestamp (0 = never checked)
		$url_queue = array();
		foreach ( array_keys( $urls_to_posts ) as $url ) {
			$url_queue[ $url ] = isset( $timestamps[ $url ] ) ? intval( $timestamps[ $url ] ) : 0;
		}
		asort( $url_queue );

		// 4. Probe the oldest batch, interleaved by host, with rate limiting and logging
		$counts = $this->probe_urls( array_keys( $url_queue ), $batch_size, $urls_to_posts, $settings, $timestamps, $backoff );
		$this->save_host_backoff( $backoff );

		// 5. Prune timestamps of removed URLs (not autoloaded: it grows with the site)
		$timestamps = array_intersect_key( $timestamps, $urls_to_posts );
		update_option( 'lean_blc_url_timestamps', $timestamps, false );

		// 6. Update statistics
		$stats = array(
			'last_updated'   => current_time( 'mysql' ),
			'total_links'    => count( $data['links'] ),
			'unique_urls'    => count( $urls_to_posts ),
			'batch_checked'  => $counts['probed'],
			'healthy'        => $counts['healthy'],
			'redirected'     => $counts['redirect'],
			'rate_limited'   => $counts['rate_limited'],
			'deferred'       => $counts['deferred'],
			'broken'         => Lean_BLC_DB::get_count(),
			'cycle_progress' => count( array_filter( $timestamps ) ),
		);
		update_option( 'lean_blc_stats', $stats );

		$this->release_lock();

		// 7. Notification if new broken links found
		if ( $counts['broken'] > 0 && ! empty( $settings['send_email_report'] ) ) {
			$this->dispatch_wordpress_notifications( $stats, $counts['probed'], $counts['broken'] );
		}

		return $stats;
	}

	/**
	 * Run a full on-demand site scan (rate-limited).
	 * Broken links are saved as they are found, so a run cut short by a timeout keeps its results.
	 * Records of rate-limited or deferred URLs are kept until they can be checked.
	 * Returns the stats array, or a WP_Error if another run is in progress.
	 */
	public function run_full_scan() {
		$settings = $this->get_settings();

		$data          = $this->extract_all_links();
		$urls_to_posts = $data['urls_to_posts'];
		$unique_urls   = array_keys( $urls_to_posts );
		$time_limit    = max( 600, count( $unique_urls ) * ( $settings['timeout'] + 7 + 60 / $settings['requests_per_minute'] ) );

		if ( ! $this->acquire_lock( $time_limit ) ) {
			return new WP_Error( 'lean_blc_locked', 'Another link check is already running. Try again when it has finished.' );
		}
		$this->extend_time_limit( $time_limit );

		Lean_BLC_DB::prune_stale_links( $urls_to_posts );

		$timestamps = array();
		$backoff    = $this->load_host_backoff();
		$counts     = $this->probe_urls( $unique_urls, count( $unique_urls ), $urls_to_posts, $settings, $timestamps, $backoff );
		$this->save_host_backoff( $backoff );

		update_option( 'lean_blc_url_timestamps', $timestamps, false );

		$stats = array(
			'last_updated'   => current_time( 'mysql' ),
			'total_links'    => count( $data['links'] ),
			'unique_urls'    => count( $unique_urls ),
			'batch_checked'  => $counts['probed'],
			'healthy'        => $counts['healthy'],
			'redirected'     => $counts['redirect'],
			'rate_limited'   => $counts['rate_limited'],
			'deferred'       => $counts['deferred'],
			'broken'         => Lean_BLC_DB::get_count(),
			'cycle_progress' => count( array_filter( $timestamps ) ),
		);
		update_option( 'lean_blc_stats', $stats );

		$this->release_lock();

		if ( $counts['broken'] > 0 && ! empty( $settings['send_email_report'] ) ) {
			$this->dispatch_wordpress_notifications( $stats, $counts['probed'], $counts['broken'] );
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

		$code            = wp_remote_retrieve_response_code( $response );
		$is_rate_limited = ( 429 === $code );
		$retry_after     = $is_rate_limited ? $this->parse_retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) ) : null;
		$is_broken       = ( ! $is_rate_limited && ( $code >= 400 || 0 === $code ) );
		$is_redirect     = ( $code >= 300 && $code < 400 );
		$msg             = wp_remote_retrieve_response_message( $response );
		if ( empty( $msg ) ) {
			$msg = ( 200 === $code ) ? 'OK' : ( $is_redirect ? 'Redirect' : ( $is_rate_limited ? 'Too Many Requests' : 'HTTP ' . $code ) );
		}

		return array(
			'is_broken'       => $is_broken,
			'is_redirect'     => $is_redirect,
			'is_rate_limited' => $is_rate_limited,
			'retry_after'     => $retry_after,
			'code'            => $code,
			'duration_ms'     => $duration_ms,
			'message'         => $msg,
		);
	}
}
