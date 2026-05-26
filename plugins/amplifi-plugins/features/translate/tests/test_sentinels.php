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

t_section( 'apply_keep_sentinels: regex-special chars in term' );
$out = ACWPT_Glossary::apply_keep_sentinels(
    'We use C++ and (Ltd.) in our copy.',
    array( 'C++', '(Ltd.)' )
);
t_equals(
    'We use <x-keep>C++</x-keep> and <x-keep>(Ltd.)</x-keep> in our copy.',
    $out,
    'preg_quote handles + . ( ) special chars'
);

t_section( 'apply_keep_sentinels: term appears multiple times' );
$out = ACWPT_Glossary::apply_keep_sentinels(
    'Acme is great. Try Acme today. Why Acme?',
    array( 'Acme' )
);
t_equals(
    '<x-keep>Acme</x-keep> is great. Try <x-keep>Acme</x-keep> today. Why <x-keep>Acme</x-keep>?',
    $out,
    'wraps every occurrence'
);

t_section( 'apply_keep_sentinels: NUL byte in source is stripped' );
$out = ACWPT_Glossary::apply_keep_sentinels( "before\0after Acme end", array( 'Acme' ) );
t_equals(
    'beforeafter <x-keep>Acme</x-keep> end',
    $out,
    'NUL bytes scrubbed before placeholder strategy runs'
);

t_section( 'apply_keep_sentinels / strip_keep_sentinels: null input' );
t_equals( '', ACWPT_Glossary::apply_keep_sentinels( null, array( 'X' ) ), 'null text returns empty string' );
t_equals( '', ACWPT_Glossary::strip_keep_sentinels( null ), 'null text returns empty string for strip too' );

echo "\nALL PASS\n";
