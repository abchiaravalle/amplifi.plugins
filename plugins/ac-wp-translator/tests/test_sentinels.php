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
