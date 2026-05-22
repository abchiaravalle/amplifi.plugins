<?php
declare(strict_types=1);
namespace Amplifi\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Plugin {
	public function boot(): void {
		Installer::maybe_upgrade();
		( new Admin\Admin() )->register();
	}
}
