<?php
// Feature module of amplifi-plugins (amplifi.aos); bundled, not a standalone plugin.
// Subtle staggered reveal animations as content scrolls into view.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ACAOS_VERSION' ) ) {
	return;
}
define( 'ACAOS_VERSION', '3.4.0-dev.2' );
define( 'ACAOS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACAOS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACAOS_PLUGIN_FILE', __FILE__ );

// Load the amplifi.studio shared framework.
require_once ACAOS_PLUGIN_DIR . 'includes/amplifi-framework.php';

class Amplifi_AOS {

	const OPTION       = 'amplifi_aos_settings';
	const HANDLE       = 'amplifi-aos';
	const NONCE_ACTION = 'amplifi_aos_save';
	const PAGE_SLUG    = 'amplifi-ac-aos';

	/** Longest delay any element in a batch can get (ms). */
	const MAX_DELAY = 450;

	/** @var Amplifi_AOS|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		amplifi_register_plugin(
			'ac-aos',
			'AOS',
			'Subtle staggered reveal animations as content scrolls into view.',
			ACAOS_VERSION,
			ACAOS_PLUGIN_FILE,
			array( __CLASS__, 'render_admin_page' )
		);

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . self::NONCE_ACTION, array( __CLASS__, 'handle_save' ) );
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------- */

	/** Easing presets. All are ease-out curves with y in [0,1]: no overshoot, no bounce. */
	public static function easings() {
		return array(
			'spring'   => array( 'label' => 'Spring (damped)', 'css' => 'linear(0, 0.0464, 0.1494, 0.2724, 0.3953, 0.5079, 0.6059, 0.6883, 0.756, 0.8106, 0.854, 0.8881, 0.9147, 0.9353, 0.9511, 0.9632, 0.9723, 0.9793, 0.9845, 0.9885, 0.9914, 0.9936, 0.9953, 0.9965, 0.9974, 0.9981, 0.9986, 1)' ),
			'expo'     => array( 'label' => 'Expo out',     'css' => 'cubic-bezier(0.16, 1, 0.3, 1)' ),
			'quart'    => array( 'label' => 'Quart out',    'css' => 'cubic-bezier(0.25, 1, 0.5, 1)' ),
			'cubic'    => array( 'label' => 'Cubic out',    'css' => 'cubic-bezier(0.33, 1, 0.68, 1)' ),
			'standard' => array( 'label' => 'Standard',     'css' => 'cubic-bezier(0.2, 0, 0, 1)' ),
			'ease-out' => array( 'label' => 'Ease out',     'css' => 'ease-out' ),
		);
	}

	public static function defaults() {
		return array(
			'scope'           => 'all',   // all | front | ids
			'page_ids'        => array(),
			'rules'           => '',
			'distance'        => 20,
			'distance_mobile' => 14,
			'duration'        => 800,
			'stagger'         => 90,
			'easing'          => 'expo',
			'animate_on_load' => false,
			'blur'            => 0,
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return self::sanitize( array_merge( self::defaults(), $saved ) );
	}

	private static function clamp_int( $value, $min, $max, $fallback ) {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) round( (float) $value ) ) );
	}

	/** Strip characters that have no business in a selector list and could break out of a context. */
	public static function sanitize_rules( $raw ) {
		$raw   = wp_check_invalid_utf8( (string) $raw );
		$raw   = str_replace( array( '<', '>', '{', '}' ), '', $raw );
		$raw   = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw );
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( preg_replace( '/\s+/', ' ', $line ) );
			if ( '' === $line ) {
				continue;
			}
			$out[] = mb_substr( $line, 0, 300 );
			if ( count( $out ) >= 60 ) {
				break;
			}
		}
		return mb_substr( implode( "\n", $out ), 0, 6000 );
	}

	public static function sanitize( $in ) {
		$d   = self::defaults();
		$in  = is_array( $in ) ? $in : array();
		$out = array();

		$scope        = isset( $in['scope'] ) ? (string) $in['scope'] : $d['scope'];
		$out['scope'] = in_array( $scope, array( 'all', 'front', 'ids' ), true ) ? $scope : $d['scope'];

		$ids = isset( $in['page_ids'] ) ? $in['page_ids'] : array();
		if ( ! is_array( $ids ) ) {
			$ids = preg_split( '/[^0-9]+/', (string) $ids );
		}
		$ids             = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$out['page_ids'] = array_slice( $ids, 0, 200 );

		$out['rules']           = self::sanitize_rules( isset( $in['rules'] ) ? $in['rules'] : '' );
		$out['distance']        = self::clamp_int( $in['distance'] ?? null, 0, 60, $d['distance'] );
		$out['distance_mobile'] = self::clamp_int( $in['distance_mobile'] ?? null, 0, 60, $d['distance_mobile'] );
		$out['duration']        = self::clamp_int( $in['duration'] ?? null, 150, 1500, $d['duration'] );
		$out['stagger']         = self::clamp_int( $in['stagger'] ?? null, 0, 300, $d['stagger'] );
		$out['blur']            = self::clamp_int( $in['blur'] ?? null, 0, 20, $d['blur'] );

		$easing        = isset( $in['easing'] ) ? (string) $in['easing'] : $d['easing'];
		$out['easing'] = array_key_exists( $easing, self::easings() ) ? $easing : $d['easing'];

		$out['animate_on_load'] = ! empty( $in['animate_on_load'] ) && 'false' !== $in['animate_on_load'];

		return $out;
	}

	/** Parse the rules textarea into [{s: selector, v: variant}]. A line may end in " | fade" / " | zoom" / " | up". */
	public static function parse_rules( $rules ) {
		$out = array();
		foreach ( preg_split( '/\n/', (string) $rules ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$variant = '';
			if ( preg_match( '/^(.*?)\s*\|\s*(up|fade|zoom)$/i', $line, $m ) && '' !== $m[1] ) {
				$line    = $m[1];
				$variant = strtolower( $m[2] );
			}
			$out[] = array( 's' => $line, 'v' => $variant );
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Front end
	 * ---------------------------------------------------------------- */

	private static function in_scope( $s ) {
		if ( is_admin() || is_feed() || wp_doing_ajax() ) {
			return false;
		}
		// Never hide content inside a page-builder editor canvas.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['fl_builder'] ) || isset( $_GET['et_fb'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		switch ( $s['scope'] ) {
			case 'front':
				return is_front_page();
			case 'ids':
				return $s['page_ids'] && is_singular() && in_array( (int) get_queried_object_id(), $s['page_ids'], true );
			default:
				return true;
		}
	}

	public static function enqueue() {
		$s = self::get_settings();
		if ( ! apply_filters( 'amplifi_aos_enabled', self::in_scope( $s ), $s ) ) {
			return;
		}

		$path = ACAOS_PLUGIN_DIR . 'assets/aos.js';
		if ( ! is_file( $path ) ) {
			return;
		}
		// Content hash in our own query arg: survives sites that strip ?ver= and CDNs that pin asset URLs.
		$src = add_query_arg( 'aosv', substr( (string) md5_file( $path ), 0, 10 ), ACAOS_PLUGIN_URL . 'assets/aos.js' );
		wp_register_script( self::HANDLE, $src, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

		$easings = self::easings();
		$config  = array(
			'rules'          => self::parse_rules( $s['rules'] ),
			'distance'       => $s['distance'],
			'distanceMobile' => $s['distance_mobile'],
			'duration'       => $s['duration'],
			'stagger'        => $s['stagger'],
			'maxDelay'       => self::MAX_DELAY,
			'easing'         => $easings[ $s['easing'] ]['css'],
			'blur'           => $s['blur'],
			'animateOnLoad'  => (bool) $s['animate_on_load'],
		);
		$config = apply_filters( 'amplifi_aos_config', $config, $s );

		wp_add_inline_script(
			self::HANDLE,
			'window.amplifiAos=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
		wp_enqueue_script( self::HANDLE );
	}

	/* ------------------------------------------------------------------
	 * Admin
	 * ---------------------------------------------------------------- */

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'amplifi-plugins' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field below and again in sanitize().
		$in   = array(
			'scope'           => isset( $post['scope'] ) ? sanitize_key( $post['scope'] ) : '',
			'page_ids'        => isset( $post['page_ids'] ) ? sanitize_text_field( $post['page_ids'] ) : '',
			'rules'           => isset( $post['rules'] ) ? self::sanitize_rules( $post['rules'] ) : '',
			'distance'        => $post['distance'] ?? null,
			'distance_mobile' => $post['distance_mobile'] ?? null,
			'duration'        => $post['duration'] ?? null,
			'stagger'         => $post['stagger'] ?? null,
			'blur'            => $post['blur'] ?? null,
			'easing'          => isset( $post['easing'] ) ? sanitize_key( $post['easing'] ) : '',
			'animate_on_load' => ! empty( $post['animate_on_load'] ),
		);
		update_option( self::OPTION, self::sanitize( $in ) );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::get_settings();
		?>
		<div class="wrap">
			<h1>AOS</h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::NONCE_ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="acaos-scope">Load on</label></th>
						<td>
							<select id="acaos-scope" name="scope">
								<option value="all" <?php selected( $s['scope'], 'all' ); ?>>All pages</option>
								<option value="front" <?php selected( $s['scope'], 'front' ); ?>>Front page only</option>
								<option value="ids" <?php selected( $s['scope'], 'ids' ); ?>>Page IDs</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="acaos-ids">Page IDs</label></th>
						<td><input type="text" id="acaos-ids" name="page_ids" class="regular-text" value="<?php echo esc_attr( implode( ', ', $s['page_ids'] ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="acaos-rules">Selectors</label></th>
						<td><textarea id="acaos-rules" name="rules" rows="12" class="large-text code"><?php echo esc_textarea( $s['rules'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="acaos-distance">Distance (px)</label></th>
						<td><input type="number" id="acaos-distance" name="distance" min="0" max="60" step="1" class="small-text" value="<?php echo esc_attr( $s['distance'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="acaos-distance-m">Distance under 768px (px)</label></th>
						<td><input type="number" id="acaos-distance-m" name="distance_mobile" min="0" max="60" step="1" class="small-text" value="<?php echo esc_attr( $s['distance_mobile'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="acaos-duration">Duration (ms)</label></th>
						<td><input type="number" id="acaos-duration" name="duration" min="150" max="1500" step="10" class="small-text" value="<?php echo esc_attr( $s['duration'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="acaos-stagger">Stagger (ms)</label></th>
						<td><input type="number" id="acaos-stagger" name="stagger" min="0" max="300" step="5" class="small-text" value="<?php echo esc_attr( $s['stagger'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="acaos-blur">Text blur (px)</label></th>
						<td><input type="number" id="acaos-blur" name="blur" min="0" max="20" step="1" class="small-text" value="<?php echo esc_attr( $s['blur'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="acaos-easing">Easing</label></th>
						<td>
							<select id="acaos-easing" name="easing">
								<?php foreach ( self::easings() as $key => $e ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['easing'], $key ); ?>><?php echo esc_html( $e['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Animate content visible on load</th>
						<td><label><input type="checkbox" name="animate_on_load" value="1" <?php checked( $s['animate_on_load'] ); ?>> On</label></td>
					</tr>
				</table>
				<?php submit_button( 'Save' ); ?>
			</form>
		</div>
		<?php
	}
}

Amplifi_AOS::instance();
