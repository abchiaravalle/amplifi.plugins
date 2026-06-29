<?php
/**
 * amplifi.consent — data store.
 *
 * All persistence lives here: settings, managed scripts, and the categorized
 * cookie catalog. Everything is kept in wp_options (no custom tables) so the
 * module stays dependency-free and uninstall is a clean option delete.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Amplifi_Consent_Store {

	const OPT_SETTINGS = 'acconsent_settings';
	const OPT_SCRIPTS  = 'acconsent_scripts';
	const OPT_COOKIES  = 'acconsent_cookies';

	/**
	 * The commonly-accepted consent categories. `necessary` is always granted
	 * and cannot be rejected; the rest are opt-in and gate their scripts.
	 */
	public static function categories() {
		return array(
			'necessary' => array(
				'label'       => __( 'Strictly Necessary', 'amplifi-consent' ),
				'description' => __( 'Required for the site to function. Always active.', 'amplifi-consent' ),
				'locked'      => true,
			),
			'functional' => array(
				'label'       => __( 'Functional', 'amplifi-consent' ),
				'description' => __( 'Remembers preferences and choices to personalize your experience.', 'amplifi-consent' ),
				'locked'      => false,
			),
			'analytics' => array(
				'label'       => __( 'Analytics', 'amplifi-consent' ),
				'description' => __( 'Helps us understand how visitors use the site so we can improve it.', 'amplifi-consent' ),
				'locked'      => false,
			),
			'marketing' => array(
				'label'       => __( 'Marketing', 'amplifi-consent' ),
				'description' => __( 'Used to track visitors and show relevant advertising.', 'amplifi-consent' ),
				'locked'      => false,
			),
		);
	}

	public static function default_settings() {
		return array(
			'banner_title'    => __( 'We value your privacy', 'amplifi-consent' ),
			'banner_message'  => __( 'We use cookies to improve your experience. Tracking scripts will not run until you accept. You can accept, reject, or manage your choices.', 'amplifi-consent' ),
			'accept_label'    => __( 'Accept all', 'amplifi-consent' ),
			'reject_label'    => __( 'Reject all', 'amplifi-consent' ),
			'manage_label'    => __( 'Manage', 'amplifi-consent' ),
			'save_label'      => __( 'Save choices', 'amplifi-consent' ),
			'toast_accepted'  => __( 'Preferences saved — thanks!', 'amplifi-consent' ),
			'toast_rejected'  => __( 'Tracking declined. Only essential cookies are active.', 'amplifi-consent' ),
			'consent_days'    => 180,
			'accent_color'    => '#055c5f',
			'position'        => 'bottom', // bottom | center
			'enabled'         => true,
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPT_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::default_settings(), $saved );
	}

	public static function save_settings( $settings ) {
		$defaults = self::default_settings();
		$clean    = array();
		foreach ( $defaults as $key => $default ) {
			if ( ! isset( $settings[ $key ] ) ) {
				$clean[ $key ] = $default;
				continue;
			}
			switch ( $key ) {
				case 'consent_days':
					$clean[ $key ] = max( 1, min( 365, intval( $settings[ $key ] ) ) );
					break;
				case 'accent_color':
					$clean[ $key ] = preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $settings[ $key ] ) ? $settings[ $key ] : $default;
					break;
				case 'enabled':
					$clean[ $key ] = (bool) $settings[ $key ];
					break;
				case 'position':
					$clean[ $key ] = in_array( $settings[ $key ], array( 'bottom', 'center' ), true ) ? $settings[ $key ] : $default;
					break;
				default:
					$clean[ $key ] = sanitize_text_field( $settings[ $key ] );
			}
		}
		update_option( self::OPT_SETTINGS, $clean );
		return $clean;
	}

	/* ---------------- Managed scripts ---------------- */

	public static function get_scripts() {
		$scripts = get_option( self::OPT_SCRIPTS, array() );
		return is_array( $scripts ) ? array_values( $scripts ) : array();
	}

	/**
	 * Sanitize a single script record. The `code` field intentionally keeps the
	 * raw markup (it IS a script the admin pasted) — it is escaped for display
	 * but stored verbatim so it can be re-emitted as a gated tag.
	 */
	public static function sanitize_script( $s ) {
		$categories = array_keys( self::categories() );
		$placements = array( 'head', 'body_open', 'footer' );
		return array(
			'id'        => isset( $s['id'] ) && $s['id'] ? sanitize_key( $s['id'] ) : 'scr_' . wp_generate_password( 8, false, false ),
			'label'     => isset( $s['label'] ) ? sanitize_text_field( $s['label'] ) : '',
			'category'  => isset( $s['category'] ) && in_array( $s['category'], $categories, true ) ? $s['category'] : 'analytics',
			'placement' => isset( $s['placement'] ) && in_array( $s['placement'], $placements, true ) ? $s['placement'] : 'head',
			'code'      => isset( $s['code'] ) ? (string) $s['code'] : '',
			'enabled'   => isset( $s['enabled'] ) ? (bool) $s['enabled'] : true,
		);
	}

	public static function save_scripts( $scripts ) {
		$clean = array();
		foreach ( (array) $scripts as $s ) {
			$rec = self::sanitize_script( $s );
			if ( '' === trim( $rec['code'] ) ) {
				continue;
			}
			$clean[ $rec['id'] ] = $rec;
		}
		update_option( self::OPT_SCRIPTS, $clean );
		return array_values( $clean );
	}

	public static function get_script( $id ) {
		$id = sanitize_key( $id );
		foreach ( self::get_scripts() as $s ) {
			if ( $s['id'] === $id ) {
				return $s;
			}
		}
		return null;
	}

	/* ---------------- Cookie catalog ---------------- */

	public static function get_cookies() {
		$cookies = get_option( self::OPT_COOKIES, array() );
		return is_array( $cookies ) ? array_values( $cookies ) : array();
	}

	public static function sanitize_cookie( $c ) {
		$categories = array_keys( self::categories() );
		return array(
			'name'        => isset( $c['name'] ) ? sanitize_text_field( $c['name'] ) : '',
			'category'    => isset( $c['category'] ) && in_array( $c['category'], $categories, true ) ? $c['category'] : 'analytics',
			'script_id'   => isset( $c['script_id'] ) ? sanitize_key( $c['script_id'] ) : '',
			'domain'      => isset( $c['domain'] ) ? sanitize_text_field( $c['domain'] ) : '',
			'duration'    => isset( $c['duration'] ) ? sanitize_text_field( $c['duration'] ) : '',
			'description' => isset( $c['description'] ) ? sanitize_text_field( $c['description'] ) : '',
		);
	}

	public static function save_cookies( $cookies ) {
		$clean = array();
		foreach ( (array) $cookies as $c ) {
			$rec = self::sanitize_cookie( $c );
			if ( '' === $rec['name'] ) {
				continue;
			}
			$clean[ $rec['name'] ] = $rec; // keyed by name → dedupes.
		}
		update_option( self::OPT_COOKIES, array_values( $clean ) );
		return array_values( $clean );
	}

	/** Merge newly-detected cookies into the catalog without clobbering categorizations already made. */
	public static function merge_detected_cookies( $detected, $script_id ) {
		$existing = array();
		foreach ( self::get_cookies() as $c ) {
			$existing[ $c['name'] ] = $c;
		}
		foreach ( (array) $detected as $d ) {
			$name = isset( $d['name'] ) ? sanitize_text_field( $d['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			if ( isset( $existing[ $name ] ) ) {
				// Keep the admin's categorization; just refresh domain/script linkage.
				if ( empty( $existing[ $name ]['script_id'] ) ) {
					$existing[ $name ]['script_id'] = sanitize_key( $script_id );
				}
				if ( empty( $existing[ $name ]['domain'] ) && ! empty( $d['domain'] ) ) {
					$existing[ $name ]['domain'] = sanitize_text_field( $d['domain'] );
				}
				continue;
			}
			$existing[ $name ] = self::sanitize_cookie( array(
				'name'      => $name,
				'category'  => '', // unset → defaults to analytics; admin re-categorizes.
				'script_id' => $script_id,
				'domain'    => isset( $d['domain'] ) ? $d['domain'] : '',
				'duration'  => isset( $d['duration'] ) ? $d['duration'] : '',
			) );
		}
		update_option( self::OPT_COOKIES, array_values( $existing ) );
		return array_values( $existing );
	}

	public static function activate() {
		if ( false === get_option( self::OPT_SETTINGS, false ) ) {
			update_option( self::OPT_SETTINGS, self::default_settings() );
		}
		if ( false === get_option( self::OPT_SCRIPTS, false ) ) {
			update_option( self::OPT_SCRIPTS, array() );
		}
		if ( false === get_option( self::OPT_COOKIES, false ) ) {
			update_option( self::OPT_COOKIES, array() );
		}
	}
}
