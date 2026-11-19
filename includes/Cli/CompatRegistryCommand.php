<?php
/**
 * WP-CLI commands that manage the per-site signed composition registry (development
 * card G-09b — owner decision 3, the F-09 item of the v1.3.0 contract).
 *
 * Purpose: an operator-manageable way to sign an approved composition for a site
 * without a code change (multi-environment operation: staging, client sites,
 * multisite per-site needs). Three subcommands are exposed under
 * `wp hal-member-profiles`: sign-composition, list-compositions, revoke-composition.
 *
 * Loading: registration happens under `defined( 'WP_CLI' ) && WP_CLI` — the official
 * WP-CLI conditional-loading pattern (card D-04). The main plugin file requires this
 * file and calls CompatRegistryCommand::register() inside exactly that guard; there is
 * deliberately no Bootstrap involvement.
 *
 * Permissions: this is an EXPLICIT in-plugin permission decision — the official WP-CLI
 * documentation defines no "CLI = trust" model, so EVERY command runs authorize()
 * FIRST, which requires --user=<admin> AND current_user_can( 'manage_options' ) INSIDE
 * the command. Unauthenticated or non-admin invocations abort through \WP_CLI::error()
 * before any option is read or written.
 *
 * Single approval source: these commands introduce NO version gate and NO second
 * approval source of their own — no version_compare logic, no approvals. They only
 * sign, store, list, and revoke registry rows in the hal_member_profiles_composition_rows
 * option. The compatibility gate's signed registry (CompatibilityGate) plus the
 * ManagedTemplates WRITES_APPROVED_SINCE write gate remain the ONLY approval
 * authorities; this CLI is purely a row-authoring tool for the gate's external-row
 * input, and every row it stores is re-verified post-filter by CompatibilityGate on
 * each construction (F-02a step 3) before it can ever match.
 *
 * Canonical JSON: canonical_json() / canonicalize() / is_sequential_list() below
 * reproduce EXACTLY the algorithm documented on CompatibilityGate::canonical_json() —
 * that docblock IS the normative contract (it explicitly states the F-09 tooling must
 * reproduce it), so this file copies it byte-for-byte instead of reaching into the
 * gate's private method. The signature is wp_hash( $canonical, 'auth', 'sha256' ) —
 * the algorithm is passed EXPLICITLY, never left to the wp_hash default (audit
 * round 3, D-03). A stored row opens a gate verdict only when this reproduction and
 * the gate's verifier agree byte-for-byte.
 *
 * Rows are stored per capability as array( <capability> => array( <row>, ... ), ... )
 * in the row shape documented on CompatibilityGate (with 'signed' => true and the
 * 'signature' key). Capability slugs come exclusively from the CompatibilityGate::CAP_*
 * constants — no second name list exists. uninstall.php already deletes this option
 * (contract line: uninstall.php:29; F-15 re-verified).
 *
 * Output strings are plain English via \WP_CLI::success/line/error — no __()
 * (WP-CLI convention, no POT impact).
 *
 * @package HAL\MemberProfiles\Cli
 */

declare( strict_types=1 );

namespace HAL\MemberProfiles\Cli;

use HAL\MemberProfiles\CompatibilityGate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompatRegistryCommand {

	/**
	 * Per-site option holding the signed external composition rows, keyed by capability.
	 *
	 * @var string
	 */
	private const OPTION_KEY = 'hal_member_profiles_composition_rows';

	/**
	 * Registers the command class with WP-CLI. Deliberately a no-op outside a real
	 * WP-CLI environment (the D-04 conditional-load pattern: defined( 'WP_CLI' ) &&
	 * WP_CLI, and the WP-CLI class actually being present).
	 *
	 * WP-CLI class registration maps public methods to dasherized subcommands, so
	 * `wp hal-member-profiles sign-composition` resolves to sign_composition() below.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( \WP_CLI::class ) ) {
			return;
		}

		\WP_CLI::add_command( 'hal-member-profiles', self::class );
	}

	/**
	 * Signs one composition row and stores it in the per-site option.
	 *
	 * `wp hal-member-profiles sign-composition <capability> --type=<pinned|floor>
	 * --matrix-row=<label> --versions=<JSON object>` (--type defaults to 'pinned').
	 *
	 * The row is built in the gate's documented shape with 'signed' => true, its
	 * canonical JSON is computed WITHOUT the signature key, and the signature is
	 * wp_hash( $canonical, 'auth', 'sha256' ) — the algorithm passed EXPLICITLY
	 * (audit round 3, D-03).
	 *
	 * Nothing is dropped silently: an unknown capability, an unknown type, a missing
	 * --matrix-row, malformed --versions JSON, an empty versions map, a non-scalar
	 * version value, or an empty component key aborts the command with
	 * \WP_CLI::error() before anything is stored.
	 *
	 * @param array<int, mixed>    $args       Positional arguments: [0] = capability slug.
	 * @param array<string, mixed> $assoc_args Associative flags: type, matrix-row, versions.
	 * @return array<string, mixed> The signed row as stored.
	 */
	public function sign_composition( array $args, array $assoc_args = array() ): array {
		self::authorize();

		$capability = (string) ( $args[0] ?? '' );

		if ( ! in_array( $capability, self::capabilities(), true ) ) {
			\WP_CLI::error(
				'Unknown capability "' . $capability . '". Allowed: ' . implode( ', ', self::capabilities() ) . '.'
			);
		}

		$type = $assoc_args['type'] ?? 'pinned';

		if ( ! is_string( $type ) || ( 'pinned' !== $type && 'floor' !== $type ) ) {
			\WP_CLI::error( 'Unknown row type. Allowed: pinned, floor.' );
		}

		$raw_matrix_row = $assoc_args['matrix-row'] ?? '';

		if ( ! is_string( $raw_matrix_row ) || '' === trim( $raw_matrix_row ) ) {
			\WP_CLI::error( 'Missing required --matrix-row=<label> (the matrix row id / date this row mirrors).' );
		}

		$matrix_row = trim( $raw_matrix_row );

		$raw_versions = $assoc_args['versions'] ?? '';

		if ( ! is_string( $raw_versions ) ) {
			\WP_CLI::error( '--versions must be a single JSON object string of component => version pairs.' );
		}

		if ( '' === trim( $raw_versions ) ) {
			\WP_CLI::error( 'Missing required --versions=<JSON object of component => version pairs>.' );
		}

		$decoded = json_decode( $raw_versions, true );

		if ( ! is_array( $decoded ) ) {
			\WP_CLI::error( '--versions is not valid JSON: ' . json_last_error_msg() . '.' );
		}

		$row = array(
			'matrix_row' => $matrix_row,
			'signed'     => true,
			'type'       => $type,
			'versions'   => self::normalize_versions_map( $decoded ),
		);

		$canonical = self::canonical_json( $row );

		// canonical_json() returns null only when json_encode() cannot encode the value
		// (malformed UTF-8, depth overflow). The normalized row above is plain scalars,
		// but a corrupted --matrix-row payload must never be signed with a partial
		// canonical form — fail closed instead.
		if ( null === $canonical ) {
			\WP_CLI::error( 'The row could not be encoded to canonical JSON; nothing was signed.' );
		}

		$row['signature'] = wp_hash( $canonical, 'auth', 'sha256' );

		$rows = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$rows[ $capability ][] = $row;

		update_option( self::OPTION_KEY, $rows );

		\WP_CLI::success(
			'Signed ' . $type . ' composition for capability "' . $capability . '" (matrix row: ' . $matrix_row
			. '); stored in option ' . self::OPTION_KEY . '.'
		);

		return $row;
	}

	/**
	 * Lists every stored composition row, grouped per capability.
	 *
	 * `wp hal-member-profiles list-compositions` — emits one line per capability/row
	 * (matrix row, type, versions summary, whether a signature is present) and returns
	 * the full rows map. A corrupted option entry (non-array) is skipped so the listing
	 * can never fatal; nothing is modified.
	 *
	 * @param array<int, mixed>    $args       Positional arguments (none used).
	 * @param array<string, mixed> $assoc_args Associative flags (none used).
	 * @return array<string, mixed> The full stored rows map (empty array when none).
	 */
	public function list_compositions( array $args = array(), array $assoc_args = array() ): array {
		self::authorize();

		$rows = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		if ( array() === $rows ) {
			\WP_CLI::line( 'No composition rows are stored in ' . self::OPTION_KEY . '.' );

			return array();
		}

		foreach ( $rows as $capability => $capability_rows ) {
			if ( ! is_array( $capability_rows ) ) {
				continue;
			}

			foreach ( array_values( $capability_rows ) as $index => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$versions = is_array( $row['versions'] ?? null ) ? $row['versions'] : array();
				$pairs    = array();

				foreach ( $versions as $component => $version ) {
					$pairs[] = $component . '=' . (string) $version;
				}

				\WP_CLI::line(
					'[' . $capability . '] row ' . $index . ': matrix_row="' . ( $row['matrix_row'] ?? '' )
					. '", type=' . ( $row['type'] ?? 'pinned' )
					. ', versions: ' . ( array() === $pairs ? '(none)' : implode( ', ', $pairs ) )
					. ', signature: ' . ( ! empty( $row['signature'] ) ? 'present' : 'absent' )
				);
			}
		}

		return $rows;
	}

	/**
	 * Revokes stored composition rows: one capability's rows (positional capability
	 * argument) or the whole registry (no argument).
	 *
	 * `wp hal-member-profiles revoke-composition [<capability>]`.
	 *
	 * A per-capability revoke unsets that capability's key ENTIRELY (an emptied list
	 * leaves no key behind) and stores the remainder. A full revoke stores an empty
	 * array (update_option, NOT delete_option — the option must keep existing as an
	 * explicit empty registry). A scoped revoke never touches other capabilities.
	 *
	 * @param array<int, mixed>    $args       Positional arguments: [0] = capability slug (optional).
	 * @param array<string, mixed> $assoc_args Associative flags (none used).
	 * @return array<string, mixed> Summary: array( 'revoked' => <capability slug or 'all'>, 'remaining' => <the rows map left in the option, array() on a full clear> ).
	 */
	public function revoke_composition( array $args = array(), array $assoc_args = array() ): array {
		self::authorize();

		$rows = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$capability = (string) ( $args[0] ?? '' );

		if ( '' !== $capability ) {
			if ( ! in_array( $capability, self::capabilities(), true ) ) {
				\WP_CLI::error(
					'Unknown capability "' . $capability . '". Allowed: ' . implode( ', ', self::capabilities() ) . '.'
				);
			}

			$remaining = $rows;

			unset( $remaining[ $capability ] );

			update_option( self::OPTION_KEY, $remaining );

			\WP_CLI::success(
				'Revoked every stored composition row for capability "' . $capability . '" in ' . self::OPTION_KEY
				. '; other capabilities are untouched.'
			);

			return array(
				'revoked'   => $capability,
				'remaining' => $remaining,
			);
		}

		update_option( self::OPTION_KEY, array() );

		\WP_CLI::success( 'Revoked ALL stored composition rows: option ' . self::OPTION_KEY . ' is now empty.' );

		return array(
			'revoked'   => 'all',
			'remaining' => array(),
		);
	}

	/**
	 * The shared permission gate for EVERY command (explicit in-plugin permission
	 * decision — no official "CLI = trust" model exists): the caller must run under
	 * --user with a resolved WordPress user that holds manage_options. Anything else
	 * aborts through \WP_CLI::error() BEFORE any option is read or written
	 * (fail-closed; real WP_CLI::error exits, the unit-stub double throws).
	 *
	 * @return void
	 */
	private static function authorize(): void {
		if ( 0 === get_current_user_id() || ! current_user_can( 'manage_options' ) ) {
			\WP_CLI::error( 'This command requires --user=<admin> with the manage_options capability.' );
		}
	}

	/**
	 * The seven capability slugs this plugin exposes, taken from the
	 * CompatibilityGate::CAP_* constants — the single source of truth. Capability
	 * validation in sign-composition / revoke-composition never hardcodes names.
	 *
	 * @return array<int, string>
	 */
	private static function capabilities(): array {
		return array(
			CompatibilityGate::CAP_PROFILE,
			CompatibilityGate::CAP_AMELIA,
			CompatibilityGate::CAP_MANAGED_TEMPLATES,
			CompatibilityGate::CAP_AMELIA_API_READ,
			CompatibilityGate::CAP_AMELIA_FIELDS_WRITE,
			CompatibilityGate::CAP_UM_SCHEMA,
			CompatibilityGate::CAP_ELEMENTOR_DYNAMIC_TAGS,
		);
	}

	/**
	 * Validates and normalizes a decoded --versions payload: keys lowercased + trimmed,
	 * values cast to trimmed strings. Nothing is dropped silently — an empty map, a
	 * non-string or empty key, or a non-scalar value aborts the command with
	 * \WP_CLI::error(), so a malformed row can never be signed (fail-closed: the gate
	 * would drop such a row anyway, but the operator must hear about it here).
	 *
	 * @param array<mixed> $map The associative array json_decode() produced.
	 * @return array<string, string> Normalized component => version map.
	 */
	private static function normalize_versions_map( array $map ): array {
		if ( array() === $map ) {
			\WP_CLI::error( '--versions must decode to a NON-EMPTY JSON object of component => version pairs.' );
		}

		$normalized = array();

		foreach ( $map as $raw_key => $raw_value ) {
			if ( ! is_string( $raw_key ) || '' === trim( $raw_key ) ) {
				\WP_CLI::error( '--versions carries an empty or non-string component key; every key must be a non-empty string.' );
			}

			if ( ! is_scalar( $raw_value ) ) {
				\WP_CLI::error( '--versions value for component "' . (string) $raw_key . '" is not a scalar; every version must be a scalar.' );
			}

			$normalized[ strtolower( trim( $raw_key ) ) ] = trim( (string) $raw_value );
		}

		return $normalized;
	}

	/**
	 * Canonical JSON encoding of a registry row for HMAC signing — a byte-for-byte
	 * reproduction of the algorithm documented on CompatibilityGate::canonical_json()
	 * (that docblock IS the normative contract the F-09 tooling must reproduce; this
	 * copy deliberately does NOT touch the gate's private method). See that method for
	 * the full step-by-step algorithm description.
	 *
	 * @param mixed $value Row without its top-level signature key.
	 * @return string|null Canonical JSON string, or null when the value cannot be encoded.
	 */
	private static function canonical_json( $value ): ?string {
		$encoded = json_encode( self::canonicalize( $value ) );

		return false === $encoded ? null : $encoded;
	}

	/**
	 * Recursive canonicalization pass used by canonical_json() — byte-for-byte the
	 * algorithm documented on CompatibilityGate::canonical_json(): sequential lists
	 * (exact 0,1,2,... keys; the empty array counts as a list) keep index order;
	 * everything else is a map whose keys are cast to strings and sorted with
	 * ksort( ..., SORT_STRING ); values are canonicalized recursively.
	 *
	 * @param mixed $value Value to canonicalize.
	 * @return mixed Canonicalized value.
	 */
	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( self::is_sequential_list( $value ) ) {
			$list = array();

			foreach ( $value as $item ) {
				$list[] = self::canonicalize( $item );
			}

			return $list;
		}

		$entries = array();

		foreach ( $value as $key => $item ) {
			$entries[ (string) $key ] = self::canonicalize( $item );
		}

		ksort( $entries, SORT_STRING );

		return $entries;
	}

	/**
	 * Whether the array's keys, in storage order, are exactly the integers 0,1,2,...;
	 * the empty array counts as a sequential list.
	 *
	 * @param array<mixed> $value Array to inspect.
	 * @return bool
	 */
	private static function is_sequential_list( array $value ): bool {
		$expected = 0;

		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected++ ) {
				return false;
			}
		}

		return true;
	}
}
