<?php
/**
 * Text-domain loader.
 *
 * @package Amplifi\Security
 */

declare(strict_types=1);

namespace Amplifi\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class I18n {

	public static function register(): void {
		add_action( 'init', [ self::class, 'load_textdomain' ] );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'amplifi-security',
			false,
			dirname( AMPLIFI_SECURITY_BASENAME ) . '/languages'
		);
	}
}
