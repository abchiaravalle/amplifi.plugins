<?php
declare(strict_types=1);
namespace Amplifi\Schema\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Admin {
	public function register(): void {
		add_action( 'init', [ $this, 'register_with_framework' ], 5 );
	}

	public function register_with_framework(): void {
		if ( ! function_exists( 'amplifi_register_plugin' ) ) {
			return;
		}
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
}
