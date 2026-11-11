<?php
/**
 * Unit tests for the CompatibilityGate F-02a card (steps 1-3 of F-02): the two row
 * types (pinned / floor), the constructor registry-extension filter, and post-filter
 * HMAC signature verification over the documented canonical JSON form.
 *
 * Acceptance mapping (execution card F-02a, item 5):
 * - (a) valid signed row + matching environment -> approved_composition (pinned and
 *       floor separately)
 * - (b) tampered or unsigned external row -> silently dropped, verdict unchanged
 * - (c) floor row survives patch-level component updates
 * - (d) empty constant with no filter -> awaiting_matrix_signoff (no regression)
 * - (e) the constructor filter is actually invoked and its rows open the gate
 * - (f) canonical JSON stability (same input -> same signature; permuted key order ->
 *       same signature)
 *
 * wp_hash() is not part of the unit-stub fixture layer, so this file defines a
 * faithful global stub: WordPress core's wp_hash( $data, $scheme = 'auth', $algo =
 * 'md5' ) is exactly `hash_hmac( $algo, $data, wp_salt( $scheme ) )`, and the stub
 * reproduces that implementation verbatim. The ONLY semantic difference from
 * production is the salt source — the fixture's fixed test salt ('unit-test-salt')
 * from tests/Fixtures/wp-stubs-extra.php instead of the site secret. Same HMAC
 * algorithm, same data, same explicit 'sha256' algo (D-03), same hex output, and the
 * gate's verification code path is byte-identical between production and test.
 *
 * test_canonical_json() below is an INDEPENDENT re-implementation of the canonical
 * form documented on CompatibilityGate::canonical_json() — deliberately not a
 * reflection call on the gate itself, because that documentation is the contract the
 * later F-09 tooling must reproduce; these tests prove the documented contract is
 * reproducible from its description alone.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

declare( strict_types=1 );

namespace {
	if ( ! function_exists( 'wp_hash' ) ) {
		/**
		 * Unit-test stub: byte-for-byte the WordPress core implementation of wp_hash(),
		 * resolving the salt through the fixture's wp_salt() stub (fixed test salt
		 * instead of the site secret). Defined in the GLOBAL namespace because the gate
		 * calls wp_hash() unqualified from its own namespace, which falls through to
		 * the global function — exactly like production.
		 *
		 * @param string $data   Data to hash.
		 * @param string $scheme Salt scheme ('auth' is used by the gate).
		 * @param string $algo   Hash algorithm — the gate passes 'sha256' explicitly (D-03).
		 * @return string HMAC of $data.
		 */
		function wp_hash( $data, $scheme = 'auth', $algo = 'md5' ) {
			return hash_hmac( $algo, $data, wp_salt( $scheme ) );
		}
	}
}

namespace HAL\MemberProfiles\Tests\Unit {

	use HAL\MemberProfiles\CompatibilityGate;
	use PHPUnit\Framework\TestCase;

	final class CompatibilityGateTest extends TestCase {

		private const FILTER_HOOK = 'hal_member_profiles_approved_compositions';

		protected function setUp(): void {
			remove_all_filters( self::FILTER_HOOK );
			hal_wp_stub_extra_set( 'env_type', 'production' );
		}

		protected function tearDown(): void {
			remove_all_filters( self::FILTER_HOOK );
		}

		// ---------------------------------------------------------------
		// (a) valid signed row + matching environment -> approved_composition
		// ---------------------------------------------------------------

		public function test_valid_signed_pinned_row_with_matching_environment_approves(): void {
			$row = $this->sign_row(
				$this->pinned_row(
					array(
						'wp'        => '6.5.2',
						'php'       => '8.2.1',
						'um'        => '2.8.3',
						'elementor' => '3.19.4',
					)
				)
			);

			$this->add_rows_via_filter( 'profile', array( $row ) );

			$gate = new CompatibilityGate( $this->environment() );

			$this->assertTrue( $gate->passes( 'profile' ) );
			$this->assertSame( 'approved_composition', $gate->describe( 'profile' ) );
		}

		public function test_valid_signed_floor_row_with_matching_environment_approves(): void {
			$row = $this->sign_row(
				$this->floor_row(
					array(
						'wp'        => '6.5.0',
						'php'       => '8.2.0',
						'um'        => '2.8.0',
						'elementor' => '3.19.0',
					)
				)
			);

			$this->add_rows_via_filter( 'profile', array( $row ) );

			$gate = new CompatibilityGate( $this->environment() );

			$this->assertTrue( $gate->passes( 'profile' ) );
			$this->assertSame( 'approved_composition', $gate->describe( 'profile' ) );
		}

		// ---------------------------------------------------------------
		// (b) tampered or unsigned external row -> silently dropped
		// ---------------------------------------------------------------

		public function test_tampered_external_row_is_silently_dropped(): void {
			$row = $this->sign_row(
				$this->pinned_row(
					array(
						'wp'        => '6.5.2',
						'php'       => '8.2.1',
						'um'        => '2.8.3',
						'elementor' => '3.19.4',
					)
				)
			);

			// Deliberately tamper a field that is NOT part of the version comparison:
			// if the gate failed to verify signatures, this row would still match the
			// environment and approve — so the awaiting_matrix_signoff verdict below
			// proves the row was dropped, not merely mismatched.
			$row['matrix_row'] = 'tampered-after-signing';

			$this->add_rows_via_filter( 'profile', array( $row ) );

			$gate = new CompatibilityGate( $this->environment() );

			$this->assertFalse( $gate->passes( 'profile' ) );
			$this->assertSame( 'awaiting_matrix_signoff', $gate->describe( 'profile' ) );
		}

		public function test_unsigned_external_row_is_silently_dropped(): void {
			// signed => true but NO signature key at all.
			$row = $this->pinned_row(
				array(
					'wp'        => '6.5.2',
					'php'       => '8.2.1',
					'um'        => '2.8.3',
					'elementor' => '3.19.4',
				)
			);

			$this->add_rows_via_filter( 'profile', array( $row ) );

			$gate = new CompatibilityGate( $this->environment() );

			$this->assertFalse( $gate->passes( 'profile' ) );
			$this->assertSame( 'awaiting_matrix_signoff', $gate->describe( 'profile' ) );
		}

		public function test_corrupt_signature_is_silently_dropped(): void {
			$row = $this->sign_row(
				$this->pinned_row(
					array(
						'wp'        => '6.5.2',
						'php'       => '8.2.1',
						'um'        => '2.8.3',
						'elementor' => '3.19.4',
					)
				)
			);

			$row['signature'] = str_repeat( 'a', 64 );

			$this->add_rows_via_filter( 'profile', array( $row ) );

			$gate = new CompatibilityGate( $this->environment() );

			// The row would otherwise match and approve; the closed verdict proves the
			// fail-closed verification, not a version mismatch.
			$this->assertFalse( $gate->passes( 'profile' ) );
			$this->assertSame( 'awaiting_matrix_signoff', $gate->describe( 'profile' ) );
		}

		public function test_tampered_row_does_not_disturb_a_valid_sibling_row(): void {
			$valid = $this->sign_row(
				$this->pinned_row(
					array(
						'wp'        => '6.5.2',
						'php'       => '8.2.1',
						'um'        => '2.8.3',
						'elementor' => '3.19.4',
					)
				)
			);

			$tampered = $this->sign_row(
				$this->pinned_row(
					array(
						'wp'        => '6.5.2',
						'php'       => '8.2.1',
						'um'        => '2.8.3',
						'elementor' => '3.19.4',
					)
				)
			);
			$tampered['matrix_row'] = 'tampered-after-signing';

			$this->add_rows_via_filter( 'profile', array( $tampered, $valid ) );

			$gate = new CompatibilityGate( $this->environment() );

			// The tampered row is silently dropped; the valid sibling still approves.
			$this->assertTrue( $gate->passes( 'profile' ) );
			$this->assertSame( 'approved_composition', $gate->describe( 'profile' ) );
		}

		// ---------------------------------------------------------------
		// (c) floor row survives patch-level component updates
		// ---------------------------------------------------------------

		public function test_floor_row_survives_patch_level_component_updates(): void {
			$row = $this->sign_row(
				$this->floor_row(
					array(
						'wp'        => '6.5.0',
						'php'       => '8.2.0',
						'um'        => '2.8.0',
						'elementor' => '3.19.0',
					)
				)
			);

			$this->add_rows_via_filter( 'profile', array( $row ) );

			// Every component receives an auto-update bumping only its patch level; the
			// same signed row keeps matching without being re-signed.
			$gate = new CompatibilityGate(
				$this->environment(
					array(
						'wp'        => '6.5.9',
						'php'       => '8.2.29',
						'um'        => '2.8.7',
						'elementor' => '3.19.13',
					)
				)
			);

			$this->assertTrue( $gate->passes( 'profile' ) );
			$this->assertSame( 'approved_composition', $gate->describe( 'profile' ) );
		}

		public function test_floor_row_fails_when_a_component_drops_below_minimum(): void {
			$row = $this->sign_row(
				$this->floor_row(
					array(
						'wp'        => '6.5.0',
						'php'       => '8.2.0',
						'um'        => '2.8.0',
						'elementor' => '3.19.0',
					)
				)
			);

			$this->add_rows_via_filter( 'profile', array( $row ) );

			$gate = new CompatibilityGate( $this->environment( array( 'wp' => '6.4.9' ) ) );

			$this->assertFalse( $gate->passes( 'profile' ) );
			$this->assertSame( 'composition_mismatch', $gate->describe( 'profile' ) );
		}

		// ---------------------------------------------------------------
		// (d) empty constant, no filter -> awaiting_matrix_signoff (no regression)
		// ---------------------------------------------------------------

		public function test_empty_registry_without_filter_stays_awaiting_matrix_signoff(): void {
			// Full-floor environment: every capability's presence floor is met, so each
			// verdict reaches the rows stage — which is empty (frozen constant, no
			// filter attached) and stays exactly today's closed verdict.
			$gate = new CompatibilityGate(
				array(
					'wp'            => '6.5.2',
					'php'           => '8.2.1',
					'um'            => '2.8.3',
					'elementor'     => '3.19.4',
					'elementor_pro' => '3.19.4',
					'amelia'        => '7.1.4',
					'theme'         => 'hello-elementor-child',
				)
			);

			$this->assertFalse( $gate->passes( 'profile' ) );
			$this->assertFalse( $gate->effective_passes( 'profile' ) );

			// Every known capability keeps exactly today's closed verdict.
			foreach ( array_keys( $gate->capabilities() ) as $capability ) {
				$this->assertSame( 'awaiting_matrix_signoff', $gate->describe( $capability ), $capability );
			}

			$this->assertSame( 'unknown_capability', $gate->describe( 'nonexistent' ) );
		}

		// ---------------------------------------------------------------
		// (e) the constructor filter is actually invoked and its rows open the gate
		// ---------------------------------------------------------------

		public function test_constructor_filter_is_invoked_and_its_rows_open_the_gate(): void {
			$invocations = 0;
			$seen        = null;

			add_filter(
				self::FILTER_HOOK,
				static function ( $approved ) use ( &$invocations, &$seen ) {
					$invocations++;
					$seen = $approved;

					return $approved;
				}
			);

			// Part 1: the hook runs at construction and receives the assigned registry
			// (the frozen, empty constant on the production path).
			new CompatibilityGate( $this->environment() );

			$this->assertSame( 1, $invocations );
			$this->assertSame( array(), $seen );

			// Part 2: a filter appending a valid signed row opens the gate.
			$row = $this->sign_row(
				$this->pinned_row(
					array(
						'wp'        => '6.5.2',
						'php'       => '8.2.1',
						'um'        => '2.8.3',
						'elementor' => '3.19.4',
					)
				)
			);

			add_filter(
				self::FILTER_HOOK,
				static function ( $approved ) use ( $row ) {
					$approved['profile'][] = $row;

					return $approved;
				}
			);

			$gate = new CompatibilityGate( $this->environment() );

			$this->assertTrue( $gate->passes( 'profile' ) );
			$this->assertSame( 'approved_composition', $gate->describe( 'profile' ) );
			$this->assertSame( 2, $invocations );
		}

		public function test_filter_returning_garbage_is_ignored_fail_closed(): void {
			add_filter(
				self::FILTER_HOOK,
				static function () {
					return 'not-a-registry';
				}
			);

			// A broken callback can neither inject rows nor fatal the gate: the registry
			// falls back to what was assigned before the filter.
			$gate = new CompatibilityGate( $this->environment() );

			$this->assertFalse( $gate->passes( 'profile' ) );
			$this->assertSame( 'awaiting_matrix_signoff', $gate->describe( 'profile' ) );
		}

		// ---------------------------------------------------------------
		// (f) canonical JSON stability
		// ---------------------------------------------------------------

		public function test_canonical_json_is_stable_across_key_insertion_order(): void {
			$value_a = array(
				'matrix_row' => 'test-canonical',
				'signed'     => true,
				'type'       => 'floor',
				'versions'   => array(
					'wp'  => '6.5.0',
					'php' => '8.2.0',
				),
			);

			// Same content, different key insertion order (top level AND nested map).
			$value_b = array(
				'versions'   => array(
					'php' => '8.2.0',
					'wp'  => '6.5.0',
				),
				'type'       => 'floor',
				'signed'     => true,
				'matrix_row' => 'test-canonical',
			);

			$gate   = new CompatibilityGate( $this->environment() );
			$method = new \ReflectionMethod( CompatibilityGate::class, 'canonical_json' );
			$method->setAccessible( true );

			$canonical_a = $method->invoke( $gate, $value_a );
			$canonical_b = $method->invoke( $gate, $value_b );

			$this->assertIsString( $canonical_a );

			// Same input -> same canonical output.
			$this->assertSame( $canonical_a, $method->invoke( $gate, $value_a ) );

			// Permuted key order -> same canonical output.
			$this->assertSame( $canonical_a, $canonical_b );

			// The gate's canonical form matches the INDEPENDENT re-implementation of the
			// documented contract (what the later F-09 tooling must reproduce).
			$this->assertSame( self::test_canonical_json( $value_a ), $canonical_a );

			// Same input -> same signature; permuted keys -> same signature.
			$this->assertSame(
				wp_hash( $canonical_a, 'auth', 'sha256' ),
				wp_hash( $canonical_b, 'auth', 'sha256' )
			);
		}

		public function test_permuted_row_key_order_still_verifies_end_to_end(): void {
			$standard = $this->sign_row(
				$this->pinned_row(
					array(
						'wp'        => '6.5.2',
						'php'       => '8.2.1',
						'um'        => '2.8.3',
						'elementor' => '3.19.4',
					)
				)
			);

			$permuted = array(
				'versions'   => array(
					'elementor' => '3.19.4',
					'um'        => '2.8.3',
					'php'       => '8.2.1',
					'wp'        => '6.5.2',
				),
				'type'       => 'pinned',
				'signed'     => true,
				'matrix_row' => 'test-pinned',
			);
			$permuted_signed = $this->sign_row( $permuted );

			// Different insertion order, identical canonical form -> identical signature.
			$this->assertSame( $standard['signature'], $permuted_signed['signature'] );

			// ... and the independently signed permuted row verifies end to end.
			$this->add_rows_via_filter( 'profile', array( $permuted_signed ) );

			$gate = new CompatibilityGate( $this->environment() );

			$this->assertTrue( $gate->passes( 'profile' ) );
			$this->assertSame( 'approved_composition', $gate->describe( 'profile' ) );
		}

		// ---------------------------------------------------------------
		// Internal rows keep today's semantics; filters cannot weaken them.
		// ---------------------------------------------------------------

		public function test_internal_override_rows_keep_today_matching_semantics(): void {
			// Rows passed through the test-only constructor override and left unchanged
			// by the (no-op) filter are internal: matched exactly as today, with NO
			// signature field required — this is what keeps pre-F-02a row shapes valid.
			$row = array(
				'matrix_row' => 'test-internal',
				'signed'     => true,
				'versions'   => array(
					'wp'        => '6.5.2',
					'php'       => '8.2.1',
					'um'        => '2.8.3',
					'elementor' => '3.19.4',
				),
			);

			$gate = new CompatibilityGate( $this->environment(), array( 'profile' => array( $row ) ) );

			$this->assertTrue( $gate->passes( 'profile' ) );
			$this->assertSame( 'approved_composition', $gate->describe( 'profile' ) );
		}

		public function test_filter_cannot_weaken_an_internal_row_without_signing_it(): void {
			$row = array(
				'matrix_row' => 'test-internal',
				'signed'     => true,
				'versions'   => array(
					'wp'        => '6.5.2',
					'php'       => '8.2.1',
					'um'        => '2.8.3',
					'elementor' => '3.19.4',
				),
			);

			add_filter(
				self::FILTER_HOOK,
				static function ( $approved ) {
					if ( isset( $approved['profile'][0] ) ) {
						$approved['profile'][0]['versions']['elementor'] = '0.0.0';
					}

					return $approved;
				}
			);

			$gate = new CompatibilityGate( $this->environment(), array( 'profile' => array( $row ) ) );

			// The reshaped row turned external without a signature -> silently dropped;
			// the registry ends empty for the capability -> verdict closed.
			$this->assertFalse( $gate->passes( 'profile' ) );
			$this->assertSame( 'awaiting_matrix_signoff', $gate->describe( 'profile' ) );
		}

		// ---------------------------------------------------------------
		// Row-type guard: unknown types fail closed.
		// ---------------------------------------------------------------

		public function test_unknown_row_type_fails_closed(): void {
			$row = $this->sign_row(
				array(
					'matrix_row' => 'test-bogus-type',
					'signed'     => true,
					'type'       => 'bananas',
					'versions'   => array(
						'wp'        => '6.5.2',
						'php'       => '8.2.1',
						'um'        => '2.8.3',
						'elementor' => '3.19.4',
					),
				)
			);

			$this->add_rows_via_filter( 'profile', array( $row ) );

			$gate = new CompatibilityGate( $this->environment() );

			// Signature valid -> the row is kept in the registry, but an unknown type
			// can never match: the verdict closes at composition_mismatch, never opens.
			$this->assertFalse( $gate->passes( 'profile' ) );
			$this->assertSame( 'composition_mismatch', $gate->describe( 'profile' ) );
		}

		// ---------------------------------------------------------------
		// Absence semantics (identical for pinned and floor, F-02a step 1).
		// ---------------------------------------------------------------

		public function test_row_component_absent_from_environment_fails_for_both_types(): void {
			// A component listed in the row but not reported by the environment fails the
			// row — even when the capability's own presence floor is satisfied.
			$this->add_rows_via_filter(
				'amelia',
				array(
					$this->sign_row(
						array(
							'matrix_row' => 'test-absence-pinned',
							'signed'     => true,
							'type'       => 'pinned',
							'versions'   => array(
								'wp'  => '6.5.2',
								'php' => '8.2.1',
								'um'  => '2.8.3',
							),
						)
					),
					$this->sign_row(
						array(
							'matrix_row' => 'test-absence-floor',
							'signed'     => true,
							'type'       => 'floor',
							'versions'   => array(
								'wp'  => '6.5.0',
								'php' => '8.2.0',
								'um'  => '2.8.0',
							),
						)
					),
				)
			);

			// amelia's presence floor (wp, php, amelia) is satisfied; 'um' is absent.
			$gate = new CompatibilityGate(
				array(
					'wp'     => '6.5.2',
					'php'    => '8.2.1',
					'amelia' => '7.1.4',
				)
			);

			$this->assertFalse( $gate->passes( 'amelia' ) );
			$this->assertSame( 'composition_mismatch', $gate->describe( 'amelia' ) );
		}

		public function test_environment_components_not_listed_in_row_are_ignored(): void {
			$this->add_rows_via_filter(
				'amelia',
				array(
					$this->sign_row(
						array(
							'matrix_row' => 'test-unlisted-pinned',
							'signed'     => true,
							'type'       => 'pinned',
							'versions'   => array(
								'wp'  => '6.5.2',
								'php' => '8.2.1',
							),
						)
					),
					$this->sign_row(
						array(
							'matrix_row' => 'test-unlisted-floor',
							'signed'     => true,
							'type'       => 'floor',
							'versions'   => array(
								'wp'  => '6.5.0',
								'php' => '8.2.0',
							),
						)
					),
				)
			);

			// Extra environment components (theme; amelia itself is required by the
			// capability floor but not pinned by the rows) are not compared by the rows.
			$gate = new CompatibilityGate(
				array(
					'wp'     => '6.5.2',
					'php'    => '8.2.1',
					'amelia' => '7.1.4',
					'theme'  => 'hello-elementor-child',
				)
			);

			$this->assertTrue( $gate->passes( 'amelia' ) );
			$this->assertSame( 'approved_composition', $gate->describe( 'amelia' ) );
		}

		// ---------------------------------------------------------------
		// Helpers.
		// ---------------------------------------------------------------

		/**
		 * Current-environment fixture for the `profile` capability (its presence floor
		 * needs wp, php, um, elementor), with optional per-component overrides.
		 *
		 * @param array<string, string> $overrides Component overrides.
		 * @return array<string, string>
		 */
		private function environment( array $overrides = array() ): array {
			return array_merge(
				array(
					'wp'        => '6.5.2',
					'php'       => '8.2.1',
					'um'        => '2.8.3',
					'elementor' => '3.19.4',
				),
				$overrides
			);
		}

		/**
		 * @param array<string, string> $versions Exact tested versions.
		 * @return array<string, mixed>
		 */
		private function pinned_row( array $versions ): array {
			return array(
				'matrix_row' => 'test-pinned',
				'signed'     => true,
				'type'       => 'pinned',
				'versions'   => $versions,
			);
		}

		/**
		 * @param array<string, string> $minimums Signed per-component minimums.
		 * @return array<string, mixed>
		 */
		private function floor_row( array $minimums ): array {
			return array(
				'matrix_row' => 'test-floor',
				'signed'     => true,
				'type'       => 'floor',
				'versions'   => $minimums,
			);
		}

		/**
		 * Signs a row exactly as an integrator must per the constructor-filter contract:
		 * remove the top-level signature key, canonicalize per the documented form, HMAC
		 * with wp_hash( ..., 'auth', 'sha256' ).
		 *
		 * @param array<string, mixed> $row Row to sign.
		 * @return array<string, mixed> Signed row.
		 */
		private function sign_row( array $row ): array {
			unset( $row['signature'] );

			$row['signature'] = wp_hash( self::test_canonical_json( $row ), 'auth', 'sha256' );

			return $row;
		}

		/**
		 * Appends rows to a capability through the constructor filter (the documented
		 * callback contract: `$rows[ $capability ][] = $row; return $rows;`).
		 *
		 * @param string                $capability Capability slug.
		 * @param array<int, mixed>     $rows       Rows to append.
		 * @return void
		 */
		private function add_rows_via_filter( string $capability, array $rows ): void {
			add_filter(
				self::FILTER_HOOK,
				static function ( $approved ) use ( $capability, $rows ) {
					foreach ( $rows as $row ) {
						$approved[ $capability ][] = $row;
					}

					return $approved;
				}
			);
		}

		/**
		 * Independent re-implementation of the canonical form documented on
		 * CompatibilityGate::canonical_json() — see that method for the normative
		 * algorithm. Deliberately NOT a reflection call: these tests prove the
		 * documented contract is reproducible from its description alone.
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
