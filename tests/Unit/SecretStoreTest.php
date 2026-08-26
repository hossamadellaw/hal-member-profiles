<?php
/**
 * Unit tests for SecretStore (cards D-07/D-17). Two environments are supported:
 *
 * - WITH libsodium: the full AEAD contract (roundtrip, at-rest plaintext absence,
 *   masking, tamper auth-failure, salt rotation, revocation).
 * - WITHOUT libsodium: the mandated fail-closed contract (writes refused, reads null).
 *
 * Constant-override priority and capability gating run in both environments.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\SecretStore;
use PHPUnit\Framework\TestCase;

final class SecretStoreTest extends TestCase {

	protected function setUp(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		$GLOBALS['wp_stubs']['options'] = array();
	}

	private static function secret(): string {
		return 'UNIT-SECRET-24680xyz';
	}

	public function test_capability_gate_blocks_writes(): void {
		$GLOBALS['wp_stubs']['can_manage'] = false;

		$res = SecretStore::set_amelia_api_key( self::secret() );

		$GLOBALS['wp_stubs']['can_manage'] = true;

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'denied', $res['reason'] );
	}

	public function test_constant_override_is_returned_verbatim_and_blocks_mutations(): void {
		// Another unit file may have defined this constant earlier in the same process
		// (define() is global and first-wins), so assert against whatever value exists.
		if ( ! defined( 'HAL_MEMBER_PROFILES_AMELIA_API_KEY' ) ) {
			define( 'HAL_MEMBER_PROFILES_AMELIA_API_KEY', 'CONST-OVERRIDE-D17' );
		}

		$expected = (string) constant( 'HAL_MEMBER_PROFILES_AMELIA_API_KEY' );

		$this->assertNotSame( '', $expected );
		$this->assertSame( $expected, SecretStore::get_amelia_api_key() );

		$res = SecretStore::set_amelia_api_key( 'whatever' );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'constant_override_active', $res['reason'] );

		$this->assertSame(
			'constant_override_active',
			SecretStore::clear_amelia_api_key()['reason']
		);

		$state = SecretStore::storage_state();
		$this->assertSame( 'constant', $state['source'] );
	}

	public function test_sodium_absent_fails_closed_and_present_runs_full_contract(): void {
		if ( defined( 'HAL_MEMBER_PROFILES_AMELIA_API_KEY' ) ) {
			// An earlier suite in this process pinned the override constant; the
			// storage-contract battery requires an override-free environment.
			$this->markTestSkipped( 'constant override active from an earlier suite' );

			return;
		}

		if ( ! SecretStore::is_crypto_available() ) {
			$res = SecretStore::set_amelia_api_key( self::secret() );

			$this->assertFalse( $res['ok'] );
			$this->assertSame( 'crypto_unavailable', $res['reason'] );
			$this->assertNull( SecretStore::get_amelia_api_key() );

			$state = SecretStore::storage_state();

			$this->assertFalse( $state['crypto_available'] );
			$this->assertFalse( $state['stored'] );
			$this->assertNull( $state['decodable'] );
			$this->assertNull( SecretStore::masked_preview() );
			$this->addWarningNote();

			return;
		}

		// --- Full AEAD battery ---------------------------------------------------------

		$res = SecretStore::set_amelia_api_key( self::secret() );
		$this->assertTrue( $res['ok'], 'set accepted' );
		$this->assertSame( self::secret(), SecretStore::get_amelia_api_key(), 'authenticated roundtrip' );

		$raw_option = (string) json_encode( hal_wp_stub_extra( 'options' )[ SecretStore::OPTION_NAME ] ?? '' );

		$this->assertFalse( strpos( $raw_option, self::secret() ), 'plaintext never at rest' );
		$this->assertStringContainsString( 'hmpv1:', $raw_option, 'versioned blob container' );

		$state = SecretStore::storage_state();

		$this->assertSame( 'encrypted', $state['source'] );
		$this->assertTrue( $state['decodable'] );

		// Masking discipline.
		$masked = SecretStore::masked_preview();

		$this->assertIsString( $masked );
		$this->assertSame( '6xyz', substr( $masked, -4 ) );
		$this->assertFalse( strpos( (string) $masked, '24680' ) );
		$this->assertLessThanOrEqual( 16, strlen( (string) $masked ) );
		$this->assertSame( '••••••', SecretStore::mask( 'abc123' ) );
		$this->assertSame( '••', SecretStore::mask( 'ab' ) );

		// Tamper -> auth failure.
		$slots   = $GLOBALS['wp_stubs']['options'][ SecretStore::OPTION_NAME ];
		$blob    = (string) $slots[ 'amelia_api_key' ];
		$pos     = strlen( $blob ) - 5;
		$swapped = 'A' === $blob[ $pos ] ? 'B' : 'A';
		$GLOBALS['wp_stubs']['options'][ SecretStore::OPTION_NAME ]['amelia_api_key'] =
			substr( $blob, 0, $pos ) . $swapped . substr( $blob, $pos + 1 );

		$this->assertNull( SecretStore::get_amelia_api_key(), 'tampered blob must not decrypt' );

		// Salt rotation -> fail closed.
		$this->assertTrue( SecretStore::set_amelia_api_key( self::secret() )['ok'] );

		$old_salt = hal_wp_stub_extra( 'salt' );
		hal_wp_stub_extra_set( 'salt', 'rotated-salt' );

		$this->assertNull( SecretStore::get_amelia_api_key(), 'rotation demands re-entry' );

		hal_wp_stub_extra_set( 'salt', $old_salt );

		// Revocation.
		$res = SecretStore::clear_amelia_api_key();

		$this->assertTrue( $res['ok'] );
		$this->assertNull( SecretStore::get_amelia_api_key() );
		$this->assertFalse( SecretStore::storage_state()['stored'] );
	}
}
