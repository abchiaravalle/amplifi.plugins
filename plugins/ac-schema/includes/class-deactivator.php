<?php
declare(strict_types=1);
namespace Amplifi\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Deactivator {
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'ac_schema_run_bulk_batch' );
	}
}
