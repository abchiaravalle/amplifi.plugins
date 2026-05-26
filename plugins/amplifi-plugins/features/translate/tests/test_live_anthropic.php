<?php
/**
 * Live integration test against the real Anthropic API.
 *
 * NOT run in CI. Invoke manually with an Anthropic API key:
 *   ACWPT_TEST_KEY=sk-ant-... php tests/test_live_anthropic.php
 *
 * This test exercises the full content translation pipeline end-to-end:
 *   prompt assembler → sentinel injection → HTTP call → response parse →
 *   sentinel strip. It does NOT require WordPress or Docker.
 */

$key = getenv( 'ACWPT_TEST_KEY' );
if ( ! $key ) {
    fwrite( STDERR, "ACWPT_TEST_KEY not set. Skipping live test.\n" );
    exit( 0 );
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-acwpt-glossary.php';
require_once __DIR__ . '/../includes/class-acwpt-prompts.php';

function http_post_messages( $key, $model, $system, $user, $max_tokens ) {
    $ch = curl_init( 'https://api.anthropic.com/v1/messages' );
    curl_setopt_array( $ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => array(
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ),
        CURLOPT_POSTFIELDS     => json_encode( array(
            'model'       => $model,
            'max_tokens'  => $max_tokens,
            'temperature' => 0.3,
            'system'      => $system,
            'messages'    => array( array( 'role' => 'user', 'content' => $user ) ),
        ) ),
        CURLOPT_TIMEOUT        => 60,
    ) );
    $body = curl_exec( $ch );
    $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $err  = curl_error( $ch );
    unset( $ch ); // curl_close() is a no-op since PHP 8.0; let GC release the handle.
    if ( $err ) {
        t_fail( 'http', $err );
    }
    if ( $code !== 200 ) {
        t_fail( 'http', "status={$code} body={$body}" );
    }
    return json_decode( $body, true );
}

$model   = 'claude-haiku-4-5';
$language = 'pl'; // Polish — exercises the language pack
$custom = array(
    'never_translate' => array( 'Amplifi' ),
    'glossary'        => array(
        array( 'en' => 'Contact us', 'pl' => 'Kontakt z nami' ),
    ),
);

t_section( 'content pipeline: assembler + sentinels + Claude + strip' );

// Source content with both never-translate AND glossary terms
$title   = 'Contact us about Amplifi';
$content = '<p>Amplifi helps growing B2B teams scale. Contact us to learn more about our platform.</p>';
$excerpt = 'Get started with Amplifi today.';

// Apply sentinels before sending
$glossary_entries = ACWPT_Glossary::entries_for_language( $custom['glossary'], $language );
$title   = ACWPT_Glossary::apply_keep_sentinels( $title,   $custom['never_translate'] );
$content = ACWPT_Glossary::apply_keep_sentinels( $content, $custom['never_translate'] );
$excerpt = ACWPT_Glossary::apply_keep_sentinels( $excerpt, $custom['never_translate'] );
$title   = ACWPT_Glossary::apply_glossary_sentinels( $title,   $glossary_entries );
$content = ACWPT_Glossary::apply_glossary_sentinels( $content, $glossary_entries );
$excerpt = ACWPT_Glossary::apply_glossary_sentinels( $excerpt, $glossary_entries );

t_assert( strpos( $title, '<x-keep>Amplifi</x-keep>' )                          !== false, 'keep sentinel wrapped title' );
t_assert( strpos( $title, '<x-glossary term="Kontakt z nami">Contact us</x-glossary>' ) !== false, 'glossary sentinel wrapped title' );
t_assert( strpos( $content, '<x-keep>Amplifi</x-keep>' )                        !== false, 'keep sentinel wrapped content' );
t_assert( strpos( $content, '<x-glossary term="Kontakt z nami">Contact us</x-glossary>' ) !== false, 'glossary sentinel wrapped content' );
t_assert( strpos( $excerpt, '<x-keep>Amplifi</x-keep>' )                        !== false, 'keep sentinel wrapped excerpt' );

$system_prompt = ACWPT_Prompts::build_content_prompt( $language, $custom );
t_assert( strpos( $system_prompt, 'TARGET LANGUAGE: Polish' )       !== false, 'assembled prompt names Polish' );
t_assert( strpos( $system_prompt, 'MANDATORY GLOSSARY' )            !== false, 'assembled prompt has glossary block' );
t_assert( strpos( $system_prompt, 'Amplifi' )                       !== false, 'assembled prompt mentions never-translate term' );

$user_message = "===TITLE===\n{$title}\n\n===CONTENT===\n{$content}\n\n===EXCERPT===\n{$excerpt}";

t_section( 'hitting Anthropic /v1/messages' );
fwrite( STDERR, "  (calling claude-haiku-4-5 — this takes a few seconds)\n" );

$data = http_post_messages( $key, $model, $system_prompt, $user_message, 4000 );

t_assert( isset( $data['content'][0]['text'] ),  'response has content[0].text' );
t_assert( isset( $data['usage']['input_tokens'] ), 'response has usage.input_tokens' );
t_assert( isset( $data['usage']['output_tokens'] ), 'response has usage.output_tokens' );

$response_text = $data['content'][0]['text'];
fwrite( STDERR, "  input_tokens="  . $data['usage']['input_tokens']  . " output_tokens=" . $data['usage']['output_tokens'] . "\n" );

t_section( 'parse + strip sentinels' );

// Parse delimiter format (reimplementing parse_response from the translator class)
$parts = array( 'title' => '', 'content' => '', 'excerpt' => '' );
if ( preg_match( '/===TITLE===\s*(.*?)(?=\s*===CONTENT===)/s', $response_text, $m ) )   $parts['title']   = trim( $m[1] );
if ( preg_match( '/===CONTENT===\s*(.*?)(?=\s*===EXCERPT===)/s', $response_text, $m ) ) $parts['content'] = trim( $m[1] );
if ( preg_match( '/===EXCERPT===\s*(.*)/s',                        $response_text, $m ) ) $parts['excerpt'] = trim( $m[1] );

t_assert( $parts['title']   !== '', 'parsed title non-empty' );
t_assert( $parts['content'] !== '', 'parsed content non-empty' );
t_assert( $parts['excerpt'] !== '', 'parsed excerpt non-empty' );

// Strip glossary first, then keep
foreach ( array( 'title', 'content', 'excerpt' ) as $k ) {
    $parts[ $k ] = ACWPT_Glossary::strip_glossary_sentinels( $parts[ $k ] );
    $parts[ $k ] = ACWPT_Glossary::strip_keep_sentinels( $parts[ $k ] );
}

fwrite( STDERR, "\n  === Translated output ===\n" );
fwrite( STDERR, "  TITLE:   {$parts['title']}\n" );
fwrite( STDERR, "  CONTENT: {$parts['content']}\n" );
fwrite( STDERR, "  EXCERPT: {$parts['excerpt']}\n\n" );

t_assert( strpos( $parts['title'],   '<x-keep' )     === false, 'no keep sentinels leaked into title' );
t_assert( strpos( $parts['title'],   '<x-glossary' ) === false, 'no glossary sentinels leaked into title' );
t_assert( strpos( $parts['content'], '<x-keep' )     === false, 'no keep sentinels leaked into content' );
t_assert( strpos( $parts['content'], '<x-glossary' ) === false, 'no glossary sentinels leaked into content' );
t_assert( strpos( $parts['excerpt'], '<x-keep' )     === false, 'no keep sentinels leaked into excerpt' );
t_assert( strpos( $parts['excerpt'], '<x-glossary' ) === false, 'no glossary sentinels leaked into excerpt' );

// Never-translate term should appear verbatim (model may not always preserve case across inflection, so check case-insensitively for survival)
t_assert( stripos( $parts['title'],   'Amplifi' ) !== false, 'Amplifi survives in title (never-translate worked)' );
t_assert( stripos( $parts['content'], 'Amplifi' ) !== false, 'Amplifi survives in content' );
t_assert( stripos( $parts['excerpt'], 'Amplifi' ) !== false, 'Amplifi survives in excerpt' );

// Glossary — mandated Polish phrase should appear
t_assert( strpos( $parts['title'],   'Kontakt z nami' ) !== false, 'glossary mandated translation in title' );
t_assert( strpos( $parts['content'], 'Kontakt z nami' ) !== false, 'glossary mandated translation in content' );

// HTML structure preserved
t_assert( strpos( $parts['content'], '<p>' )   !== false, '<p> tag preserved' );
t_assert( strpos( $parts['content'], '</p>' )  !== false, '</p> tag preserved' );

echo "\nALL PASS\n";
