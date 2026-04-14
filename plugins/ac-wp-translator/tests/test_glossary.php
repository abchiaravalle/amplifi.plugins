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
