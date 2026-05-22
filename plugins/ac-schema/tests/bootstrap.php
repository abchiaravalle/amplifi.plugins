<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'AMPLIFI_SCHEMA_PATH' ) ) {
	define( 'AMPLIFI_SCHEMA_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';
\Amplifi\Schema\Autoloader::register();
