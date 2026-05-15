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
		add_action( 'wp_ajax_acalt_preflight', array( $this, 'ajax_preflight' ) );
		add_action( 'wp_ajax_acalt_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_acalt_pause', array( $this, 'ajax_pause' ) );
		add_action( 'wp_ajax_acalt_resume', array( $this, 'ajax_resume' ) );
		add_action( 'wp_ajax_acalt_probe', array( $this, 'ajax_probe' ) );
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
		$health   = $this->health_checks();
		$paused_info = ACALT_Generator::paused_info();
		$reach    = ACALT_Reachability::info();
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

			<?php foreach ( $health as $issue ) : ?>
				<div class="notice notice-<?php echo esc_attr( $issue['level'] ); ?>" style="margin:8px 0;">
					<p><strong><?php echo esc_html( $issue['title'] ); ?>:</strong> <?php echo wp_kses_post( $issue['message'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<?php if ( ! empty( $paused_info['paused'] ) ) : ?>
				<div class="notice notice-error" style="margin:8px 0;">
					<p>
						<strong>Queue paused</strong> at <?php echo esc_html( gmdate( 'Y-m-d H:i', (int) ( $paused_info['paused_at'] ?? 0 ) ) ); ?> UTC.
						Reason: <code><?php echo esc_html( $paused_info['reason_code'] ?? 'unknown' ); ?></code> &mdash; <?php echo esc_html( $paused_info['message'] ?? '' ); ?>
						<button type="button" class="button button-primary acalt-resume-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="margin-left:8px;">Resume queue</button>
					</p>
				</div>
			<?php endif; ?>

			<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin:16px 0;">
				<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;">
					<div style="color:#666;font-size:12px;text-transform:uppercase;">Today</div>
					<div class="acalt-card-today-gen" style="font-size:24px;font-weight:600;"><?php echo (int) $today_st['generated']; ?> generated</div>
					<div class="acalt-card-today-sub" style="color:#666;"><?php echo (int) $today_st['failed']; ?> failed · <?php echo (int) $today_st['skipped']; ?> skipped</div>
				</div>
				<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;">
					<div style="color:#666;font-size:12px;text-transform:uppercase;">Today's Spend</div>
					<div class="acalt-card-today-spend" style="font-size:24px;font-weight:600;">$<?php echo number_format( $spent, 4 ); ?></div>
					<div style="background:#eee;height:6px;border-radius:3px;margin-top:6px;overflow:hidden;">
						<div style="background:<?php echo $cap_pct >= 100 ? '#a00' : '#2271b1'; ?>;height:100%;width:<?php echo esc_attr( $cap_pct ); ?>%;"></div>
					</div>
					<div style="color:#666;font-size:12px;margin-top:4px;">Cap: $<?php echo number_format( $cap, 2 ); ?>/day</div>
				</div>
				<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;">
					<div style="color:#666;font-size:12px;text-transform:uppercase;">Queue</div>
					<div class="acalt-card-pending" style="font-size:24px;font-weight:600;"><?php echo (int) $counts['pending']; ?> pending</div>
					<div class="acalt-card-processing" style="color:#666;"><?php echo (int) $counts['processing']; ?> processing · <?php echo (int) $counts['done']; ?> done · <?php echo (int) $counts['failed']; ?> failed</div>
				</div>
			</div>

			<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;margin-bottom:16px;">
				<h2 style="margin-top:0;">Bulk: existing images</h2>
				<p>Scans your media library for images with no alt text and queues them for generation. Already-set alt text is preserved.</p>
				<button id="acalt-preflight" class="button button-primary" data-nonce="<?php echo esc_attr( $nonce ); ?>">Generate for all existing images</button>
				<button id="acalt-run-now" class="button" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="margin-left:8px;">Process queue now</button>
				<button id="acalt-pause" class="button" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="margin-left:8px;">Pause queue</button>
				<span id="acalt-bulk-status" style="margin-left:12px;color:#666;"></span>
			</div>

			<div id="acalt-preflight-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:99999;align-items:center;justify-content:center;">
				<div style="background:#fff;border-radius:6px;max-width:560px;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.2);">
					<h2 style="margin-top:0;">Bulk generation estimate</h2>
					<div id="acalt-preflight-body">Calculating…</div>
					<div style="margin-top:16px;text-align:right;">
						<button type="button" class="button" id="acalt-preflight-cancel">Cancel</button>
						<button type="button" class="button button-primary" id="acalt-preflight-confirm" disabled style="margin-left:8px;">Start generation</button>
					</div>
				</div>
			</div>

			<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;margin-bottom:16px;">
				<h2 style="margin-top:0;">Reachability</h2>
				<p style="margin-bottom:8px;">
					<?php if ( ! empty( $reach['mode'] ) ) :
						$mode = $reach['mode']; ?>
						Mode: <strong><?php echo esc_html( $mode === 'url' ? 'public URL (cheap)' : ( $mode === 'base64' ? 'base64 inline (publicly unreachable)' : 'unknown' ) ); ?></strong>.
						<span style="color:#666;"><?php echo esc_html( $reach['reason'] ?? '' ); ?></span>
						<?php if ( ! empty( $reach['probed_at'] ) ) : ?>
							<span style="color:#999;"> &mdash; probed <?php echo esc_html( human_time_diff( (int) $reach['probed_at'] ) ); ?> ago</span>
						<?php endif; ?>
					<?php else : ?>
						<span style="color:#666;">Not yet probed.</span>
					<?php endif; ?>
				</p>
				<button type="button" id="acalt-probe-btn" class="button" data-nonce="<?php echo esc_attr( $nonce ); ?>">Re-test reachability</button>
				<span id="acalt-probe-status" style="margin-left:8px;color:#666;"></span>
				<p style="color:#666;font-size:12px;margin-top:8px;">
					If your site is behind Cloudflare Bot Fight or similar, OpenAI may not be able to fetch image URLs directly. The plugin then inlines images as base64 (larger payload, same correctness).
				</p>
			</div>

			<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;margin-bottom:16px;">
				<h2 style="margin-top:0;">Recent activity</h2>
				<table class="widefat striped acalt-recent">
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
				var preflightBtn = document.getElementById('acalt-preflight');
				var runBtn       = document.getElementById('acalt-run-now');
				var pauseBtn     = document.getElementById('acalt-pause');
				var probeBtn     = document.getElementById('acalt-probe-btn');
				var status       = document.getElementById('acalt-bulk-status');
				var nonce        = preflightBtn.dataset.nonce;
				var modal        = document.getElementById('acalt-preflight-modal');
				var modalBody    = document.getElementById('acalt-preflight-body');
				var modalCancel  = document.getElementById('acalt-preflight-cancel');
				var modalConfirm = document.getElementById('acalt-preflight-confirm');

				function post(action, fields, opts) {
					var fd = new FormData();
					fd.append('action', action);
					fd.append('_wpnonce', nonce);
					Object.keys(fields || {}).forEach(function(k){ fd.append(k, fields[k]); });
					return fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r){ return r.json(); });
				}

				function openModal() { modal.style.display = 'flex'; }
				function closeModal() { modal.style.display = 'none'; }

				preflightBtn.addEventListener('click', function() {
					openModal();
					modalBody.innerHTML = 'Calculating…';
					modalConfirm.disabled = true;
					post('acalt_preflight').then(function(j) {
						if (!j.success) {
							modalBody.innerHTML = '<p style="color:#a00;">Error: ' + (j.data && j.data.message || 'unknown') + '</p>';
							return;
						}
						var d = j.data;
						modalBody.innerHTML =
							'<p><strong>' + d.candidate_count.toLocaleString() + '</strong> image(s) need alt text.</p>' +
							'<p>Estimated cost: <strong>$' + d.estimate.cost_low.toFixed(2) + ' &ndash; $' + d.estimate.cost_high.toFixed(2) + '</strong> (model: ' + d.model + ').</p>' +
							(d.daily_cap > 0
								? '<p>Daily cap is <strong>$' + d.daily_cap.toFixed(2) + '</strong>. At this rate, the run will take <strong>' + d.estimate.days_to_finish + '</strong> day(s) if the cap is hit.</p>'
								: '<p><em>No daily cap set. Generation will run flat-out.</em></p>') +
							'<p style="color:#666;font-size:12px;">Rate-limit / pre-flight is an estimate. Real cost depends on image complexity. The queue can be paused at any time.</p>';
						modalConfirm.disabled = false;
					}).catch(function(e) {
						modalBody.innerHTML = '<p style="color:#a00;">Request failed: ' + e.message + '</p>';
					});
				});

				modalCancel.addEventListener('click', closeModal);
				modalConfirm.addEventListener('click', function() {
					modalConfirm.disabled = true;
					modalBody.innerHTML += '<p><em>Enqueuing…</em></p>';
					post('acalt_start_bulk').then(function(j) {
						closeModal();
						if (j.success) {
							status.textContent = 'Enqueued ' + j.data.enqueued + ' image(s). Workers are processing them — this page auto-refreshes.';
							startPolling();
						} else {
							status.textContent = 'Error: ' + (j.data || 'unknown');
						}
					});
				});

				runBtn.addEventListener('click', function() {
					runBtn.disabled = true;
					status.textContent = 'Processing one batch…';
					post('acalt_run_drain_now').then(function(j) {
						runBtn.disabled = false;
						if (j.success) {
							status.textContent = 'Processed ' + j.data.processed + ' job(s).';
							startPolling();
						} else {
							status.textContent = 'Error: ' + (j.data || 'unknown');
						}
					});
				});

				pauseBtn.addEventListener('click', function() {
					pauseBtn.disabled = true;
					post('acalt_pause').then(function(j) {
						pauseBtn.disabled = false;
						if (j.success) location.reload();
						else status.textContent = 'Pause failed: ' + (j.data || 'unknown');
					});
				});

				document.querySelectorAll('.acalt-resume-btn').forEach(function(b) {
					b.addEventListener('click', function() {
						b.disabled = true;
						post('acalt_resume').then(function(j) {
							if (j.success) location.reload();
							else { b.disabled = false; alert('Resume failed: ' + (j.data || 'unknown')); }
						});
					});
				});

				probeBtn.addEventListener('click', function() {
					probeBtn.disabled = true;
					var ps = document.getElementById('acalt-probe-status');
					ps.textContent = 'Probing…';
					post('acalt_probe').then(function(j) {
						probeBtn.disabled = false;
						if (j.success) { ps.textContent = 'Mode: ' + j.data.mode; setTimeout(function(){ location.reload(); }, 600); }
						else ps.textContent = 'Error: ' + (j.data || 'unknown');
					});
				});

				// ----- live polling -----
				var pollTimer = null;
				function startPolling() {
					if (pollTimer) return;
					pollTimer = setInterval(refreshStatus, 3000);
					refreshStatus();
				}
				function stopPolling() {
					if (!pollTimer) return;
					clearInterval(pollTimer);
					pollTimer = null;
				}
				function refreshStatus() {
					post('acalt_status').then(function(j) {
						if (!j.success) return;
						var d = j.data;
						// Update counter cards.
						var $ = function(s) { return document.querySelector(s); };
						var c = d.counts;
						var t = d.today;
						var pendingEl = document.querySelector('.acalt-card-pending');
						if (pendingEl) pendingEl.textContent = c.pending + ' pending';
						var processingEl = document.querySelector('.acalt-card-processing');
						if (processingEl) processingEl.textContent = c.processing + ' processing · ' + c.done + ' done · ' + c.failed + ' failed';
						var todayGenEl = document.querySelector('.acalt-card-today-gen');
						if (todayGenEl) todayGenEl.textContent = t.generated + ' generated';
						var todaySubEl = document.querySelector('.acalt-card-today-sub');
						if (todaySubEl) todaySubEl.textContent = t.failed + ' failed · ' + t.skipped + ' skipped';
						var todaySpendEl = document.querySelector('.acalt-card-today-spend');
						if (todaySpendEl) todaySpendEl.textContent = '$' + parseFloat(t.cost_usd).toFixed(4);

						// Replace recent activity table.
						if (d.recent_html) {
							var tbody = document.querySelector('.acalt-recent tbody');
							if (tbody) tbody.innerHTML = d.recent_html;
						}

						// Stop polling when queue is drained.
						if (c.pending === 0 && c.processing === 0) {
							stopPolling();
						}
					});
				}

				// Auto-start polling if there's work in flight.
				<?php if ( ( $counts['pending'] + $counts['processing'] ) > 0 ) : ?>
					startPolling();
				<?php endif; ?>
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

	// =================================================================
	// New endpoints (beta.8): preflight, status polling, pause/resume, probe
	// =================================================================

	public function ajax_preflight() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		check_ajax_referer( 'acalt_bulk' );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
			   AND (m.meta_value IS NULL OR m.meta_value = '')"
		);

		$settings = $this->settings();
		$model    = $settings['model'];
		$cap      = (float) $settings['daily_spend_cap_usd'];

		// Empirical rates from gpt-4o-mini test runs: ~8.7K tokens_in, ~22 tokens_out per image.
		// Tokens_in dominates because we may inline images as base64.
		$est_in  = 8700;
		$est_out = 22;
		$per_image_low  = ACALT_Generator::price( $model, (int) ( $est_in * 0.5 ),  $est_out ); // small/decorative
		$per_image_high = ACALT_Generator::price( $model, (int) ( $est_in * 1.2 ),  (int) ( $est_out * 1.5 ) );

		$cost_low  = $per_image_low  * $count;
		$cost_high = $per_image_high * $count;

		$days_to_finish = ( $cap > 0 && $cost_high > $cap ) ? (int) ceil( $cost_high / $cap ) : 1;

		wp_send_json_success( array(
			'candidate_count' => $count,
			'model'           => $model,
			'daily_cap'       => $cap,
			'estimate'        => array(
				'cost_low'       => $cost_low,
				'cost_high'      => $cost_high,
				'days_to_finish' => $days_to_finish,
			),
		) );
	}

	public function ajax_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}
		check_ajax_referer( 'acalt_bulk' );

		$counts = ACALT_Queue::counts();
		$today  = ACALT_Generator::today_key();
		$stats  = get_option( 'acalt_daily_stats', array() );
		$t      = $stats[ $today ] ?? array( 'generated' => 0, 'failed' => 0, 'skipped' => 0, 'cost_usd' => 0 );

		// Render recent activity rows.
		$recent = ACALT_Queue::recent( 20 );
		ob_start();
		if ( empty( $recent ) ) {
			echo '<tr><td colspan="6"><em>No jobs yet.</em></td></tr>';
		} else {
			foreach ( $recent as $r ) {
				printf(
					'<tr><td><a href="%s">#%d</a></td><td><span style="text-transform:uppercase;font-size:11px;font-weight:600;color:%s;">%s</span></td><td>%s</td><td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">%s</td><td>$%s</td><td>%s</td></tr>',
					esc_url( admin_url( 'post.php?post=' . (int) $r->attachment_id . '&action=edit' ) ),
					(int) $r->attachment_id,
					esc_attr( $this->status_color( $r->status ) ),
					esc_html( $r->status ),
					esc_html( $r->source ),
					esc_html( (string) $r->alt_generated ),
					esc_html( number_format( (float) $r->cost_usd, 6 ) ),
					esc_html( $r->updated_at )
				);
			}
		}
		$recent_html = ob_get_clean();

		wp_send_json_success( array(
			'counts'      => $counts,
			'today'       => $t,
			'recent_html' => $recent_html,
			'paused'      => ACALT_Generator::is_paused(),
		) );
	}

	public function ajax_pause() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}
		check_ajax_referer( 'acalt_bulk' );

		ACALT_Generator::pause_queue( 'manual', 'paused from admin dashboard' );
		wp_send_json_success();
	}

	public function ajax_resume() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}
		check_ajax_referer( 'acalt_bulk' );

		ACALT_Generator::resume_queue();
		wp_send_json_success();
	}

	public function ajax_probe() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}
		check_ajax_referer( 'acalt_bulk' );

		$state = ACALT_Reachability::probe();
		wp_send_json_success( $state );
	}

	/**
	 * Compute health-panel issues. Each issue is shown as a notice on the
	 * dashboard. Returning an empty array means "all green."
	 */
	private function health_checks() {
		$issues = array();

		// WP-Cron disabled?
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$issues[] = array(
				'level'   => 'warning',
				'title'   => 'WP-Cron disabled',
				'message' => 'This site has <code>DISABLE_WP_CRON</code> defined. The queue will not drain on its own. Set up a real cron job that hits <code>wp-cron.php</code> or use WP-CLI: <code>wp cron event run acalt_cron_drain</code>.',
			);
		}

		// Cron worker recently ran?
		$last = ACALT_Cron::last_drain_at();
		$counts = ACALT_Queue::counts();
		if ( ( $counts['pending'] + $counts['processing'] ) > 0 && $last > 0 && ( time() - $last ) > 300 ) {
			$issues[] = array(
				'level'   => 'warning',
				'title'   => 'Cron worker stale',
				'message' => sprintf(
					'Queue has work but the worker has not ticked in %s. Low-traffic sites can trigger this — visit the front-end or set up a real cron job that hits <code>wp-cron.php</code> regularly.',
					human_time_diff( $last )
				),
			);
		}

		// Reachability unknown?
		$reach_mode = ACALT_Reachability::current_mode();
		if ( $reach_mode === 'unknown' ) {
			$issues[] = array(
				'level'   => 'info',
				'title'   => 'Reachability not yet probed',
				'message' => 'Run the reachability test below before starting a bulk run so the plugin picks the cheapest send mode.',
			);
		}

		return $issues;
	}
}
