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
    array( 's' => 'quote " and brace }' ),
    ACWPT_Glossary::extract_first_json_object( '{"s":"quote \\" and brace }"}' ),
    'respects backslash-escaped quotes'
);

t_section( 'extract_first_json_object: no object' );
t_equals( null, ACWPT_Glossary::extract_first_json_object( 'no json here' ), 'no braces' );
t_equals( null, ACWPT_Glossary::extract_first_json_object( '{not valid json' ), 'unbalanced' );

echo "\nALL PASS\n";
