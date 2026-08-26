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
 * Development card D-13: the capability surface expands to eight INDEPENDENT gates —
 * profile, account, amelia, managed_templates, amelia_api_read, amelia_fields_write,
 * um_schema, elementor_dynamic_tags. There is deliberately NO master/general pass: every
 * capability carries its own required-component floor (enforced in passes() BEFORE any
 * tuple match, so even a mis-signed row can never enable a feature whose stack component
 * is absent) and its own signed-composition rows. Losing one stack component disables
 * exactly its own capabilities and nothing else. describe() exposes the reason for any
 * verdict so diagnostic surfaces (card D-06 dashboard, card D-14 wiring) can explain
 * themselves without guessing.
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

	// Development card D-13: five further INDEPENDENT capabilities. No general pass
	// exists — each of the eight gates opens exclusively through its own signed rows.
	const CAP_MANAGED_TEMPLATES      = 'managed_templates';
	const CAP_AMELIA_API_READ        = 'amelia_api_read';
	const CAP_AMELIA_FIELDS_WRITE    = 'amelia_fields_write';
	const CAP_UM_SCHEMA              = 'um_schema';
	const CAP_ELEMENTOR_DYNAMIC_TAGS = 'elementor_dynamic_tags';

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
	 * D-13: the minimal component floor each capability REQUIRES in the live environment,
	 * enforced before tuple matching. A missing floor component fails that capability
	 * (and only that one) regardless of any registry content — this is what makes "Elite
	 * missing disables only the API" and "Pro missing disables only Tags" structural.
	 * Tuples in APPROVED_COMPOSITIONS may pin these same components to exact tested
	 * versions; the floor here is presence-only.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const REQUIRED_COMPONENTS = array(
		self::CAP_PROFILE                => array( 'wp', 'php', 'um', 'elementor' ),
		self::CAP_ACCOUNT                => array( 'wp', 'php', 'um', 'elementor' ),
		self::CAP_AMELIA                 => array( 'wp', 'php', 'amelia' ),
		self::CAP_MANAGED_TEMPLATES      => array( 'wp', 'php', 'theme' ),
		self::CAP_AMELIA_API_READ        => array( 'wp', 'php', 'amelia' ),
		self::CAP_AMELIA_FIELDS_WRITE    => array( 'wp', 'php', 'amelia' ),
		self::CAP_UM_SCHEMA              => array( 'wp', 'php', 'um' ),
		self::CAP_ELEMENTOR_DYNAMIC_TAGS => array( 'wp', 'php', 'elementor', 'elementor_pro' ),
	);

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

		if ( '' === $capability || ! isset( self::REQUIRED_COMPONENTS[ $capability ] ) ) {
			return false;
		}

		// D-13 floor check FIRST: a capability whose stack component is absent from this
		// environment can never pass, no matter what any registry row claims. This is the
		// structural guarantee behind "missing Elite disables only the API" etc.
		foreach ( self::REQUIRED_COMPONENTS[ $capability ] as $component ) {
			if ( ! isset( $this->versions[ $component ] ) ) {
				return false;
			}
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
	 * Integration Closure #7: staging-QA-aware verdict. Identical to passes() except that
	 * on an explicitly-enabled staging environment (see \HAL\MemberProfiles\StagingQA) the
	 * matrix-signature requirement is waived while the component FLOOR still applies —
	 * this is how QA produces the evidence that later becomes signed rows.
	 *
	 * Production can never reach the waiver (environment check fails there), and there is
	 * still no master pass: each capability keeps its own floor and independence.
	 *
	 * Amelia-side WRITES ignore this override entirely by policy (their consumer uses the
	 * strict passes()).
	 *
	 * @param string $capability One of this class's CAP_* constants.
	 * @return bool
	 */
	public function effective_passes( string $capability ): bool {
		if ( $this->passes( $capability ) ) {
			return true;
		}

		$capability = strtolower( trim( $capability ) );

		if ( '' === $capability || ! isset( self::REQUIRED_COMPONENTS[ $capability ] ) ) {
			return false;
		}

		if ( ! class_exists( \HAL\MemberProfiles\StagingQA::class ) || ! StagingQA::enabled() ) {
			return false;
		}

		foreach ( self::REQUIRED_COMPONENTS[ $capability ] as $component ) {
			if ( ! isset( $this->versions[ $component ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * D-13: explains WHY a capability holds its current verdict, using machine-stable
	 * slugs suitable for dashboards and logs (no secrets, no environment beyond component
	 * names). Never throws; unknown capabilities describe themselves as such.
	 *
	 * @param string $capability One of this class's CAP_* constants (or anything else).
	 * @return string One of: approved_composition | missing_components:<list> |
	 *                awaiting_matrix_signoff | composition_mismatch | unknown_capability
	 */
	public function describe( string $capability ): string {
		$capability = strtolower( trim( $capability ) );

		if ( '' === $capability || ! isset( self::REQUIRED_COMPONENTS[ $capability ] ) ) {
			return 'unknown_capability';
		}

		$missing = array();

		foreach ( self::REQUIRED_COMPONENTS[ $capability ] as $component ) {
			if ( ! isset( $this->versions[ $component ] ) ) {
				$missing[] = $component;
			}
		}

		if ( ! empty( $missing ) ) {
			return 'missing_components:' . implode( ',', $missing );
		}

		$rows = $this->approved[ $capability ] ?? null;

		if ( ! is_array( $rows ) || array() === $rows ) {
			return 'awaiting_matrix_signoff';
		}

		foreach ( $rows as $row ) {
			if ( $this->matches( $row ) ) {
				return 'approved_composition';
			}
		}

		return 'composition_mismatch';
	}

	/**
	 * D-13: the full capability→required-components map (read-only copy) so diagnostic
	 * surfaces can enumerate every independent gate without hardcoding names.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function capabilities(): array {
		return self::REQUIRED_COMPONENTS;
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
