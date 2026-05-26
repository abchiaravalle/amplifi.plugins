<?php
/**
 * Shell / backdoor signature definitions.
 *
 * Rules are pure PHP data structures — never executable rule code. Each rule
 * is evaluated by `Signature_Engine` against PHP file contents using
 * `preg_match`, `token_get_all`, byte-frequency, and Shannon-entropy checks.
 *
 * Logic ported (NOT copy-pasted) from the YARA rule sets cited in the
 * project's THIRD_PARTY_NOTICES.md:
 *   - php-malware-finder (Apache 2.0)  — semantic shell detection
 *   - Pressidium YARA Rules (MIT)      — WP-tuned malware patterns
 *
 * Conventions:
 *   - `match`: regex (PCRE) — must include all delimiters and modifiers.
 *   - `tokens`: list of PHP tokens that must all appear in the file.
 *   - `entropy_min`: minimum Shannon entropy (PHP source typically 4.5–5.5).
 *   - `weight`: contribution to combined score; final scanner reports any rule
 *     match individually plus a `combined_score` per file.
 *
 * @package Amplifi\Security\Signatures
 */

declare(strict_types=1);

namespace Amplifi\Security\Signatures;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	/* ---------- raw eval / dynamic execution ---------- */
	[
		'id'      => 'eval_b64',
		'name'    => 'eval(base64_decode(...))',
		'match'   => '/eval\s*\(\s*base64_decode\s*\(/i',
		'weight'  => 9,
		'category'=> 'shell',
	],
	[
		'id'      => 'eval_gzinflate',
		'name'    => 'eval(gzinflate(base64_decode(...)))',
		'match'   => '/eval\s*\(\s*gzinflate\s*\(\s*base64_decode/i',
		'weight'  => 9,
		'category'=> 'shell',
	],
	[
		'id'      => 'eval_str_rot13',
		'name'    => 'eval(str_rot13(...))',
		'match'   => '/eval\s*\(\s*str_rot13\s*\(/i',
		'weight'  => 8,
		'category'=> 'shell',
	],
	[
		'id'      => 'preg_replace_e',
		'name'    => 'preg_replace with /e modifier',
		'match'   => '/preg_replace\s*\(\s*[\'"][^\'"]*[\'"][a-z]*e[a-z]*[\'"]/i',
		'weight'  => 8,
		'category'=> 'shell',
	],
	[
		'id'      => 'create_function',
		'name'    => 'create_function (deprecated, common in shells)',
		'match'   => '/create_function\s*\(/i',
		'weight'  => 5,
		'category'=> 'suspicious',
	],
	[
		'id'      => 'assert_variable',
		'name'    => 'assert(variable) — dynamic exec via assert',
		'match'   => '/assert\s*\(\s*\$/i',
		'weight'  => 7,
		'category'=> 'shell',
	],

	/* ---------- shell command execution ---------- */
	[
		'id'      => 'system_exec',
		'name'    => 'shell command execution',
		'match'   => '/\b(?:system|exec|shell_exec|passthru|popen|proc_open)\s*\(/i',
		'weight'  => 4,
		'category'=> 'suspicious',
	],
	[
		'id'      => 'backticks',
		'name'    => 'backtick command execution',
		'match'   => '/`[^`\n]{2,}`/',
		'weight'  => 3,
		'category'=> 'suspicious',
	],

	/* ---------- file-write to PHP paths ---------- */
	[
		'id'      => 'fwrite_php',
		'name'    => 'file_put_contents to .php',
		'match'   => '/(?:file_put_contents|fwrite|fputs)\s*\([^)]*\.php[\'"]/i',
		'weight'  => 6,
		'category'=> 'suspicious',
	],

	/* ---------- known shell signatures (logic, not strings) ---------- */
	[
		'id'      => 'filesman_signature',
		'name'    => 'FilesMan shell artifacts',
		'match'   => '/FilesMan|c99sh|@ini_set\([\'"]error_log[\'"],\s*null\s*\)/i',
		'weight'  => 10,
		'category'=> 'shell',
	],
	[
		'id'      => 'wso_shell',
		'name'    => 'WSO shell artifacts',
		'match'   => '/WSO\s+\d+\.\d+|wso_login|\$default_action\s*=\s*[\'"]FilesMan[\'"]/i',
		'weight'  => 10,
		'category'=> 'shell',
	],
	[
		'id'      => 'b374k_shell',
		'name'    => 'b374k shell artifacts',
		'match'   => '/b374k|b\d+k\b/i',
		'weight'  => 10,
		'category'=> 'shell',
	],
	[
		'id'      => 'r57_shell',
		'name'    => 'r57 shell artifacts',
		'match'   => '/r57shell|r57_(?:upload|cmd)/i',
		'weight'  => 10,
		'category'=> 'shell',
	],
	[
		'id'      => 'weevely',
		'name'    => 'Weevely backdoor',
		'match'   => '/eval\s*\(\s*\$[a-z]+\s*\.\s*\$[a-z]+\s*\)\s*;\s*\}\s*else/i',
		'weight'  => 9,
		'category'=> 'shell',
	],
	[
		'id'      => 'china_chopper',
		'name'    => 'China Chopper-style one-liner',
		'match'   => '/<\?php\s+@?eval\s*\(\s*\$_(?:POST|REQUEST|GET)\s*\[/i',
		'weight'  => 10,
		'category'=> 'shell',
	],

	/* ---------- obfuscation patterns ---------- */
	[
		'id'      => 'long_base64_string',
		'name'    => 'long base64 blob in source',
		'match'   => '/[\'"][A-Za-z0-9+\/=]{500,}[\'"]/',
		'weight'  => 4,
		'category'=> 'obfuscation',
	],
	[
		'id'      => 'chr_concat',
		'name'    => 'long sequence of chr() concatenations',
		'match'   => '/(?:chr\s*\(\s*\d+\s*\)\s*\.\s*){8,}/i',
		'weight'  => 7,
		'category'=> 'obfuscation',
	],
	[
		'id'      => 'goto_obfuscation',
		'name'    => 'goto-based obfuscation',
		'match'   => '/(?:goto\s+[A-Za-z_][A-Za-z0-9_]*\s*;.*?){10,}/s',
		'weight'  => 6,
		'category'=> 'obfuscation',
	],
	[
		'id'      => 'hex_var_names',
		'name'    => 'hex/random variable name flood',
		'match'   => '/(?:\$_?[A-Fa-f0-9]{8,}\b.*?){12,}/s',
		'weight'  => 5,
		'category'=> 'obfuscation',
	],

	/* ---------- network exfil / C2 ---------- */
	[
		'id'      => 'curl_post_to_var',
		'name'    => 'curl POST to a variable host',
		'match'   => '/curl_setopt\s*\([^)]*CURLOPT_URL[^)]*\$/i',
		'weight'  => 4,
		'category'=> 'suspicious',
	],
	[
		'id'      => 'fopen_remote',
		'name'    => 'fopen to remote URL with allow_url_fopen',
		'match'   => '/fopen\s*\(\s*[\'"]https?:\/\//i',
		'weight'  => 3,
		'category'=> 'suspicious',
	],

	/* ---------- WP-specific malicious patterns ---------- */
	[
		'id'      => 'wp_user_insert',
		'name'    => 'direct wp_insert_user with admin role outside core',
		'match'   => '/wp_insert_user\s*\([^)]*[\'"]administrator[\'"]/is',
		'weight'  => 6,
		'category'=> 'wp_persistence',
	],
	[
		'id'      => 'wp_remote_unknown',
		'name'    => 'wp_remote_get/post to inline literal IP',
		'match'   => '/wp_remote_(?:get|post)\s*\(\s*[\'"]https?:\/\/(?:\d{1,3}\.){3}\d{1,3}/i',
		'weight'  => 5,
		'category'=> 'suspicious',
	],
	[
		'id'      => 'add_user_register',
		'name'    => 'direct insert into wp_users via $wpdb',
		'match'   => '/\$wpdb->(?:insert|query)\s*\([^)]*users/i',
		'weight'  => 4,
		'category'=> 'suspicious',
	],

	/* ---------- prompt-injection canaries (caught BEFORE Claude triage) ---------- */
	[
		'id'      => 'prompt_injection_canary',
		'name'    => 'prompt-injection attempt embedded in evidence',
		'match'   => '/(?:ignore\s+(?:all\s+)?(?:previous|prior)\s+instructions|disregard\s+(?:all\s+)?(?:previous|earlier)\s+(?:instructions|prompts)|you\s+are\s+now\s+(?:a|an)\s+|new\s+instructions:|system:\s*you\s+(?:must|will|are))/i',
		'weight'  => 12,
		'category'=> 'prompt_injection',
	],
];
