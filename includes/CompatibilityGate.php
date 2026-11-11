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
 * - Matching is signed-only: an unknown component version, a missing key, or an
 *   unsigned row never passes. Markdown/request data is never consulted at runtime.
 * - Capabilities are independent: blocking one (e.g. amelia) never blocks the others.
 * - No network, no filesystem, no DB schema: the gate itself can never break a page or
 *   leak data. F-02a adds exactly two guarded WordPress functions, each failing closed
 *   when unavailable: apply_filters() in the constructor (registry extension, D-02) and
 *   wp_hash( ..., 'auth', 'sha256' ) for external-row signature verification (D-03).
 *
 * Development card F-02a (v1.2.1 = steps 1-3 of F-02): the registry accepts two row
 * types — `pinned` (exact comparison, as today, the default) and `floor` (every listed
 * component at or above its signed minimum via version_compare( $actual, $minimum,
 * '>=' )) — and the extensibility filter 'hal_member_profiles_approved_compositions'
 * runs inside the constructor. Every row a filter introduces must carry an HMAC
 * signature over the row's canonical JSON; verification runs AFTER the filter (D-02)
 * and unverifiable rows are silently dropped before any matching (fail-closed). Step 4
 * of F-02 (the first approved pinned row) is a separate owner-gated card (F-02b,
 * v1.3.0): APPROVED_COMPOSITIONS stays empty in this release and the gate stays closed
 * on 1.2.x (awaiting_matrix_signoff).
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
	 * test produces a signed Pass row in docs/compatibility-matrix.md — that first row
	 * is card F-02b, owner-gated to v1.3.0, so the gate stays closed on 1.2.x. Each
	 * entry shape (F-02a step 1; 'signed' => true is mandatory for BOTH types):
	 *
	 * array(
	 *     'matrix_row' => '<row id / date in the matrix>',
	 *     'signed'     => true,
	 *     'type'       => 'pinned', // optional: 'pinned' (default, exact) | 'floor'
	 *     'versions'   => array(
	 *         'wp'            => '6.5.x', // pinned: exact tested version string
	 *         'php'           => '8.0.x',
	 *         'um'            => '2.x.x',
	 *         'elementor'     => '3.x.x',
	 *         'elementor_pro' => '3.x.x',
	 *     ),
	 * ),
	 *
	 * For 'floor' rows every 'versions' entry is a signed MINIMUM: the row keeps
	 * matching while each listed component reports version_compare( $actual, $minimum,
	 * '>=' ) in the live environment. Absence semantics are identical for both types: a
	 * component listed in a row must be reported by the environment or the row fails,
	 * and environment components NOT listed in the row are not compared. Rows in this
	 * constant are INTERNAL (committed code, matrix-governed) and need no signature;
	 * rows added at runtime through the constructor filter are EXTERNAL and additionally
	 * carry 'signature' => wp_hash( canonical_json( row_without_signature ), 'auth',
	 * 'sha256' ) — see canonical_json() for the exact canonical form.
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

		// F-02a step 2: registry extension INSIDE the constructor, on the
		// already-assigned property (the constant is private and is never referenced
		// outside this class). The pre-filter snapshot below is the step-3 provenance
		// baseline: rows that pass through the filter unchanged keep their internal
		// status; anything the filter introduces or reshapes is external and must
		// verify. In pure-PHP contexts without WordPress's filter API the hook is
		// skipped and the registry stays exactly as assigned (fail-closed: no filter,
		// no extension, no fatal).
		$pre_filter_registry = $this->approved;
		$filtered_registry   = $this->approved;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Extensibility hook for operator/integrator-approved composition rows
			 * (development card F-02a, steps 1-3). Filters are an extension mechanism,
			 * never a documented security boundary (D-02) — signature verification for
			 * every external row runs immediately AFTER this filter, in the same class.
			 *
			 * Callback contract:
			 * - Add rows per capability: `$rows[ $capability ][] = $row; return $rows;`
			 *   (capabilities use the CAP_* slugs; unknown capability keys are inert).
			 * - Every external row must be an array carrying `signed => true` and
			 *   `signature => wp_hash( canonical_json( row_without_signature ), 'auth',
			 *   'sha256' )` — the exact canonical form is documented on canonical_json().
			 *   Unsigned, tampered, or otherwise unverifiable rows are silently dropped
			 *   before any matching (fail-closed).
			 * - Rows strictly equal (PHP === : same keys, same order, same values) to a
			 *   row that was already present keep internal status: rows from the frozen
			 *   constant are governed by committed code + matrix sign-off, and the
			 *   test-only constructor override keeps today's no-signature semantics.
			 * - A filter may restrict (dropping rows or whole capabilities is honored)
			 *   but can never weaken a row: any reshaped row turns external and must
			 *   verify, or it is dropped.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $approved The assigned registry (frozen constant, or test-only override).
			 */
			$filtered_registry = apply_filters( 'hal_member_profiles_approved_compositions', $this->approved );
		}

		// F-02a step 3: HMAC verification AFTER the filter (D-02), BEFORE any matching.
		// The filter's return is validated in a local first (the property is typed
		// array, so a garbage return must never touch it): a non-array result is
		// ignored wholesale — the registry falls back to what was assigned before the
		// filter, so a broken callback can neither inject rows nor fatal the gate
		// (fail-closed).
		$this->approved = is_array( $filtered_registry )
			? self::verify_external_rows( $pre_filter_registry, $filtered_registry )
			: $pre_filter_registry;
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
	 * Whether one registry entry is signed and matches the current versions under its
	 * row type (F-02a step 1): `pinned` compares exact strings as today; `floor` passes
	 * while every listed component is at or above its signed minimum via
	 * version_compare( $actual, $minimum, '>=' ) (D-06).
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

		// F-02a step 1: row type. Omitted = 'pinned', so every row shape that exists
		// today keeps matching exactly as before. Any value other than the two exact
		// lowercase slugs fails closed.
		$type = $row['type'] ?? 'pinned';

		if ( 'pinned' !== $type && 'floor' !== $type ) {
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

			// Fail closed when the environment does not report this component at all.
			// This absence semantics is identical for both row types: a row only
			// constrains the components it lists, and every listed component must be
			// reported by the environment; components the environment reports but the
			// row does not list are not compared.
			if ( ! array_key_exists( $key, $this->versions ) ) {
				return false;
			}

			if ( 'floor' === $type ) {
				// F-02a step 1: floor row — the row keeps matching while every listed
				// component is at or above its signed minimum. version_compare()
				// caveats are honored per D-06 by contract on the ROW AUTHOR, not in
				// code: the comparison is case-sensitive for pre-release tokens
				// (dev/alpha/RC ordering), and a build suffix sorts LOWER than its
				// bare version ( '1.0.0+build' < '1.0.0' ), so environment strings
				// carrying build suffixes must be normalized by the row author before
				// signing.
				if ( ! version_compare( $this->versions[ $key ], $expected, '>=' ) ) {
					return false;
				}

				continue;
			}

			// Pinned row (as today): anything other than the exact tested version fails.
			if ( $this->versions[ $key ] !== $expected ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * F-02a step 3: fail-closed HMAC verification of filter-provided rows, run after the
	 * step-2 filter and before any matching (verdicts can never see an unverified row).
	 *
	 * Provenance rules:
	 * - A row strictly equal (PHP ===) to a row in the pre-filter registry for the same
	 *   capability is INTERNAL: it came from the frozen constant (governed by committed
	 *   code, matched exactly as today, no signature field required) or from the
	 *   test-only constructor override. It is kept as-is. The filter merely passing
	 *   internal rows through cannot change their status.
	 * - Every other entry is EXTERNAL (introduced, reshaped, or moved across
	 *   capabilities by the filter): it must be an array whose signature verifies via
	 *   row_signature_valid(), otherwise it is silently dropped — no logging surface
	 *   exists here and none is added.
	 * - A filter may remove rows or whole capabilities (restriction is honored — the
	 *   affected verdicts close, never open); it can never weaken a row, because any
	 *   reshaped row turns external and must verify.
	 * - Non-array capability values pass through untouched: matches()/describe() already
	 *   fail closed on them exactly as they do today.
	 *
	 * @param array<string, mixed> $pre_filter Registry as assigned before the filter.
	 * @param array<string, mixed> $filtered   Registry as returned by the filter.
	 * @return array<string, mixed> Registry with every unverifiable external row dropped.
	 */
	private static function verify_external_rows( array $pre_filter, array $filtered ): array {
		$verified = array();

		foreach ( $filtered as $capability => $rows ) {
			if ( ! is_array( $rows ) ) {
				$verified[ $capability ] = $rows;
				continue;
			}

			$pre_rows = is_array( $pre_filter[ $capability ] ?? null ) ? $pre_filter[ $capability ] : array();

			$kept = array();

			foreach ( $rows as $row ) {
				if ( in_array( $row, $pre_rows, true ) ) {
					$kept[] = $row;
					continue;
				}

				if ( is_array( $row ) && self::row_signature_valid( $row ) ) {
					$kept[] = $row;
				}
			}

			$verified[ $capability ] = $kept;
		}

		return $verified;
	}

	/**
	 * Whether one EXTERNAL row's HMAC signature verifies.
	 *
	 * The signature value must be `wp_hash( canonical_json( row_without_signature ),
	 * 'auth', 'sha256' )` — sha256 passed EXPLICITLY because wp_hash defaults to md5
	 * (D-03). Comparison uses hash_equals() (constant time). Any missing, malformed, or
	 * unverifiable piece fails closed. When the WordPress HMAC function is unavailable
	 * (pure-PHP contexts) there is no verifier at all, so no external row can be
	 * authenticated and all of them are dropped.
	 *
	 * @param array<string, mixed> $row Candidate external row.
	 * @return bool
	 */
	private static function row_signature_valid( array $row ): bool {
		$signature = $row['signature'] ?? null;

		if ( ! is_string( $signature ) || '' === $signature ) {
			return false;
		}

		if ( ! function_exists( 'wp_hash' ) ) {
			return false;
		}

		$unsigned = $row;
		unset( $unsigned['signature'] );

		$canonical = self::canonical_json( $unsigned );

		if ( null === $canonical ) {
			return false;
		}

		return hash_equals( wp_hash( $canonical, 'auth', 'sha256' ), $signature );
	}

	/**
	 * Canonical JSON encoding of a registry row for HMAC signing (development card
	 * F-02a step 3). Deterministic and reproducible on both sides — the integrator
	 * computing the signature, this gate recomputing it, and the later F-09 CLI tooling.
	 * The algorithm below IS the contract; reproduce it byte-for-byte:
	 *
	 * 1. Recursively canonicalize the value:
	 *    - An array is a "list" when its keys, in storage order, are exactly the
	 *      integers 0,1,2,... (PHP itself stores integer-like string keys as integers;
	 *      the empty array counts as a list). A list encodes as a JSON array, elements
	 *      in index order.
	 *    - Anything else is a "map": keys are cast to their string form and sorted
	 *      ascending in byte-wise strcmp order (ksort with SORT_STRING), values
	 *      canonicalized recursively. Two arrays differing only in key insertion order
	 *      therefore produce the identical canonical form.
	 *    - After sorting, PHP re-normalizes integer-like keys back to integers, so a map
	 *      whose key set is exactly 0..n-1 (in any original insertion order) is rendered
	 *      by json_encode() as a JSON array. This collapse is deterministic: signer and
	 *      verifier run the identical algorithm on the same PHP array, so the bytes
	 *      always agree. Scalars and null pass through unchanged.
	 * 2. Encode the canonicalized value with json_encode() using DEFAULT flags and the
	 *    default depth (512): strings must be valid UTF-8, so the output is pure ASCII.
	 * 3. json_encode() returning false (malformed UTF-8, depth overflow) leaves the
	 *    canonical form undefined — callers treat the row as unverifiable (fail-closed).
	 *
	 * Row authors: sign `canonical_json( row_without_signature )`, where only the
	 * TOP-LEVEL 'signature' key is removed before encoding; everything else in the row
	 * (including unknown extra keys, nested arrays, 'matrix_row', 'type') is covered by
	 * the signature.
	 *
	 * @param mixed $value Row without its top-level signature key.
	 * @return string|null Canonical JSON string, or null when the value cannot be encoded.
	 */
	private static function canonical_json( $value ) {
		$encoded = json_encode( self::canonicalize( $value ) );

		return false === $encoded ? null : $encoded;
	}

	/**
	 * Recursive canonicalization pass used by canonical_json() — see that method for
	 * the normative algorithm description.
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
