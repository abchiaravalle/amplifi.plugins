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

		// Nav menu meta box for adding Language Switcher to menus.
		add_action( 'admin_head-nav-menus.php', array( $this, 'add_nav_menu_meta_box' ) );
		add_filter( 'wp_setup_nav_menu_item', array( $this, 'label_nav_menu_item' ) );
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

		// Invalidate sitemap cache.
		delete_transient( 'acwpt_sitemap_xml' );

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
		$cache_stats   = ACWPT_Cache::stats();
		$current_usage = ACWPT_Translator::get_current_month_usage();
		$total_usage   = ACWPT_Translator::get_total_usage();
		$usage_history = ACWPT_Translator::get_usage();
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

				<!-- Language Switcher -->
				<div class="acwpt-card">
					<h2>Language Switcher</h2>

					<h3 style="margin-top:0;">Nav Menu Item</h3>
					<p>
						Go to <strong>Appearance &gt; Menus</strong>, find the <strong>AC Language Switcher</strong> panel on the left, and click <em>Add to Menu</em>.
						The top-level item shows the current language; sub-items list all available languages.
					</p>
					<p class="description">Works with any classic WordPress menu. In block themes, use the <em>Classic Menu</em> block in the Site Editor.</p>

					<h3>Shortcode (Dropdown)</h3>
					<p>Add a <code>&lt;select&gt;</code> dropdown anywhere using:</p>
					<code>[acwpt_switcher]</code>
					<p class="description" style="margin-top: 8px;">
						You can also use it in theme templates:
						<code>&lt;?php echo do_shortcode('[acwpt_switcher]'); ?&gt;</code>
					</p>
				</div>

			<!-- SEO Sitemap -->
				<div class="acwpt-card">
					<h2>Multilingual Sitemap</h2>
					<p>A sitemap with <code>hreflang</code> annotations is automatically generated at:</p>
					<p><a href="<?php echo esc_url( home_url( '/acwpt-sitemap.xml' ) ); ?>" target="_blank"><code><?php echo esc_html( home_url( '/acwpt-sitemap.xml' ) ); ?></code></a></p>
					<p class="description">
						This sitemap is referenced in <code>robots.txt</code> and tells search engines about every language version of every page.
						It follows <a href="https://developers.google.com/search/docs/specialty/international/localized-versions#sitemap" target="_blank">Google's hreflang sitemap spec</a>.
						The sitemap is cached for 1 hour and automatically regenerated when posts are updated or settings change.
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

			<!-- API Usage & Cost -->
			<div class="acwpt-card">
				<h2>API Usage & Cost</h2>

				<?php if ( $current_usage ) : ?>
					<div class="acwpt-usage-current">
						<h3>Current Month (<?php echo esc_html( gmdate( 'F Y' ) ); ?>)</h3>
						<table class="acwpt-usage-table">
							<tr>
								<td><strong>API Requests</strong></td>
								<td><?php echo esc_html( number_format( $current_usage['requests'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong>Page Translations</strong></td>
								<td><?php echo esc_html( number_format( $current_usage['content_translations'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong>String Batches</strong></td>
								<td><?php echo esc_html( number_format( $current_usage['string_translations'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong>Input Tokens</strong></td>
								<td><?php echo esc_html( number_format( $current_usage['prompt_tokens'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong>Output Tokens</strong></td>
								<td><?php echo esc_html( number_format( $current_usage['completion_tokens'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong>Total Tokens</strong></td>
								<td><?php echo esc_html( number_format( $current_usage['total_tokens'] ) ); ?></td>
							</tr>
							<tr class="acwpt-usage-cost-row">
								<td><strong>Estimated Cost</strong></td>
								<td><strong>$<?php echo esc_html( number_format( $current_usage['estimated_cost'], 4 ) ); ?></strong></td>
							</tr>
						</table>
					</div>
				<?php else : ?>
					<p>No API usage recorded this month yet.</p>
				<?php endif; ?>

				<?php if ( $total_usage['months'] > 0 ) : ?>
					<div class="acwpt-usage-totals">
						<h3>All-Time Totals</h3>
						<p>
							<strong><?php echo esc_html( number_format( $total_usage['requests'] ) ); ?></strong> API requests
							&middot; <strong><?php echo esc_html( number_format( $total_usage['total_tokens'] ) ); ?></strong> tokens
							&middot; <strong>$<?php echo esc_html( number_format( $total_usage['estimated_cost'], 4 ) ); ?></strong> estimated cost
							&middot; across <strong><?php echo esc_html( $total_usage['months'] ); ?></strong> month(s)
						</p>
					</div>

					<?php if ( count( $usage_history ) > 1 || ( count( $usage_history ) === 1 && ! $current_usage ) ) : ?>
						<div class="acwpt-usage-history">
							<h3>Monthly History</h3>
							<table class="widefat striped acwpt-history-table">
								<thead>
									<tr>
										<th>Month</th>
										<th>Requests</th>
										<th>Pages</th>
										<th>Strings</th>
										<th>Tokens</th>
										<th>Cost</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $usage_history as $month => $mdata ) : ?>
										<?php
										$date_obj   = DateTime::createFromFormat( 'Y-m', $month );
										$month_label = $date_obj ? $date_obj->format( 'M Y' ) : $month;
										$is_current  = ( $month === gmdate( 'Y-m' ) );
										?>
										<tr<?php echo $is_current ? ' class="acwpt-current-month"' : ''; ?>>
											<td><?php echo esc_html( $month_label ); ?><?php echo $is_current ? ' <em>(current)</em>' : ''; ?></td>
											<td><?php echo esc_html( number_format( $mdata['requests'] ) ); ?></td>
											<td><?php echo esc_html( number_format( $mdata['content_translations'] ) ); ?></td>
											<td><?php echo esc_html( number_format( $mdata['string_translations'] ) ); ?></td>
											<td><?php echo esc_html( number_format( $mdata['total_tokens'] ) ); ?></td>
											<td>$<?php echo esc_html( number_format( $mdata['estimated_cost'], 4 ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<p class="description" style="margin-top: 12px;">
					Cost estimates based on OpenAI published pricing: GPT-4o Mini ($0.15/$0.60 per 1M tokens in/out), GPT-4o ($2.50/$10.00 per 1M tokens in/out).
					Actual charges may vary slightly. Check your <a href="https://platform.openai.com/usage" target="_blank">OpenAI dashboard</a> for exact billing.
				</p>
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

	// =========================================================================
	// Nav Menu Meta Box
	// =========================================================================

	/**
	 * Register meta box on the Appearance > Menus screen.
	 */
	public function add_nav_menu_meta_box() {
		add_meta_box(
			'acwpt-language-switcher-box',
			'AC Language Switcher',
			array( $this, 'render_nav_menu_meta_box' ),
			'nav-menus',
			'side',
			'default'
		);
	}

	/**
	 * Render the Language Switcher meta box content.
	 */
	public function render_nav_menu_meta_box() {
		global $_nav_menu_placeholder;
		$_nav_menu_placeholder = ( isset( $_nav_menu_placeholder ) && $_nav_menu_placeholder < -1 )
			? $_nav_menu_placeholder - 1
			: -1;
		?>
		<div id="acwpt-lang-switcher-div" class="posttypediv">
			<div id="tabs-panel-acwpt-switcher" class="tabs-panel tabs-panel-active">
				<ul id="acwpt-switcher-checklist" class="categorychecklist form-no-clear">
					<li>
						<label class="menu-item-title">
							<input type="checkbox" class="menu-item-checkbox"
								name="menu-item[<?php echo (int) $_nav_menu_placeholder; ?>][menu-item-object-id]"
								value="-1" />
							Language Switcher
						</label>
						<input type="hidden" class="menu-item-type"
							name="menu-item[<?php echo (int) $_nav_menu_placeholder; ?>][menu-item-type]"
							value="custom" />
						<input type="hidden" class="menu-item-title"
							name="menu-item[<?php echo (int) $_nav_menu_placeholder; ?>][menu-item-title]"
							value="Language Switcher" />
						<input type="hidden" class="menu-item-url"
							name="menu-item[<?php echo (int) $_nav_menu_placeholder; ?>][menu-item-url]"
							value="#acwpt-language-switcher" />
					</li>
				</ul>
			</div>
			<p class="button-controls wp-clearfix">
				<span class="add-to-menu">
					<input type="submit" class="button submit-add-to-menu right"
						value="<?php esc_attr_e( 'Add to Menu' ); ?>"
						name="add-post-type-menu-item"
						id="submit-acwpt-lang-switcher-div" />
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Customise the type label for the Language Switcher menu item in admin.
	 */
	public function label_nav_menu_item( $item ) {
		if ( is_object( $item ) && isset( $item->url ) && $item->url === '#acwpt-language-switcher' ) {
			$item->type_label = 'AC Language Switcher';
		}
		return $item;
	}
}
