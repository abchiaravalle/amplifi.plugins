<?php
/**
 * amplifi.consent — REST API.
 *
 * One public, read-only endpoint exposing the consent configuration (categories
 * + the categorized cookie catalog) so a decoupled front end or an audit tool
 * can read what this site gates and how cookies are classified. No secrets, no
 * writes — the script bodies are intentionally NOT exposed here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Amplifi_Consent_Rest {

	const NS = 'amplifi-consent/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( self::NS, '/config', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_config' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function get_config() {
		$settings = Amplifi_Consent_Store::get_settings();

		// Cookies grouped by category (no script bodies).
		$by_cat = array();
		foreach ( Amplifi_Consent_Store::get_cookies() as $c ) {
			$by_cat[ $c['category'] ][] = array(
				'name'        => $c['name'],
				'domain'      => $c['domain'],
				'duration'    => $c['duration'],
				'description' => $c['description'],
			);
		}

		// Script inventory (metadata only — never the code).
		$scripts = array();
		foreach ( Amplifi_Consent_Store::get_scripts() as $s ) {
			$scripts[] = array(
				'id'        => $s['id'],
				'label'     => $s['label'],
				'category'  => $s['category'],
				'placement' => $s['placement'],
				'enabled'   => (bool) $s['enabled'],
			);
		}

		return rest_ensure_response( array(
			'enabled'      => (bool) $settings['enabled'],
			'consent_days' => (int) $settings['consent_days'],
			'categories'   => Amplifi_Consent_Store::categories(),
			'cookies'      => $by_cat,
			'scripts'      => $scripts,
		) );
	}
}
