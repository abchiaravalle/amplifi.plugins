<?php
declare(strict_types=1);
namespace Amplifi\Schema\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dashboard_Page {
	public function render(): void {
		echo '<div class="wrap"><h1>amplifi.schema</h1><p>Coming online…</p></div>';
	}
}
