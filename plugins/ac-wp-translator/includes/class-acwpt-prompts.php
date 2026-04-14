<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ACWPT_Prompts {

    /** Override pack dir during tests. Production uses ACWPT_PLUGIN_DIR/includes/prompts. */
    private static $pack_dir = null;

    public static function set_pack_dir_for_testing( $dir ) {
        self::$pack_dir = rtrim( $dir, '/\\' );
    }

    private static function pack_dir() {
        if ( self::$pack_dir !== null ) {
            return self::$pack_dir;
        }
        return defined( 'ACWPT_PLUGIN_DIR' )
            ? rtrim( ACWPT_PLUGIN_DIR, '/\\' ) . '/includes/prompts'
            : __DIR__ . '/prompts';
    }

    /** Load the base prompt string. */
    public static function base_prompt() {
        return require self::pack_dir() . '/base-prompt.php';
    }

    /** Load the language pack array, or null if missing. */
    public static function load_pack( $lang_code ) {
        $code = preg_replace( '/[^a-z0-9_-]/i', '', strtolower( (string) $lang_code ) );
        if ( $code === '' ) {
            return null;
        }
        $path = self::pack_dir() . '/lang/' . $code . '.php';
        if ( ! file_exists( $path ) ) {
            return null;
        }
        $pack = require $path;
        return is_array( $pack ) ? $pack : null;
    }

    /**
     * Build the system prompt for translating a post (title/content/excerpt).
     */
    public static function build_content_prompt( $lang_code, array $custom ) {
        $sections   = array( self::base_prompt() );
        $sections[] = self::language_section( $lang_code );
        $sections[] = self::custom_section( $lang_code, $custom );
        $sections[] = self::content_output_contract();
        return self::join_sections( $sections );
    }

    /**
     * Build the system prompt for batch string translation (JSON return).
     */
    public static function build_strings_prompt( $lang_code, array $custom ) {
        $sections   = array( self::base_prompt() );
        $sections[] = self::language_section( $lang_code );
        $sections[] = self::custom_section( $lang_code, $custom );
        $sections[] = self::strings_output_contract();
        return self::join_sections( $sections );
    }

    private static function language_section( $lang_code ) {
        $pack = self::load_pack( $lang_code );
        if ( ! $pack ) {
            return "TARGET LANGUAGE: {$lang_code}\nNo language-specific pack is available; rely on the base rules and write as a fluent native B2B speaker would.";
        }

        $lines   = array();
        $lines[] = 'TARGET LANGUAGE: ' . $pack['name'];
        if ( ! empty( $pack['register'] ) ) {
            $lines[] = 'REGISTER: ' . $pack['register'];
        }
        if ( ! empty( $pack['b2b_terminology'] ) ) {
            $lines[] = 'PREFERRED B2B TERMINOLOGY:';
            foreach ( $pack['b2b_terminology'] as $en => $tr ) {
                $lines[] = sprintf( '- "%s" → "%s"', $en, $tr );
            }
        }
        if ( ! empty( $pack['nuances'] ) ) {
            $lines[] = 'NATIVE-SPEAKER NUANCES (follow all):';
            foreach ( $pack['nuances'] as $n ) {
                $lines[] = '- ' . $n;
            }
        }
        if ( ! empty( $pack['avoid'] ) ) {
            $lines[] = 'AVOID THESE PATTERNS (they read as machine-translated):';
            foreach ( $pack['avoid'] as $a ) {
                $lines[] = '- ' . $a;
            }
        }
        if ( ! empty( $pack['examples'] ) ) {
            $lines[] = 'NATURAL B2B PHRASING EXAMPLES:';
            foreach ( $pack['examples'] as $ex ) {
                if ( ! empty( $ex['en'] ) && ! empty( $ex['translation'] ) ) {
                    $lines[] = sprintf( '- "%s" → "%s"', $ex['en'], $ex['translation'] );
                }
            }
        }
        return implode( "\n", $lines );
    }

    private static function custom_section( $lang_code, array $custom ) {
        $parts = array();

        $never = isset( $custom['never_translate'] ) ? (array) $custom['never_translate'] : array();
        if ( ! empty( $never ) ) {
            $parts[] = "NEVER-TRANSLATE TERMS (these will appear wrapped in <x-keep>...</x-keep> in the input — output them verbatim including the wrapper):\n- " . implode( "\n- ", $never );
        }

        $glossary = isset( $custom['glossary'] ) ? (array) $custom['glossary'] : array();
        if ( ! empty( $glossary ) ) {
            $entries = ACWPT_Glossary::entries_for_language( $glossary, $lang_code );
            $block   = ACWPT_Glossary::format_prompt_block( $entries );
            if ( $block !== '' ) {
                $parts[] = $block;
            }
        }

        return implode( "\n\n", $parts );
    }

    private static function content_output_contract() {
        return "OUTPUT FORMAT: Return your translation using the EXACT same delimiter format as the input (===TITLE===, ===CONTENT===, ===EXCERPT===). No preamble. No commentary. No code fences.";
    }

    private static function strings_output_contract() {
        return "OUTPUT FORMAT: Return ONLY a JSON object whose keys are the numeric indices (as strings) from the input and whose values are the translations. The first character of your response must be `{` and the last must be `}`. No prose. No code fences. No commentary.";
    }

    private static function join_sections( array $sections ) {
        return implode( "\n\n", array_filter( array_map( 'trim', $sections ), 'strlen' ) );
    }
}
