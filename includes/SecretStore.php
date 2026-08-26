<?php
/**
 * Encrypted, authenticated storage for plugin secrets — development card D-07 (P0).
 *
 * Sole responsibility: custody of sensitive values (currently: the Amelia Elite API key)
 * so that no secret ever lands in ordinary options, logs, HTML/JS output, or exports.
 * Request surfaces (forms, buttons, nonces for entering/rotating the key) belong to the
 * dashboard cards; this class exposes guarded programmatic verbs only.
 *
 * Resolution order (per the governing card):
 * 1. Constant override: when HAL_MEMBER_PROFILES_AMELIA_API_KEY is defined (wp-config.php
 *    or hosting environment), its value IS the secret. Nothing is ever written to the
 *    database in this mode, and set/clear verbs deliberately refuse with
 *    constant_override_active instead of silently shadowing the operator's choice.
 * 2. Encrypted storage: otherwise the value is sealed with authenticated symmetric
 *    encryption (libsodium secretbox — AEAD with integrity), under a key derived from
 *    WordPress's own salt material, in a NON-autoloaded option. The database therefore
 *    never contains the plaintext, and WP exports/options screens only ever show an
 *    opaque blob prefixed hmpv1.
 *
 * Failure policy (all fail-closed):
 * - Crypto primitives missing → reads return null and writes refuse (crypto_unavailable).
 * - Database blob tampered → authentication tag mismatch → read returns null, state
 *   reports undecodable, nothing is echoed.
 * - WordPress salts rotated → derived key changes → previously stored blob no longer
 *   authenticates → read returns null and the operator must re-enter the key. This is
 *   the mandated salt/key-change behavior, never a silent wrong-value fallback.
 * - Every mutation requires an admin context plus manage_options; request nonces remain
 *   the calling endpoint's duty (same split as ManagedTemplates/Lifecycle).
 *
 * Display discipline: the plaintext getter exists for internal consumers only; UI layers
 * must use mask()/masked_preview(), which reveal at most the final four characters and
 * fully hide keys of four characters or fewer.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SecretStore {

	public const OPTION_NAME     = 'hal_member_profiles_secrets';
	public const CONSTANT_SOURCE = 'HAL_MEMBER_PROFILES_AMELIA_API_KEY';

	private const SLOT_AMELIA  = 'amelia_api_key';
	private const BLOB_PREFIX  = 'hmpv1';
	private const MAX_KEY_LEN  = 2048;

	/**
	 * Whether the trusted crypto primitives exist in this environment.
	 *
	 * @return bool
	 */
	public static function is_crypto_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_generichash' );
	}

	/**
	 * Whether a wp-config/hosting constant currently overrides storage entirely.
	 *
	 * @return bool
	 */
	public static function has_constant_source(): bool {
		return defined( self::CONSTANT_SOURCE )
			&& is_string( constant( self::CONSTANT_SOURCE ) )
			&& '' !== trim( (string) constant( self::CONSTANT_SOURCE ) );
	}

	/**
	 * Diagnostic snapshot for dashboards: machine slugs and booleans only — never the
	 * secret itself.
	 *
	 * @return array{source:string, crypto_available:bool, stored:bool, decodable:?bool}
	 */
	public static function storage_state(): array {
		if ( self::has_constant_source() ) {
			return array(
				'source'          => 'constant',
				'crypto_available' => self::is_crypto_available(),
				'stored'          => false,
				'decodable'       => null,
			);
		}

		$stored_slot = self::read_slot();

		if ( null === $stored_slot ) {
			return array(
				'source'           => 'none',
				'crypto_available' => self::is_crypto_available(),
				'stored'           => false,
				'decodable'        => null,
			);
		}

		return array(
			'source'           => 'encrypted',
			'crypto_available' => self::is_crypto_available(),
			'stored'           => true,
			'decodable'        => null !== self::decrypt_blob( $stored_slot ),
		);
	}

	/**
	 * Returns the Amelia API key: constant override first, decrypted storage second,
	 * null whenever either path cannot produce an authenticated value (never a guess,
	 * never a partial value, never a logged error containing the secret).
	 *
	 * @return string|null
	 */
	public static function get_amelia_api_key(): ?string {
		if ( self::has_constant_source() ) {
			return trim( (string) constant( self::CONSTANT_SOURCE ) );
		}

		$blob = self::read_slot();

		if ( null === $blob ) {
			return null;
		}

		return self::decrypt_blob( $blob );
	}

	/**
	 * Stores the Amelia API key encrypted-at-rest. Refuses instead of acting whenever the
	 * environment cannot honor the contract.
	 *
	 * @param string $key Raw key exactly as supplied by the authorized operator.
	 * @return array{ok:bool, reason:string}
	 */
	public static function set_amelia_api_key( string $key ): array {
		if ( ! self::may_write() ) {
			return array( 'ok' => false, 'reason' => 'denied' );
		}

		if ( self::has_constant_source() ) {
			return array( 'ok' => false, 'reason' => 'constant_override_active' );
		}

		if ( ! self::is_crypto_available() ) {
			return array( 'ok' => false, 'reason' => 'crypto_unavailable' );
		}

		$key = trim( $key );

		if ( '' === $key || strlen( $key ) > self::MAX_KEY_LEN ) {
			return array( 'ok' => false, 'reason' => 'invalid_key' );
		}

		$blob = self::encrypt_blob( $key );

		if ( null === $blob || ! is_string( $blob ) || ! str_starts_with( $blob, self::BLOB_PREFIX . ':' ) ) {
			return array( 'ok' => false, 'reason' => 'encryption_failed' );
		}

		$slots           = self::read_slots();
		$slots[ self::SLOT_AMELIA ] = $blob;

		update_option( self::OPTION_NAME, $slots, false );

		// Verify the stored OUTCOME rather than update_option()'s change-detection return,
		// which is false when the value was already identical.
		return self::read_slot() === $blob
			? array( 'ok' => true, 'reason' => 'stored' )
			: array( 'ok' => false, 'reason' => 'persist_failed' );
	}

	/**
	 * Revokes the stored key. A wp-config constant override cannot be cleared from here
	 * by design — the refusal names itself.
	 *
	 * @return array{ok:bool, reason:string}
	 */
	public static function clear_amelia_api_key(): array {
		if ( ! self::may_write() ) {
			return array( 'ok' => false, 'reason' => 'denied' );
		}

		if ( self::has_constant_source() ) {
			return array( 'ok' => false, 'reason' => 'constant_override_active' );
		}

		$slots = self::read_slots();
		unset( $slots[ self::SLOT_AMELIA ] );

		update_option( self::OPTION_NAME, $slots, false );

		return null === self::read_slot()
			? array( 'ok' => true, 'reason' => 'cleared' )
			: array( 'ok' => false, 'reason' => 'persist_failed' );
	}

	/**
	 * Safe display form for dashboards: at most the trailing four characters survive;
	 * short keys are hidden completely. The plaintext never reaches this return value.
	 *
	 * @return string|null Null when no usable key exists.
	 */
	public static function masked_preview(): ?string {
		$key = self::get_amelia_api_key();

		if ( null === $key ) {
			return null;
		}

		return self::mask( $key );
	}

	/**
	 * Masks an arbitrary secret for display.
	 *
	 * @param string $key Plaintext secret.
	 * @return string
	 */
	public static function mask( string $key ): string {
		$length = strlen( $key );

		if ( $length <= 4 ) {
			return str_repeat( '•', $length );
		}

		return str_repeat( '•', min( $length - 4, 12 ) ) . substr( $key, -4 );
	}

	/**
	 * Admin-context and capability boundary shared by all mutating verbs; request nonces
	 * belong to the calling endpoint.
	 *
	 * @return bool
	 */
	private static function may_write(): bool {
		return is_admin() && current_user_can( 'manage_options' );
	}

	/**
	 * @return array<string, string> All stored encrypted slots (raw blobs).
	 */
	private static function read_slots(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$slots = array();

		foreach ( $stored as $name => $blob ) {
			if ( is_string( $name ) && is_string( $blob ) ) {
				$slots[ $name ] = $blob;
			}
		}

		return $slots;
	}

	/**
	 * @return string|null The stored blob for the Amelia slot, or null.
	 */
	private static function read_slot(): ?string {
		$slots = self::read_slots();
		$blob  = $slots[ self::SLOT_AMELIA ] ?? null;

		return is_string( $blob ) && '' !== $blob ? $blob : null;
	}

	/**
	 * Derives the 32-byte secretbox key from WordPress salt material. Rotating the salts
	 * rotates this derivation, which is exactly what makes old blobs fail closed.
	 *
	 * @return string Binary key, or an empty string when salts/crypto are unavailable.
	 */
	private static function derived_key(): string {
		if ( ! self::is_crypto_available() || ! function_exists( 'wp_salt' ) ) {
			return '';
		}

		try {
			return sodium_crypto_generichash(
				'hal-member-profiles|' . wp_salt( 'auth' ),
				'',
				SODIUM_CRYPTO_SECRETBOX_KEYBYTES
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Seals plaintext into a versioned, base64-encoded, authenticated blob:
	 * hmpv1:<base64(nonce ‖ ciphertext)>.
	 *
	 * @param string $plaintext Secret bytes.
	 * @return string|null Blob, or null when sealing failed for any reason.
	 */
	private static function encrypt_blob( string $plaintext ): ?string {
		$key = self::derived_key();

		if ( '' === $key ) {
			return null;
		}

		try {
			$nonce     = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		} catch ( \Throwable $e ) {
			return null;
		}

		return self::BLOB_PREFIX . ':' . base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary AEAD output for option storage; this is NOT obfuscation of a secret.
	}

	/**
	 * Opens a stored blob. Any structural doubt, base64 damage, or authentication-tag
	 * mismatch yields null — never a partially decrypted value, never a thrown error.
	 *
	 * @param string $blob Stored blob.
	 * @return string|null Plaintext on success, null on every failure path.
	 */
	private static function decrypt_blob( string $blob ): ?string {
		$key = self::derived_key();

		if ( '' === $key ) {
			return null;
		}

		$prefix = self::BLOB_PREFIX . ':';

		if ( ! str_starts_with( $blob, $prefix ) ) {
			return null;
		}

		$packed = base64_decode( substr( $blob, strlen( $prefix ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Strict decoding of our own AEAD container; see the matching encode note.

		if ( ! is_string( $packed ) || strlen( $packed ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce     = substr( $packed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $packed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_string( $plaintext ) ? $plaintext : null;
	}
}
