<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_acwpt_test_api_key', array( $this, 'ajax_test_api_key' ) );
		add_action( 'wp_ajax_acwpt_flush_cache', array( $this, 'ajax_flush_cache' ) );
	}

	public function add_menu() {
		add_options_page(
			'AC WP Translator',
			'AC Translator',
			'manage_options',
			'acwpt-settings',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_acwpt-settings' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'acwpt-admin', ACWPT_PLUGIN_URL . 'assets/css/admin.css', array(), ACWPT_VERSION );
		wp_enqueue_script( 'acwpt-admin', ACWPT_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), ACWPT_VERSION, true );
		wp_localize_script( 'acwpt-admin', 'acwptAdmin', array(
			'nonce'   => wp_create_nonce( 'acwpt_admin' ),
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
		) );
	}

	public function register_settings() {
		register_setting( 'acwpt_settings_group', 'acwpt_settings', array(
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );
	}

	public function sanitize_settings( $input ) {
		$clean = array();

		$clean['api_key']         = sanitize_text_field( $input['api_key'] ?? '' );
		$clean['source_language'] = sanitize_text_field( $input['source_language'] ?? 'en' );
		$clean['model']           = sanitize_text_field( $input['model'] ?? 'gpt-4o-mini' );
		$clean['show_flags']      = ! empty( $input['show_flags'] );
		$clean['show_suggestion'] = ! empty( $input['show_suggestion'] );

		$enabled = array();
		if ( ! empty( $input['enabled_languages'] ) && is_array( $input['enabled_languages'] ) ) {
			$all_codes = array_keys( ACWPT_Languages::get_all() );
			foreach ( $input['enabled_languages'] as $code ) {
				$code = sanitize_text_field( $code );
				if ( in_array( $code, $all_codes, true ) ) {
					$enabled[] = $code;
				}
			}
		}
		$clean['enabled_languages'] = $enabled;

		// Flush rewrite rules when languages change.
		$old = get_option( 'acwpt_settings', array() );
		$old_langs = isset( $old['enabled_languages'] ) ? $old['enabled_languages'] : array();
		if ( $old_langs !== $enabled ) {
			// Schedule a flush on next page load.
			update_option( 'acwpt_flush_rules', true );
		}

		// Clear string translation caches when settings change.
		ACWPT_Frontend::instance()->clear_all_string_caches();

		return $clean;
	}

	public function render_page() {
		$settings    = get_option( 'acwpt_settings', array() );
		$all_langs   = ACWPT_Languages::get_all();
		$enabled     = isset( $settings['enabled_languages'] ) ? $settings['enabled_languages'] : array();
		$source      = isset( $settings['source_language'] ) ? $settings['source_language'] : 'en';
		$show_flags  = isset( $settings['show_flags'] ) ? (bool) $settings['show_flags'] : true;
		$show_sugg   = isset( $settings['show_suggestion'] ) ? (bool) $settings['show_suggestion'] : true;
		$api_key     = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		$model       = isset( $settings['model'] ) ? $settings['model'] : 'gpt-4o-mini';
		$cache_stats = ACWPT_Cache::stats();
		?>
		<div class="wrap acwpt-settings">
			<h1>AC WP Translator</h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'acwpt_settings_group' ); ?>

				<!-- API Settings -->
				<div class="acwpt-card">
					<h2>API Settings</h2>
					<table class="form-table">
						<tr>
							<th><label for="acwpt_api_key">OpenAI API Key</label></th>
							<td>
								<input type="password" id="acwpt_api_key" name="acwpt_settings[api_key]"
									value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" autocomplete="off" />
								<button type="button" id="acwpt-test-key" class="button button-secondary">Test Key</button>
								<span id="acwpt-key-status"></span>
							</td>
						</tr>
						<tr>
							<th><label for="acwpt_model">OpenAI Model</label></th>
							<td>
								<select id="acwpt_model" name="acwpt_settings[model]">
									<option value="gpt-4o-mini" <?php selected( $model, 'gpt-4o-mini' ); ?>>GPT-4o Mini (cheapest)</option>
									<option value="gpt-4o" <?php selected( $model, 'gpt-4o' ); ?>>GPT-4o (higher quality)</option>
								</select>
								<p class="description">GPT-4o Mini is recommended for cost-effective translation.</p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Language Settings -->
				<div class="acwpt-card">
					<h2>Language Settings</h2>
					<table class="form-table">
						<tr>
							<th><label for="acwpt_source">Source Language</label></th>
							<td>
								<select id="acwpt_source" name="acwpt_settings[source_language]">
									<?php foreach ( $all_langs as $code => $lang ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $source, $code ); ?>>
											<?php echo esc_html( $lang['flag'] . ' ' . $lang['name'] . ' (' . $lang['native'] . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">The language your content is written in. Pages without a language prefix will display in this language.</p>
							</td>
						</tr>
						<tr>
							<th>Target Languages</th>
							<td>
								<fieldset class="acwpt-lang-grid">
									<?php foreach ( $all_langs as $code => $lang ) : ?>
										<?php if ( $code === $source ) continue; ?>
										<label>
											<input type="checkbox" name="acwpt_settings[enabled_languages][]"
												value="<?php echo esc_attr( $code ); ?>"
												<?php checked( in_array( $code, $enabled, true ) ); ?> />
											<?php echo esc_html( $lang['flag'] . ' ' . $lang['name'] ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description">Select the languages you want your site translated to. Each will be accessible at <code>/lang-code/page-slug/</code>.</p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Display Settings -->
				<div class="acwpt-card">
					<h2>Display Settings</h2>
					<table class="form-table">
						<tr>
							<th>Flag Emojis</th>
							<td>
								<label>
									<input type="checkbox" name="acwpt_settings[show_flags]" value="1" <?php checked( $show_flags ); ?> />
									Show flag emojis in the language switcher
								</label>
							</td>
						</tr>
						<tr>
							<th>Language Suggestion</th>
							<td>
								<label>
									<input type="checkbox" name="acwpt_settings[show_suggestion]" value="1" <?php checked( $show_sugg ); ?> />
									Show a suggestion banner when a visitor's browser language differs from the page language
								</label>
							</td>
						</tr>
					</table>
				</div>

				<!-- Shortcode Info -->
				<div class="acwpt-card">
					<h2>Language Switcher Shortcode</h2>
					<p>Add the language switcher anywhere using:</p>
					<code>[acwpt_switcher]</code>
					<p class="description" style="margin-top: 8px;">
						You can also use it as a widget in theme widget areas, or add it directly in your theme template:
						<code>&lt;?php echo do_shortcode('[acwpt_switcher]'); ?&gt;</code>
					</p>
				</div>

				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<!-- Cache Management -->
			<div class="acwpt-card">
				<h2>Translation Cache</h2>
				<p>
					<strong>Total cached translations:</strong> <?php echo esc_html( $cache_stats['total'] ); ?>
				</p>
				<?php if ( ! empty( $cache_stats['by_language'] ) ) : ?>
					<ul>
						<?php foreach ( $cache_stats['by_language'] as $code => $row ) : ?>
							<?php $lang = ACWPT_Languages::get( $code ); ?>
							<li>
								<?php echo esc_html( $lang ? $lang['flag'] . ' ' . $lang['name'] : $code ); ?>:
								<?php echo esc_html( $row->count ); ?> translations
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p>
					<button type="button" id="acwpt-flush-cache" class="button button-secondary">
						Clear All Cached Translations
					</button>
					<span id="acwpt-flush-status"></span>
				</p>
				<p class="description">Translations are automatically re-cached when a page is updated or when the cache is cleared.</p>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: test the API key.
	 */
	public function ajax_test_api_key() {
		check_ajax_referer( 'acwpt_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$api_key = sanitize_text_field( $_POST['api_key'] ?? '' );
		if ( empty( $api_key ) ) {
			wp_send_json_error( 'No API key provided.' );
		}

		$result = ACWPT_Translator::test_api_key( $api_key );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( 'API key is valid!' );
	}

	/**
	 * AJAX: flush the translation cache.
	 */
	public function ajax_flush_cache() {
		check_ajax_referer( 'acwpt_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		ACWPT_Cache::flush_all();
		wp_send_json_success( 'Cache cleared.' );
	}
}
