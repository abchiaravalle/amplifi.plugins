<?php
declare(strict_types=1);
namespace Amplifi\Schema\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Admin {
	private const PARENT_SLUG = 'amplifi-studio';
	private const PAGE_DASHBOARD = 'amplifi-ac-schema';
	private const PAGE_GLOBAL    = 'amplifi-ac-schema-global';
	private const PAGE_RULES     = 'amplifi-ac-schema-rules';
	private const PAGE_BULK      = 'amplifi-ac-schema-bulk';

	public function register(): void {
		add_action( 'init', [ $this, 'register_with_framework' ], 5 );
		add_action( 'admin_menu', [ $this, 'register_extra_submenus' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'maybe_render_import_notice' ] );
		( new Post_Editor() )->register();
	}

	public function register_with_framework(): void {
		if ( ! function_exists( 'amplifi_register_plugin' ) ) { return; }
		$dashboard = new Dashboard_Page();
		amplifi_register_plugin(
			'ac-schema',
			'Schema',
			'AI schema.org generation and editor.',
			AMPLIFI_SCHEMA_VERSION,
			AMPLIFI_SCHEMA_FILE,
			[ $dashboard, 'render' ]
		);
	}

	public function register_extra_submenus(): void {
		$global = new Global_Page();
		$rules  = new Rules_Page();
		$bulk   = new Bulk_Page();
		add_submenu_page( self::PARENT_SLUG, 'Schema: Global', 'Schema: Global', 'manage_options', self::PAGE_GLOBAL, [ $global, 'render' ] );
		add_submenu_page( self::PARENT_SLUG, 'Schema: URL Rules', 'Schema: URL Rules', 'manage_options', self::PAGE_RULES, [ $rules, 'render' ] );
		add_submenu_page( self::PARENT_SLUG, 'Schema: Bulk', 'Schema: Bulk', 'manage_options', self::PAGE_BULK, [ $bulk, 'render' ] );
	}

	public function enqueue_assets( string $hook ): void {
		// Only on our pages.
		$ours = [
			'amplifi-studio_page_' . self::PAGE_DASHBOARD,
			'amplifi-studio_page_' . self::PAGE_GLOBAL,
			'amplifi-studio_page_' . self::PAGE_RULES,
			'amplifi-studio_page_' . self::PAGE_BULK,
		];
		if ( ! in_array( $hook, $ours, true ) ) { return; }
		// All admin pages share a small bridge that exposes REST nonce + URL to inline page scripts.
		wp_register_script( 'ac-schema-admin-bridge', false, [], AMPLIFI_SCHEMA_VERSION, true );
		wp_enqueue_script( 'ac-schema-admin-bridge' );
		wp_localize_script( 'ac-schema-admin-bridge', 'AcSchemaAdmin', [
			'restUrl'  => esc_url_raw( rest_url( 'amplifi-schema/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
		] );
	}

	public function maybe_render_import_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		if ( get_option( 'ac_schema_meta_import_status', '' ) !== 'pending' ) { return; }
		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_ac_jsonld_data'" ); // phpcs:ignore
		if ( $count === 0 ) {
			// Nothing to import; auto-complete and silence.
			update_option( 'ac_schema_meta_import_status', 'done' );
			return;
		}
		$rest_url = esc_url_raw( rest_url( 'amplifi-schema/v1/migrate-from-meta' ) );
		$nonce    = wp_create_nonce( 'wp_rest' );
		?>
		<div class="notice notice-info is-dismissible" id="ac-schema-import-notice">
			<p>
				<strong>amplifi.schema:</strong> Detected <?php echo esc_html( (string) $count ); ?> posts with JSON-LD from amplifi.meta.
				<button type="button" class="button button-primary" data-action="import">Import to amplifi.schema</button>
				<button type="button" class="button" data-action="skip">Skip</button>
				<span class="ac-status" style="margin-left:10px;"></span>
			</p>
		</div>
		<script>
		(function(){
			const notice = document.getElementById('ac-schema-import-notice');
			notice.querySelectorAll('button[data-action]').forEach(function(btn){
				btn.addEventListener('click', async function(){
					const action = btn.dataset.action;
					const status = notice.querySelector('.ac-status');
					status.textContent = action === 'skip' ? 'Skipping…' : 'Importing…';
					const r = await fetch(<?php echo wp_json_encode( $rest_url ); ?>, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode( $nonce ); ?> },
						body: JSON.stringify({ action: action }),
					});
					const data = await r.json();
					status.textContent = r.ok ? ('Done — imported ' + (data.imported || 0)) : 'Error';
					if (r.ok) setTimeout(() => location.reload(), 1200);
				});
			});
		})();
		</script>
		<?php
	}
}
