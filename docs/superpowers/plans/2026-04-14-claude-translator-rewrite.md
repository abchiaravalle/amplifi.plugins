# amplifi.translate Claude Rewrite — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate `plugins/ac-wp-translator/` from OpenAI to Anthropic Claude (BYOK), add per-language native-speaker prompt packs (Polish + 7 others), and add a never-translate list and per-language glossary. Ships as v2.0.0.

**Architecture:** Provider swap inside `class-acwpt-translator.php`. New prompt assembler (`class-acwpt-prompts.php`) loads a base prompt plus a per-language nuance pack from `includes/prompts/lang/<code>.php`. New glossary helper (`class-acwpt-glossary.php`) wraps reserved terms with `<x-keep>` sentinels before sending and strips them on return. Custom-instructions changes invalidate cache by mixing a `custom_version` int into the existing `content_hash`. No schema migration.

**Tech Stack:** PHP 7.4+, WordPress 5.6+, Anthropic Messages API (`/v1/messages`, `anthropic-version: 2023-06-01`), Docker (existing `docker-compose.yml`), `wp-cli` for unit-style testing.

**Reference spec:** `docs/superpowers/specs/2026-04-14-claude-translator-rewrite-design.md`

**Testing approach:** No PHPUnit/composer infra (intentionally — keeps the change focused). Pure-PHP units that don't need WordPress globals are tested with standalone PHP scripts in `tests/` invoked via `php`. WP-integrated code is smoke-tested via `wp eval-file` inside the running Docker container. Manual end-to-end smoke test against a real Anthropic key is the last task.

**Conventions:**
- Always `cd plugins/ac-wp-translator/` for git/docker commands unless noted.
- Plugin path inside Docker: `/var/www/html/wp-content/plugins/ac-wp-translator/`.
- Run `docker-compose up -d` once before testing tasks that need WP.
- Commit messages use Conventional Commits (`feat:`, `refactor:`, `test:`, `chore:`).

---

## Task 1: Add minimal test harness directory

**Files:**
- Create: `plugins/ac-wp-translator/tests/bootstrap.php`
- Create: `plugins/ac-wp-translator/tests/README.md`

- [ ] **Step 1: Create `tests/bootstrap.php`**

This file lets standalone PHP unit tests load plugin classes without WordPress. Real WP-dependent code is tested via `wp eval-file`.

```php
<?php
/**
 * Minimal test bootstrap for pure-PHP units.
 * Defines stubs for the WordPress functions we touch in unit tests.
 * For WP-integrated tests, use `wp eval-file` inside Docker instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = '' ) { return $text; }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}

require_once __DIR__ . '/assert_helpers.php';
```

- [ ] **Step 2: Create `tests/assert_helpers.php`**

```php
<?php
/**
 * Minimal assertion helpers. No external dependencies.
 * Tests print PASS/FAIL lines and exit non-zero on first failure.
 */

function t_pass( $name ) {
    fwrite( STDOUT, "  PASS: {$name}\n" );
}

function t_fail( $name, $msg = '' ) {
    fwrite( STDERR, "  FAIL: {$name}" . ( $msg ? " — {$msg}" : '' ) . "\n" );
    exit( 1 );
}

function t_assert( $cond, $name, $msg = '' ) {
    if ( $cond ) { t_pass( $name ); } else { t_fail( $name, $msg ); }
}

function t_equals( $expected, $actual, $name ) {
    if ( $expected === $actual ) {
        t_pass( $name );
    } else {
        $e = var_export( $expected, true );
        $a = var_export( $actual, true );
        t_fail( $name, "expected={$e} actual={$a}" );
    }
}

function t_section( $title ) {
    fwrite( STDOUT, "\n== {$title} ==\n" );
}
```

- [ ] **Step 3: Create `tests/README.md`**

```markdown
# Tests

Minimal harness — no PHPUnit/composer.

## Pure-PHP unit tests
Run from plugin root: `php tests/test_<name>.php`
Each test prints PASS/FAIL lines and exits 1 on first failure.

## WP-integrated tests
Run from repo root after `docker-compose up -d` inside the plugin dir:
`docker-compose exec wordpress wp eval-file /var/www/html/wp-content/plugins/ac-wp-translator/tests/test_<name>.php`
```

- [ ] **Step 4: Verify bootstrap loads cleanly**

```bash
cd plugins/ac-wp-translator && php -r "require __DIR__.'/tests/bootstrap.php'; echo \"ok\n\";"
```
Expected output: `ok`

- [ ] **Step 5: Commit**

```bash
cd plugins/ac-wp-translator
git add tests/
git commit -m "test(ac-wp-translator): add minimal standalone test harness"
```

---

## Task 2: Sentinel helpers for never-translate

Implements `apply_keep_sentinels()` and `strip_keep_sentinels()` on a new `ACWPT_Glossary` class. The class will gain glossary methods in Task 4 — start with sentinels.

**Files:**
- Create: `plugins/ac-wp-translator/includes/class-acwpt-glossary.php`
- Create: `plugins/ac-wp-translator/tests/test_sentinels.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-acwpt-glossary.php';

t_section( 'apply_keep_sentinels: basic' );
$out = ACWPT_Glossary::apply_keep_sentinels(
    'Try Acme Cloud today!',
    array( 'Acme Cloud' )
);
t_equals( 'Try <x-keep>Acme Cloud</x-keep> today!', $out, 'wraps single match' );

t_section( 'apply_keep_sentinels: case-sensitive whole-word' );
$out = ACWPT_Glossary::apply_keep_sentinels(
    'acme cloud and Acme Cloud and Acme Cloudy',
    array( 'Acme Cloud' )
);
t_equals(
    'acme cloud and <x-keep>Acme Cloud</x-keep> and Acme Cloudy',
    $out,
    'only matches exact case + whole word'
);

t_section( 'apply_keep_sentinels: multiple terms, longest first' );
$out = ACWPT_Glossary::apply_keep_sentinels(
    'Acme and Acme Cloud',
    array( 'Acme', 'Acme Cloud' )
);
t_equals(
    '<x-keep>Acme</x-keep> and <x-keep>Acme Cloud</x-keep>',
    $out,
    'longest-first wrapping does not double-wrap'
);

t_section( 'apply_keep_sentinels: empty inputs' );
t_equals( 'hello', ACWPT_Glossary::apply_keep_sentinels( 'hello', array() ), 'no terms' );
t_equals( '', ACWPT_Glossary::apply_keep_sentinels( '', array( 'X' ) ), 'empty text' );

t_section( 'strip_keep_sentinels' );
$out = ACWPT_Glossary::strip_keep_sentinels( 'Try <x-keep>Acme Cloud</x-keep> today!' );
t_equals( 'Try Acme Cloud today!', $out, 'strips wrapper' );
$out = ACWPT_Glossary::strip_keep_sentinels( 'plain text' );
t_equals( 'plain text', $out, 'no-op when no sentinels' );

echo "\nALL PASS\n";
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd plugins/ac-wp-translator && php tests/test_sentinels.php
```
Expected: PHP fatal "class ACWPT_Glossary not found" or similar.

- [ ] **Step 3: Implement minimal class**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ACWPT_Glossary {

    /**
     * Wrap each term in $never_list with <x-keep>...</x-keep> in $text.
     * Case-sensitive, whole-word match. Longest terms wrapped first to avoid
     * double-wrapping shorter terms that are substrings of longer ones.
     */
    public static function apply_keep_sentinels( $text, array $never_list ) {
        if ( $text === '' || empty( $never_list ) ) {
            return $text;
        }

        // Dedupe + sort longest-first so "Acme Cloud" wraps before "Acme".
        $terms = array_values( array_unique( array_filter( $never_list, 'strlen' ) ) );
        usort( $terms, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

        // Use a placeholder strategy: replace each match with a unique token,
        // then swap tokens for sentinels. Prevents wrapping content that's
        // already inside a sentinel from a longer earlier term.
        $tokens = array();
        foreach ( $terms as $i => $term ) {
            $token = "\0KEEP_{$i}\0";
            $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}_])/u';
            $text = preg_replace( $pattern, $token, $text );
            $tokens[ $token ] = '<x-keep>' . $term . '</x-keep>';
        }

        return strtr( $text, $tokens );
    }

    /**
     * Strip <x-keep>...</x-keep> wrappers, preserving inner content.
     */
    public static function strip_keep_sentinels( $text ) {
        return preg_replace( '#<x-keep>(.*?)</x-keep>#s', '$1', $text );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd plugins/ac-wp-translator && php tests/test_sentinels.php
```
Expected: all PASS lines, ends with `ALL PASS`, exit 0.

- [ ] **Step 5: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/class-acwpt-glossary.php tests/test_sentinels.php
git commit -m "feat(ac-wp-translator): add x-keep sentinel helpers for never-translate terms"
```

---

## Task 3: JSON brace-balanced extractor

Anthropic has no `response_format`. Add a tolerant extractor that pulls the first complete JSON object out of a string (skipping any preamble/code-fence Claude might leak).

**Files:**
- Modify: `plugins/ac-wp-translator/includes/class-acwpt-glossary.php` (add static helper — keep all parser-ish helpers in one utility class for now; if the file grows past ~300 lines we split later)
- Create: `plugins/ac-wp-translator/tests/test_json_extract.php`

Decision note: the JSON extractor logically lives with response parsing rather than glossary, but creating a third class for one function violates YAGNI. Park it on `ACWPT_Glossary` as `extract_first_json_object()` and rename the class to `ACWPT_Util` only if it accumulates more unrelated helpers.

- [ ] **Step 1: Write the failing test**

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-acwpt-glossary.php';

t_section( 'extract_first_json_object: clean object' );
t_equals(
    array( 'a' => 1, 'b' => 'two' ),
    ACWPT_Glossary::extract_first_json_object( '{"a":1,"b":"two"}' ),
    'pure JSON'
);

t_section( 'extract_first_json_object: with preamble' );
t_equals(
    array( '0' => 'Hola', '1' => 'Adiós' ),
    ACWPT_Glossary::extract_first_json_object(
        "Sure, here you go:\n```json\n{\"0\":\"Hola\",\"1\":\"Adiós\"}\n```"
    ),
    'tolerates code fence + preamble'
);

t_section( 'extract_first_json_object: nested braces' );
t_equals(
    array( 'a' => array( 'b' => 1 ) ),
    ACWPT_Glossary::extract_first_json_object( 'noise {"a":{"b":1}} more noise' ),
    'balances nested braces'
);

t_section( 'extract_first_json_object: braces inside strings' );
t_equals(
    array( 's' => 'has } in it' ),
    ACWPT_Glossary::extract_first_json_object( 'pre {"s":"has } in it"} post' ),
    'ignores braces inside JSON strings'
);

t_section( 'extract_first_json_object: escape handling' );
t_equals(
    array( 's' => 'quote \" and brace }' ),
    ACWPT_Glossary::extract_first_json_object( '{"s":"quote \\" and brace }"}' ),
    'respects backslash-escaped quotes'
);

t_section( 'extract_first_json_object: no object' );
t_equals( null, ACWPT_Glossary::extract_first_json_object( 'no json here' ), 'no braces' );
t_equals( null, ACWPT_Glossary::extract_first_json_object( '{not valid json' ), 'unbalanced' );

echo "\nALL PASS\n";
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd plugins/ac-wp-translator && php tests/test_json_extract.php
```
Expected: undefined method error.

- [ ] **Step 3: Add the extractor to ACWPT_Glossary**

Append inside the class body, before the closing brace:

```php
    /**
     * Extract the first complete JSON object from a string, tolerating any
     * preamble, code fences, or trailing text. Returns the decoded array or
     * null if no balanced object is found / decoding fails.
     */
    public static function extract_first_json_object( $text ) {
        $len   = strlen( $text );
        $start = strpos( $text, '{' );
        if ( $start === false ) {
            return null;
        }

        $depth     = 0;
        $in_str    = false;
        $escaped   = false;
        $end       = -1;

        for ( $i = $start; $i < $len; $i++ ) {
            $ch = $text[ $i ];

            if ( $in_str ) {
                if ( $escaped ) {
                    $escaped = false;
                } elseif ( $ch === '\\' ) {
                    $escaped = true;
                } elseif ( $ch === '"' ) {
                    $in_str = false;
                }
                continue;
            }

            if ( $ch === '"' ) {
                $in_str = true;
            } elseif ( $ch === '{' ) {
                $depth++;
            } elseif ( $ch === '}' ) {
                $depth--;
                if ( $depth === 0 ) {
                    $end = $i;
                    break;
                }
            }
        }

        if ( $end === -1 ) {
            return null;
        }

        $json    = substr( $text, $start, $end - $start + 1 );
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : null;
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd plugins/ac-wp-translator && php tests/test_json_extract.php
```
Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/class-acwpt-glossary.php tests/test_json_extract.php
git commit -m "feat(ac-wp-translator): add brace-balanced JSON extractor for Claude responses"
```

---

## Task 4: Glossary lookup + prompt-block + sentinel methods

Add the actual glossary functionality to `ACWPT_Glossary`.

**Files:**
- Modify: `plugins/ac-wp-translator/includes/class-acwpt-glossary.php`
- Create: `plugins/ac-wp-translator/tests/test_glossary.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-acwpt-glossary.php';

$glossary = array(
    array( 'en' => 'Contact us',  'pl' => 'Kontakt z nami', 'es' => 'Contáctenos' ),
    array( 'en' => 'Learn more',  'pl' => 'Dowiedz się więcej', 'es' => '' ),
    array( 'en' => '',            'pl' => 'X', 'es' => 'Y' ),
);

t_section( 'entries_for_language: filters empty + missing source' );
$pl = ACWPT_Glossary::entries_for_language( $glossary, 'pl' );
t_equals(
    array(
        array( 'en' => 'Contact us', 'translation' => 'Kontakt z nami' ),
        array( 'en' => 'Learn more', 'translation' => 'Dowiedz się więcej' ),
    ),
    $pl,
    'returns both for pl'
);

$es = ACWPT_Glossary::entries_for_language( $glossary, 'es' );
t_equals(
    array(
        array( 'en' => 'Contact us', 'translation' => 'Contáctenos' ),
    ),
    $es,
    'drops entries with empty translation'
);

t_section( 'entries_for_language: missing language' );
t_equals( array(), ACWPT_Glossary::entries_for_language( $glossary, 'jp' ), 'no jp column' );

t_section( 'format_prompt_block: empty' );
t_equals( '', ACWPT_Glossary::format_prompt_block( array() ), 'empty list = empty string' );

t_section( 'format_prompt_block: populated' );
$block = ACWPT_Glossary::format_prompt_block( $pl );
t_assert( strpos( $block, 'MANDATORY GLOSSARY' ) !== false, 'header present' );
t_assert( strpos( $block, '"Contact us" → "Kontakt z nami"' ) !== false, 'arrow line present' );
t_assert( strpos( $block, '"Learn more" → "Dowiedz się więcej"' ) !== false, 'second line present' );

t_section( 'apply_glossary_sentinels: wraps source terms' );
$out = ACWPT_Glossary::apply_glossary_sentinels( 'Please Contact us today.', $pl );
t_equals(
    'Please <x-glossary term="Kontakt z nami">Contact us</x-glossary> today.',
    $out,
    'wraps with translation hint'
);

t_section( 'strip_glossary_sentinels: replaces with translation' );
$out = ACWPT_Glossary::strip_glossary_sentinels(
    'Please <x-glossary term="Kontakt z nami">Skontaktuj się</x-glossary> today.'
);
t_equals(
    'Please Kontakt z nami today.',
    $out,
    'response replaced with mandated translation'
);

echo "\nALL PASS\n";
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd plugins/ac-wp-translator && php tests/test_glossary.php
```
Expected: undefined method error.

- [ ] **Step 3: Add methods to `ACWPT_Glossary`**

Append before the closing class brace:

```php
    /**
     * Return glossary rows that have a non-empty source ('en') and a
     * non-empty translation for the given language code.
     */
    public static function entries_for_language( array $glossary, $lang_code ) {
        $out = array();
        foreach ( $glossary as $row ) {
            $src = isset( $row['en'] ) ? trim( (string) $row['en'] ) : '';
            $tr  = isset( $row[ $lang_code ] ) ? trim( (string) $row[ $lang_code ] ) : '';
            if ( $src === '' || $tr === '' ) {
                continue;
            }
            $out[] = array( 'en' => $src, 'translation' => $tr );
        }
        return $out;
    }

    /**
     * Format a prompt block listing mandatory translations for the model.
     * Returns empty string if no entries.
     */
    public static function format_prompt_block( array $entries ) {
        if ( empty( $entries ) ) {
            return '';
        }
        $lines = array( 'MANDATORY GLOSSARY — translate these source terms EXACTLY as shown:' );
        foreach ( $entries as $e ) {
            $lines[] = sprintf( '"%s" → "%s"', $e['en'], $e['translation'] );
        }
        return implode( "\n", $lines );
    }

    /**
     * Wrap glossary source terms in the input with <x-glossary term="...">
     * sentinels carrying the mandated translation. Case-sensitive whole-word.
     */
    public static function apply_glossary_sentinels( $text, array $entries ) {
        if ( $text === '' || empty( $entries ) ) {
            return $text;
        }
        // Longest-first to avoid partial overlap.
        usort( $entries, function ( $a, $b ) { return strlen( $b['en'] ) - strlen( $a['en'] ); } );

        $tokens = array();
        foreach ( $entries as $i => $e ) {
            $token   = "\0GLOSS_{$i}\0";
            $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( $e['en'], '/' ) . '(?![\p{L}\p{N}_])/u';
            $text    = preg_replace( $pattern, $token, $text );
            $tokens[ $token ] = sprintf(
                '<x-glossary term="%s">%s</x-glossary>',
                str_replace( '"', '&quot;', $e['translation'] ),
                $e['en']
            );
        }
        return strtr( $text, $tokens );
    }

    /**
     * Replace each <x-glossary term="X">...</x-glossary> in $text with X
     * (the mandated translation), discarding whatever the model put inside.
     */
    public static function strip_glossary_sentinels( $text ) {
        return preg_replace_callback(
            '#<x-glossary term="([^"]*)">.*?</x-glossary>#s',
            function ( $m ) { return html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ); },
            $text
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd plugins/ac-wp-translator && php tests/test_glossary.php
```
Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/class-acwpt-glossary.php tests/test_glossary.php
git commit -m "feat(ac-wp-translator): add glossary lookup, prompt block, and sentinel helpers"
```

---

## Task 5: Base prompt file

**Files:**
- Create: `plugins/ac-wp-translator/includes/prompts/base-prompt.php`

- [ ] **Step 1: Create the file**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Base system prompt shared across all language packs.
 * Returned as a string so the assembler can concat it.
 */
return <<<'PROMPT'
You are a senior in-house copywriter and native speaker of the target language, translating B2B marketing and product copy for a company website. Your output must read as if it was originally written in the target language by a fluent professional — never word-for-word, never literal, never machine-translated.

ABSOLUTE STRUCTURAL RULES (violating these breaks the website):
- Preserve ALL HTML tags exactly as they are (attributes, casing, order, self-closing form).
- Preserve ALL WordPress shortcodes (anything inside square brackets) exactly.
- Preserve ALL WordPress block comments (<!-- wp:... --> and <!-- /wp:... -->) exactly.
- Preserve URLs, email addresses, file paths, code identifiers, and version numbers exactly.
- Preserve numbers and currency values; convert formatting only if the target language conventionally requires it (e.g., decimal commas).

LOCKED CONTENT — do not translate or modify:
- Anything inside <x-keep>...</x-keep>. Output it verbatim with the surrounding tags intact.
- Anything inside <x-glossary term="...">...</x-glossary>. Output the entire wrapper verbatim — do not paraphrase the inner content; downstream code handles substitution.

VOICE AND REGISTER:
- Default register is professional B2B: confident, clear, benefit-oriented.
- Avoid first-person plural unless the source uses it.
- Match the source's tone (formal vs. conversational) but always within the target language's natural B2B norms.
- Headlines and CTAs should follow the target language's marketing conventions, not English title case.

ANTI-PATTERNS — avoid these "AI/translation tells":
- Don't add hedging words ("perhaps", "kind of", "essentially") that aren't in the source.
- Don't add meta-commentary, explanations, or notes about your translation.
- Don't expand acronyms unless the source does.
- Don't translate idioms literally — use the natural equivalent in the target language, or rewrite for the same effect.
- Don't mirror English sentence structure when the target language prefers different ordering.

OUTPUT CONTRACT:
- Return ONLY the translated content using the EXACT same delimiter format as the input.
- No preamble, no commentary, no code fences.
PROMPT;
```

- [ ] **Step 2: Smoke check**

```bash
cd plugins/ac-wp-translator && php -r "\$s = require __DIR__.'/includes/prompts/base-prompt.php'; echo strlen(\$s) > 500 ? \"ok\n\" : \"too short\n\";"
```
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/prompts/base-prompt.php
git commit -m "feat(ac-wp-translator): add base system prompt for translator"
```

---

## Task 6: Prompts assembler class

**Files:**
- Create: `plugins/ac-wp-translator/includes/class-acwpt-prompts.php`
- Create: `plugins/ac-wp-translator/tests/test_prompts.php`
- Create: `plugins/ac-wp-translator/includes/prompts/lang/_template.php` (reference template, not loaded)

- [ ] **Step 1: Create reference template**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Language pack template. Copy to <code>.php (e.g. pl.php) and fill in.
 * Aim for 80-120 entries in `nuances`. More is fine; redundant is fine —
 * the model benefits from repetition of important rules.
 */
return array(
    'name'            => 'LANGUAGE NAME IN ENGLISH',
    'register'        => 'One paragraph describing formality, address forms, and tone defaults for B2B copy in this language.',
    'b2b_terminology' => array(
        // 'english source' => 'preferred target translation',
    ),
    'nuances' => array(
        // Each entry is a short imperative sentence the translator must follow.
    ),
    'avoid' => array(
        // Each entry describes a pattern that screams machine translation.
    ),
    'examples' => array(
        // array( 'en' => 'Source phrase', 'translation' => 'Native phrasing' ),
    ),
);
```

- [ ] **Step 2: Write the failing test**

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-acwpt-glossary.php';
require_once __DIR__ . '/../includes/class-acwpt-prompts.php';

// Stub language pack on disk for testing.
$tmp_dir = sys_get_temp_dir() . '/acwpt_test_packs';
@mkdir( $tmp_dir, 0777, true );
@mkdir( $tmp_dir . '/lang', 0777, true );
copy( __DIR__ . '/../includes/prompts/base-prompt.php', $tmp_dir . '/base-prompt.php' );
file_put_contents( $tmp_dir . '/lang/xx.php', "<?php return array(\n  'name' => 'Xtest',\n  'register' => 'Use formal voice.',\n  'b2b_terminology' => array('platform' => 'platforma'),\n  'nuances' => array('Always do A.', 'Never do B.'),\n  'avoid' => array('Avoid C.'),\n  'examples' => array(array('en'=>'Get started.', 'translation'=>'Zacznij.')),\n);\n" );

ACWPT_Prompts::set_pack_dir_for_testing( $tmp_dir );

t_section( 'build_content_prompt: includes base + pack sections' );
$p = ACWPT_Prompts::build_content_prompt( 'xx', array( 'never_translate' => array(), 'glossary' => array() ) );
t_assert( strpos( $p, 'B2B' )                  !== false, 'base text present' );
t_assert( strpos( $p, 'Xtest' )                !== false, 'language name present' );
t_assert( strpos( $p, 'formal voice' )         !== false, 'register present' );
t_assert( strpos( $p, 'platform' )             !== false, 'b2b term key present' );
t_assert( strpos( $p, 'platforma' )            !== false, 'b2b term value present' );
t_assert( strpos( $p, 'Always do A.' )         !== false, 'nuance present' );
t_assert( strpos( $p, 'Avoid C.' )             !== false, 'avoid present' );
t_assert( strpos( $p, 'Get started.' )         !== false, 'example en present' );
t_assert( strpos( $p, 'Zacznij.' )             !== false, 'example translation present' );
t_assert( strpos( $p, '===TITLE===' )          !== false, 'delimiter contract present' );

t_section( 'build_content_prompt: glossary block when entries exist' );
$p = ACWPT_Prompts::build_content_prompt( 'xx', array(
    'never_translate' => array( 'Acme' ),
    'glossary'        => array( array( 'en' => 'Contact us', 'xx' => 'Skontaktuj się' ) ),
) );
t_assert( strpos( $p, 'MANDATORY GLOSSARY' )                  !== false, 'glossary block present' );
t_assert( strpos( $p, '"Contact us" → "Skontaktuj się"' )    !== false, 'glossary line present' );
t_assert( strpos( $p, 'Acme' )                                !== false, 'never-translate term hinted' );

t_section( 'build_content_prompt: unknown language falls back to generic' );
$p = ACWPT_Prompts::build_content_prompt( 'zz', array( 'never_translate' => array(), 'glossary' => array() ) );
t_assert( strpos( $p, 'B2B' ) !== false, 'base still present for fallback' );

t_section( 'build_strings_prompt: declares JSON-only contract' );
$p = ACWPT_Prompts::build_strings_prompt( 'xx', array( 'never_translate' => array(), 'glossary' => array() ) );
t_assert( strpos( $p, 'JSON' ) !== false, 'JSON contract present' );
t_assert( strpos( $p, 'first character of your response must be `{`' ) !== false, 'strict opening rule present' );

echo "\nALL PASS\n";
```

- [ ] **Step 3: Run test to verify it fails**

```bash
cd plugins/ac-wp-translator && php tests/test_prompts.php
```
Expected: class not found.

- [ ] **Step 4: Implement `class-acwpt-prompts.php`**

```php
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
```

- [ ] **Step 5: Run test to verify it passes**

```bash
cd plugins/ac-wp-translator && php tests/test_prompts.php
```
Expected: ALL PASS.

- [ ] **Step 6: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/class-acwpt-prompts.php includes/prompts/lang/_template.php tests/test_prompts.php
git commit -m "feat(ac-wp-translator): add prompt assembler with language-pack loader"
```

---

## Tasks 7–14: Language packs

Each language pack follows the same shape (see `_template.php` from Task 6). The packs are content-heavy; aim for **80–120 nuances per language**. The starter set below for each language covers the highest-impact categories:

1. **Register/formality** (formal vs. informal address)
2. **Capitalization** (esp. headlines, German nouns)
3. **Punctuation conventions** (French NBSP, Spanish ¿¡, German „…")
4. **Number/date/currency formatting**
5. **False friends and English calques to avoid**
6. **B2B-specific terminology** (solution, platform, stakeholder, KPI, etc.)
7. **CTAs and headlines** (natural marketing phrasing)
8. **Idioms** (find equivalents, never literal)
9. **Pronouns / company references**
10. **Word-order traps**

The starter sets below are roughly 25–35 nuances each. **Expand to 80–120 during the final smoke-test phase** (Task 26) by translating sample content and adding nuances for each pattern you see Claude get wrong. The plan ships v2.0.0 with the starter packs; subsequent point releases extend them.

Each task has identical step structure: **(1)** create file, **(2)** smoke-load to ensure no parse errors, **(3)** commit. Code blocks below.

---

### Task 7: Polish language pack

**Files:**
- Create: `plugins/ac-wp-translator/includes/prompts/lang/pl.php`

- [ ] **Step 1: Create the file**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

return array(
    'name' => 'Polish',
    'register' => 'In B2B copy, default to formal address: Pan/Pani for individuals, Państwo for groups. Use formal verb forms (e.g., "Zapraszamy" not "Zapraszam"). Avoid casual ty/wy except in clearly informal startup-style brands. Polish business writing tolerates passive constructions and noun-heavy phrasing more than English.',
    'b2b_terminology' => array(
        'solution'      => 'rozwiązanie',
        'platform'      => 'platforma',
        'workflow'      => 'proces',
        'dashboard'     => 'pulpit',
        'features'      => 'funkcje',
        'integrations'  => 'integracje',
        'pricing'       => 'cennik',
        'get started'   => 'rozpocznij',
        'learn more'    => 'dowiedz się więcej',
        'contact us'    => 'skontaktuj się z nami',
        'sign up'       => 'zarejestruj się',
        'log in'        => 'zaloguj się',
        'case study'    => 'studium przypadku',
        'whitepaper'    => 'raport',
        'enterprise'    => 'dla przedsiębiorstw',
        'small business'=> 'dla małych firm',
    ),
    'nuances' => array(
        'Use Polish quotation marks „…" not English "…".',
        'Capitalize only the first word of headlines and sentences. Never carry over English title case.',
        'Capitalize months and days only at the start of a sentence (not mid-sentence).',
        'Use a non-breaking space after single-letter prepositions (w, z, o, i, a, u) to prevent them from ending a line. In HTML output use &nbsp;.',
        'Decline company-name dependents correctly: "z firmą Acme" (instrumental), not "z firma Acme".',
        'Translate "Learn more" as "Dowiedz się więcej", never "Naucz się więcej".',
        'Translate "Get started" as "Rozpocznij" or "Zacznij" depending on context — never "Otrzymaj rozpoczęty".',
        'Translate "Sign up" as "Zarejestruj się" not "Podpisz się" (which means "sign your name").',
        'Translate "Free trial" as "Bezpłatny okres próbny" or "Darmowy test", not "Wolna próba".',
        'Translate "Free" as "bezpłatny" in formal B2B; "darmowy" is more casual.',
        'Use the gerund-like "-anie/-enie" form for action nouns: "wdrożenie", "uruchomienie", "skalowanie".',
        'Decimal separator is comma: 1,5 mln, not 1.5 mln.',
        'Thousands separator is space: 10 000, not 10,000.',
        'Currency formatting: "1 500 zł" or "1500 PLN", with currency after the number.',
        'Date format is DD.MM.YYYY (e.g., 14.04.2026), not YYYY-MM-DD or MM/DD/YYYY.',
        'Use 24-hour time format (14:30) by default in B2B.',
        'Translate "Click here" as "Kliknij tutaj" but prefer descriptive link text — e.g., "Pobierz raport".',
        'Avoid the calque "biznesowe rozwiązanie"; prefer "rozwiązanie dla firm" or "rozwiązanie biznesowe" (latter is acceptable but second).',
        'Translate "best-in-class" as "najlepsze w swojej klasie" or "wiodące", not literal "najlepsze w klasie".',
        'Translate "scalable" as "skalowalny", never "skalowalne" without checking gender agreement.',
        'Adjectives agree in gender, number, and case with their noun. Double-check agreement after every translation.',
        'Translate "data-driven" as "oparty na danych", not "kierowany danymi".',
        'Translate "stakeholder" as "interesariusz" in formal contexts; "zainteresowana strona" in plain language.',
        'Translate "ROI" as "ROI" (keep abbreviation) or "zwrot z inwestycji"; never expand inconsistently.',
        'Translate "case study" as "studium przypadku"; plural is "studia przypadków".',
        'Translate "whitepaper" as "raport" or "white paper" (italics) — never "biały papier".',
        'Use "Państwa firma" not "twoja firma" in formal sales copy.',
        'Translate "we help X do Y" as "Pomagamy [komu? — dative] [co robić? — infinitive]" — e.g., "Pomagamy firmom rozwijać sprzedaż".',
        'Translate "Trusted by X+ companies" as "Zaufało nam ponad X firm" (note past tense + dative + perfective).',
        'Translate "Powered by" as "Działa w oparciu o" or "Napędzany przez" (latter for tech products).',
        'For SaaS: "subscribe" → "zasubskrybuj"; "subscription" → "subskrypcja"; "billing" → "rozliczenia".',
        'Translate "onboarding" as "wdrożenie" in B2B SaaS; "onboarding" is also accepted.',
    ),
    'avoid' => array(
        'Direct calque "biznesowe X" when "X dla firm" is more natural.',
        '"Naucz się więcej" for "Learn more" (means "study more", not "find out more").',
        'English title case in headlines (Capitalizing Every Word).',
        'Period after every bullet item if the source omits them.',
        'Translating "you" as "ty" in B2B copy aimed at companies — use formal "Państwo" or rewrite impersonally.',
        'Word-for-word translation of "make sure" as "zrób pewny" — use "upewnij się".',
        'Calques like "zrobić sens" for "make sense" — use "mieć sens".',
        'Calques like "wziąć pod uwagę" overused — sometimes "uwzględnić" is cleaner.',
    ),
    'examples' => array(
        array( 'en' => 'Get started today.',                'translation' => 'Zacznij już dziś.' ),
        array( 'en' => 'Trusted by 500+ companies.',        'translation' => 'Zaufało nam ponad 500 firm.' ),
        array( 'en' => 'Book a demo.',                      'translation' => 'Umów demo.' ),
        array( 'en' => 'No credit card required.',          'translation' => 'Bez podawania karty płatniczej.' ),
        array( 'en' => 'Cancel anytime.',                   'translation' => 'Anuluj w dowolnym momencie.' ),
        array( 'en' => 'Built for growing teams.',          'translation' => 'Stworzone z myślą o rozwijających się zespołach.' ),
        array( 'en' => 'Save up to 40% with annual billing.', 'translation' => 'Oszczędź do 40% przy rozliczeniu rocznym.' ),
    ),
);
```

- [ ] **Step 2: Smoke-load**

```bash
cd plugins/ac-wp-translator && php -r "\$p = require __DIR__.'/includes/prompts/lang/pl.php'; echo (is_array(\$p) && \$p['name']==='Polish') ? \"ok\n\" : \"bad\n\";"
```
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/prompts/lang/pl.php
git commit -m "feat(ac-wp-translator): add Polish nuance pack (B2B, formal register)"
```

---

### Task 8: Spanish language pack

**Files:**
- Create: `plugins/ac-wp-translator/includes/prompts/lang/es.php`

- [ ] **Step 1: Create the file**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

return array(
    'name' => 'Spanish',
    'register' => 'For broad B2B reach, default to neutral international Spanish (no strong regionalisms). Use formal "usted/ustedes" rather than "tú/vosotros". Spain prefers "vosotros"; Latin America prefers "ustedes" — defaulting to ustedes works in both. Business writing in Spanish is more formal than English; avoid contractions of address.',
    'b2b_terminology' => array(
        'solution'     => 'solución',
        'platform'     => 'plataforma',
        'workflow'     => 'flujo de trabajo',
        'dashboard'    => 'panel',
        'features'     => 'funciones',
        'pricing'      => 'precios',
        'get started'  => 'empezar',
        'learn more'   => 'más información',
        'contact us'   => 'contáctenos',
        'sign up'      => 'regístrese',
        'log in'       => 'inicie sesión',
        'case study'   => 'caso de éxito',
        'whitepaper'   => 'informe',
        'enterprise'   => 'empresarial',
        'small business' => 'pequeñas empresas',
        'free trial'   => 'prueba gratuita',
    ),
    'nuances' => array(
        'Use ¿ and ¡ to open questions and exclamations: "¿Listo para empezar?"',
        'Spanish uses sentence case in headlines, not title case.',
        'Capitalize only the first word and proper nouns in titles. Days, months, and nationalities are lowercase.',
        'Decimal separator is comma in Spain ("1,5 millones") and most of Latin America; period is also accepted in Mexico and parts of LatAm. Default to comma for international reach.',
        'Thousands separator is period or space: "1.500" or "1 500".',
        'Date format is DD/MM/YYYY (14/04/2026), not MM/DD/YYYY.',
        'Use 24-hour time format in B2B contexts.',
        'Currency placement: "1.500 €" with space, or "USD 1,500" — depends on currency.',
        'Translate "Learn more" as "Más información" (preferred for buttons) or "Saber más"; never "Aprende más" in B2B.',
        'Translate "Get started" as "Empezar" or "Comenzar"; never "Conseguir empezado".',
        'Translate "Sign up" as "Registrarse" or "Crear cuenta"; never "Firmar arriba".',
        'Translate "Sign in" / "Log in" as "Iniciar sesión"; never "Firmar en".',
        'Translate "Free trial" as "Prueba gratuita" or "Prueba gratis", never "Juicio libre".',
        'Translate "Click here" as "Haga clic aquí" (formal) — but prefer descriptive link text.',
        'Translate "Book a demo" as "Solicitar una demostración" or "Reservar una demo".',
        'Translate "Contact us" as "Contáctenos" (formal) or "Contáctanos" (informal); for B2B use formal.',
        'Translate "you" as "usted" (singular formal) or "ustedes" (plural) in B2B — never "tú/vosotros" by default.',
        'Verbs after usted/ustedes use third-person forms: "Descubra cómo..." not "Descubre cómo...".',
        'Translate "free" as "gratuito" (formal/written) or "gratis" (informal/marketing); both are valid.',
        'Translate "data-driven" as "basado en datos" or "impulsado por datos".',
        'Translate "scalable" as "escalable".',
        'Translate "ROI" as "ROI" or "retorno de la inversión"; consistency matters.',
        'Translate "stakeholder" as "parte interesada" or keep "stakeholder" in tech-savvy B2B.',
        'Translate "we help X do Y" as "Ayudamos a X a hacer Y" — note the double "a".',
        'Translate "Trusted by X+ companies" as "Más de X empresas confían en nosotros".',
        'Translate "Powered by" as "Con tecnología de" or "Impulsado por".',
        'Translate "onboarding" as "incorporación" or keep "onboarding" in SaaS.',
        'Translate "subscription" as "suscripción"; "billing" as "facturación".',
        'Avoid the false friend "actualmente" (= currently, not actually). "Actually" → "en realidad".',
        'Avoid "asistir" for "assist" (means "attend"). Use "ayudar" for "help/assist".',
        'Avoid "eventualmente" for "eventually" (means "occasionally"). Use "con el tiempo" or "a la larga".',
        'Translate "support" as "asistencia" (help service) or "soporte" (technical) — context-dependent.',
    ),
    'avoid' => array(
        'English title case in headlines.',
        'Translating "you" as "tú" in business communication.',
        'Calques like "tomar acción" for "take action" — use "actuar" or "tomar medidas".',
        'Calques like "hacer sentido" for "make sense" — use "tener sentido".',
        'False friends: "actualmente" (currently) vs "en realidad" (actually).',
        'Overuse of "obtener" — sometimes "conseguir" or "lograr" is more natural.',
        'Period at the end of headlines and CTAs (Spanish typography omits them).',
    ),
    'examples' => array(
        array( 'en' => 'Get started today.',                'translation' => 'Empiece hoy mismo' ),
        array( 'en' => 'Trusted by 500+ companies.',        'translation' => 'Más de 500 empresas confían en nosotros' ),
        array( 'en' => 'Book a demo.',                      'translation' => 'Solicite una demostración' ),
        array( 'en' => 'No credit card required.',          'translation' => 'No se requiere tarjeta de crédito' ),
        array( 'en' => 'Cancel anytime.',                   'translation' => 'Cancele cuando quiera' ),
        array( 'en' => 'Built for growing teams.',          'translation' => 'Diseñado para equipos en crecimiento' ),
        array( 'en' => 'Save up to 40% with annual billing.', 'translation' => 'Ahorre hasta un 40 % con facturación anual' ),
    ),
);
```

- [ ] **Step 2: Smoke-load**

```bash
cd plugins/ac-wp-translator && php -r "\$p = require __DIR__.'/includes/prompts/lang/es.php'; echo (is_array(\$p) && \$p['name']==='Spanish') ? \"ok\n\" : \"bad\n\";"
```
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/prompts/lang/es.php
git commit -m "feat(ac-wp-translator): add Spanish nuance pack (neutral international, formal usted)"
```

---

### Task 9: French language pack

**Files:**
- Create: `plugins/ac-wp-translator/includes/prompts/lang/fr.php`

- [ ] **Step 1: Create the file**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

return array(
    'name' => 'French',
    'register' => 'B2B French defaults to formal "vous" address. French business writing is more formal and structured than English; sentences can be longer. Avoid Anglicisms when a natural French equivalent exists, but established tech terms (cloud, API, dashboard) are acceptable.',
    'b2b_terminology' => array(
        'solution'     => 'solution',
        'platform'     => 'plateforme',
        'workflow'     => 'flux de travail',
        'dashboard'    => 'tableau de bord',
        'features'     => 'fonctionnalités',
        'pricing'      => 'tarifs',
        'get started'  => 'commencer',
        'learn more'   => 'en savoir plus',
        'contact us'   => 'nous contacter',
        'sign up'      => 's\'inscrire',
        'log in'       => 'se connecter',
        'case study'   => 'étude de cas',
        'whitepaper'   => 'livre blanc',
        'enterprise'   => 'grandes entreprises',
        'small business' => 'PME',
        'free trial'   => 'essai gratuit',
    ),
    'nuances' => array(
        'Use a non-breaking space (&nbsp; in HTML) before : ; ! ? » and after «. This is a hard typography rule in French.',
        'Use French guillemets « … » with non-breaking spaces inside, not English "…".',
        'Sentence case in headlines and titles — capitalize only the first word and proper nouns.',
        'Months and days are lowercase (janvier, lundi).',
        'Decimal separator is comma: 1,5 million.',
        'Thousands separator is non-breaking space: 10&nbsp;000.',
        'Currency: "1 500 €" with non-breaking space and € after the number.',
        'Date format is DD/MM/YYYY (14/04/2026).',
        'Use 24-hour time format with "h" separator: 14h30.',
        'Translate "Learn more" as "En savoir plus" (the standard CTA), never "Apprendre plus".',
        'Translate "Get started" as "Commencer" or "Démarrer"; in CTAs "Commencer" is most common.',
        'Translate "Sign up" as "S\'inscrire" or "Créer un compte".',
        'Translate "Sign in" / "Log in" as "Se connecter" or "Connexion".',
        'Translate "Free trial" as "Essai gratuit"; "Try for free" as "Essayer gratuitement".',
        'Translate "Click here" as "Cliquez ici" — but prefer descriptive link text.',
        'Translate "Book a demo" as "Demander une démo" or "Réserver une démo".',
        'Translate "Contact us" as "Nous contacter" (button) or "Contactez-nous" (sentence).',
        'Translate "you" as "vous" in B2B; never "tu".',
        'Translate "free" as "gratuit"; "for free" as "gratuitement".',
        'Translate "data-driven" as "axé sur les données" or "guidé par les données".',
        'Translate "scalable" as "évolutif" — "scalable" is increasingly accepted in tech.',
        'Translate "ROI" as "ROI" or "retour sur investissement".',
        'Translate "stakeholder" as "partie prenante".',
        'Translate "case study" as "étude de cas" (singular); plural "études de cas".',
        'Translate "whitepaper" as "livre blanc"; never "papier blanc".',
        'Translate "we help X do Y" as "Nous aidons X à faire Y" — note the "à".',
        'Translate "Trusted by X+ companies" as "Plus de X entreprises nous font confiance".',
        'Translate "Powered by" as "Propulsé par" or "Avec la technologie de".',
        'Translate "onboarding" as "intégration" or keep "onboarding" in SaaS.',
        'Translate "subscription" as "abonnement"; "billing" as "facturation".',
        'Capitalize only the first letter of acronyms when used as words: "PME" stays uppercase.',
        'Use "afin de + infinitive" or "pour + infinitive" — both are valid; "pour" is less formal.',
        'French numbers: "vingt et un" (21), "quatre-vingts" (80), "quatre-vingt-dix" (90) — be careful with hyphenation and "et".',
    ),
    'avoid' => array(
        'English title case in headlines.',
        'Forgetting non-breaking spaces before : ; ! ? — this looks unprofessional in French.',
        'Translating "you" as "tu" in B2B contexts.',
        'Calques like "faire sens" for "make sense" — use "avoir du sens" (though "faire sens" is creeping into modern French).',
        'Anglicisms when natural French exists: "checker" → "vérifier", "process" → "processus".',
        'Period at the end of headlines and CTAs.',
        'Overuse of "supporter" for "support" — means "tolerate". Use "prendre en charge" or "assurer le support".',
    ),
    'examples' => array(
        array( 'en' => 'Get started today.',                'translation' => 'Commencez dès aujourd\'hui' ),
        array( 'en' => 'Trusted by 500+ companies.',        'translation' => 'Plus de 500 entreprises nous font confiance' ),
        array( 'en' => 'Book a demo.',                      'translation' => 'Demandez une démo' ),
        array( 'en' => 'No credit card required.',          'translation' => 'Sans carte bancaire' ),
        array( 'en' => 'Cancel anytime.',                   'translation' => 'Annulez à tout moment' ),
        array( 'en' => 'Built for growing teams.',          'translation' => 'Conçu pour les équipes en pleine croissance' ),
        array( 'en' => 'Save up to 40% with annual billing.', 'translation' => 'Économisez jusqu\'à 40&nbsp;% avec la facturation annuelle' ),
    ),
);
```

- [ ] **Step 2: Smoke-load**

```bash
cd plugins/ac-wp-translator && php -r "\$p = require __DIR__.'/includes/prompts/lang/fr.php'; echo (is_array(\$p) && \$p['name']==='French') ? \"ok\n\" : \"bad\n\";"
```
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/prompts/lang/fr.php
git commit -m "feat(ac-wp-translator): add French nuance pack (formal vous, NBSP typography)"
```

---

### Task 10: German language pack

**Files:**
- Create: `plugins/ac-wp-translator/includes/prompts/lang/de.php`

- [ ] **Step 1: Create the file**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

return array(
    'name' => 'German',
    'register' => 'B2B German defaults to formal "Sie" address (capitalized when addressing the reader). German business writing is precise and structured; nouns are capitalized; compound nouns are written together. Avoid Anglicisms when a clear German equivalent exists, but tech terms (Software, Cloud, Dashboard) are universal.',
    'b2b_terminology' => array(
        'solution'     => 'Lösung',
        'platform'     => 'Plattform',
        'workflow'     => 'Workflow',
        'dashboard'    => 'Dashboard',
        'features'     => 'Funktionen',
        'pricing'      => 'Preise',
        'get started'  => 'Loslegen',
        'learn more'   => 'Mehr erfahren',
        'contact us'   => 'Kontaktieren Sie uns',
        'sign up'      => 'Registrieren',
        'log in'       => 'Anmelden',
        'case study'   => 'Fallstudie',
        'whitepaper'   => 'Whitepaper',
        'enterprise'   => 'Unternehmen',
        'small business' => 'KMU',
        'free trial'   => 'Kostenlose Testversion',
    ),
    'nuances' => array(
        'ALL nouns are capitalized in German (das Unternehmen, die Lösung, der Kunde).',
        'Use formal "Sie" address; capitalize "Sie", "Ihr", "Ihnen" when addressing the reader.',
        'Use sentence case in headlines (only the first word + nouns + proper nouns capitalized — but since all nouns are capitalized, this still means many capitals).',
        'Use German quotation marks „…" (low-high), not English "…".',
        'Decimal separator is comma: 1,5 Millionen.',
        'Thousands separator is period: 10.000 (or non-breaking space in modern style).',
        'Currency: "1.500 €" with € after the number; "EUR 1.500" in formal documents.',
        'Date format is DD.MM.YYYY (14.04.2026).',
        'Use 24-hour time format with period or colon: "14:30 Uhr" or "14.30 Uhr".',
        'Compound nouns are written as one word: "Unternehmenssoftware", "Kundenerfolgsmanager", "Datenschutzerklärung".',
        'Use ß (eszett) where appropriate: "groß", "Straße". Switzerland uses "ss" instead.',
        'Use ä, ö, ü — never "ae", "oe", "ue" in normal writing (only in URLs/file names).',
        'Translate "Learn more" as "Mehr erfahren", never "Mehr lernen".',
        'Translate "Get started" as "Loslegen" or "Jetzt starten"; never "Bekomme begonnen".',
        'Translate "Sign up" as "Registrieren" or "Konto erstellen".',
        'Translate "Sign in" / "Log in" as "Anmelden"; "Sign out" / "Log out" as "Abmelden".',
        'Translate "Free trial" as "Kostenlose Testversion" or "Kostenlos testen".',
        'Translate "Click here" as "Hier klicken" — but prefer descriptive link text.',
        'Translate "Book a demo" as "Demo buchen" or "Demo vereinbaren".',
        'Translate "Contact us" as "Kontaktieren Sie uns" or "Kontakt aufnehmen".',
        'Translate "free" as "kostenlos" (no charge) or "gratis" (informal); avoid "frei" (means "available/empty").',
        'Translate "data-driven" as "datengestützt" or "datengetrieben".',
        'Translate "scalable" as "skalierbar".',
        'Translate "ROI" as "ROI" or "Kapitalrendite".',
        'Translate "stakeholder" as "Stakeholder" (commonly kept in business German) or "Interessengruppe".',
        'Translate "case study" as "Fallstudie" or "Anwendungsbeispiel".',
        'Translate "whitepaper" as "Whitepaper" (commonly kept) or "Fachbericht".',
        'Translate "we help X do Y" as "Wir helfen X dabei, Y zu tun" — note "dabei" + "zu" + infinitive.',
        'Translate "Trusted by X+ companies" as "Über X Unternehmen vertrauen uns".',
        'Translate "Powered by" as "Bereitgestellt von" or "Mit Technologie von".',
        'Translate "onboarding" as "Einarbeitung" or "Onboarding" (kept in SaaS).',
        'Translate "subscription" as "Abonnement"; "billing" as "Abrechnung".',
        'Verbs go to the end in subordinate clauses introduced by weil/dass/wenn — preserve correct word order.',
        'Use the genitive case where standard German requires it ("aufgrund des Berichts"), not dative ("aufgrund dem Bericht").',
        'Avoid the false friend "Bekommen" for "become" (means "receive"). "Become" → "werden".',
    ),
    'avoid' => array(
        'Lowercase nouns (a constant German error in machine translation).',
        'English title case in headlines.',
        'Du-form in B2B copy.',
        'Translating "actually" as "aktuell" (means "currently"). Use "tatsächlich" or "eigentlich".',
        'Translating "eventually" as "eventuell" (means "possibly"). Use "schließlich" or "irgendwann".',
        'Calques like "Sinn machen" for "make sense" — historically incorrect; "Sinn ergeben" is preferred. (Sinn machen has crept into modern usage but is still flagged in formal writing.)',
        'Splitting compound nouns with spaces: "Daten Schutz" instead of "Datenschutz".',
        'Forgetting umlauts.',
        'Period at end of headlines and CTAs.',
    ),
    'examples' => array(
        array( 'en' => 'Get started today.',                'translation' => 'Starten Sie noch heute' ),
        array( 'en' => 'Trusted by 500+ companies.',        'translation' => 'Über 500 Unternehmen vertrauen uns' ),
        array( 'en' => 'Book a demo.',                      'translation' => 'Demo vereinbaren' ),
        array( 'en' => 'No credit card required.',          'translation' => 'Keine Kreditkarte erforderlich' ),
        array( 'en' => 'Cancel anytime.',                   'translation' => 'Jederzeit kündbar' ),
        array( 'en' => 'Built for growing teams.',          'translation' => 'Gemacht für wachsende Teams' ),
        array( 'en' => 'Save up to 40% with annual billing.', 'translation' => 'Sparen Sie bis zu 40 % mit jährlicher Abrechnung' ),
    ),
);
```

- [ ] **Step 2: Smoke-load**

```bash
cd plugins/ac-wp-translator && php -r "\$p = require __DIR__.'/includes/prompts/lang/de.php'; echo (is_array(\$p) && \$p['name']==='German') ? \"ok\n\" : \"bad\n\";"
```
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/prompts/lang/de.php
git commit -m "feat(ac-wp-translator): add German nuance pack (formal Sie, capitalized nouns)"
```

---

### Task 11: Portuguese (Brazilian-leaning) language pack

**Files:**
- Create: `plugins/ac-wp-translator/includes/prompts/lang/pt.php`

- [ ] **Step 1: Create the file**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

return array(
    'name' => 'Portuguese (Brazilian-leaning for B2B)',
    'register' => 'Default to Brazilian Portuguese for broadest B2B reach (Brazil is the largest market). Use formal "você" for general B2B; reserve "o senhor / a senhora" for very formal financial or legal contexts. Brazilian Portuguese is more accepting of English loanwords in tech than European Portuguese.',
    'b2b_terminology' => array(
        'solution'     => 'solução',
        'platform'     => 'plataforma',
        'workflow'     => 'fluxo de trabalho',
        'dashboard'    => 'painel',
        'features'     => 'recursos',
        'pricing'      => 'preços',
        'get started'  => 'comece',
        'learn more'   => 'saiba mais',
        'contact us'   => 'fale conosco',
        'sign up'      => 'cadastre-se',
        'log in'       => 'entrar',
        'case study'   => 'estudo de caso',
        'whitepaper'   => 'whitepaper',
        'enterprise'   => 'empresarial',
        'small business' => 'pequenas empresas',
        'free trial'   => 'teste grátis',
    ),
    'nuances' => array(
        'Use sentence case in headlines, not title case.',
        'Months and days are lowercase (janeiro, segunda-feira).',
        'Decimal separator is comma: 1,5 milhões.',
        'Thousands separator is period: 10.000.',
        'Currency: "R$ 1.500,00" with R$ before the number; "USD 1,500" or "US$ 1.500".',
        'Date format is DD/MM/YYYY (14/04/2026).',
        'Use 24-hour time format: 14h30 or 14:30.',
        'Translate "Learn more" as "Saiba mais", never "Aprenda mais" in marketing.',
        'Translate "Get started" as "Comece" or "Comece agora", never "Pegue iniciado".',
        'Translate "Sign up" as "Cadastre-se" or "Crie sua conta".',
        'Translate "Sign in" / "Log in" as "Entrar" or "Acessar"; "Sign out" / "Log out" as "Sair".',
        'Translate "Free trial" as "Teste grátis" or "Teste gratuito"; "Try for free" as "Experimente grátis".',
        'Translate "Click here" as "Clique aqui" — but prefer descriptive link text.',
        'Translate "Book a demo" as "Agende uma demonstração" or "Solicite uma demo".',
        'Translate "Contact us" as "Fale conosco" (Brazil) or "Contate-nos" (more formal).',
        'Translate "you" as "você" in B2B; "vocês" plural; reserve "o senhor / a senhora" for very formal contexts.',
        'Translate "free" as "grátis" or "gratuito"; "free trial" almost always "teste grátis".',
        'Translate "data-driven" as "orientado por dados" or "baseado em dados".',
        'Translate "scalable" as "escalável".',
        'Translate "ROI" as "ROI" or "retorno sobre investimento".',
        'Translate "stakeholder" as "stakeholder" (kept) or "parte interessada".',
        'Translate "case study" as "estudo de caso"; plural "estudos de caso".',
        'Translate "whitepaper" as "whitepaper" (kept in tech B2B).',
        'Translate "we help X do Y" as "Ajudamos X a fazer Y" — note the "a".',
        'Translate "Trusted by X+ companies" as "Mais de X empresas confiam em nós".',
        'Translate "Powered by" as "Desenvolvido com" or "Tecnologia da".',
        'Translate "onboarding" as "onboarding" (kept) or "integração".',
        'Translate "subscription" as "assinatura"; "billing" as "cobrança" or "faturamento".',
        'Use enclitic pronouns naturally: "faça-o" not "faça ele" in formal writing; "ele" is more colloquial.',
        'Use "obrigado" if speaker is male, "obrigada" if female — for company-voiced content prefer neutral phrasings ("agradecemos").',
        'Avoid the false friend "atualmente" (= currently, not actually). "Actually" → "na verdade".',
        'Avoid "pretender" as "pretend" (means "intend"). "Pretend" → "fingir".',
    ),
    'avoid' => array(
        'European Portuguese spellings when targeting Brazil ("ação" not "acção"; "ótimo" not "óptimo").',
        'English title case in headlines.',
        'Calques like "fazer sentido" — actually "fazer sentido" IS standard in PT-BR; it\'s "tirar sentido" or other oddities to avoid.',
        'Period at end of headlines and CTAs.',
        'Translating "support" as "suportar" (means "endure"). Use "oferecer suporte" or "dar suporte".',
        'Translating "library" as "livraria" (means "bookstore"). Use "biblioteca".',
        'Overuse of formal "o senhor / a senhora" in modern B2B SaaS — feels stiff.',
    ),
    'examples' => array(
        array( 'en' => 'Get started today.',                'translation' => 'Comece hoje mesmo' ),
        array( 'en' => 'Trusted by 500+ companies.',        'translation' => 'Mais de 500 empresas confiam em nós' ),
        array( 'en' => 'Book a demo.',                      'translation' => 'Agende uma demonstração' ),
        array( 'en' => 'No credit card required.',          'translation' => 'Sem cartão de crédito' ),
        array( 'en' => 'Cancel anytime.',                   'translation' => 'Cancele quando quiser' ),
        array( 'en' => 'Built for growing teams.',          'translation' => 'Feito para times em crescimento' ),
        array( 'en' => 'Save up to 40% with annual billing.', 'translation' => 'Economize até 40% no plano anual' ),
    ),
);
```

- [ ] **Step 2: Smoke-load**

```bash
cd plugins/ac-wp-translator && php -r "\$p = require __DIR__.'/includes/prompts/lang/pt.php'; echo (is_array(\$p) && strpos(\$p['name'],'Portuguese')!==false) ? \"ok\n\" : \"bad\n\";"
```
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/prompts/lang/pt.php
git commit -m "feat(ac-wp-translator): add Portuguese (Brazilian-leaning) nuance pack"
```

---

### Task 12: Italian language pack

**Files:**
- Create: `plugins/ac-wp-translator/includes/prompts/lang/it.php`

- [ ] **Step 1: Create the file**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

return array(
    'name' => 'Italian',
    'register' => 'B2B Italian defaults to formal "Lei" address (capitalized when referring to the reader). Italian business writing is more formal and verbose than English; rhetorical structure is valued. Avoid Anglicisms when natural Italian exists, but standard tech terms (software, cloud, dashboard, performance) are widely accepted.',
    'b2b_terminology' => array(
        'solution'     => 'soluzione',
        'platform'     => 'piattaforma',
        'workflow'     => 'flusso di lavoro',
        'dashboard'    => 'dashboard',
        'features'     => 'funzionalità',
        'pricing'      => 'prezzi',
        'get started'  => 'inizia',
        'learn more'   => 'scopri di più',
        'contact us'   => 'contattaci',
        'sign up'      => 'registrati',
        'log in'       => 'accedi',
        'case study'   => 'caso di studio',
        'whitepaper'   => 'whitepaper',
        'enterprise'   => 'aziende',
        'small business' => 'piccole imprese',
        'free trial'   => 'prova gratuita',
    ),
    'nuances' => array(
        'Use formal "Lei" with capital L when addressing the reader; verbs follow third-person singular form ("Scopra come..." not "Scopri come...").',
        'Use sentence case in headlines, not title case.',
        'Months and days are lowercase (gennaio, lunedì).',
        'Decimal separator is comma: 1,5 milioni.',
        'Thousands separator is period: 10.000.',
        'Currency: "1.500 €" with € after the number; sometimes "€ 1.500".',
        'Date format is DD/MM/YYYY (14/04/2026).',
        'Use 24-hour time format: 14:30 or 14.30.',
        'Translate "Learn more" as "Scopri di più" (informal CTA) or "Per saperne di più" (formal); for Lei: "Scopra di più".',
        'Translate "Get started" as "Inizia" / "Inizia ora"; for Lei: "Inizi ora".',
        'Translate "Sign up" as "Registrati" / "Crea un account"; for Lei: "Si registri".',
        'Translate "Sign in" / "Log in" as "Accedi"; for Lei: "Acceda".',
        'Translate "Free trial" as "Prova gratuita"; "Try for free" as "Provalo gratis".',
        'Translate "Click here" as "Clicca qui" — but prefer descriptive link text.',
        'Translate "Book a demo" as "Richiedi una demo" or "Prenota una demo".',
        'Translate "Contact us" as "Contattaci" (informal) or "Ci contatti" (formal Lei).',
        'Translate "you" as "Lei" (singular formal) in B2B; "voi" for plural; never "tu" by default.',
        'Translate "free" as "gratuito" (formal) or "gratis" (informal/marketing).',
        'Translate "data-driven" as "basato sui dati" or "guidato dai dati".',
        'Translate "scalable" as "scalabile".',
        'Translate "ROI" as "ROI" or "ritorno sull\'investimento".',
        'Translate "stakeholder" as "stakeholder" (kept) or "parte interessata".',
        'Translate "case study" as "caso di studio"; plural "casi di studio".',
        'Translate "whitepaper" as "whitepaper" (kept in tech B2B).',
        'Translate "we help X do Y" as "Aiutiamo X a fare Y" — note the "a".',
        'Translate "Trusted by X+ companies" as "Più di X aziende si affidano a noi".',
        'Translate "Powered by" as "Realizzato con" or "Tecnologia di".',
        'Translate "onboarding" as "onboarding" (kept) or "inserimento".',
        'Translate "subscription" as "abbonamento"; "billing" as "fatturazione".',
        'Articulated prepositions are mandatory: "del", "nella", "sui", etc. — never "di il", "in la", "su i".',
        'Adjectives agree in gender and number with the noun.',
        'Avoid the false friend "eventualmente" (= possibly, not eventually). "Eventually" → "alla fine" or "col tempo".',
        'Avoid "attualmente" for "actually" (means "currently"). Use "in realtà".',
        'Avoid "fattoria" for "factory" (means "farm"). Use "fabbrica" or "stabilimento".',
    ),
    'avoid' => array(
        'English title case in headlines.',
        'Translating "you" as "tu" in B2B contexts.',
        'Calques like "fare senso" for "make sense" — use "avere senso".',
        'Period at end of headlines and CTAs.',
        'False friends: "eventualmente" (possibly) vs "alla fine" (eventually).',
        'Direct calques like "preso in considerazione" overused — sometimes "valutare" is cleaner.',
    ),
    'examples' => array(
        array( 'en' => 'Get started today.',                'translation' => 'Inizia subito' ),
        array( 'en' => 'Trusted by 500+ companies.',        'translation' => 'Più di 500 aziende si affidano a noi' ),
        array( 'en' => 'Book a demo.',                      'translation' => 'Richiedi una demo' ),
        array( 'en' => 'No credit card required.',          'translation' => 'Nessuna carta di credito richiesta' ),
        array( 'en' => 'Cancel anytime.',                   'translation' => 'Disdici quando vuoi' ),
        array( 'en' => 'Built for growing teams.',          'translation' => 'Pensato per i team in crescita' ),
        array( 'en' => 'Save up to 40% with annual billing.', 'translation' => 'Risparmia fino al 40% con la fatturazione annuale' ),
    ),
);
```

- [ ] **Step 2: Smoke-load**

```bash
cd plugins/ac-wp-translator && php -r "\$p = require __DIR__.'/includes/prompts/lang/it.php'; echo (is_array(\$p) && \$p['name']==='Italian') ? \"ok\n\" : \"bad\n\";"
```
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/prompts/lang/it.php
git commit -m "feat(ac-wp-translator): add Italian nuance pack (formal Lei)"
```

---

### Task 13: Japanese language pack

**Files:**
- Create: `plugins/ac-wp-translator/includes/prompts/lang/ja.php`

- [ ] **Step 1: Create the file**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

return array(
    'name' => 'Japanese',
    'register' => 'B2B Japanese defaults to formal polite forms — desu/masu (です/ます) for sentences, with honorific prefixes (お/ご) on key nouns where appropriate. Avoid keigo (尊敬語/謙譲語) extremes for general SaaS/marketing copy; reserve them for sales letters and direct customer correspondence. Use katakana for foreign tech terms (クラウド, ダッシュボード, プラットフォーム).',
    'b2b_terminology' => array(
        'solution'     => 'ソリューション',
        'platform'     => 'プラットフォーム',
        'workflow'     => 'ワークフロー',
        'dashboard'    => 'ダッシュボード',
        'features'     => '機能',
        'pricing'      => '料金',
        'get started'  => '始める',
        'learn more'   => '詳細を見る',
        'contact us'   => 'お問い合わせ',
        'sign up'      => '新規登録',
        'log in'       => 'ログイン',
        'case study'   => '導入事例',
        'whitepaper'   => 'ホワイトペーパー',
        'enterprise'   => '大企業向け',
        'small business' => '中小企業向け',
        'free trial'   => '無料トライアル',
    ),
    'nuances' => array(
        'Use desu/masu (です/ます) polite form throughout B2B copy. Avoid plain form (だ/する).',
        'Use Japanese full-width punctuation: 。 (period), 、 (comma), 「」 (quotes). Never use English . , " ‘ in Japanese sentences.',
        'No spaces between words in Japanese — but a half-width space is acceptable around foreign words / numbers / English brand names for readability.',
        'Numbers in B2B copy use Western digits (1,500) when in numerical context; use 万 (10,000) and 億 (100M) units when culturally appropriate ("売上1億円突破" not "売上100,000,000円突破").',
        'Currency: ¥ before the number ("¥1,500") or 円 after ("1,500円"). 円 is more common in Japanese contexts.',
        'Date format is YYYY年MM月DD日 (2026年4月14日) or YYYY/MM/DD.',
        'Use 24-hour time: 14:30 or 14時30分.',
        'Translate "Learn more" as "詳細を見る" or "詳しく見る" or "もっと知る".',
        'Translate "Get started" as "始める" or "今すぐ始める"; never "取得開始".',
        'Translate "Sign up" as "新規登録" or "アカウント作成".',
        'Translate "Sign in" / "Log in" as "ログイン"; "Sign out" / "Log out" as "ログアウト".',
        'Translate "Free trial" as "無料トライアル" or "無料お試し".',
        'Translate "Click here" as "こちらをクリック" — but prefer descriptive link text.',
        'Translate "Book a demo" as "デモを予約" or "デモのご予約".',
        'Translate "Contact us" as "お問い合わせ" (with お honorific).',
        'Use お/ご honorifics on customer-facing nouns: お客様 (customer), ご利用 (use), お申し込み (application), お問い合わせ (inquiry).',
        'Translate "you" as "お客様" or rewrite to omit subject (Japanese drops subjects when context is clear).',
        'Translate "we" as "弊社" (humble, our company) or "当社" (our company, neutral). 弊社 is more formal in customer correspondence.',
        'Translate "free" as "無料"; "for free" as "無料で".',
        'Translate "data-driven" as "データドリブン" (katakana) or "データに基づく".',
        'Translate "scalable" as "スケーラブル" or "拡張可能な".',
        'Translate "ROI" as "ROI" or "投資対効果".',
        'Translate "stakeholder" as "ステークホルダー" or "関係者".',
        'Translate "case study" as "導入事例" (preferred in B2B SaaS) or "ケーススタディ".',
        'Translate "whitepaper" as "ホワイトペーパー" (commonly kept) or "技術資料".',
        'Translate "we help X do Y" as "弊社はXがYするのをサポートします" or rewrite as "Xの〜を支援します".',
        'Translate "Trusted by X+ companies" as "X社以上にご利用いただいています" or "X社以上の導入実績".',
        'Translate "Powered by" as "提供元" or "技術提供".',
        'Translate "onboarding" as "オンボーディング" (kept) or "導入支援".',
        'Translate "subscription" as "サブスクリプション" or "定期購読"; "billing" as "請求".',
        'Use ・ (middle dot) to separate items in lists or compound foreign names: "Acme・Inc."',
        'Question marks (？) and exclamation marks (！) are used in casual / marketing contexts but rarely in formal business writing — prefer 。 ending.',
        'Be careful with passive voice — Japanese passives can imply suffering (受身); use active or causative forms in marketing copy.',
        'Use long-form forms in CTAs sparingly; short noun phrases (体言止め) often work better in headlines: "業界最高水準のセキュリティ" beats "業界最高水準のセキュリティを提供します" for a hero headline.',
    ),
    'avoid' => array(
        'English punctuation in Japanese sentences (use 。、「」 not . , "").',
        'Spaces between Japanese characters.',
        'Plain form (だ/する) in B2B copy.',
        'Over-formal keigo for general marketing (sounds stilted).',
        'Direct katakana transliteration when natural Japanese exists ("ジョブ" for "job" when "業務" or "仕事" fits better).',
        'Period at the end of CTAs and headlines.',
        'Translating "you" literally as "あなた" — feels distant and Western. Prefer "お客様" or omit.',
    ),
    'examples' => array(
        array( 'en' => 'Get started today.',                'translation' => '今すぐ始める' ),
        array( 'en' => 'Trusted by 500+ companies.',        'translation' => '500社以上にご利用いただいています' ),
        array( 'en' => 'Book a demo.',                      'translation' => 'デモを予約' ),
        array( 'en' => 'No credit card required.',          'translation' => 'クレジットカード不要' ),
        array( 'en' => 'Cancel anytime.',                   'translation' => 'いつでもキャンセル可能' ),
        array( 'en' => 'Built for growing teams.',          'translation' => '成長中のチームのために' ),
        array( 'en' => 'Save up to 40% with annual billing.', 'translation' => '年払いで最大40%お得' ),
    ),
);
```

- [ ] **Step 2: Smoke-load**

```bash
cd plugins/ac-wp-translator && php -r "\$p = require __DIR__.'/includes/prompts/lang/ja.php'; echo (is_array(\$p) && \$p['name']==='Japanese') ? \"ok\n\" : \"bad\n\";"
```
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/prompts/lang/ja.php
git commit -m "feat(ac-wp-translator): add Japanese nuance pack (desu/masu, full-width punctuation)"
```

---

### Task 14: Simplified Chinese language pack

**Files:**
- Create: `plugins/ac-wp-translator/includes/prompts/lang/zh.php`

- [ ] **Step 1: Create the file**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

return array(
    'name' => 'Simplified Chinese',
    'register' => 'B2B Simplified Chinese is direct and concise. Use formal "您" for the reader (instead of "你"). Avoid overly literary classical phrasings; modern professional tone preferred. Tech terms are often a mix of translated terms (云 for cloud, 平台 for platform) and English (API, SaaS, ROI) — keep English for established global terms.',
    'b2b_terminology' => array(
        'solution'     => '解决方案',
        'platform'     => '平台',
        'workflow'     => '工作流程',
        'dashboard'    => '仪表板',
        'features'     => '功能',
        'pricing'      => '价格',
        'get started'  => '立即开始',
        'learn more'   => '了解更多',
        'contact us'   => '联系我们',
        'sign up'      => '注册',
        'log in'       => '登录',
        'case study'   => '案例研究',
        'whitepaper'   => '白皮书',
        'enterprise'   => '企业版',
        'small business' => '中小企业',
        'free trial'   => '免费试用',
    ),
    'nuances' => array(
        'Use Simplified Chinese characters (简体), not Traditional (繁體).',
        'Use formal "您" when addressing the reader in B2B; "你" is too casual.',
        'Use Chinese full-width punctuation: 。 (period), ， (comma), 、 (enumeration comma), ： （ ） " " — never English . , " () in Chinese sentences.',
        'No spaces between Chinese characters; insert a single half-width space between Chinese characters and English/numbers for readability ("使用 API 接口").',
        'Numbers in B2B copy use Western digits (1,500) — Chinese numerals (一千五百) are reserved for formal/literary contexts.',
        'Use 万 (10,000) and 亿 (100M) for large numbers when culturally appropriate ("月活跃用户超 1000 万" not "月活跃用户超 10,000,000").',
        'Currency: ¥ or RMB before the number ("¥1,500" or "RMB 1,500"); 元 after ("1,500 元").',
        'Date format: YYYY年MM月DD日 (2026年4月14日) or YYYY-MM-DD.',
        'Use 24-hour time: 14:30.',
        'Translate "Learn more" as "了解更多" or "查看详情".',
        'Translate "Get started" as "立即开始" or "开始使用".',
        'Translate "Sign up" as "注册" or "免费注册".',
        'Translate "Sign in" / "Log in" as "登录"; "Sign out" / "Log out" as "退出".',
        'Translate "Free trial" as "免费试用" or "免费体验".',
        'Translate "Click here" as "点击这里" — but prefer descriptive link text.',
        'Translate "Book a demo" as "预约演示" or "申请演示".',
        'Translate "Contact us" as "联系我们".',
        'Translate "you" as "您" in B2B; never "你" by default.',
        'Translate "we" as "我们" or "本公司" / "我司" for very formal contexts.',
        'Translate "free" as "免费"; "for free" as "免费".',
        'Translate "data-driven" as "数据驱动" (commonly used) or "以数据为驱动".',
        'Translate "scalable" as "可扩展的" or "可伸缩的".',
        'Translate "ROI" as "ROI" or "投资回报率"; in B2B "ROI" alone is widely understood.',
        'Translate "stakeholder" as "利益相关者" or "干系人".',
        'Translate "case study" as "案例研究" or "成功案例" (preferred in marketing).',
        'Translate "whitepaper" as "白皮书".',
        'Translate "we help X do Y" as "我们帮助 X 实现 Y" or "我们协助 X 做 Y".',
        'Translate "Trusted by X+ companies" as "超过 X 家企业的信赖之选" or "X+ 家企业信赖之选".',
        'Translate "Powered by" as "由 X 提供技术支持" or "技术支持: X".',
        'Translate "onboarding" as "入职" (HR) or "客户引导" / "新手引导" (SaaS).',
        'Translate "subscription" as "订阅"; "billing" as "结算" or "账单".',
        'Use 的 / 地 / 得 correctly: 的 for adjectives modifying nouns (高质量的产品), 地 for adverbs modifying verbs (快速地处理), 得 for verb complements (做得很好).',
        'Avoid double negatives unless intentional — in Chinese they can come across as awkward.',
        'Use 之 for slightly more formal connections (信赖之选, 不二之选) in marketing copy.',
        'Section headers and CTAs typically have no period (。) at the end.',
        'Use 」 「 only in Traditional Chinese — Simplified uses " " or 『 』.',
        'Avoid the calque "做出意义" for "make sense" — use "有道理" or "有意义".',
    ),
    'avoid' => array(
        'Traditional Chinese characters when targeting Mainland China.',
        'English punctuation in Chinese sentences (. , " " () instead of 。 ， " " （ ）).',
        'Casual "你" in formal B2B copy.',
        'Period (。) at the end of headlines and short CTAs.',
        'Direct calques like "做出感觉" for "make sense" — use "有道理".',
        'Western metaphors that don\'t translate ("hit the ground running" — rephrase to "迅速上手").',
        'Over-translation of well-known English tech terms (API, SaaS, ROI, B2B are typically left in English).',
    ),
    'examples' => array(
        array( 'en' => 'Get started today.',                'translation' => '立即开始' ),
        array( 'en' => 'Trusted by 500+ companies.',        'translation' => '超过 500 家企业的信赖之选' ),
        array( 'en' => 'Book a demo.',                      'translation' => '预约演示' ),
        array( 'en' => 'No credit card required.',          'translation' => '无需信用卡' ),
        array( 'en' => 'Cancel anytime.',                   'translation' => '随时可取消' ),
        array( 'en' => 'Built for growing teams.',          'translation' => '为成长中的团队打造' ),
        array( 'en' => 'Save up to 40% with annual billing.', 'translation' => '年付立省 40%' ),
    ),
);
```

- [ ] **Step 2: Smoke-load**

```bash
cd plugins/ac-wp-translator && php -r "\$p = require __DIR__.'/includes/prompts/lang/zh.php'; echo (is_array(\$p) && strpos(\$p['name'],'Chinese')!==false) ? \"ok\n\" : \"bad\n\";"
```
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/prompts/lang/zh.php
git commit -m "feat(ac-wp-translator): add Simplified Chinese nuance pack (formal 您, full-width punctuation)"
```

---

## Task 15: Anthropic API helper + pricing table

Replace OpenAI plumbing in the translator class with a single `call_anthropic()` helper. Also replace the `$pricing` table.

**Files:**
- Modify: `plugins/ac-wp-translator/includes/class-acwpt-translator.php`

- [ ] **Step 1: Replace the `$pricing` property**

Open the file. Replace lines 9–20 (the entire `$pricing` static array including its docblock) with:

```php
    /**
     * Model pricing per token. Verify against Anthropic's published rates
     * before each release (https://www.anthropic.com/pricing).
     * Unknown models fall back to Sonnet pricing.
     */
    private static $pricing = array(
        'claude-haiku-4-5'  => array( 'input' => 0.000001,  'output' => 0.000005 ),
        'claude-sonnet-4-5' => array( 'input' => 0.000003,  'output' => 0.000015 ),
        'claude-sonnet-4-6' => array( 'input' => 0.000003,  'output' => 0.000015 ),
        'claude-opus-4-5'   => array( 'input' => 0.000015,  'output' => 0.000075 ),
        'claude-opus-4-6'   => array( 'input' => 0.000015,  'output' => 0.000075 ),
    );

    private static $default_model = 'claude-haiku-4-5';
```

- [ ] **Step 2: Add `call_anthropic()` helper**

Append immediately before the existing `record_usage()` method (i.e., just before the `// =====` Usage Tracking divider):

```php
    /**
     * Make a Messages API call to Anthropic. Returns array on success,
     * WP_Error on failure. Caller is responsible for prompt assembly and
     * response parsing.
     *
     * @param string $api_key
     * @param string $model
     * @param string $system      Top-level system prompt.
     * @param string $user        User message body.
     * @param int    $max_tokens
     * @param int    $timeout
     * @return array|WP_Error     Decoded response body on success.
     */
    private static function call_anthropic( $api_key, $model, $system, $user, $max_tokens = 8192, $timeout = 30 ) {
        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            array(
                'timeout' => $timeout,
                'headers' => array(
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'model'       => $model,
                        'max_tokens'  => $max_tokens,
                        'temperature' => 0.3,
                        'system'      => $system,
                        'messages'    => array(
                            array( 'role' => 'user', 'content' => $user ),
                        ),
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 ) {
            $msg = isset( $data['error']['message'] ) ? $data['error']['message'] : "HTTP {$code}";
            return new WP_Error( 'anthropic_error', 'Anthropic API error: ' . $msg );
        }

        if ( empty( $data['content'][0]['text'] ) ) {
            return new WP_Error( 'empty_response', 'Anthropic returned an empty response.' );
        }

        return $data;
    }
```

- [ ] **Step 3: Update `record_usage()` to read Anthropic usage shape**

Find `record_usage()`. Replace the body with:

```php
    private static function record_usage( $data, $model, $type ) {
        if ( empty( $data['usage'] ) ) {
            return;
        }

        $input_tokens  = (int) ( $data['usage']['input_tokens']  ?? 0 );
        $output_tokens = (int) ( $data['usage']['output_tokens'] ?? 0 );

        $pricing = isset( self::$pricing[ $model ] ) ? self::$pricing[ $model ] : self::$pricing['claude-sonnet-4-5'];
        $cost    = ( $input_tokens * $pricing['input'] ) + ( $output_tokens * $pricing['output'] );

        $month = gmdate( 'Y-m' );
        $usage = get_option( 'acwpt_usage', array() );

        if ( ! isset( $usage[ $month ] ) ) {
            $usage[ $month ] = array(
                'requests'             => 0,
                'prompt_tokens'        => 0,
                'completion_tokens'    => 0,
                'total_tokens'         => 0,
                'estimated_cost'       => 0.0,
                'content_translations' => 0,
                'string_translations'  => 0,
            );
        }

        $usage[ $month ]['requests']          += 1;
        $usage[ $month ]['prompt_tokens']     += $input_tokens;   // schema kept for back-compat
        $usage[ $month ]['completion_tokens'] += $output_tokens;
        $usage[ $month ]['total_tokens']      += $input_tokens + $output_tokens;
        $usage[ $month ]['estimated_cost']    += $cost;

        if ( $type === 'content' ) {
            $usage[ $month ]['content_translations'] += 1;
        } else {
            $usage[ $month ]['string_translations'] += 1;
        }

        update_option( 'acwpt_usage', $usage, false );
    }
```

(Note: We keep the `prompt_tokens` / `completion_tokens` keys in the usage option for backward compatibility with any UI/reporting that already reads them.)

- [ ] **Step 4: Smoke-check syntax**

```bash
cd plugins/ac-wp-translator && php -l includes/class-acwpt-translator.php
```
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/class-acwpt-translator.php
git commit -m "refactor(ac-wp-translator): add Anthropic API helper, swap pricing table, update usage tracking"
```

---

## Task 16: Rewrite `translate()` for Anthropic + custom instructions

**Files:**
- Modify: `plugins/ac-wp-translator/includes/class-acwpt-translator.php`
- Modify: `plugins/ac-wp-translator/ac-wp-translator.php` (add requires for new classes)

- [ ] **Step 1: Add requires for the new classes**

In `ac-wp-translator.php`, find the block of `require_once` statements (around line 41–46). Add these two lines immediately after `class-acwpt-cache.php` is loaded and before `class-acwpt-translator.php`:

```php
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-glossary.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-prompts.php';
```

- [ ] **Step 2: Replace the `translate()` method**

Open `class-acwpt-translator.php`. Replace the entire `translate()` method (the one starting at the line `public static function translate( $title, $content, $excerpt, $language ) {`) with:

```php
    /**
     * Translate a post's title, content, and excerpt via Anthropic Claude.
     */
    public static function translate( $title, $content, $excerpt, $language ) {
        $settings = get_option( 'acwpt_settings', array() );
        $api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
        $model    = isset( $settings['model'] ) && $settings['model'] !== '' ? $settings['model'] : self::$default_model;

        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'Anthropic API key is not configured.' );
        }

        $custom = array(
            'never_translate' => isset( $settings['never_translate'] ) ? (array) $settings['never_translate'] : array(),
            'glossary'        => isset( $settings['glossary'] )        ? (array) $settings['glossary']        : array(),
        );

        // Apply sentinels to the source text before assembling the user message.
        $glossary_entries = ACWPT_Glossary::entries_for_language( $custom['glossary'], $language );

        $title   = ACWPT_Glossary::apply_keep_sentinels( $title,   $custom['never_translate'] );
        $content = ACWPT_Glossary::apply_keep_sentinels( $content, $custom['never_translate'] );
        $excerpt = ACWPT_Glossary::apply_keep_sentinels( $excerpt, $custom['never_translate'] );

        $title   = ACWPT_Glossary::apply_glossary_sentinels( $title,   $glossary_entries );
        $content = ACWPT_Glossary::apply_glossary_sentinels( $content, $glossary_entries );
        $excerpt = ACWPT_Glossary::apply_glossary_sentinels( $excerpt, $glossary_entries );

        $system_prompt = ACWPT_Prompts::build_content_prompt( $language, $custom );

        $has_excerpt  = ! empty( trim( $excerpt ) );
        $user_message = "===TITLE===\n{$title}\n\n===CONTENT===\n{$content}";
        if ( $has_excerpt ) {
            $user_message .= "\n\n===EXCERPT===\n{$excerpt}";
        }

        $data = self::call_anthropic( $api_key, $model, $system_prompt, $user_message, 16000, 60 );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        self::record_usage( $data, $model, 'content' );

        $translated_text = $data['content'][0]['text'];

        $parsed = self::parse_response( $translated_text, $has_excerpt );

        // Strip glossary wrappers (replace with mandated translation), then strip never-translate wrappers.
        foreach ( array( 'title', 'content', 'excerpt' ) as $k ) {
            $parsed[ $k ] = ACWPT_Glossary::strip_glossary_sentinels( $parsed[ $k ] );
            $parsed[ $k ] = ACWPT_Glossary::strip_keep_sentinels( $parsed[ $k ] );
        }

        return $parsed;
    }
```

- [ ] **Step 3: Smoke-check syntax**

```bash
cd plugins/ac-wp-translator && php -l includes/class-acwpt-translator.php && php -l ac-wp-translator.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/class-acwpt-translator.php ac-wp-translator.php
git commit -m "refactor(ac-wp-translator): rewrite translate() for Claude + sentinel injection"
```

---

## Task 17: Rewrite `translate_strings()` for Anthropic + JSON extractor

**Files:**
- Modify: `plugins/ac-wp-translator/includes/class-acwpt-translator.php`

- [ ] **Step 1: Replace the `translate_strings()` method**

Open `class-acwpt-translator.php`. Replace the entire `translate_strings()` method with:

```php
    /**
     * Batch translate an array of short strings via Anthropic Claude.
     */
    public static function translate_strings( $strings, $language ) {
        $settings = get_option( 'acwpt_settings', array() );
        $api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
        $model    = isset( $settings['model'] ) && $settings['model'] !== '' ? $settings['model'] : self::$default_model;

        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'Anthropic API key is not configured.' );
        }
        if ( empty( $strings ) ) {
            return array();
        }

        $custom = array(
            'never_translate' => isset( $settings['never_translate'] ) ? (array) $settings['never_translate'] : array(),
            'glossary'        => isset( $settings['glossary'] )        ? (array) $settings['glossary']        : array(),
        );
        $glossary_entries = ACWPT_Glossary::entries_for_language( $custom['glossary'], $language );

        $originals = array_values( $strings );
        $indexed   = array();
        foreach ( $originals as $i => $s ) {
            $wrapped = ACWPT_Glossary::apply_keep_sentinels( $s, $custom['never_translate'] );
            $wrapped = ACWPT_Glossary::apply_glossary_sentinels( $wrapped, $glossary_entries );
            $indexed[ (string) $i ] = $wrapped;
        }

        $system_prompt = ACWPT_Prompts::build_strings_prompt( $language, $custom );
        $user_message  = wp_json_encode( $indexed, JSON_UNESCAPED_UNICODE );

        $data = self::call_anthropic( $api_key, $model, $system_prompt, $user_message, 8192, 30 );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        self::record_usage( $data, $model, 'strings' );

        $text       = $data['content'][0]['text'];
        $translated = ACWPT_Glossary::extract_first_json_object( $text );
        if ( ! is_array( $translated ) ) {
            return new WP_Error( 'parse_error', 'Could not parse string translation response.' );
        }

        $result = array();
        foreach ( $originals as $i => $original ) {
            $key = (string) $i;
            $val = isset( $translated[ $key ] ) ? (string) $translated[ $key ] : $original;
            $val = ACWPT_Glossary::strip_glossary_sentinels( $val );
            $val = ACWPT_Glossary::strip_keep_sentinels( $val );
            $result[ $original ] = $val;
        }

        return $result;
    }
```

- [ ] **Step 2: Smoke-check syntax**

```bash
cd plugins/ac-wp-translator && php -l includes/class-acwpt-translator.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/class-acwpt-translator.php
git commit -m "refactor(ac-wp-translator): rewrite translate_strings() for Claude with JSON extractor"
```

---

## Task 18: Rewrite `test_api_key()` for Anthropic

**Files:**
- Modify: `plugins/ac-wp-translator/includes/class-acwpt-translator.php`

- [ ] **Step 1: Replace `test_api_key()`**

Open `class-acwpt-translator.php`. Replace the entire `test_api_key()` method with:

```php
    /**
     * Test the API key by making a minimal Messages call.
     */
    public static function test_api_key( $api_key ) {
        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            array(
                'timeout' => 15,
                'headers' => array(
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'model'      => self::$default_model,
                        'max_tokens' => 16,
                        'messages'   => array(
                            array( 'role' => 'user', 'content' => 'Reply with the single word: ok' ),
                        ),
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $msg  = isset( $body['error']['message'] ) ? $body['error']['message'] : "HTTP {$code}";
            return new WP_Error( 'api_test_failed', $msg );
        }

        return true;
    }
```

- [ ] **Step 2: Smoke-check syntax**

```bash
cd plugins/ac-wp-translator && php -l includes/class-acwpt-translator.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/class-acwpt-translator.php
git commit -m "refactor(ac-wp-translator): rewrite test_api_key() for Anthropic"
```

---

## Task 19: Mix `custom_version` into content_hash for cache invalidation

When the user edits the never-translate list or glossary, we bump `custom_version`. Mixing it into the `content_hash` invalidates affected cached translations without a schema change.

**Files:**
- Modify: `plugins/ac-wp-translator/includes/class-acwpt-frontend.php` (line 253 area)
- Modify: any other call site that computes `content_hash`

- [ ] **Step 1: Find all content_hash computations**

```bash
cd plugins/ac-wp-translator && grep -rn "content_hash = md5" .
```
Expected: at least the line in `class-acwpt-frontend.php:253`. Also check `class-acwpt-preloader.php` and any other consumers.

- [ ] **Step 2: Update each computation to include `custom_version`**

For each match, change:

```php
$content_hash = md5( $post->post_title . '||' . $post->post_content . '||' . $post->post_excerpt );
```

To:

```php
$settings       = get_option( 'acwpt_settings', array() );
$custom_version = isset( $settings['custom_version'] ) ? (int) $settings['custom_version'] : 0;
$content_hash   = md5( $post->post_title . '||' . $post->post_content . '||' . $post->post_excerpt . '||v' . $custom_version );
```

(If `$settings` is already loaded earlier in the same function, just reuse it instead of re-loading.)

- [ ] **Step 3: Smoke-check syntax for every modified file**

```bash
cd plugins/ac-wp-translator && for f in $(grep -rln "custom_version" includes/); do php -l "$f"; done
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 4: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/
git commit -m "feat(ac-wp-translator): mix custom_version into content_hash for cache invalidation"
```

---

## Task 20: Admin: relabel OpenAI → Anthropic / Claude

**Files:**
- Modify: `plugins/ac-wp-translator/includes/class-acwpt-admin.php`

- [ ] **Step 1: Find all OpenAI references**

```bash
cd plugins/ac-wp-translator && grep -n "OpenAI\|openai" includes/class-acwpt-admin.php
```
Note the line numbers; you'll need to update each.

- [ ] **Step 2: Apply the relabels**

For each match, apply these substitutions (preserve surrounding HTML/PHP exactly):

| Find | Replace with |
|---|---|
| `OpenAI API Key` | `Anthropic API Key` |
| `OpenAI Model` | `Claude Model` |
| `Models are fetched from your OpenAI account.` | `Models are fetched from your Anthropic account.` |
| `gpt-4o-mini is recommended for cost-effective translation.` | `claude-haiku-4-5 is recommended for cost-effective translation.` |
| `Each page is translated per language via OpenAI` | `Each page is translated per language via Claude` |
| `Cost estimates based on OpenAI published pricing: GPT-4o Mini ($0.15/$0.60 per 1M tokens in/out), GPT-4o ($2.50/$10.00 per 1M tokens in/out).` | `Cost estimates based on Anthropic published pricing: Claude Haiku 4.5 ($1/$5 per 1M tokens in/out), Claude Sonnet 4.5 ($3/$15 per 1M tokens in/out), Claude Opus 4.5 ($15/$75 per 1M tokens in/out).` |
| `Actual charges may vary slightly. Check your <a href="https://platform.openai.com/usage" target="_blank">OpenAI dashboard</a> for exact billing.` | `Actual charges may vary slightly. Check your <a href="https://console.anthropic.com/settings/usage" target="_blank">Anthropic console</a> for exact billing.` |
| `AJAX: fetch available models from OpenAI.` (docblock) | `AJAX: fetch available models from Anthropic.` |

- [ ] **Step 3: Verify no remaining OpenAI strings**

```bash
cd plugins/ac-wp-translator && grep -n "OpenAI\|openai" includes/class-acwpt-admin.php || echo "clean"
```
Expected: `clean` (or no output).

- [ ] **Step 4: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/class-acwpt-admin.php
git commit -m "refactor(ac-wp-translator): relabel admin UI from OpenAI to Anthropic/Claude"
```

---

## Task 21: Admin: rewrite `ajax_fetch_models` for Anthropic

**Files:**
- Modify: `plugins/ac-wp-translator/includes/class-acwpt-admin.php`

- [ ] **Step 1: Replace the entire `ajax_fetch_models()` method**

Find the method (it starts around line 431 in the current file). Replace its entire body with:

```php
    /**
     * AJAX: fetch available Claude models from the Anthropic API.
     */
    public function ajax_fetch_models() {
        check_ajax_referer( 'acwpt_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        if ( empty( $api_key ) ) {
            $settings = get_option( 'acwpt_settings', array() );
            $api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
        }

        if ( empty( $api_key ) ) {
            wp_send_json_error( 'No API key provided.' );
        }

        $cached = get_transient( 'acwpt_models_list' );
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            wp_send_json_success( $cached );
        }

        $response = wp_remote_get(
            'https://api.anthropic.com/v1/models?limit=1000',
            array(
                'timeout' => 15,
                'headers' => array(
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = isset( $body['error']['message'] ) ? $body['error']['message'] : "HTTP {$code}";
            wp_send_json_error( $msg );
        }

        if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
            wp_send_json_error( 'No models returned.' );
        }

        $models = array();
        foreach ( $body['data'] as $m ) {
            if ( empty( $m['id'] ) ) {
                continue;
            }
            $id = $m['id'];
            // Only Claude chat models.
            if ( strpos( $id, 'claude-' ) !== 0 ) {
                continue;
            }
            $models[] = $id;
        }

        sort( $models );

        set_transient( 'acwpt_models_list', $models, HOUR_IN_SECONDS );

        wp_send_json_success( $models );
    }
```

- [ ] **Step 2: Smoke-check syntax**

```bash
cd plugins/ac-wp-translator && php -l includes/class-acwpt-admin.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/class-acwpt-admin.php
git commit -m "refactor(ac-wp-translator): fetch models from Anthropic API"
```

---

## Task 22: Admin UI: never-translate textarea

**Files:**
- Modify: `plugins/ac-wp-translator/includes/class-acwpt-admin.php`

We're adding a new settings section to the existing settings page. Locate the existing settings form rendering and add the section after the model picker.

- [ ] **Step 1: Locate the model picker `<tr>` block**

```bash
cd plugins/ac-wp-translator && grep -n "Claude Model\|acwpt_model" includes/class-acwpt-admin.php
```
Note the line numbers of the `<tr>` block that renders the model picker (the `<th>` with `Claude Model` label and the matching `</tr>`).

- [ ] **Step 2: Insert the never-translate `<tr>` block immediately after the model picker `</tr>`**

Add this block (adjust indentation to match surrounding HTML):

```php
                        <tr>
                            <th><label for="acwpt_never_translate">Never translate</label></th>
                            <td>
                                <?php
                                $never = isset( $settings['never_translate'] ) ? (array) $settings['never_translate'] : array();
                                ?>
                                <textarea
                                    id="acwpt_never_translate"
                                    name="acwpt_settings[never_translate]"
                                    rows="6"
                                    class="large-text code"
                                    placeholder="One term per line. Examples:&#10;Acme Cloud&#10;PageSpeed Insights&#10;CEO"
                                ><?php echo esc_textarea( implode( "\n", $never ) ); ?></textarea>
                                <p class="description">
                                    One term per line. Matches are case-sensitive and whole-word. Listed terms are wrapped in protective sentinels before being sent to Claude, so they always appear verbatim in the translation.
                                </p>
                            </td>
                        </tr>
```

- [ ] **Step 3: Find the settings sanitize/save logic**

```bash
cd plugins/ac-wp-translator && grep -n "register_setting\|sanitize_settings\|acwpt_settings" includes/class-acwpt-admin.php
```
Locate the function that processes `$_POST['acwpt_settings']` (typically the sanitize callback registered via `register_setting`).

- [ ] **Step 4: Add never-translate handling to the sanitize logic**

Inside that sanitize callback, after the existing field handling and BEFORE `return $clean;`, add:

```php
        // never_translate: textarea, one term per line
        $never_in  = isset( $input['never_translate'] ) ? (string) $input['never_translate'] : '';
        $never_arr = array_filter( array_map( 'trim', preg_split( '/\r?\n/', $never_in ) ), 'strlen' );
        $never_arr = array_values( array_unique( $never_arr ) );

        $existing       = get_option( 'acwpt_settings', array() );
        $existing_never = isset( $existing['never_translate'] ) ? (array) $existing['never_translate'] : array();
        if ( $never_arr !== $existing_never ) {
            $clean['custom_version'] = ( isset( $existing['custom_version'] ) ? (int) $existing['custom_version'] : 0 ) + 1;
        } elseif ( isset( $existing['custom_version'] ) ) {
            $clean['custom_version'] = (int) $existing['custom_version'];
        }
        $clean['never_translate'] = $never_arr;
```

(If the sanitize callback doesn't read `$existing` already, this block introduces it. Don't duplicate the `get_option` call if one exists earlier in the function — reuse the existing variable.)

- [ ] **Step 5: Smoke-check syntax**

```bash
cd plugins/ac-wp-translator && php -l includes/class-acwpt-admin.php
```
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/class-acwpt-admin.php
git commit -m "feat(ac-wp-translator): add never-translate textarea to settings"
```

---

## Task 23: Admin UI: glossary table

**Files:**
- Modify: `plugins/ac-wp-translator/includes/class-acwpt-admin.php`

- [ ] **Step 1: Insert the glossary `<tr>` block after the never-translate block**

Add immediately after the never-translate `<tr>` you added in Task 22:

```php
                        <tr>
                            <th><label>Glossary</label></th>
                            <td>
                                <?php
                                $glossary = isset( $settings['glossary'] ) ? (array) $settings['glossary'] : array();
                                $enabled  = isset( $settings['enabled_languages'] ) ? (array) $settings['enabled_languages'] : array();
                                $all_langs = ACWPT_Languages::all();
                                ?>
                                <table class="widefat acwpt-glossary-table" id="acwpt-glossary-table">
                                    <thead>
                                        <tr>
                                            <th style="width:30%;">English term</th>
                                            <?php foreach ( $enabled as $code ) :
                                                $name = isset( $all_langs[ $code ]['name'] ) ? $all_langs[ $code ]['name'] : $code;
                                                ?>
                                                <th><?php echo esc_html( $name ); ?> <code>(<?php echo esc_html( $code ); ?>)</code></th>
                                            <?php endforeach; ?>
                                            <th style="width:60px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Always render at least one empty row at the end for adding new entries.
                                        $rows = $glossary;
                                        $rows[] = array(); // sentinel empty
                                        foreach ( $rows as $i => $row ) :
                                            ?>
                                            <tr>
                                                <td>
                                                    <input type="text" name="acwpt_settings[glossary][<?php echo (int) $i; ?>][en]" value="<?php echo esc_attr( isset( $row['en'] ) ? $row['en'] : '' ); ?>" class="widefat" placeholder="e.g. Contact us" />
                                                </td>
                                                <?php foreach ( $enabled as $code ) : ?>
                                                    <td>
                                                        <input type="text" name="acwpt_settings[glossary][<?php echo (int) $i; ?>][<?php echo esc_attr( $code ); ?>]" value="<?php echo esc_attr( isset( $row[ $code ] ) ? $row[ $code ] : '' ); ?>" class="widefat" />
                                                    </td>
                                                <?php endforeach; ?>
                                                <td>
                                                    <button type="button" class="button button-small acwpt-glossary-remove" aria-label="Remove row">×</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <p>
                                    <button type="button" class="button" id="acwpt-glossary-add-row">+ Add row</button>
                                </p>
                                <p class="description">
                                    Mandatory translations for specific terms. Empty cells let Claude decide. Source terms are wrapped before sending so the model can't paraphrase them. Adding a row only appears after you save once (use the empty trailing row for new entries, or click "Add row" to insert more).
                                </p>
                                <script>
                                (function() {
                                    var addBtn = document.getElementById('acwpt-glossary-add-row');
                                    var table  = document.getElementById('acwpt-glossary-table');
                                    if ( ! addBtn || ! table ) return;
                                    addBtn.addEventListener('click', function() {
                                        var tbody = table.querySelector('tbody');
                                        var lastRow = tbody.querySelector('tr:last-child');
                                        var newRow = lastRow.cloneNode(true);
                                        var idx = tbody.querySelectorAll('tr').length;
                                        newRow.querySelectorAll('input').forEach(function(inp) {
                                            inp.value = '';
                                            inp.name = inp.name.replace(/glossary\]\[\d+\]/, 'glossary][' + idx + ']');
                                        });
                                        tbody.appendChild(newRow);
                                    });
                                    table.addEventListener('click', function(e) {
                                        if ( e.target.classList.contains('acwpt-glossary-remove') ) {
                                            var row = e.target.closest('tr');
                                            if ( table.querySelectorAll('tbody tr').length > 1 ) {
                                                row.parentNode.removeChild(row);
                                            } else {
                                                row.querySelectorAll('input').forEach(function(inp) { inp.value = ''; });
                                            }
                                        }
                                    });
                                })();
                                </script>
                            </td>
                        </tr>
```

- [ ] **Step 2: Add glossary handling to the sanitize callback**

In the same sanitize function modified in Task 22, add this block AFTER the never-translate block and BEFORE the `return`:

```php
        // glossary: array of rows (en + per-language translations)
        $glossary_in   = isset( $input['glossary'] ) && is_array( $input['glossary'] ) ? $input['glossary'] : array();
        $glossary_out  = array();
        foreach ( $glossary_in as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $clean_row = array();
            foreach ( $row as $k => $v ) {
                $kk = preg_replace( '/[^a-z0-9_-]/i', '', (string) $k );
                if ( $kk === '' ) {
                    continue;
                }
                $clean_row[ $kk ] = sanitize_text_field( wp_unslash( (string) $v ) );
            }
            // Drop entirely-empty rows.
            if ( implode( '', $clean_row ) === '' ) {
                continue;
            }
            $glossary_out[] = $clean_row;
        }

        $existing_glossary = isset( $existing['glossary'] ) ? (array) $existing['glossary'] : array();
        if ( $glossary_out !== $existing_glossary ) {
            // Bump custom_version if not already bumped by never_translate change.
            $clean['custom_version'] = isset( $clean['custom_version'] )
                ? max( (int) $clean['custom_version'], ( isset( $existing['custom_version'] ) ? (int) $existing['custom_version'] : 0 ) + 1 )
                : ( ( isset( $existing['custom_version'] ) ? (int) $existing['custom_version'] : 0 ) + 1 );
        }
        $clean['glossary'] = $glossary_out;
```

- [ ] **Step 3: Smoke-check syntax**

```bash
cd plugins/ac-wp-translator && php -l includes/class-acwpt-admin.php
```
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
cd plugins/ac-wp-translator
git add includes/class-acwpt-admin.php
git commit -m "feat(ac-wp-translator): add glossary editor table to settings"
```

---

## Task 24: Bootstrap: version bump + upgrade routine

**Files:**
- Modify: `plugins/ac-wp-translator/ac-wp-translator.php`

- [ ] **Step 1: Update the plugin header and version constant**

Open `ac-wp-translator.php`. Make these changes near the top:

- Line 5 (`Description:`) — replace with:
  ```
   * Description: AI-powered real-time translation using Anthropic Claude. Translates pages and posts with URL-based language prefixes (/es/, /fr/, etc.), native-speaker B2B prompts per language, custom never-translate list and glossary, smart caching. By amplifi.studio.
  ```
- Line 6 (`Version:`) — change to:
  ```
   * Version: 2.0.0
  ```
- Line 19 (`define( 'ACWPT_VERSION', '1.2.7' );`) — change to:
  ```
  define( 'ACWPT_VERSION', '2.0.0' );
  ```

- [ ] **Step 2: Add upgrade routine**

In `ac-wp-translator.php`, after the constant definitions and before the `require_once` block (around line 23), add:

```php
/**
 * One-shot upgrade routine. Runs when stored db version is older than current.
 * v2.0.0: provider switched from OpenAI to Anthropic. Clear stale model
 * selection and the cached models list so the user re-picks a Claude model.
 */
function acwpt_maybe_upgrade() {
    $stored = get_option( 'acwpt_db_version', '1.0' );
    if ( version_compare( $stored, '2.0.0', '<' ) ) {
        $settings = get_option( 'acwpt_settings', array() );
        if ( isset( $settings['model'] ) ) {
            $settings['model'] = '';
        }
        if ( ! isset( $settings['custom_version'] ) ) {
            $settings['custom_version'] = 0;
        }
        update_option( 'acwpt_settings', $settings );
        delete_transient( 'acwpt_models_list' );
        update_option( 'acwpt_db_version', '2.0.0' );
        update_option( 'acwpt_show_v2_notice', 1 );
    }
}
add_action( 'admin_init', 'acwpt_maybe_upgrade' );
```

- [ ] **Step 3: Smoke-check syntax**

```bash
cd plugins/ac-wp-translator && php -l ac-wp-translator.php
```
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
cd plugins/ac-wp-translator
git add ac-wp-translator.php
git commit -m "chore(ac-wp-translator): bump to v2.0.0 with Anthropic upgrade routine"
```

---

## Task 25: Admin notice on first post-upgrade load

**Files:**
- Modify: `plugins/ac-wp-translator/ac-wp-translator.php`

- [ ] **Step 1: Add the notice function**

In `ac-wp-translator.php`, after the `acwpt_maybe_upgrade()` function added in Task 24, add:

```php
/**
 * One-time admin notice after upgrading to v2.0.0.
 */
function acwpt_v2_admin_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! get_option( 'acwpt_show_v2_notice' ) ) {
        return;
    }
    $url = admin_url( 'admin.php?page=amplifi-ac-wp-translator' );
    ?>
    <div class="notice notice-warning is-dismissible">
        <p>
            <strong>amplifi.translate v2.0.0:</strong> This release switches from OpenAI to <strong>Anthropic Claude</strong>.
            Your existing OpenAI key will not work. Please <a href="<?php echo esc_url( $url ); ?>">enter your Anthropic API key and pick a Claude model</a> to resume translations.
        </p>
    </div>
    <?php
    delete_option( 'acwpt_show_v2_notice' );
}
add_action( 'admin_notices', 'acwpt_v2_admin_notice' );
```

- [ ] **Step 2: Smoke-check syntax**

```bash
cd plugins/ac-wp-translator && php -l ac-wp-translator.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd plugins/ac-wp-translator
git add ac-wp-translator.php
git commit -m "feat(ac-wp-translator): show one-time admin notice after v2.0.0 upgrade"
```

---

## Task 26: End-to-end smoke test in Docker

This task validates that the full stack works end-to-end against a real Anthropic key. It is the only task that requires a real API call and a real WordPress environment. **Requires:** an Anthropic API key with credit.

**Files:**
- No file changes (testing only).

- [ ] **Step 1: Bring up Docker**

```bash
cd plugins/ac-wp-translator && docker-compose up -d
```

Wait ~10 seconds for WP to become ready. Verify: open `http://localhost:8085` in a browser. You should see WP install or the existing site.

- [ ] **Step 2: Verify class loading**

```bash
cd plugins/ac-wp-translator && docker-compose exec wordpress bash -c 'cd /var/www/html && wp eval "var_dump(class_exists(\"ACWPT_Translator\"), class_exists(\"ACWPT_Prompts\"), class_exists(\"ACWPT_Glossary\"));" --allow-root'
```
Expected: three `bool(true)` lines.

- [ ] **Step 3: Verify prompt assembly**

```bash
cd plugins/ac-wp-translator && docker-compose exec wordpress bash -c 'cd /var/www/html && wp eval "echo substr(ACWPT_Prompts::build_content_prompt(\"pl\", array(\"never_translate\"=>array(\"Acme\"),\"glossary\"=>array(array(\"en\"=>\"Contact us\",\"pl\"=>\"Kontakt z nami\")))), 0, 2000);" --allow-root'
```
Expected: a multi-section prompt that includes the base text, "Polish", "MANDATORY GLOSSARY", and "Acme".

- [ ] **Step 4: Configure plugin via WP-CLI**

Replace `<KEY>` with your real Anthropic API key.

```bash
cd plugins/ac-wp-translator && docker-compose exec wordpress bash -c 'cd /var/www/html && wp option update acwpt_settings "$(cat <<JSON
{"api_key":"<KEY>","model":"claude-haiku-4-5","source_language":"en","enabled_languages":["es","pl"],"show_flags":1,"never_translate":["Amplifi"],"glossary":[{"en":"Contact us","es":"Contáctenos","pl":"Kontakt z nami"}],"custom_version":1}
JSON
)" --format=json --allow-root'
```

- [ ] **Step 5: Translate a post via WP-CLI**

Pick any published post ID (use `wp post list --post_type=post --format=ids --allow-root` to find one, or create a test post with `wp post create --post_title='Contact us today, Amplifi customers!' --post_content='Get started with our scalable B2B platform. Trusted by 500+ companies. Learn more.' --post_status=publish --allow-root`).

```bash
cd plugins/ac-wp-translator && docker-compose exec wordpress bash -c 'cd /var/www/html && wp eval "
\$post = get_post(POST_ID);
\$out = ACWPT_Translator::translate(\$post->post_title, \$post->post_content, \$post->post_excerpt, \"pl\");
if (is_wp_error(\$out)) { echo \"ERROR: \" . \$out->get_error_message(); }
else { echo \"TITLE: \" . \$out[\"title\"] . PHP_EOL . \"CONTENT: \" . \$out[\"content\"] . PHP_EOL . \"EXCERPT: \" . \$out[\"excerpt\"]; }
" --allow-root'
```

Replace `POST_ID` with the actual post ID.

Verify in the output:
- Polish translation looks fluent and B2B-natural (not literal).
- "Amplifi" appears verbatim (no translation, no `<x-keep>` wrapper).
- "Contact us" was translated as exactly "Kontakt z nami" (the glossary entry).
- HTML/shortcode preservation if the source had any.

- [ ] **Step 6: Translate via the URL-prefix flow**

In a browser, visit `http://localhost:8085/pl/<post-slug>/`. Verify:
- Page loads without errors.
- Title and body are translated to Polish.
- Glossary terms render as the mandated translations.
- No `<x-keep>` or `<x-glossary>` tags appear in the rendered HTML (view source).

If sentinels leak into the rendered HTML, the strip step is broken. Re-check Tasks 16 and 17.

- [ ] **Step 7: Verify usage tracking incremented**

```bash
cd plugins/ac-wp-translator && docker-compose exec wordpress bash -c 'cd /var/www/html && wp option get acwpt_usage --format=json --allow-root'
```
Expected: a JSON object with the current YYYY-MM key, non-zero requests/tokens, non-zero `estimated_cost`.

- [ ] **Step 8: Test glossary invalidation**

Update the glossary (change "Contact us" → "Skontaktuj się"):

```bash
cd plugins/ac-wp-translator && docker-compose exec wordpress bash -c 'cd /var/www/html && wp eval "
\$s = get_option(\"acwpt_settings\");
\$s[\"glossary\"][0][\"pl\"] = \"Skontaktuj się\";
\$s[\"custom_version\"] = (int) \$s[\"custom_version\"] + 1;
update_option(\"acwpt_settings\", \$s);
echo \"version=\" . \$s[\"custom_version\"];
" --allow-root'
```

Re-visit `http://localhost:8085/pl/<post-slug>/`. Verify the page re-translates (no longer cached) and the new glossary value appears.

- [ ] **Step 9: Tear down (optional)**

```bash
cd plugins/ac-wp-translator && docker-compose down
```

- [ ] **Step 10: If smoke tests revealed nuance gaps, expand language packs**

If Polish output had specific patterns that read as machine-translated, append nuances to `includes/prompts/lang/pl.php` (and similar for other languages tested). Aim to reach 80–120 nuances per language by adding 1-2 commits per language. This is iterative; ship v2.0.0 with the starter packs and improve in v2.0.1+.

If you added nuances:

```bash
cd plugins/ac-wp-translator
git add includes/prompts/lang/
git commit -m "feat(ac-wp-translator): expand language packs based on smoke-test findings"
```

---

## Task 27: Update plugins-manifest.json description

**Files:**
- Modify: `plugins-manifest.json` (repo root)

- [ ] **Step 1: Read the current manifest**

```bash
cat plugins-manifest.json
```

- [ ] **Step 2: Update the `ac-wp-translator` entry**

Find the `ac-wp-translator` entry. Update its `description` field to:

```
AI-powered real-time translation using Anthropic Claude. URL-based language prefixes (/es/, /fr/, etc.), native-speaker B2B prompts per language, custom never-translate list and glossary, and smart caching.
```

(Use the Edit tool to make a targeted change rather than rewriting the file.)

- [ ] **Step 3: Validate JSON**

```bash
python3 -m json.tool plugins-manifest.json > /dev/null && echo "valid"
```
Expected: `valid`

- [ ] **Step 4: Commit**

```bash
git add plugins-manifest.json
git commit -m "chore: update plugins-manifest description for amplifi.translate v2.0.0"
```

---

## Final verification checklist (run before declaring done)

- [ ] All unit tests pass: `cd plugins/ac-wp-translator && for t in tests/test_*.php; do php "$t" || exit 1; done && echo ALL TESTS PASS`
- [ ] No remaining "OpenAI" or "openai" strings in code (excluding `LICENSE`, `social/`, `blog/` historical content): `grep -rn "OpenAI\|openai" plugins/ac-wp-translator --exclude-dir=social --exclude-dir=blog --exclude=LICENSE`
- [ ] Plugin syntax is clean: `for f in plugins/ac-wp-translator/ac-wp-translator.php plugins/ac-wp-translator/includes/*.php; do php -l "$f"; done`
- [ ] Smoke test in Docker (Task 26) passed end-to-end against a real Anthropic key.
- [ ] Polish output reads naturally (per Task 26 smoke test); user has reviewed it.
- [ ] Plugin version is `2.0.0` in both the header comment and the `ACWPT_VERSION` constant.

---

## Out of scope (deferred)

- PHPUnit + composer infra (kept lean intentionally; standalone scripts cover what we need).
- Per-language nuance packs for the remaining 25 languages — add iteratively.
- Glossary CSV import/export — add if users ask.
- "Clear stale cache" UI button — `custom_version` invalidates lazily; revisit if needed.
- Streaming responses for long pages.
- Fallback when Anthropic is down (e.g., serve untranslated source) — out of scope, original behavior is to return WP_Error which the frontend already handles.
