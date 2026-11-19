<?php
/**
 * Unit tests for the WP-CLI composition-registry commands (development card G-09b —
 * owner decision 3, the F-09 item): sign-composition / list-compositions /
 * revoke-composition over the hal_member_profiles_composition_rows option.
 *
 * Acceptance mapping (execution card G-09b):
 * - (1) register() captures \WP_CLI::add_command( 'hal-member-profiles',
 *       CompatRegistryCommand::class ) — the D-04 conditional-load entry point.
 * - (2) sign happy path ('amelia' — the frozen registry has no amelia rows, so
 *       verdicts stay clean): the option stores the row under the capability and the
 *       stored signature EQUALS wp_hash over the test's INDEPENDENT canonical-JSON
 *       reimplementation of the row without 'signature' (CompatibilityGateTest's
 *       pattern).
 * - (3) END-TO-END acceptance: the stored rows injected through the documented filter
 *       'hal_member_profiles_approved_compositions' verify POST-FILTER and match —
 *       passes( 'amelia' ) TRUE + describe 'approved_composition' ("a signed external
 *       row verifies post-filter and matches").
 * - (4) a tampered row never matches: signature verification silently drops it and
 *       amelia — which has no frozen rows — keeps the closed no-rows verdict
 *       'awaiting_matrix_signoff', proving the row was dropped, not merely mismatched.
 * - (5) list-compositions returns the stored map; after a full revoke it returns
 *       array().
 * - (6) revoke with a capability argument clears only that capability (the capability
 *       key is deleted entirely; another capability's row survives); revoke without
 *       arguments clears everything.
 * - (7) permissions INSIDE every command: current_user 0 and non-admin capability
 *       (manage_options false) each record the error AND abort; the option stays
 *       untouched.
 * - (8) validation is fail-closed (each aborts unsigned, option untouched): unknown
 *       capability; malformed --versions JSON; empty versions map; non-scalar version
 *       value; unknown --type; missing --matrix-row.
 * - (9) floor row end-to-end: signed with type=floor + minimums, the gate passes on an
 *       environment exactly at the minimums (and on a patch-bumped one).
 *
 * wp_hash() is not part of the unit-stub fixture layer, so this file defines a
 * faithful global stub — the same pattern (and body) as
 * tests/Unit/CompatibilityGateTest.php: WordPress core's wp_hash( $data, $scheme =
 * 'auth', $algo = 'md5' ) is exactly hash_hmac( $algo, $data, wp_salt( $scheme ) ),
 * and the stub reproduces that implementation verbatim. The ONLY semantic difference
 * from production is the salt source — the fixture's fixed test salt ('unit-test-salt')
 * from tests/Fixtures/wp-stubs-extra.php instead of the site secret. The
 * function_exists guard makes the two identical definitions coexist harmlessly when
 * the whole suite runs in one process.
 *
 * WP_CLI is likewise absent from the fixture layer: this file defines the WP_CLI
 * constant (true) and a minimal GLOBAL WP_CLI double — registrations, errors, and
 * output are recorded; error() aborts by throwing a dedicated RuntimeException
 * subclass exactly where the real WP-CLI exits the process. No assertion below
 * concerns the frozen profile row: every gate construction here uses 'amelia' (whose
 * ground state is the closed no-rows verdict) or filtered rows, so a drifted
 * environment is irrelevant by construction.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

declare( strict_types=1 );

namespace {
	if ( ! defined( 'WP_CLI' ) ) {
		define( 'WP_CLI', true );
	}

	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Dedicated abort signal for the WP_CLI::error() stub: the real WP-CLI exits the
		 * process, the stub throws so callers abort the same way.
		 */
		class WP_CLI_Error_Exception extends \RuntimeException {
		}

		/**
		 * Minimal global WP-CLI double for the unit suite: records registrations,
		 * errors, and output; error() records AND throws.
		 */
		class WP_CLI {
			/**
			 * Recorded command registrations, keyed by command name.
			 *
			 * @var array<string, mixed>
			 */
			public static array $commands = array();

			/**
			 * Recorded error messages, in call order.
			 *
			 * @var array<int, string>
			 */
			public static array $errors = array();

			/**
			 * Recorded output as array( kind, message ) pairs (success / line / log).
			 *
			 * @var array<int, array<int, string>>
			 */
			public static array $messages = array();

			/**
			 * @param string $name          Command name.
			 * @param mixed  $implementation Callable or class name handling the command.
			 * @return void
			 */
			public static function add_command( $name, $implementation ) {
				self::$commands[ $name ] = $implementation;
			}

			/**
			 * Records the message and aborts (real WP_CLI::error exits the process).
			 *
			 * @param string $message   Error message.
			 * @param int    $exit_code Exit code (recorded as the exception code).
			 * @return void
			 * @throws WP_CLI_Error_Exception Always — the abort mechanism.
			 */
			public static function error( $message, $exit_code = 1 ) {
				self::$errors[] = (string) $message;

				throw new WP_CLI_Error_Exception( (string) $message, (int) $exit_code );
			}

			/**
			 * @param string $message Success message.
			 * @return void
			 */
			public static function success( $message ) {
				self::$messages[] = array( 'success', (string) $message );
			}

			/**
			 * @param string $message Informational line.
			 * @return void
			 */
			public static function line( $message ) {
				self::$messages[] = array( 'line', (string) $message );
			}

			/**
			 * @param string $message Debug/log line.
			 * @return void
			 */
			public static function log( $message ) {
				self::$messages[] = array( 'log', (string) $message );
			}

			/**
			 * Clears every recorded registration, error, and message.
			 *
			 * @return void
			 */
			public static function reset(): void {
				self::$commands = array();
				self::$errors   = array();
				self::$messages = array();
			}
		}
	}

	if ( ! function_exists( 'wp_hash' ) ) {
		/**
		 * Unit-test stub: byte-for-byte the WordPress core implementation of wp_hash(),
		 * resolving the salt through the fixture's wp_salt() stub (fixed test salt
		 * instead of the site secret). Defined in the GLOBAL namespace because the gate
		 * and the CLI command call wp_hash() unqualified from their own namespaces,
		 * which falls through to the global function — exactly like production. Same
		 * rationale as tests/Unit/CompatibilityGateTest.php.
		 *
		 * @param string $data   Data to hash.
		 * @param string $scheme Salt scheme ('auth' is used here).
		 * @param string $algo   Hash algorithm — passed 'sha256' explicitly (D-03).
		 * @return string HMAC of $data.
		 */
		function wp_hash( $data, $scheme = 'auth', $algo = 'md5' ) {
			return hash_hmac( $algo, $data, wp_salt( $scheme ) );
		}
	}
}

namespace HAL\MemberProfiles\Tests\Unit {

	use HAL\MemberProfiles\Cli\CompatRegistryCommand;
	use HAL\MemberProfiles\CompatibilityGate;
	use PHPUnit\Framework\TestCase;

	final class CompatRegistryCommandTest extends TestCase {

		private const FILTER_HOOK     = 'hal_member_profiles_approved_compositions';
		private const OPTION_KEY      = 'hal_member_profiles_composition_rows';
		private const AMELIA_VERSIONS = '{"wp":"6.5.2","php":"8.2.1","amelia":"7.1.4"}';

		/**
		 * Backed-up stub state restored in tearDown() so this file never leaks state
		 * into a sibling test file running in the same process.
		 *
		 * @var array<string, mixed>
		 */
		private array $backed_up;

		protected function setUp(): void {
			$this->backed_up = array(
				'options'      => $GLOBALS['wp_stubs']['options'] ?? array(),
				'current_user' => $GLOBALS['wp_stubs']['current_user'] ?? 0,
				'can_manage'   => $GLOBALS['wp_stubs']['can_manage'] ?? false,
			);

			remove_all_filters( self::FILTER_HOOK );
			\WP_CLI::reset();
			$GLOBALS['wp_stubs']['options']      = array();
			$GLOBALS['wp_stubs']['current_user'] = 1;
			$GLOBALS['wp_stubs']['can_manage']   = true;
		}

		protected function tearDown(): void {
			remove_all_filters( self::FILTER_HOOK );
			\WP_CLI::reset();
			$GLOBALS['wp_stubs']['options']      = $this->backed_up['options'];
			$GLOBALS['wp_stubs']['current_user'] = $this->backed_up['current_user'];
			$GLOBALS['wp_stubs']['can_manage']   = $this->backed_up['can_manage'];
		}

		// ---------------------------------------------------------------
		// (1) register() — the D-04 conditional-load entry point
		// ---------------------------------------------------------------

		public function test_register_captures_the_wp_cli_add_command_registration(): void {
			CompatRegistryCommand::register();

			$this->assertSame(
				array( 'hal-member-profiles' => CompatRegistryCommand::class ),
				\WP_CLI::$commands
			);
		}

		// ---------------------------------------------------------------
		// (2) sign happy path: stored row + independently verified signature
		// ---------------------------------------------------------------

		public function test_sign_composition_stores_a_correctly_signed_amelia_row(): void {
			$row = $this->sign_amelia_pinned_row();

			$this->assertSame( 'g09b-test-pinned', $row['matrix_row'] );
			$this->assertSame( true, $row['signed'] );
			$this->assertSame( 'pinned', $row['type'] );
			$this->assertSame(
				array(
					'wp'     => '6.5.2',
					'php'    => '8.2.1',
					'amelia' => '7.1.4',
				),
				$row['versions']
			);

			// The stored signature EQUALS wp_hash over the INDEPENDENT canonical-JSON
			// reimplementation of the row without 'signature' — the CLI must sign exactly
			// the documented canonical form, not some private variation of it.
			$unsigned = $row;
			unset( $unsigned['signature'] );

			$this->assertSame(
				wp_hash( $this->test_canonical_json( $unsigned ), 'auth', 'sha256' ),
				$row['signature']
			);

			$stored = get_option( self::OPTION_KEY, array() );

			$this->assertArrayHasKey( 'amelia', $stored );
			$this->assertSame( array( $row ), $stored['amelia'] );

			// The success output mentions the capability and the option key.
			$successes = $this->success_messages();

			$this->assertCount( 1, $successes );
			$this->assertStringContainsString( 'amelia', $successes[0] );
			$this->assertStringContainsString( self::OPTION_KEY, $successes[0] );
		}

		// ---------------------------------------------------------------
		// (3) END-TO-END: a signed external row verifies post-filter and matches
		// ---------------------------------------------------------------

		public function test_signed_row_verifies_post_filter_and_matches_end_to_end(): void {
			// Ground state: without the filter, amelia has NO rows at all (the frozen
			// registry carries none) — the gate stays closed awaiting_matrix_signoff.
			$closed = new CompatibilityGate( $this->amelia_environment() );

			$this->assertSame( 'awaiting_matrix_signoff', $closed->describe( 'amelia' ) );

			$this->sign_amelia_pinned_row();
			$this->inject_stored_rows_via_filter();

			// The constructor filter receives the stored rows; the signed row is EXTERNAL
			// (strictly not equal to any pre-filter row) and must verify post-filter
			// (F-02a step 3) before it can match the environment.
			$gate = new CompatibilityGate( $this->amelia_environment() );

			$this->assertTrue( $gate->passes( 'amelia' ) );
			$this->assertSame( 'approved_composition', $gate->describe( 'amelia' ) );
		}

		// ---------------------------------------------------------------
		// (4) a tampered row never matches
		// ---------------------------------------------------------------

		public function test_tampered_stored_row_never_matches_end_to_end(): void {
			$this->sign_amelia_pinned_row();

			$rows    = get_option( self::OPTION_KEY, array() );
			$tampered = $rows['amelia'][0];

			// Deliberately tamper a field that is NOT part of the version comparison: if
			// the gate failed to verify signatures, this row would still match the
			// environment and approve. After the row is dropped, amelia has NO rows at
			// all (no frozen amelia rows) — the closed no-rows verdict below proves the
			// row was dropped, not merely mismatched (that would be
			// composition_mismatch instead).
			$tampered['matrix_row'] = 'tampered-after-signing';

			add_filter(
				self::FILTER_HOOK,
				static function ( $approved ) use ( $tampered ) {
					$approved['amelia'][] = $tampered;

					return $approved;
				}
			);

			$gate = new CompatibilityGate( $this->amelia_environment() );

			$this->assertFalse( $gate->passes( 'amelia' ) );
			$this->assertSame( 'awaiting_matrix_signoff', $gate->describe( 'amelia' ) );
		}

		// ---------------------------------------------------------------
		// (5) list-compositions returns the stored map; full revoke clears it
		// ---------------------------------------------------------------

		public function test_list_compositions_returns_the_stored_map_and_full_revoke_clears_it(): void {
			$command = new CompatRegistryCommand();
			$row     = $this->sign_amelia_pinned_row();

			$listed = $command->list_compositions();

			$this->assertSame( array( 'amelia' => array( $row ) ), $listed );

			// One line per capability/row was emitted while listing.
			$this->assertCount( 1, $this->line_messages() );
			$this->assertStringContainsString( 'amelia', $this->line_messages()[0] );

			$summary = $command->revoke_composition();

			$this->assertSame(
				array(
					'revoked'   => 'all',
					'remaining' => array(),
				),
				$summary
			);
			$this->assertSame( array(), $command->list_compositions() );
		}

		// ---------------------------------------------------------------
		// (6) scoped revoke clears only its capability; full revoke clears all
		// ---------------------------------------------------------------

		public function test_scoped_revoke_clears_only_that_capability(): void {
			$command = new CompatRegistryCommand();

			$this->sign_amelia_pinned_row();

			$templates_row = $command->sign_composition(
				array( 'managed_templates' ),
				array(
					'matrix-row' => 'g09b-test-templates',
					'versions'   => '{"wp":"6.5.2","php":"8.2.1","theme":"astra-child"}',
				)
			);

			$summary = $command->revoke_composition( array( 'amelia' ) );

			$this->assertSame( 'amelia', $summary['revoked'] );
			$this->assertSame( array( 'managed_templates' => array( $templates_row ) ), $summary['remaining'] );

			$stored = get_option( self::OPTION_KEY, array() );

			// The capability key is deleted ENTIRELY, and the sibling capability's row
			// survives untouched.
			$this->assertArrayNotHasKey( 'amelia', $stored );
			$this->assertSame( array( 'managed_templates' => array( $templates_row ) ), $stored );
		}

		public function test_full_revoke_clears_every_capability(): void {
			$command = new CompatRegistryCommand();

			$this->sign_amelia_pinned_row();
			$command->sign_composition(
				array( 'managed_templates' ),
				array(
					'matrix-row' => 'g09b-test-templates',
					'versions'   => '{"wp":"6.5.2","php":"8.2.1","theme":"astra-child"}',
				)
			);

			$summary = $command->revoke_composition();

			$this->assertSame( 'all', $summary['revoked'] );
			$this->assertSame( array(), $summary['remaining'] );
			$this->assertSame( array(), get_option( self::OPTION_KEY, array() ) );
		}

		// ---------------------------------------------------------------
		// (7) permissions INSIDE every command
		// ---------------------------------------------------------------

		public function test_commands_require_a_resolved_admin_user_inside(): void {
			$GLOBALS['wp_stubs']['current_user'] = 0;
			$GLOBALS['wp_stubs']['can_manage']   = true;

			$this->assert_command_aborts_for_missing_permissions(
				static fn () => ( new CompatRegistryCommand() )->sign_composition(
					array( 'amelia' ),
					array(
						'matrix-row' => 'g09b-test-pinned',
						'versions'   => self::AMELIA_VERSIONS,
					)
				)
			);
			$this->assert_command_aborts_for_missing_permissions(
				static fn () => ( new CompatRegistryCommand() )->list_compositions()
			);
			$this->assert_command_aborts_for_missing_permissions(
				static fn () => ( new CompatRegistryCommand() )->revoke_composition()
			);

			$this->assert_option_untouched();
		}

		public function test_commands_require_the_manage_options_capability_inside(): void {
			$GLOBALS['wp_stubs']['current_user'] = 1;
			$GLOBALS['wp_stubs']['can_manage']   = false;

			$this->assert_command_aborts_for_missing_permissions(
				static fn () => ( new CompatRegistryCommand() )->sign_composition(
					array( 'amelia' ),
					array(
						'matrix-row' => 'g09b-test-pinned',
						'versions'   => self::AMELIA_VERSIONS,
					)
				)
			);
			$this->assert_command_aborts_for_missing_permissions(
				static fn () => ( new CompatRegistryCommand() )->list_compositions()
			);
			$this->assert_command_aborts_for_missing_permissions(
				static fn () => ( new CompatRegistryCommand() )->revoke_composition()
			);

			$this->assert_option_untouched();
		}

		// ---------------------------------------------------------------
		// (8) validation is fail-closed: abort unsigned, option untouched
		// ---------------------------------------------------------------

		public function test_unknown_capability_is_rejected_without_signing(): void {
			$this->assert_validation_aborts(
				static fn () => ( new CompatRegistryCommand() )->sign_composition(
					array( 'nonexistent' ),
					array(
						'matrix-row' => 'g09b-test-pinned',
						'versions'   => self::AMELIA_VERSIONS,
					)
				)
			);
		}

		public function test_malformed_versions_json_is_rejected_without_signing(): void {
			$this->assert_validation_aborts(
				static fn () => ( new CompatRegistryCommand() )->sign_composition(
					array( 'amelia' ),
					array(
						'matrix-row' => 'g09b-test-pinned',
						'versions'   => '{"wp": 6.5.2',
					)
				)
			);
		}

		public function test_empty_versions_map_is_rejected_without_signing(): void {
			$this->assert_validation_aborts(
				static fn () => ( new CompatRegistryCommand() )->sign_composition(
					array( 'amelia' ),
					array(
						'matrix-row' => 'g09b-test-pinned',
						'versions'   => '{}',
					)
				)
			);
		}

		public function test_non_scalar_version_value_is_rejected_without_signing(): void {
			$this->assert_validation_aborts(
				static fn () => ( new CompatRegistryCommand() )->sign_composition(
					array( 'amelia' ),
					array(
						'matrix-row' => 'g09b-test-pinned',
						'versions'   => '{"wp":{"nested":"object"}}',
					)
				)
			);
		}

		public function test_unknown_type_is_rejected_without_signing(): void {
			$this->assert_validation_aborts(
				static fn () => ( new CompatRegistryCommand() )->sign_composition(
					array( 'amelia' ),
					array(
						'type'       => 'bananas',
						'matrix-row' => 'g09b-test-pinned',
						'versions'   => self::AMELIA_VERSIONS,
					)
				)
			);
		}

		public function test_missing_matrix_row_is_rejected_without_signing(): void {
			$this->assert_validation_aborts(
				static fn () => ( new CompatRegistryCommand() )->sign_composition(
					array( 'amelia' ),
					array(
						'versions' => self::AMELIA_VERSIONS,
					)
				)
			);
		}

		// ---------------------------------------------------------------
		// (9) floor row end-to-end: signed through the CLI, opens the gate
		// ---------------------------------------------------------------

		public function test_floor_row_signed_through_the_cli_opens_the_gate_at_its_minimums(): void {
			$row = ( new CompatRegistryCommand() )->sign_composition(
				array( 'amelia' ),
				array(
					'type'       => 'floor',
					'matrix-row' => 'g09b-test-floor',
					'versions'   => '{"wp":"6.5.0","php":"8.2.0","amelia":"7.0.0"}',
				)
			);

			$this->assertSame( 'floor', $row['type'] );

			$this->inject_stored_rows_via_filter();

			// Exactly at the signed minimums: every floor comparison holds.
			$gate = new CompatibilityGate(
				$this->amelia_environment(
					array(
						'wp'     => '6.5.0',
						'php'    => '8.2.0',
						'amelia' => '7.0.0',
					)
				)
			);

			$this->assertTrue( $gate->passes( 'amelia' ) );
			$this->assertSame( 'approved_composition', $gate->describe( 'amelia' ) );

			// Floor semantics: a patch-bumped environment keeps matching without re-signing.
			$bumped = new CompatibilityGate(
				$this->amelia_environment(
					array(
						'wp'     => '6.5.9',
						'php'    => '8.2.29',
						'amelia' => '7.0.14',
					)
				)
			);

			$this->assertTrue( $bumped->passes( 'amelia' ) );
		}

		// ---------------------------------------------------------------
		// Helpers.
		// ---------------------------------------------------------------

		/**
		 * Signs one pinned amelia row through the command under the default admin stub
		 * context (current_user 1 + manage_options true, set in setUp).
		 *
		 * @return array<string, mixed> The signed row as stored.
		 */
		private function sign_amelia_pinned_row(): array {
			return ( new CompatRegistryCommand() )->sign_composition(
				array( 'amelia' ),
				array(
					'type'       => 'pinned',
					'matrix-row' => 'g09b-test-pinned',
					'versions'   => self::AMELIA_VERSIONS,
				)
			);
		}

		/**
		 * Current-environment fixture for the `amelia` capability (its presence floor
		 * needs wp, php, amelia), with optional per-component overrides.
		 *
		 * @param array<string, string> $overrides Component overrides.
		 * @return array<string, string>
		 */
		private function amelia_environment( array $overrides = array() ): array {
			return array_merge(
				array(
					'wp'     => '6.5.2',
					'php'    => '8.2.1',
					'amelia' => '7.1.4',
				),
				$overrides
			);
		}

		/**
		 * Injects the STORED rows through the documented constructor filter, following
		 * the documented callback contract (append per capability, return the registry).
		 *
		 * @return void
		 */
		private function inject_stored_rows_via_filter(): void {
			$rows = get_option( self::OPTION_KEY, array() );

			if ( ! is_array( $rows ) ) {
				$rows = array();
			}

			add_filter(
				self::FILTER_HOOK,
				static function ( $approved ) use ( $rows ) {
					foreach ( $rows as $capability => $capability_rows ) {
						if ( ! is_array( $capability_rows ) ) {
							continue;
						}

						foreach ( $capability_rows as $row ) {
							$approved[ $capability ][] = $row;
						}
					}

					return $approved;
				}
			);
		}

		/**
		 * Asserts the command aborted through WP_CLI::error() with the exact shared
		 * permission message (recorded BEFORE the abort) — unauthenticated and
		 * non-admin invocations must never reach any read or write.
		 *
		 * @param callable $run The command invocation under test.
		 * @return void
		 */
		private function assert_command_aborts_for_missing_permissions( callable $run ): void {
			\WP_CLI::reset();

			$thrown = null;

			try {
				$run();
			} catch ( \WP_CLI_Error_Exception $exception ) {
				$thrown = $exception;
			}

			$this->assertNotNull( $thrown, 'The command must abort via WP_CLI::error.' );
			$this->assertSame(
				array( 'This command requires --user=<admin> with the manage_options capability.' ),
				\WP_CLI::$errors
			);
		}

		/**
		 * Asserts a validation failure aborted through WP_CLI::error() with exactly one
		 * recorded error and left the option untouched (nothing was signed or stored).
		 *
		 * @param callable $run The command invocation under test.
		 * @return void
		 */
		private function assert_validation_aborts( callable $run ): void {
			\WP_CLI::reset();

			$thrown = null;

			try {
				$run();
			} catch ( \WP_CLI_Error_Exception $exception ) {
				$thrown = $exception;
			}

			$this->assertNotNull( $thrown, 'Validation must abort the command via WP_CLI::error.' );
			$this->assertCount( 1, \WP_CLI::$errors, 'Exactly one error is recorded before the abort.' );
			$this->assert_option_untouched();
		}

		/**
		 * The raw stub options map must not carry the composition-rows key at all —
		 * proof the option was never written.
		 *
		 * @return void
		 */
		private function assert_option_untouched(): void {
			$this->assertArrayNotHasKey( self::OPTION_KEY, $GLOBALS['wp_stubs']['options'] );
		}

		/**
		 * @return array<int, string> Recorded success messages.
		 */
		private function success_messages(): array {
			$out = array();

			foreach ( \WP_CLI::$messages as $message ) {
				if ( 'success' === $message[0] ) {
					$out[] = $message[1];
				}
			}

			return $out;
		}

		/**
		 * @return array<int, string> Recorded informational lines.
		 */
		private function line_messages(): array {
			$out = array();

			foreach ( \WP_CLI::$messages as $message ) {
				if ( 'line' === $message[0] ) {
					$out[] = $message[1];
				}
			}

			return $out;
		}

		/**
		 * Independent re-implementation of the canonical form documented on
		 * CompatibilityGate::canonical_json() — see that method for the normative
		 * algorithm. Deliberately NOT a reflection call (the contract forbids reaching
		 * into the gate): this proves the CLI's signature is computed over the
		 * documented canonical form, reproducible from the description alone.
		 *
		 * @param mixed $value Value to encode.
		 * @return string Canonical JSON.
		 */
		private static function test_canonical_json( $value ): string {
			$encoded = json_encode( self::test_canonicalize( $value ) );

			return false === $encoded ? '' : $encoded;
		}

		/**
		 * Independent re-implementation of the recursive canonicalization pass.
		 *
		 * @param mixed $value Value to canonicalize.
		 * @return mixed
		 */
		private static function test_canonicalize( $value ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}

			$keys = array_keys( $value );
			$list = true;

			for ( $i = 0, $n = count( $keys ); $i < $n; $i++ ) {
				if ( $keys[ $i ] !== $i ) {
					$list = false;
					break;
				}
			}

			if ( $list ) {
				$out = array();

				foreach ( $value as $item ) {
					$out[] = self::test_canonicalize( $item );
				}

				return $out;
			}

			$entries = array();

			foreach ( $value as $key => $item ) {
				$entries[ (string) $key ] = self::test_canonicalize( $item );
			}

			ksort( $entries, SORT_STRING );

			return $entries;
		}
	}
}
