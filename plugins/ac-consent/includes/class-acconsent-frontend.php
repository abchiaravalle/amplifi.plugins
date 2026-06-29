<?php
/**
 * amplifi.consent — front-end engine.
 *
 * Hard-withholding model: every managed script is emitted inside an INERT
 * <template> element tagged with its consent category. Browsers do not execute
 * anything inside a <template> (including <script src>), so NOTHING fires on
 * page load. The front-end JS only re-materializes the scripts (by creating
 * fresh <script> nodes) for categories the visitor has granted. Reject = the
 * templates are never released, so zero tracking runs. This is genuine prior
 * blocking, not Consent Mode's anonymized-but-still-firing behavior.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Amplifi_Consent_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_head', array( __CLASS__, 'emit_head_scripts' ), 1 );
		add_action( 'wp_body_open', array( __CLASS__, 'emit_body_open_scripts' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'emit_footer_scripts' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'render_banner' ), 50 );
		add_shortcode( 'amplifi-consent-manager', array( __CLASS__, 'shortcode' ) );
	}

	private static function enabled() {
		$s = Amplifi_Consent_Store::get_settings();
		return ! empty( $s['enabled'] );
	}

	public static function enqueue() {
		if ( ! self::enabled() ) {
			return;
		}
		wp_register_style( 'acconsent', ACCONSENT_PLUGIN_URL . 'assets/css/consent.css', array(), ACCONSENT_VERSION );
		wp_register_script( 'acconsent', ACCONSENT_PLUGIN_URL . 'assets/js/consent.js', array(), ACCONSENT_VERSION, true );

		$settings   = Amplifi_Consent_Store::get_settings();
		$categories = Amplifi_Consent_Store::categories();

		// Group cookies by category for the Manage UI.
		$cookies_by_cat = array();
		foreach ( Amplifi_Consent_Store::get_cookies() as $c ) {
			$cookies_by_cat[ $c['category'] ][] = array(
				'name'        => $c['name'],
				'domain'      => $c['domain'],
				'duration'    => $c['duration'],
				'description' => $c['description'],
			);
		}

		wp_localize_script( 'acconsent', 'ACCONSENT', array(
			'settings'     => array(
				'banner_title'   => $settings['banner_title'],
				'banner_message' => $settings['banner_message'],
				'accept_label'   => $settings['accept_label'],
				'reject_label'   => $settings['reject_label'],
				'manage_label'   => $settings['manage_label'],
				'save_label'     => $settings['save_label'],
				'toast_accepted' => $settings['toast_accepted'],
				'toast_rejected' => $settings['toast_rejected'],
				'consent_days'   => (int) $settings['consent_days'],
				'accent_color'   => $settings['accent_color'],
				'position'       => $settings['position'],
			),
			'categories'   => $categories,
			'cookies'      => $cookies_by_cat,
			'storage_key'  => 'acconsent_v1',
		) );

		wp_enqueue_style( 'acconsent' );
		wp_enqueue_script( 'acconsent' );

		// Accent color as a CSS custom property.
		$accent = $settings['accent_color'];
		wp_add_inline_style( 'acconsent', ":root{--acconsent-accent:{$accent};}" );
	}

	/**
	 * Emit the gated scripts for a given placement as inert <template> blocks.
	 */
	private static function emit_for_placement( $placement ) {
		if ( ! self::enabled() ) {
			return;
		}
		foreach ( Amplifi_Consent_Store::get_scripts() as $s ) {
			if ( empty( $s['enabled'] ) || $s['placement'] !== $placement ) {
				continue;
			}
			printf(
				"\n<template class=\"acconsent-gated\" data-acconsent-category=\"%s\" data-acconsent-id=\"%s\">%s</template>\n",
				esc_attr( $s['category'] ),
				esc_attr( $s['id'] ),
				// Raw snippet: it is the admin's own script, kept verbatim inside the
				// inert template. Not executed until release. Not escaped (it is markup).
				$s['code'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
	}

	public static function emit_head_scripts() {
		self::emit_for_placement( 'head' );
	}

	public static function emit_body_open_scripts() {
		self::emit_for_placement( 'body_open' );
	}

	public static function emit_footer_scripts() {
		self::emit_for_placement( 'footer' );
	}

	/**
	 * Banner + modal shell. The JS shows/hides it based on stored consent.
	 */
	public static function render_banner() {
		if ( ! self::enabled() ) {
			return;
		}
		?>
		<div id="acconsent-root" hidden></div>
		<?php
	}

	/**
	 * [amplifi-consent-manager] — a button that re-opens the preferences modal
	 * so visitors can change their choices at any time (required for compliance).
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'label' => __( 'Manage cookie preferences', 'amplifi-consent' ),
		), $atts, 'amplifi-consent-manager' );

		if ( ! self::enabled() ) {
			return '';
		}

		return sprintf(
			'<button type="button" class="acconsent-manage-trigger" data-acconsent-open>%s</button>',
			esc_html( $atts['label'] )
		);
	}
}
