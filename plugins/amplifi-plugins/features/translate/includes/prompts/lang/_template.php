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
