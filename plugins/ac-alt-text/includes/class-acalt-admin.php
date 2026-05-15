<?php
/**
 * Admin pages + AJAX handlers for amplifi.alt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACALT_Admin {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_submenus' ), 20 );
		add_action( 'admin_post_acalt_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_acalt_retry_job', array( $this, 'handle_retry_job' ) );
		add_action( 'wp_ajax_acalt_start_bulk', array( $this, 'ajax_start_bulk' ) );
		add_action( 'wp_ajax_acalt_run_drain_now', array( $this, 'ajax_run_drain_now' ) );
		add_action( 'wp_ajax_acalt_send_test_report', array( $this, 'ajax_send_test_report' ) );
	}

	public function register_submenus() {
		add_submenu_page(
			'amplifi-studio',
			'Alt: Jobs',
			'Alt: Jobs',
			'manage_options',
			'amplifi-ac-alt-text-jobs',
			array( $this, 'render_jobs' )
		);
		add_submenu_page(
			'amplifi-studio',
			'Alt: Settings',
			'Alt: Settings',
			'manage_options',
			'amplifi-ac-alt-text-settings',
			array( $this, 'render_settings' )
		);
	}

	private function settings() {
		$s = get_option( 'acalt_settings', array() );
		$defaults = array(
			'api_key'             => '',
			'model'               => 'gpt-4o-mini',
			'auto_on_upload'      => false,
			'daily_spend_cap_usd' => 5.0,
			'report_email'        => '',
			'report_enabled'      => true,
			'prompt_style'        => 'concise',
			'language'            => get_locale(),
		);
		return array_merge( $defaults, is_array( $s ) ? $s : array() );
	}

	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$counts   = ACALT_Queue::counts();
		$settings = $this->settings();
		$today    = ACALT_Generator::today_key();
		$stats    = get_option( 'acalt_daily_stats', array() );
		$today_st = $stats[ $today ] ?? array( 'generated' => 0, 'failed' => 0, 'skipped' => 0, 'cost_usd' => 0 );
		$cap      = (float) $settings['daily_spend_cap_usd'];
		$spent    = (float) $today_st['cost_usd'];
		$cap_pct  = $cap > 0 ? min( 100, ( $spent / $cap ) * 100 ) : 0;
		$recent   = ACALT_Queue::recent( 20 );
		$nonce    = wp_create_nonce( 'acalt_bulk' );
		?>
		<div class="wrap">
			<h1>amplifi.alt</h1>
			<p>AI-powered alt text for your WordPress media library.</p>

			<?php if ( empty( $settings['api_key'] ) ) : ?>
				<div class="notice notice-warning"><p>
					<strong>API key needed.</strong> Add your OpenAI API key in
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=amplifi-ac-alt-text-settings' ) ); ?>">Alt: Settings</a> to start generating.
				</p></div>
			<?php endif; ?>

			<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin:16px 0;">
				<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;">
					<div style="color:#666;font-size:12px;text-transform:uppercase;">Today</div>
					<div style="font-size:24px;font-weight:600;"><?php echo (int) $today_st['generated']; ?> generated</div>
					<div style="color:#666;"><?php echo (int) $today_st['failed']; ?> failed · <?php echo (int) $today_st['skipped']; ?> skipped</div>
				</div>
				<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;">
					<div style="color:#666;font-size:12px;text-transform:uppercase;">Today's Spend</div>
					<div style="font-size:24px;font-weight:600;">$<?php echo number_format( $spent, 4 ); ?></div>
					<div style="background:#eee;height:6px;border-radius:3px;margin-top:6px;overflow:hidden;">
						<div style="background:<?php echo $cap_pct >= 100 ? '#a00' : '#2271b1'; ?>;height:100%;width:<?php echo esc_attr( $cap_pct ); ?>%;"></div>
					</div>
					<div style="color:#666;font-size:12px;margin-top:4px;">Cap: $<?php echo number_format( $cap, 2 ); ?>/day</div>
				</div>
				<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;">
					<div style="color:#666;font-size:12px;text-transform:uppercase;">Queue</div>
					<div style="font-size:24px;font-weight:600;"><?php echo (int) $counts['pending']; ?> pending</div>
					<div style="color:#666;"><?php echo (int) $counts['processing']; ?> processing · <?php echo (int) $counts['done']; ?> done · <?php echo (int) $counts['failed']; ?> failed</div>
				</div>
			</div>

			<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;margin-bottom:16px;">
				<h2 style="margin-top:0;">Bulk: existing images</h2>
				<p>Scans your media library for images with no alt text and queues them for generation. Already-set alt text is preserved.</p>
				<button id="acalt-start-bulk" class="button button-primary" data-nonce="<?php echo esc_attr( $nonce ); ?>">Generate for all existing images</button>
				<button id="acalt-run-now" class="button" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="margin-left:8px;">Process queue now</button>
				<span id="acalt-bulk-status" style="margin-left:12px;color:#666;"></span>
			</div>

			<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;margin-bottom:16px;">
				<h2 style="margin-top:0;">Recent activity</h2>
				<table class="widefat striped">
					<thead><tr>
						<th>Attachment</th><th>Status</th><th>Source</th><th>Alt</th><th>Cost</th><th>Updated</th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $recent ) ) : ?>
						<tr><td colspan="6"><em>No jobs yet.</em></td></tr>
					<?php else : ?>
						<?php foreach ( $recent as $r ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $r->attachment_id . '&action=edit' ) ); ?>">#<?php echo (int) $r->attachment_id; ?></a></td>
								<td><span style="text-transform:uppercase;font-size:11px;font-weight:600;color:<?php echo $this->status_color( $r->status ); ?>;"><?php echo esc_html( $r->status ); ?></span></td>
								<td><?php echo esc_html( $r->source ); ?></td>
								<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( (string) $r->alt_generated ); ?></td>
								<td>$<?php echo number_format( (float) $r->cost_usd, 6 ); ?></td>
								<td><?php echo esc_html( $r->updated_at ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>

			<script>
			(function() {
				var bulkBtn = document.getElementById('acalt-start-bulk');
				var runBtn  = document.getElementById('acalt-run-now');
				var status  = document.getElementById('acalt-bulk-status');
				var nonce   = bulkBtn.dataset.nonce;

				function postAction(action, btn, busyText, done) {
					btn.disabled = true;
					status.textContent = busyText;
					var fd = new FormData();
					fd.append('action', action);
					fd.append('_wpnonce', nonce);
					fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function(r) { return r.json(); })
						.then(function(j) {
							btn.disabled = false;
							if (j.success) {
								status.textContent = done(j.data);
							} else {
								status.textContent = 'Error: ' + (j.data || 'unknown');
							}
						})
						.catch(function(e) {
							btn.disabled = false;
							status.textContent = 'Request failed: ' + e.message;
						});
				}

				bulkBtn.addEventListener('click', function() {
					postAction('acalt_start_bulk', bulkBtn, 'Scanning media library...',
						function(d) { return 'Enqueued ' + d.enqueued + ' image(s). Workers will process them — refresh in a minute.'; });
				});
				runBtn.addEventListener('click', function() {
					postAction('acalt_run_drain_now', runBtn, 'Processing one batch...',
						function(d) { return 'Processed ' + d.processed + ' job(s). Refresh to see results.'; });
				});
			})();
			</script>
		</div>
		<?php
	}

	public function render_jobs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$page   = isset( $_GET['paged'] )  ? max( 1, (int) $_GET['paged'] ) : 1;
		$data   = ACALT_Queue::paged( $page, 25, $status ?: null );
		$counts = ACALT_Queue::counts();
		$total_pages = max( 1, (int) ceil( $data['total'] / 25 ) );
		?>
		<div class="wrap">
			<h1>Alt: Jobs</h1>
			<p>
				<?php foreach ( array( '' => 'All', 'pending' => 'Pending', 'processing' => 'Processing', 'done' => 'Done', 'failed' => 'Failed', 'skipped' => 'Skipped' ) as $k => $label ) :
					$url = admin_url( 'admin.php?page=amplifi-ac-alt-text-jobs' . ( $k ? '&status=' . $k : '' ) );
					$is_active = ( $status === $k );
					$count = $k === '' ? array_sum( $counts ) : ( $counts[ $k ] ?? 0 );
				?>
					<a href="<?php echo esc_url( $url ); ?>" style="margin-right:12px;<?php echo $is_active ? 'font-weight:bold;' : ''; ?>"><?php echo esc_html( $label ); ?> (<?php echo (int) $count; ?>)</a>
				<?php endforeach; ?>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th>ID</th><th>Attachment</th><th>Status</th><th>Source</th><th>Attempts</th><th>Alt</th><th>Last error</th><th>Cost</th><th>Updated</th><th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $data['rows'] ) ) : ?>
					<tr><td colspan="10"><em>No jobs.</em></td></tr>
				<?php else : ?>
					<?php foreach ( $data['rows'] as $r ) :
						$retry_url = wp_nonce_url( admin_url( 'admin-post.php?action=acalt_retry_job&job_id=' . (int) $r->id ), 'acalt_retry_' . $r->id );
					?>
						<tr>
							<td><?php echo (int) $r->id; ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $r->attachment_id . '&action=edit' ) ); ?>">#<?php echo (int) $r->attachment_id; ?></a></td>
							<td><span style="color:<?php echo $this->status_color( $r->status ); ?>;font-weight:600;text-transform:uppercase;font-size:11px;"><?php echo esc_html( $r->status ); ?></span></td>
							<td><?php echo esc_html( $r->source ); ?></td>
							<td><?php echo (int) $r->attempts; ?></td>
							<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( (string) $r->alt_generated ); ?></td>
							<td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#a00;"><?php echo esc_html( (string) $r->last_error ); ?></td>
							<td>$<?php echo number_format( (float) $r->cost_usd, 6 ); ?></td>
							<td><?php echo esc_html( $r->updated_at ); ?></td>
							<td><a href="<?php echo esc_url( $retry_url ); ?>" class="button button-small">Retry</a></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $total_pages > 1 ) :
				$base = admin_url( 'admin.php?page=amplifi-ac-alt-text-jobs' . ( $status ? '&status=' . $status : '' ) );
			?>
				<p style="margin-top:12px;">
					<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
						<a href="<?php echo esc_url( $base . '&paged=' . $p ); ?>" style="margin-right:6px;<?php echo $p === $page ? 'font-weight:bold;' : ''; ?>"><?php echo (int) $p; ?></a>
					<?php endfor; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = $this->settings();
		$nonce = wp_create_nonce( 'acalt_save_settings' );
		?>
		<div class="wrap">
			<h1>Alt: Settings</h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['retried'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Job reset for retry.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="acalt_save_settings">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
				<table class="form-table">
					<tr>
						<th><label for="acalt_api_key">OpenAI API key</label></th>
						<td>
							<input type="password" id="acalt_api_key" name="api_key" value="<?php echo esc_attr( $s['api_key'] ); ?>" class="regular-text" autocomplete="off">
							<p class="description">Scope your key to Chat Completions only. Set a spending cap in the OpenAI dashboard.</p>
						</td>
					</tr>
					<tr>
						<th><label for="acalt_model">Model</label></th>
						<td>
							<select id="acalt_model" name="model">
								<option value="gpt-4o-mini" <?php selected( $s['model'], 'gpt-4o-mini' ); ?>>gpt-4o-mini (recommended)</option>
								<option value="gpt-4o" <?php selected( $s['model'], 'gpt-4o' ); ?>>gpt-4o (higher quality, ~10× cost)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>Auto-on-upload</th>
						<td>
							<label><input type="checkbox" name="auto_on_upload" value="1" <?php checked( ! empty( $s['auto_on_upload'] ) ); ?>>
								Automatically generate alt text for every new image upload (queued, runs within ~1 minute).</label>
						</td>
					</tr>
					<tr>
						<th><label for="acalt_cap">Daily spend cap (USD)</label></th>
						<td>
							<input type="number" step="0.01" min="0" id="acalt_cap" name="daily_spend_cap_usd" value="<?php echo esc_attr( (float) $s['daily_spend_cap_usd'] ); ?>" class="small-text">
							<p class="description">When today's spend hits the cap, generation pauses until the next UTC day. Set 0 to disable the cap.</p>
						</td>
					</tr>
					<tr>
						<th><label for="acalt_report_email">Daily report email</label></th>
						<td>
							<input type="email" id="acalt_report_email" name="report_email" value="<?php echo esc_attr( $s['report_email'] ); ?>" class="regular-text">
							<label style="margin-left:12px;"><input type="checkbox" name="report_enabled" value="1" <?php checked( ! empty( $s['report_enabled'] ) ); ?>> Enabled</label>
							<p class="description">Sent daily at 09:00 UTC via wp_mail(). Includes yesterday's totals, 7-day trend, top failures, queue status.</p>
							<button type="button" class="button" id="acalt-test-report" data-nonce="<?php echo esc_attr( wp_create_nonce( 'acalt_test_report' ) ); ?>">Send test report now</button>
							<span id="acalt-test-report-status" style="margin-left:8px;color:#666;"></span>
						</td>
					</tr>
					<tr>
						<th>Prompt style</th>
						<td>
							<label><input type="radio" name="prompt_style" value="concise" <?php checked( $s['prompt_style'], 'concise' ); ?>> Concise (under 80 chars)</label><br>
							<label><input type="radio" name="prompt_style" value="descriptive" <?php checked( $s['prompt_style'], 'descriptive' ); ?>> Descriptive (around 100 chars)</label>
						</td>
					</tr>
					<tr>
						<th><label for="acalt_language">Language</label></th>
						<td>
							<input type="text" id="acalt_language" name="language" value="<?php echo esc_attr( $s['language'] ); ?>" class="regular-text">
							<p class="description">Locale code (e.g. <code>en_US</code>, <code>es_ES</code>). Alt text will be generated in this language.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>

			<script>
			document.getElementById('acalt-test-report').addEventListener('click', function() {
				var btn = this;
				var status = document.getElementById('acalt-test-report-status');
				btn.disabled = true;
				status.textContent = 'Sending...';
				var fd = new FormData();
				fd.append('action', 'acalt_send_test_report');
				fd.append('_wpnonce', btn.dataset.nonce);
				fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function(r) { return r.json(); })
					.then(function(j) {
						btn.disabled = false;
						status.textContent = j.success ? 'Sent.' : ('Error: ' + (j.data || 'unknown'));
					})
					.catch(function(e) {
						btn.disabled = false;
						status.textContent = 'Failed: ' + e.message;
					});
			});
			</script>
		</div>
		<?php
	}

	private function status_color( $status ) {
		switch ( $status ) {
			case 'done':       return '#1e7e34';
			case 'failed':     return '#a00';
			case 'pending':    return '#666';
			case 'processing': return '#2271b1';
			case 'skipped':    return '#888';
		}
		return '#666';
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'acalt_save_settings' );

		$current = $this->settings();
		$current['api_key']             = isset( $_POST['api_key'] )       ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$current['model']               = isset( $_POST['model'] )         ? sanitize_text_field( wp_unslash( $_POST['model'] ) )   : 'gpt-4o-mini';
		$current['auto_on_upload']      = ! empty( $_POST['auto_on_upload'] );
		$current['daily_spend_cap_usd'] = isset( $_POST['daily_spend_cap_usd'] ) ? (float) $_POST['daily_spend_cap_usd'] : 5.0;
		$current['report_email']        = isset( $_POST['report_email'] )  ? sanitize_email( wp_unslash( $_POST['report_email'] ) ) : '';
		$current['report_enabled']      = ! empty( $_POST['report_enabled'] );
		$current['prompt_style']        = ( isset( $_POST['prompt_style'] ) && $_POST['prompt_style'] === 'descriptive' ) ? 'descriptive' : 'concise';
		$current['language']            = isset( $_POST['language'] )      ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : 'en_US';

		update_option( 'acalt_settings', $current );

		wp_safe_redirect( admin_url( 'admin.php?page=amplifi-ac-alt-text-settings&saved=1' ) );
		exit;
	}

	public function handle_retry_job() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		$job_id = isset( $_GET['job_id'] ) ? (int) $_GET['job_id'] : 0;
		check_admin_referer( 'acalt_retry_' . $job_id );

		if ( $job_id ) {
			ACALT_Queue::reset_for_retry( $job_id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=amplifi-ac-alt-text-jobs&retried=1' ) );
		exit;
	}

	public function ajax_start_bulk() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}
		check_ajax_referer( 'acalt_bulk' );

		$enqueued = ACALT_Queue::enqueue_missing_alt();
		wp_send_json_success( array( 'enqueued' => $enqueued ) );
	}

	public function ajax_run_drain_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}
		check_ajax_referer( 'acalt_bulk' );

		$before = ACALT_Queue::counts();
		ACALT_Cron::drain();
		$after  = ACALT_Queue::counts();

		$processed = ( $before['pending'] + $before['processing'] ) - ( $after['pending'] + $after['processing'] );
		wp_send_json_success( array( 'processed' => max( 0, $processed ) ) );
	}

	public function ajax_send_test_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}
		check_ajax_referer( 'acalt_test_report' );

		ACALT_Report::send();
		wp_send_json_success();
	}
}
