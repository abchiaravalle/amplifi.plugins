<?php
declare(strict_types=1);
namespace Amplifi\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Activator {
	public static function activate(): void {
		Installer::install();

		if ( ! get_option( 'ac_schema_settings' ) ) {
			update_option( 'ac_schema_settings', [
				'default_model'                => 'claude-haiku-4-5-20251001',
				'daily_spend_cap_usd'          => 5.0,
				'monthly_spend_cap_usd'        => 50.0,
				'output_priority'              => 1,
				'suppress_amplifi_meta_jsonld' => true,
			] );
		}

		// Stage meta-import notice if amplifi.meta is present.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( defined( 'AC_BULK_META_VERSION' ) || is_plugin_active( 'ac-bulk-meta/ac-bulk-meta.php' ) ) {
			if ( false === get_option( 'ac_schema_meta_import_status', false ) ) {
				update_option( 'ac_schema_meta_import_status', 'pending' );
			}
		}
	}
}
