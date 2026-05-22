<?php
declare(strict_types=1);
namespace Amplifi\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Plugin {
	public function boot(): void {
		Installer::maybe_upgrade();
		( new Admin\Admin() )->register();
		( new Frontend\Head_Output() )->register();
		( new Frontend\Foreign_Suppressor() )->register();
		( new Rest\Rest_Controller() )->register();
	}
}
