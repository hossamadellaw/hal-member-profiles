<?php
/**
 * Runtime compatibility gate for HAL Member Profiles.
 *
 * The code-side twin of docs/compatibility-matrix.md: a composition may only pass for a
 * capability after that exact version tuple was tested live AND its matrix row is signed
 * off by an authorized administrator. Everything else fails closed so the affected
 * feature stays on the observe/native fallback path.
 *
 * Governance rules baked into this class:
 * - APPROVED_COMPOSITIONS starts EMPTY and is only ever extended by a committed code
 *   change whose tuple mirrors a signed `Pass` row recorded in the matrix first.
 * - Matching is exact: an unknown component version, a missing key, or an unsigned row
 *   never passes. Markdown/request data is never consulted at runtime.
 * - Capabilities are independent: blocking one (e.g. amelia) never blocks the others.
 * - Pure logic only: no network, no filesystem, no DB schema, no WordPress API calls,
 *   so the gate itself can never break a page or leak data.
 *
 * @package HAL\MemberProfiles
 */

declare( strict_types=1 );

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompatibilityGate {

	const CAP_PROFILE = 'profile';
	const CAP_ACCOUNT = 'account';
	const CAP_AMELIA  = 'amelia';

	/**
	 * Canonical component keys used in version tuples, mirroring the matrix's §1 rows:
	 * wp, php, um, elementor, elementor_pro, amelia, theme. A tuple only has to pin the
	 * components relevant to its capability; unlisted components are not compared.
	 *
	 * QA-approved compositions per capability. Deliberately empty until a live staging
	 * test produces a signed Pass row in docs/compatibility-matrix.md; each entry shape:
	 *
	 * array(
	 *     'matrix_row' => '<row id / date in the matrix>',
	 *     'signed'     => true,
	 *     'versions'   => array(
	 *         'wp'            => '6.5.x',
	 *         'php'           => '8.0.x',
	 *         'um'            => '2.x.x',
	 *         'elementor'     => '3.x.x',
	 *         'elementor_pro' => '3.x.x',
	 *     ),
	 * ),
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private const APPROVED_COMPOSITIONS = array();

	/**
	 * Normalized current-environment versions, keyed by component slug.
	 *
	 * @var array<string, string>
	 */
	private array $versions;

	/**
	 * The registry this gate consults. Production always uses the frozen
	 * APPROVED_COMPOSITIONS constant; the constructor parameter exists purely so the
	 * matching logic can be exercised in isolated tests without mutating the constant.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $approved;

	/**
	 * @param array<string, mixed>                                        $versions Current environment versions keyed by component slug (unknown keys are ignored until a tuple references them).
	 * @param array<string, array<int, array<string, mixed>>>|null        $approved Test-only override of the approved-compositions registry; null uses the frozen production registry.
	 */
	public function __construct( array $versions, ?array $approved = null ) {
		$this->versions = self::normalize_versions( $versions );
		$this->approved = null === $approved ? self::APPROVED_COMPOSITIONS : $approved;
	}

	/**
	 * Whether the current environment matches at least one signed, QA-approved
	 * composition for the given capability. Unknown capability => false.
	 *
	 * @param string $capability One of this class's CAP_* constants.
	 * @return bool
	 */
	public function passes( string $capability ): bool {
		$capability = strtolower( trim( $capability ) );

		if ( '' === $capability ) {
			return false;
		}

		$rows = $this->approved[ $capability ] ?? null;

		if ( ! is_array( $rows ) ) {
			return false;
		}

		foreach ( $rows as $row ) {
			if ( $this->matches( $row ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether one registry entry is signed and exactly matches the current versions.
	 *
	 * @param mixed $row Candidate registry entry.
	 * @return bool
	 */
	private function matches( $row ): bool {
		if ( ! is_array( $row ) || ( true !== ( $row['signed'] ?? null ) ) ) {
			return false;
		}

		$tuple = $row['versions'] ?? null;

		if ( ! is_array( $tuple ) || array() === $tuple ) {
			return false;
		}

		foreach ( $tuple as $raw_key => $raw_expected ) {
			if ( ! is_string( $raw_key ) ) {
				return false;
			}

			$key      = strtolower( trim( $raw_key ) );
			$expected = is_scalar( $raw_expected ) ? trim( (string) $raw_expected ) : '';

			if ( '' === $key || '' === $expected ) {
				return false;
			}

			// Fail closed when the environment does not report this component at all,
			// or reports anything other than the exact tested version.
			if ( ! array_key_exists( $key, $this->versions ) || $this->versions[ $key ] !== $expected ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalizes an environment map: lowercase trimmed keys, trimmed scalar values,
	 * everything else dropped.
	 *
	 * @param array<string, mixed> $versions Raw environment versions.
	 * @return array<string, string>
	 */
	private static function normalize_versions( array $versions ): array {
		$normalized = array();

		foreach ( $versions as $raw_key => $raw_value ) {
			if ( ! is_string( $raw_key ) ) {
				continue;
			}

			$key   = strtolower( trim( $raw_key ) );
			$value = is_scalar( $raw_value ) ? trim( (string) $raw_value ) : '';

			if ( '' === $key || '' === $value ) {
				continue;
			}

			$normalized[ $key ] = $value;
		}

		return $normalized;
	}
}
