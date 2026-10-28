<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lean_BLC_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'display_admin_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_lean_blc_run_scan', array( $this, 'ajax_run_scan' ) );
		add_action( 'wp_ajax_lean_blc_run_batch', array( $this, 'ajax_run_batch' ) );
		add_action( 'wp_ajax_lean_blc_replace_link', array( $this, 'ajax_replace_link' ) );
		add_action( 'wp_ajax_lean_blc_dismiss_link', array( $this, 'ajax_dismiss_link' ) );
		add_action( 'wp_ajax_lean_blc_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
		add_action( 'wp_ajax_lean_blc_clear_log', array( $this, 'ajax_clear_log' ) );
	}

	public function register_menu() {
		add_management_page(
			'Broken Links',
			'Broken Links',
			'manage_options',
			'lean-broken-links',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'lean_blc_settings_group',
			'lean_blc_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Security: Strictly sanitize all plugin configuration settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();
		if ( ! is_array( $input ) ) {
			return $sanitized;
		}

		$old_settings = get_option( 'lean_blc_settings', array() );

		// Standard rate: default 10 requests per minute
		if ( isset( $input['requests_per_minute'] ) ) {
			$sanitized['requests_per_minute'] = min( 60, max( 5, intval( $input['requests_per_minute'] ) ) );
		} else {
			$sanitized['requests_per_minute'] = 10;
		}

		// Standard timeout: default 15 seconds
		if ( isset( $input['timeout'] ) ) {
			$sanitized['timeout'] = min( 30, max( 2, intval( $input['timeout'] ) ) );
		} else {
			$sanitized['timeout'] = 15;
		}

		// Cron frequency: every X hours (default 24)
		if ( isset( $input['cron_interval_hours'] ) ) {
			$sanitized['cron_interval_hours'] = min( 168, max( 1, intval( $input['cron_interval_hours'] ) ) );
		} else {
			$sanitized['cron_interval_hours'] = 24;
		}

		// Batch size per cron event: default 30 links
		if ( isset( $input['cron_batch_size'] ) ) {
			$sanitized['cron_batch_size'] = min( 500, max( 5, intval( $input['cron_batch_size'] ) ) );
		} else {
			$sanitized['cron_batch_size'] = 30;
		}

		// Max log rows to keep: default 100
		if ( isset( $input['max_log_rows'] ) ) {
			$sanitized['max_log_rows'] = min( 1000, max( 20, intval( $input['max_log_rows'] ) ) );
		} else {
			$sanitized['max_log_rows'] = 100;
		}

		$sanitized['send_email_report']   = ! empty( $input['send_email_report'] );
		$sanitized['auto_lookup_archive'] = ! empty( $input['auto_lookup_archive'] );
		$sanitized['check_images']        = ! empty( $input['check_images'] );

		// Excluded domains list
		if ( isset( $input['excluded_domains'] ) ) {
			$sanitized['excluded_domains'] = sanitize_textarea_field( $input['excluded_domains'] );
		} else {
			$sanitized['excluded_domains'] = '';
		}

		// Post types: only public, registered ones (the same list the form offers)
		$post_types = array();
		if ( ! empty( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$post_types = array_values( array_intersect( array_map( 'sanitize_key', $input['post_types'] ), get_post_types( array( 'public' => true ) ) ) );
		}
		$sanitized['post_types'] = $post_types ? $post_types : array( 'post', 'page' );

		// Reschedule cron if frequency changed
		$old_hours = ! empty( $old_settings['cron_interval_hours'] ) ? intval( $old_settings['cron_interval_hours'] ) : 24;
		if ( $sanitized['cron_interval_hours'] !== $old_hours ) {
			lean_blc_reschedule_cron( $sanitized['cron_interval_hours'] );
		}

		return $sanitized;
	}

	public function enqueue_assets( $hook ) {
		if ( $hook === 'tools_page_lean-broken-links' ) {
			wp_enqueue_style( 'lean-blc-admin', LEAN_BLC_URL . 'assets/admin.css', array(), LEAN_BLC_VERSION );
			wp_enqueue_script( 'lean-blc-admin', LEAN_BLC_URL . 'assets/admin.js', array( 'jquery' ), LEAN_BLC_VERSION, true );
			wp_localize_script(
				'lean-blc-admin',
				'leanBlcData',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'lean_blc_nonce' ),
				)
			);
		} else {
			$stats = get_option( 'lean_blc_stats', array() );
			if ( ! empty( $stats['broken'] ) && intval( $stats['broken'] ) > 0 ) {
				wp_enqueue_script( 'lean-blc-admin-notice', LEAN_BLC_URL . 'assets/admin.js', array( 'jquery' ), LEAN_BLC_VERSION, true );
				wp_localize_script(
					'lean-blc-admin-notice',
					'leanBlcData',
					array(
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'nonce'   => wp_create_nonce( 'lean_blc_nonce' ),
					)
				);
			}
		}
	}

	/**
	 * Native WordPress admin notice when broken links are detected.
	 */
	public function display_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && $screen->id === 'tools_page_lean-broken-links' ) {
			return;
		}

		$stats = get_option( 'lean_blc_stats', array() );
		$broken_count = ! empty( $stats['broken'] ) ? intval( $stats['broken'] ) : 0;
		if ( $broken_count <= 0 ) {
			return;
		}

		$last_updated = ! empty( $stats['last_updated'] ) ? $stats['last_updated'] : '';
		$dismissed_scan = get_user_meta( get_current_user_id(), 'lean_blc_dismissed_scan', true );

		if ( $dismissed_scan && $dismissed_scan === $last_updated ) {
			return;
		}

		$tools_url = admin_url( 'tools.php?page=lean-broken-links' );
		?>
		<div class="notice notice-warning is-dismissible lean-blc-admin-notice">
			<p>
				<strong>Lean Broken Link Checker:</strong> We detected <strong><?php echo esc_html( $broken_count ); ?></strong> broken link<?php echo $broken_count > 1 ? 's' : ''; ?> on your site.
				<a href="<?php echo esc_url( $tools_url ); ?>" class="button button-small button-secondary" style="margin-left:8px;">Review &amp; Fix</a>
			</p>
		</div>
		<?php
	}

	public function ajax_dismiss_notice() {
		check_ajax_referer( 'lean_blc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$stats = get_option( 'lean_blc_stats', array() );
		if ( ! empty( $stats['last_updated'] ) ) {
			update_user_meta( get_current_user_id(), 'lean_blc_dismissed_scan', sanitize_text_field( $stats['last_updated'] ) );
		}

		wp_send_json_success();
	}

	public function ajax_run_batch() {
		check_ajax_referer( 'lean_blc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		// A run can outlast proxy timeouts (e.g. 100 s at Cloudflare): finish it even if the browser gives up.
		ignore_user_abort( true );

		$scanner = new Lean_BLC_Scanner();
		$report  = $scanner->run_cron_batch();
		if ( is_wp_error( $report ) ) {
			wp_send_json_error( $report->get_error_message() );
		}

		wp_send_json_success( $report );
	}

	public function ajax_run_scan() {
		check_ajax_referer( 'lean_blc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		// A run can outlast proxy timeouts (e.g. 100 s at Cloudflare): finish it even if the browser gives up.
		ignore_user_abort( true );

		$scanner = new Lean_BLC_Scanner();
		$report  = $scanner->run_full_scan();
		if ( is_wp_error( $report ) ) {
			wp_send_json_error( $report->get_error_message() );
		}

		wp_send_json_success( $report );
	}

	public function ajax_replace_link() {
		check_ajax_referer( 'lean_blc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$old_url = isset( $_POST['old_url'] ) ? esc_url_raw( wp_unslash( $_POST['old_url'] ) ) : '';

		if ( ! $post_id || ! $old_url ) {
			wp_send_json_error( 'Invalid parameters.' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'Permission denied for this post.' );
		}

		// The replacement is always the snapshot the scanner recorded, never a URL from the request.
		$record = Lean_BLC_DB::get_link( $post_id, $old_url );
		if ( ! $record || empty( $record['wayback_url'] ) ) {
			wp_send_json_error( 'No archived snapshot is recorded for this link.' );
		}

		$result = Lean_BLC_Wayback::replace_link_in_post( $post_id, $old_url, $record['wayback_url'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( 'Link successfully replaced with archived snapshot.' );
	}

	public function ajax_dismiss_link() {
		check_ajax_referer( 'lean_blc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		Lean_BLC_DB::delete_link( $post_id, $url );

		$stats = get_option( 'lean_blc_stats', array() );
		if ( is_array( $stats ) && isset( $stats['broken'] ) ) {
			$stats['broken'] = Lean_BLC_DB::get_count();
			update_option( 'lean_blc_stats', $stats );
		}

		wp_send_json_success();
	}

	public function ajax_clear_log() {
		check_ajax_referer( 'lean_blc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		Lean_BLC_DB::clear_logs();
		wp_send_json_success();
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}

		$stats = get_option( 'lean_blc_stats', array() );
		$broken_items = Lean_BLC_DB::get_broken_links( 200, 0 );
		$settings = get_option( 'lean_blc_settings', array() );
		// Read-only view selectors, so no nonce is needed.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'report'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$log_filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$max_log_rows = ! empty( $settings['max_log_rows'] ) ? intval( $settings['max_log_rows'] ) : 100;
		$recent_logs = Lean_BLC_DB::get_logs( $max_log_rows, $log_filter );

		// Next scheduled cron time
		$next_cron_timestamp = wp_next_scheduled( 'lean_blc_scheduled_scan' );
		if ( $next_cron_timestamp ) {
			$time_diff = human_time_diff( time(), $next_cron_timestamp );
			$next_cron_human = sprintf( 'in %s (%s)', $time_diff, date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_cron_timestamp ) );
		} else {
			$next_cron_human = 'Not scheduled';
		}

		$available_post_types = get_post_types( array( 'public' => true ), 'objects' );
		$configured_post_types = ! empty( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : array( 'post', 'page' );
		?>
		<div class="wrap lean-blc-wrap">
			<h1>Lean Broken Link Checker</h1>
			<p class="description">High-performance link checker built directly into WordPress. Cycles through oldest links during cron, records recent check logs, and completely cleans up on uninstall.</p>

			<nav class="nav-tab-wrapper">
				<a href="?page=lean-broken-links&tab=report" class="nav-tab <?php echo $active_tab === 'report' ? 'nav-tab-active' : ''; ?>">Broken Links (<?php echo intval( isset( $stats['broken'] ) ? $stats['broken'] : 0 ); ?>)</a>
				<a href="?page=lean-broken-links&tab=log" class="nav-tab <?php echo $active_tab === 'log' ? 'nav-tab-active' : ''; ?>">Activity Log</a>
				<a href="?page=lean-broken-links&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
			</nav>

			<?php if ( $active_tab === 'settings' ) : ?>
				<form method="post" action="options.php" style="margin-top:20px;">
					<?php settings_fields( 'lean_blc_settings_group' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row">Cron Frequency</th>
							<td>
								Every <input type="number" min="1" max="168" name="lean_blc_settings[cron_interval_hours]" value="<?php echo esc_attr( isset( $settings['cron_interval_hours'] ) ? intval( $settings['cron_interval_hours'] ) : 24 ); ?>" class="small-text" /> hours
								<p class="description">How frequently WordPress background cron checks a batch of links (default: 24 hours).</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Links per Cron Batch</th>
							<td>
								<input type="number" min="5" max="500" name="lean_blc_settings[cron_batch_size]" value="<?php echo esc_attr( isset( $settings['cron_batch_size'] ) ? intval( $settings['cron_batch_size'] ) : 30 ); ?>" class="small-text" /> links per run
								<p class="description">Number of links to check each cron execution (default: 30). The scanner continuously cycles through all links on your site, checking the oldest verified links first so nothing is neglected.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Scan Rate Limit</th>
							<td>
								<input type="number" min="5" max="60" name="lean_blc_settings[requests_per_minute]" value="<?php echo esc_attr( isset( $settings['requests_per_minute'] ) ? intval( $settings['requests_per_minute'] ) : 10 ); ?>" class="small-text" /> requests / minute
								<p class="description">Standard rate ceiling (default: 10 RPM = 6.0 second pause between requests). Ensures scans never burden your server CPU or trigger remote rate-limit blocks.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Connection Timeout</th>
							<td>
								<input type="number" min="2" max="30" name="lean_blc_settings[timeout]" value="<?php echo esc_attr( isset( $settings['timeout'] ) ? intval( $settings['timeout'] ) : 15 ); ?>" class="small-text" /> seconds
								<p class="description">Maximum time to wait for a remote host before considering the link unreachable (default: 15 seconds).</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Max Log Entries</th>
							<td>
								<input type="number" min="20" max="1000" name="lean_blc_settings[max_log_rows]" value="<?php echo esc_attr( isset( $settings['max_log_rows'] ) ? intval( $settings['max_log_rows'] ) : 100 ); ?>" class="small-text" /> most recent rows
								<p class="description">Maximum number of link check events to retain in the database log (default: 100). Oldest entries automatically roll off.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Excluded Domains / URLs</th>
							<td>
								<textarea name="lean_blc_settings[excluded_domains]" rows="4" cols="50" class="large-text" placeholder="linkedin.com&#10;instagram.com&#10;facebook.com"><?php echo esc_textarea( isset( $settings['excluded_domains'] ) ? $settings['excluded_domains'] : '' ); ?></textarea>
								<p class="description">Domains or URL patterns to ignore (one per line). Useful for social networks or services that aggressively bot-block HTTP checks.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Post Types to Scan</th>
							<td>
								<fieldset>
									<?php foreach ( $available_post_types as $pt_name => $pt_obj ) : ?>
										<label style="margin-right:15px;">
											<input type="checkbox" name="lean_blc_settings[post_types][]" value="<?php echo esc_attr( $pt_name ); ?>" <?php checked( in_array( $pt_name, $configured_post_types, true ) ); ?> />
											<?php echo esc_html( $pt_obj->labels->name ); ?> (<code><?php echo esc_html( $pt_name ); ?></code>)
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description">Select which content types to scan for links and media.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Admin Email Notification</th>
							<td>
								<label>
									<input type="checkbox" name="lean_blc_settings[send_email_report]" value="1" <?php checked( ! empty( $settings['send_email_report'] ) ); ?> />
									Send standard WordPress notification email to site administrator (<code><?php echo esc_html( get_option( 'admin_email' ) ); ?></code>) when new broken links are detected.
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Wayback Machine Integration</th>
							<td>
								<label>
									<input type="checkbox" name="lean_blc_settings[auto_lookup_archive]" value="1" <?php checked( ! empty( $settings['auto_lookup_archive'] ) ); ?> />
									Automatically query the Internet Archive for snapshot URLs when a link fails.
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Check Embedded Images</th>
							<td>
								<label>
									<input type="checkbox" name="lean_blc_settings[check_images]" value="1" <?php checked( ! empty( $settings['check_images'] ) ); ?> />
									Scan <code>&lt;img&gt;</code> tags for broken image sources in addition to hyperlinks.
								</label>
							</td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</form>
			<?php elseif ( $active_tab === 'log' ) : ?>
				<div style="margin:20px 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
					<ul class="subsubsub" style="margin:0;">
						<li><a href="?page=lean-broken-links&tab=log" class="<?php echo empty( $log_filter ) ? 'current' : ''; ?>">All Events</a> |</li>
						<li><a href="?page=lean-broken-links&tab=log&filter=healthy" class="<?php echo $log_filter === 'healthy' ? 'current' : ''; ?>">Healthy (200)</a> |</li>
						<li><a href="?page=lean-broken-links&tab=log&filter=redirect" class="<?php echo $log_filter === 'redirect' ? 'current' : ''; ?>">Redirects (3xx)</a> |</li>
						<li><a href="?page=lean-broken-links&tab=log&filter=broken" class="<?php echo $log_filter === 'broken' ? 'current' : ''; ?>">Failures / Broken</a></li>
					</ul>
					<div>
						<button id="lean-blc-clear-log-btn" class="button button-secondary">Clear Activity Log</button>
					</div>
				</div>

				<table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
					<thead>
						<tr>
							<th style="width:16%;">Checked At</th>
							<th style="width:14%;">Status</th>
							<th style="width:10%;">Latency</th>
							<th style="width:40%;">URL</th>
							<th style="width:20%;">Detail</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $recent_logs ) ) : ?>
							<tr><td colspan="5" style="text-align:center;padding:20px;">No activity logged yet. Checks performed during cron batches or manual scans will appear here.</td></tr>
						<?php else : ?>
							<?php foreach ( $recent_logs as $entry ) : ?>
								<?php
								$result = $entry['result'];
								if ( $result === 'healthy' ) {
									$badge_bg = '#00a32a';
									$badge_text = '200 OK';
								} elseif ( $result === 'redirect' ) {
									$badge_bg = '#dba617';
									$badge_text = $entry['status_code'] ? $entry['status_code'] . ' Redirect' : 'Redirect';
								} else {
									$badge_bg = '#d63638';
									$badge_text = $entry['status_code'] ? $entry['status_code'] . ' Error' : 'Failed';
								}
								$time_diff = human_time_diff( strtotime( $entry['checked_at'] ), strtotime( current_time( 'mysql' ) ) );
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $entry['checked_at'] ); ?></strong><br>
										<small style="color:#646970;"><?php echo esc_html( $time_diff ); ?> ago</small>
									</td>
									<td>
										<span class="badge" style="background:<?php echo esc_attr( $badge_bg ); ?>;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:600;">
											<?php echo esc_html( $badge_text ); ?>
										</span>
									</td>
									<td>
										<code><?php echo intval( $entry['response_time_ms'] ); ?> ms</code>
									</td>
									<td>
										<a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener noreferrer" style="word-break:break-all;">
											<?php echo esc_html( $entry['url'] ); ?>
										</a>
									</td>
									<td>
										<span style="color:#3c434a;font-size:12px;"><?php echo esc_html( $entry['message'] ); ?></span>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="lean-blc-stats" style="display:flex;gap:15px;margin:20px 0;flex-wrap:wrap;">
					<div class="lean-stat-box" style="background:#fff;padding:15px;border:1px solid #ccd0d4;border-radius:4px;flex:1;min-width:160px;">
						<span style="font-size:24px;font-weight:bold;color:#2271b1;"><?php echo intval( isset( $stats['unique_urls'] ) ? $stats['unique_urls'] : 0 ); ?></span><br>
						<span>Unique URLs Monitored</span>
					</div>
					<div class="lean-stat-box" style="background:#fff;padding:15px;border:1px solid #ccd0d4;border-radius:4px;flex:1;min-width:160px;">
						<span style="font-size:24px;font-weight:bold;color:#d63638;"><?php echo intval( isset( $stats['broken'] ) ? $stats['broken'] : 0 ); ?></span><br>
						<span>Broken Links</span>
					</div>
					<div class="lean-stat-box" style="background:#fff;padding:15px;border:1px solid #ccd0d4;border-radius:4px;flex:1;min-width:160px;">
						<span style="font-size:24px;font-weight:bold;color:#00a32a;"><?php echo intval( isset( $stats['cycle_progress'] ) ? $stats['cycle_progress'] : 0 ); ?></span><br>
						<span>Checked in Current Cycle</span>
					</div>
					<div class="lean-stat-box" style="background:#fff;padding:15px;border:1px solid #ccd0d4;border-radius:4px;flex:1;min-width:200px;">
						<span style="font-size:14px;color:#646970;">Next Scheduled Run:</span><br>
						<strong><?php echo esc_html( $next_cron_human ); ?></strong>
					</div>
				</div>

				<div style="margin:20px 0;display:flex;gap:10px;align-items:center;">
					<button id="lean-blc-batch-btn" class="button button-primary">Check Next Batch Now (<?php echo esc_html( isset( $settings['cron_batch_size'] ) ? intval( $settings['cron_batch_size'] ) : 30 ); ?> links)</button>
					<button id="lean-blc-scan-btn" class="button button-secondary">Run Full Site Scan</button>
					<span id="lean-blc-spinner" class="spinner" style="float:none;"></span>
					<span id="lean-blc-status" style="font-weight:600;"></span>
				</div>

				<table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
					<thead>
						<tr>
							<th style="width:25%;">Post Title</th>
							<th style="width:30%;">Broken URL</th>
							<th style="width:12%;">HTTP Status</th>
							<th style="width:23%;">Wayback Snapshot</th>
							<th style="width:10%;">Action</th>
						</tr>
					</thead>
					<tbody id="lean-blc-table-body">
						<?php if ( empty( $broken_items ) ) : ?>
							<tr><td colspan="5" style="text-align:center;padding:20px;">No broken links recorded in database. Everything looks clean!</td></tr>
						<?php else : ?>
							<?php foreach ( $broken_items as $item ) : ?>
								<?php
								$post_title = get_the_title( $item['post_id'] );
								if ( empty( $post_title ) ) {
									$post_title = sprintf( 'Post #%d', intval( $item['post_id'] ) );
								}
								$edit_url = get_edit_post_link( $item['post_id'] );
								$view_url = get_permalink( $item['post_id'] );
								?>
								<tr data-post-id="<?php echo esc_attr( $item['post_id'] ); ?>" data-url="<?php echo esc_attr( $item['url'] ); ?>">
									<td>
										<strong><a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $post_title ); ?></a></strong><br>
										<small><a href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer">View Post</a></small>
									</td>
									<td>
										<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer" style="word-break:break-all;"><?php echo esc_html( $item['url'] ); ?></a>
									</td>
									<td>
										<span class="badge" style="background:#d63638;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">
											<?php echo esc_html( $item['status_code'] ? $item['status_code'] : 'Error' ); ?>
										</span>
										<br><small style="color:#646970;"><?php echo esc_html( $item['error_message'] ); ?></small>
									</td>
									<td>
										<?php if ( ! empty( $item['wayback_url'] ) ) : ?>
											<a href="<?php echo esc_url( $item['wayback_url'] ); ?>" target="_blank" rel="noopener noreferrer" style="color:#2271b1;">View Archive Snapshot &rarr;</a><br>
											<button class="button button-small lean-blc-replace-btn">Fix with Archive</button>
										<?php else : ?>
											<span style="color:#8c8f94;">No archive snapshot found</span>
										<?php endif; ?>
									</td>
									<td>
										<button class="button button-small lean-blc-dismiss-btn">Dismiss</button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

new Lean_BLC_Admin();
