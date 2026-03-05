<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Languages {

	/**
	 * All available languages with native names and flag emojis.
	 */
	public static function get_all() {
		return array(
			'en' => array( 'name' => 'English',              'native' => 'English',            'flag' => "\xF0\x9F\x87\xBA\xF0\x9F\x87\xB8" ),
			'es' => array( 'name' => 'Spanish',              'native' => "Espa\xC3\xB1ol",     'flag' => "\xF0\x9F\x87\xAA\xF0\x9F\x87\xB8" ),
			'fr' => array( 'name' => 'French',               'native' => "Fran\xC3\xA7ais",    'flag' => "\xF0\x9F\x87\xAB\xF0\x9F\x87\xB7" ),
			'de' => array( 'name' => 'German',               'native' => 'Deutsch',            'flag' => "\xF0\x9F\x87\xA9\xF0\x9F\x87\xAA" ),
			'it' => array( 'name' => 'Italian',              'native' => 'Italiano',           'flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB9" ),
			'pt' => array( 'name' => 'Portuguese',           'native' => "Portugu\xC3\xAAs",   'flag' => "\xF0\x9F\x87\xA7\xF0\x9F\x87\xB7" ),
			'zh' => array( 'name' => 'Chinese (Simplified)', 'native' => "\xE7\xAE\x80\xE4\xBD\x93\xE4\xB8\xAD\xE6\x96\x87", 'flag' => "\xF0\x9F\x87\xA8\xF0\x9F\x87\xB3" ),
			'ja' => array( 'name' => 'Japanese',             'native' => "\xE6\x97\xA5\xE6\x9C\xAC\xE8\xAA\x9E",             'flag' => "\xF0\x9F\x87\xAF\xF0\x9F\x87\xB5" ),
			'ko' => array( 'name' => 'Korean',               'native' => "\xED\x95\x9C\xEA\xB5\xAD\xEC\x96\xB4",             'flag' => "\xF0\x9F\x87\xB0\xF0\x9F\x87\xB7" ),
			'ar' => array( 'name' => 'Arabic',               'native' => "\xD8\xA7\xD9\x84\xD8\xB9\xD8\xB1\xD8\xA8\xD9\x8A\xD8\xA9", 'flag' => "\xF0\x9F\x87\xB8\xF0\x9F\x87\xA6" ),
			'ru' => array( 'name' => 'Russian',              'native' => "\xD0\xA0\xD1\x83\xD1\x81\xD1\x81\xD0\xBA\xD0\xB8\xD0\xB9", 'flag' => "\xF0\x9F\x87\xB7\xF0\x9F\x87\xBA" ),
			'hi' => array( 'name' => 'Hindi',                'native' => "\xE0\xA4\xB9\xE0\xA4\xBF\xE0\xA4\xA8\xE0\xA5\x8D\xE0\xA4\xA6\xE0\xA5\x80", 'flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3" ),
			'nl' => array( 'name' => 'Dutch',                'native' => 'Nederlands',         'flag' => "\xF0\x9F\x87\xB3\xF0\x9F\x87\xB1" ),
			'sv' => array( 'name' => 'Swedish',              'native' => 'Svenska',            'flag' => "\xF0\x9F\x87\xB8\xF0\x9F\x87\xAA" ),
			'tr' => array( 'name' => 'Turkish',              'native' => "T\xC3\xBCrk\xC3\xA7e", 'flag' => "\xF0\x9F\x87\xB9\xF0\x9F\x87\xB7" ),
			'pl' => array( 'name' => 'Polish',               'native' => 'Polski',             'flag' => "\xF0\x9F\x87\xB5\xF0\x9F\x87\xB1" ),
			'vi' => array( 'name' => 'Vietnamese',           'native' => "Ti\xE1\xBA\xBFng Vi\xE1\xBB\x87t", 'flag' => "\xF0\x9F\x87\xBB\xF0\x9F\x87\xB3" ),
			'th' => array( 'name' => 'Thai',                 'native' => "\xE0\xB9\x84\xE0\xB8\x97\xE0\xB8\xA2", 'flag' => "\xF0\x9F\x87\xB9\xF0\x9F\x87\xAD" ),
			'id' => array( 'name' => 'Indonesian',           'native' => 'Bahasa Indonesia',   'flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xA9" ),
			'uk' => array( 'name' => 'Ukrainian',            'native' => "\xD0\xA3\xD0\xBA\xD1\x80\xD0\xB0\xD1\x97\xD0\xBD\xD1\x81\xD1\x8C\xD0\xBA\xD0\xB0", 'flag' => "\xF0\x9F\x87\xBA\xF0\x9F\x87\xA6" ),
			'cs' => array( 'name' => 'Czech',                'native' => "\xC4\x8Ce\xC5\xA1tina", 'flag' => "\xF0\x9F\x87\xA8\xF0\x9F\x87\xBF" ),
			'da' => array( 'name' => 'Danish',               'native' => 'Dansk',              'flag' => "\xF0\x9F\x87\xA9\xF0\x9F\x87\xB0" ),
			'fi' => array( 'name' => 'Finnish',              'native' => 'Suomi',              'flag' => "\xF0\x9F\x87\xAB\xF0\x9F\x87\xAE" ),
			'el' => array( 'name' => 'Greek',                'native' => "\xCE\x95\xCE\xBB\xCE\xBB\xCE\xB7\xCE\xBD\xCE\xB9\xCE\xBA\xCE\xAC", 'flag' => "\xF0\x9F\x87\xAC\xF0\x9F\x87\xB7" ),
			'he' => array( 'name' => 'Hebrew',               'native' => "\xD7\xA2\xD7\x91\xD7\xA8\xD7\x99\xD7\xAA", 'flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB1" ),
			'hu' => array( 'name' => 'Hungarian',            'native' => 'Magyar',             'flag' => "\xF0\x9F\x87\xAD\xF0\x9F\x87\xBA" ),
			'no' => array( 'name' => 'Norwegian',            'native' => 'Norsk',              'flag' => "\xF0\x9F\x87\xB3\xF0\x9F\x87\xB4" ),
			'ro' => array( 'name' => 'Romanian',             'native' => "Rom\xC3\xA2n\xC4\x83", 'flag' => "\xF0\x9F\x87\xB7\xF0\x9F\x87\xB4" ),
			'sk' => array( 'name' => 'Slovak',               'native' => "Sloven\xC4\x8Dina",  'flag' => "\xF0\x9F\x87\xB8\xF0\x9F\x87\xB0" ),
			'bg' => array( 'name' => 'Bulgarian',            'native' => "\xD0\x91\xD1\x8A\xD0\xBB\xD0\xB3\xD0\xB0\xD1\x80\xD1\x81\xD0\xBA\xD0\xB8", 'flag' => "\xF0\x9F\x87\xA7\xF0\x9F\x87\xAC" ),
			'ms' => array( 'name' => 'Malay',                'native' => 'Bahasa Melayu',      'flag' => "\xF0\x9F\x87\xB2\xF0\x9F\x87\xBE" ),
			'ta' => array( 'name' => 'Tamil',                'native' => "\xE0\xAE\xA4\xE0\xAE\xAE\xE0\xAE\xBF\xE0\xAE\xB4\xE0\xAF\x8D", 'flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3" ),
			'bn'  => array( 'name' => 'Bengali',              'native' => "\xE0\xA6\xAC\xE0\xA6\xBE\xE0\xA6\x82\xE0\xA6\xB2\xE0\xA6\xBE", 'flag' => "\xF0\x9F\x87\xA7\xF0\x9F\x87\xA9" ),
			'val' => array( 'name' => 'Valencian',             'native' => "Valenci\xC3\xA0",    'flag' => "\xF0\x9F\x87\xAA\xF0\x9F\x87\xB8", 'bcp47' => 'ca-valencia' ),
		);
	}

	/**
	 * Get the BCP 47 language tag for a code (falls back to the code itself).
	 */
	public static function bcp47( $code ) {
		$all = self::get_all();
		return ( isset( $all[ $code ]['bcp47'] ) ) ? $all[ $code ]['bcp47'] : $code;
	}

	/**
	 * Get a language by code.
	 */
	public static function get( $code ) {
		$all = self::get_all();
		return isset( $all[ $code ] ) ? $all[ $code ] : null;
	}

	/**
	 * Get enabled languages from settings (excludes source language).
	 */
	public static function get_enabled() {
		$settings = get_option( 'acwpt_settings', array() );
		$enabled  = isset( $settings['enabled_languages'] ) ? $settings['enabled_languages'] : array();
		$source   = isset( $settings['source_language'] ) ? $settings['source_language'] : 'en';
		$all      = self::get_all();
		$result   = array();

		foreach ( $enabled as $code ) {
			if ( $code !== $source && isset( $all[ $code ] ) ) {
				$result[ $code ] = $all[ $code ];
			}
		}

		return $result;
	}

	/**
	 * Get enabled language codes only.
	 */
	public static function get_enabled_codes() {
		return array_keys( self::get_enabled() );
	}

	/**
	 * Get the source language code.
	 */
	public static function get_source() {
		$settings = get_option( 'acwpt_settings', array() );
		return isset( $settings['source_language'] ) ? $settings['source_language'] : 'en';
	}

	/**
	 * Build a display label for a language (with or without flag emoji).
	 */
	public static function label( $code, $use_native = true ) {
		$lang     = self::get( $code );
		if ( ! $lang ) {
			return $code;
		}

		$settings   = get_option( 'acwpt_settings', array() );
		$show_flags = isset( $settings['show_flags'] ) ? (bool) $settings['show_flags'] : true;
		$name       = $use_native ? $lang['native'] : $lang['name'];

		if ( $show_flags ) {
			return $lang['flag'] . ' ' . $name;
		}

		return $name;
	}
}
