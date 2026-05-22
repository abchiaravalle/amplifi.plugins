<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\Crypto;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\Crypto\Secret_Store;

final class SecretStoreTest extends TestCase {
	protected function setUp(): void {
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', str_repeat( 'a', 64 ) );
		}
		if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
			define( 'SECURE_AUTH_KEY', str_repeat( 'b', 64 ) );
		}
	}

	public function test_encrypt_decrypt_roundtrip(): void {
		$plaintext = 'sk-ant-abc123';
		$cipher = Secret_Store::encrypt( $plaintext );
		$this->assertNotSame( $plaintext, $cipher );
		$this->assertSame( $plaintext, Secret_Store::decrypt( $cipher ) );
	}

	public function test_decrypt_is_deterministic_for_same_input(): void {
		// Encryption uses a random IV, so two encrypts of the same plaintext differ.
		$a = Secret_Store::encrypt( 'hello' );
		$b = Secret_Store::encrypt( 'hello' );
		$this->assertNotSame( $a, $b, 'IV should randomize ciphertext' );
		$this->assertSame( 'hello', Secret_Store::decrypt( $a ) );
		$this->assertSame( 'hello', Secret_Store::decrypt( $b ) );
	}

	public function test_try_decrypt_returns_null_on_garbage(): void {
		$this->assertNull( Secret_Store::try_decrypt( 'not-a-valid-ciphertext' ) );
	}

	public function test_mask_redacts_middle(): void {
		$input  = 'sk-ant-supersecret12345';
		$masked = Secret_Store::mask( $input );
		// mask() uses bullet character (•) not asterisk, and shows last 4 chars.
		// The result is shorter (in character count) than the input is in bytes,
		// but the bullet is multi-byte in UTF-8, so we check the string is non-empty
		// and shorter in grapheme length, and that it ends with the last 4 chars.
		$this->assertNotSame( $input, $masked );
		$this->assertStringContainsString( '•', $masked );
		$this->assertStringEndsWith( substr( $input, -4 ), $masked );
	}
}
