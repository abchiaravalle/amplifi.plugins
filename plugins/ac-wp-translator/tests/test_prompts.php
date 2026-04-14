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
