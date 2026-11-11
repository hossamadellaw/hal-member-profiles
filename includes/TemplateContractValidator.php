<?php
/**
 * Read-only template contract validator for Elementor Library templates — card F-11.
 *
 * Sole responsibility: answer, WITHOUT rendering anything, whether one Elementor library
 * template currently contains the four required HAL marker widget types (E-06), and
 * surface that answer as a plain machine-readable array for the F-05 readiness card and
 * the F-10 form-template watcher. This class never renders, never buffers output, never
 * writes, and never registers hooks.
 *
 * Defensive posture (D-08c): `\Elementor\Plugin::instance()->documents->get()` is
 * documented (official core/documents-manager.php) to return `false|Document`; an
 * UNGUARDED false in the same machinery caused a fatal (issue #36089). Every external
 * touchpoint here is guarded: Elementor absent, instance/documents manager missing,
 * get() false/null/throwing, get_elements_data() missing/throwing/non-array, and
 * malformed or pathologically nested element trees (depth-capped). When widget-level
 * data is unavailable in any way, the validator FALLS BACK to the documented facts only
 * (CPT `elementor_library` + post status + `_elementor_edit_mode` builder meta,
 * D-08a/D-08b) and reports a FAIL — never a pass: unknown counts are UNVERIFIED, not
 * confirmed-absent, so a fallback verdict can never fake readiness (the same fail-closed
 * degradation rule the F-05 class_exists guard enforces upstream).
 *
 * Pass contract (presence): pass === true requires every one of the four required types
 * to occur at least once in the readable template data. Exact per-type counts are
 * surfaced (`found`, plus informational `duplicates`); the render-time exact-count
 * authority stays LayoutContract (E-05) — this class is the pre-render pre-check only.
 *
 * Result shape (plain array; no HTML, no JSON encoding, no rendering):
 *   template_id     int               Validated post ID as given.
 *   pass            bool              Presence contract outcome (false whenever fallback_used).
 *   fallback_used   bool              true means widget counts are UNVERIFIED (fail-closed).
 *   data_source     string            'document_elements' | 'documented_facts'.
 *   reason          string            Verdict slug (see the REASON_* constants).
 *   fallback_reason string            Why document data was unavailable ('' when available).
 *   found           array<string,int> Occurrences per required type (0 = absent or unverified).
 *   missing         string[]          Required types with zero confirmed occurrences.
 *   duplicates      string[]          Required types occurring more than once (informational).
 *   document_facts  array             Best-effort documented facts: post_found, post_type,
 *                                     status, builder_mode ('' when unreadable).
 *
 * Load contract: required once by Bootstrap::boot() (card F-11 wiring) exactly like
 * LayoutContract — a pure reader with zero side effects at load time, always loaded so
 * both the admin-only F-05 readiness card and the F-10 watcher reach it via
 * class_exists() + instantiation. When the class is absent, consumers degrade upstream;
 * this class itself never invents availability.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TemplateContractValidator {

	/** Real get_name() slug of the NativeHeader marker widget (E-06). */
	const WIDGET_NATIVE_HEADER = 'hal_member_profiles_native_header';

	/** Real get_name() slug of the ProfileHeaderCompatibility marker widget (E-06). */
	const WIDGET_HEADER_COMPATIBILITY = 'hal_member_profiles_profile_header_compatibility';

	/** Real get_name() slug of the ProfileNavigation marker widget (E-06). */
	const WIDGET_PROFILE_NAVIGATION = 'hal_member_profiles_profile_navigation';

	/** Real get_name() slug of the ProfileBody marker widget (E-06). */
	const WIDGET_PROFILE_BODY = 'hal_member_profiles_profile_body';

	/**
	 * The four required marker widget types, in the E-06 order. These MUST stay the real
	 * get_name() values of the four marker widgets; the unit suite pins this alignment.
	 *
	 * @var string[]
	 */
	const REQUIRED_WIDGET_TYPES = array(
		self::WIDGET_NATIVE_HEADER,
		self::WIDGET_HEADER_COMPATIBILITY,
		self::WIDGET_PROFILE_NAVIGATION,
		self::WIDGET_PROFILE_BODY,
	);

	const CPT_ELEMENTOR_LIBRARY = 'elementor_library';    // D-08a.
	const META_EDIT_MODE        = '_elementor_edit_mode'; // D-08b.
	const EDIT_MODE_BUILDER     = 'builder';

	const SOURCE_DOCUMENT_ELEMENTS = 'document_elements';
	const SOURCE_DOCUMENTED_FACTS  = 'documented_facts';

	const REASON_CONTRACT_SATISFIED    = 'contract_satisfied';
	const REASON_CONTRACT_FAILED       = 'contract_failed';
	const REASON_INVALID_TEMPLATE_ID   = 'invalid_template_id';
	const REASON_TEMPLATE_NOT_FOUND    = 'template_not_found';
	const REASON_NOT_ELEMENTOR_LIBRARY = 'not_elementor_library';
	const REASON_NOT_PUBLISHED         = 'not_published';
	const REASON_NOT_BUILDER_MODE      = 'not_builder_mode';
	const REASON_COUNTS_UNVERIFIED     = 'widget_counts_unverified';

	const FALLBACK_NOT_ATTEMPTED         = 'not_attempted';
	const FALLBACK_ELEMENTOR_UNAVAILABLE = 'elementor_unavailable';
	const FALLBACK_DOCUMENT_UNAVAILABLE  = 'document_unavailable';
	const FALLBACK_ELEMENTS_UNAVAILABLE  = 'elements_unavailable';

	/** Defensive recursion cap against malformed or pathologically nested element trees. */
	const MAX_ELEMENT_DEPTH = 32;

	/**
	 * Optional injected document resolver: callable( int $template_id ): object|false|null
	 * modeling documents->get() (D-08c). Null means the official Elementor documents
	 * manager is used; injection exists solely for tests and offline consumers.
	 *
	 * @var callable|null
	 */
	private $document_resolver;

	/**
	 * @param callable|null $document_resolver Optional documents->get() stand-in.
	 */
	public function __construct( ?callable $document_resolver = null ) {
		$this->document_resolver = $document_resolver;
	}

	/**
	 * Validates one template by post ID and returns the plain result array. Never throws,
	 * never renders, never writes: every external touchpoint is guarded and every failure
	 * lands on a fail-closed verdict.
	 *
	 * @param int $template_id Elementor library template post ID.
	 * @return array See the class docblock for the exact shape.
	 */
	public function validate_template( int $template_id ): array {
		$facts = $this->collect_documented_facts( $template_id );

		if ( $template_id <= 0 ) {
			return $this->fallback_verdict( $template_id, $facts, self::FALLBACK_NOT_ATTEMPTED, self::REASON_INVALID_TEMPLATE_ID );
		}

		list( $document, $unavailable_reason ) = $this->resolve_document( $template_id );

		if ( null === $document ) {
			// documents->get() unavailable or returned its documented false|null (D-08c).
			return $this->fallback_verdict( $template_id, $facts, (string) $unavailable_reason, $this->fallback_fact_verdict( $facts ) );
		}

		$elements = $this->read_elements( $document );

		if ( null === $elements ) {
			return $this->fallback_verdict( $template_id, $facts, self::FALLBACK_ELEMENTS_UNAVAILABLE, $this->fallback_fact_verdict( $facts ) );
		}

		return $this->data_verdict( $template_id, $elements, $facts );
	}

	/**
	 * Recursively counts occurrences of the four required marker widget types inside an
	 * Elementor elements tree (sections, columns, containers, inner structures). Pure:
	 * reads arrays only, walks `elements` children, depth-capped, ignores non-array
	 * entries, and never touches rendering.
	 *
	 * @param array $elements Elementor elements data (e.g. Document::get_elements_data()).
	 * @return array<string,int> Occurrences per required widget type.
	 */
	public function count_marker_widgets( array $elements ): array {
		$counts = array_fill_keys( self::REQUIRED_WIDGET_TYPES, 0 );

		$this->count_recursive( $elements, $counts, 0 );

		return $counts;
	}

	/**
	 * Resolves the document for one template ID through the injected resolver or the
	 * official Elementor documents manager, guarding every documented failure mode.
	 *
	 * @param int $template_id Template post ID.
	 * @return array Tuple: object|null document, and string|null unavailable-reason slug
	 *               (null exactly when a document object was resolved).
	 */
	private function resolve_document( int $template_id ): array {
		if ( null !== $this->document_resolver ) {
			try {
				$document = call_user_func( $this->document_resolver, $template_id );
			} catch ( \Throwable $e ) {
				return array( null, self::FALLBACK_DOCUMENT_UNAVAILABLE );
			}

			return is_object( $document )
				? array( $document, null )
				: array( null, self::FALLBACK_DOCUMENT_UNAVAILABLE );
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array( null, self::FALLBACK_ELEMENTOR_UNAVAILABLE );
		}

		try {
			$plugin = \Elementor\Plugin::instance();
		} catch ( \Throwable $e ) {
			return array( null, self::FALLBACK_ELEMENTOR_UNAVAILABLE );
		}

		if ( ! is_object( $plugin ) || ! isset( $plugin->documents ) || ! is_object( $plugin->documents ) ) {
			return array( null, self::FALLBACK_ELEMENTOR_UNAVAILABLE );
		}

		try {
			$document = $plugin->documents->get( $template_id );
		} catch ( \Throwable $e ) {
			return array( null, self::FALLBACK_DOCUMENT_UNAVAILABLE );
		}

		if ( ! is_object( $document ) ) {
			// The documented false|null return (D-08c) — guarded here, never fatal.
			return array( null, self::FALLBACK_DOCUMENT_UNAVAILABLE );
		}

		return array( $document, null );
	}

	/**
	 * Reads the elements data off a resolved document, guarding every failure mode.
	 * An EMPTY array is treated as available data (a readable template with zero
	 * elements genuinely fails the presence contract); only missing/non-array/throwing
	 * reads count as unavailable.
	 *
	 * @param object $document Resolved document object (duck-typed).
	 * @return array|null
	 */
	private function read_elements( object $document ): ?array {
		if ( ! method_exists( $document, 'get_elements_data' ) ) {
			return null;
		}

		try {
			$elements = $document->get_elements_data();
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_array( $elements ) ? $elements : null;
	}

	/**
	 * Reads the documented facts (D-08a/D-08b): post presence, CPT, status, and the
	 * `_elementor_edit_mode` builder meta. Best effort — every read guarded; unreadable
	 * values degrade to false/'' and the fallback ladder verdicts on them fail-closed.
	 *
	 * @param int $template_id Template post ID.
	 * @return array{post_found:bool,post_type:string,status:string,builder_mode:string}
	 */
	private function collect_documented_facts( int $template_id ): array {
		$facts = array(
			'post_found'   => false,
			'post_type'    => '',
			'status'       => '',
			'builder_mode' => '',
		);

		if ( $template_id <= 0 ) {
			return $facts;
		}

		try {
			$post = get_post( $template_id );
		} catch ( \Throwable $e ) {
			$post = null;
		}

		if ( is_object( $post ) ) {
			$facts['post_found'] = true;
			$facts['post_type']  = (string) ( $post->post_type ?? '' );
			$facts['status']     = (string) ( $post->post_status ?? '' );
		}

		try {
			$facts['builder_mode'] = (string) get_post_meta( $template_id, self::META_EDIT_MODE, true );
		} catch ( \Throwable $e ) {
			$facts['builder_mode'] = '';
		}

		return $facts;
	}

	/**
	 * The fallback verdict ladder over the documented facts alone. Fail-closed on every
	 * step: only a fully documented builder-built published elementor_library template
	 * with UNAVAILABLE widget data reaches widget_counts_unverified — still a fail.
	 *
	 * @param array $facts Documented facts from collect_documented_facts().
	 * @return string Verdict slug.
	 */
	private function fallback_fact_verdict( array $facts ): string {
		if ( ! $facts['post_found'] ) {
			return self::REASON_TEMPLATE_NOT_FOUND;
		}

		if ( self::CPT_ELEMENTOR_LIBRARY !== $facts['post_type'] ) {
			return self::REASON_NOT_ELEMENTOR_LIBRARY;
		}

		if ( 'publish' !== $facts['status'] ) {
			return self::REASON_NOT_PUBLISHED;
		}

		if ( self::EDIT_MODE_BUILDER !== $facts['builder_mode'] ) {
			return self::REASON_NOT_BUILDER_MODE;
		}

		return self::REASON_COUNTS_UNVERIFIED;
	}

	/**
	 * Builds the fail-closed fallback result. Every count is 0 meaning UNVERIFIED (never
	 * confirmed-absent) — the operative signal is pass=false + fallback_used=true.
	 *
	 * @param int    $template_id     Template post ID.
	 * @param array  $facts           Documented facts.
	 * @param string $fallback_reason Why document-level data was unavailable.
	 * @param string $reason          Facts-ladder verdict slug.
	 * @return array
	 */
	private function fallback_verdict( int $template_id, array $facts, string $fallback_reason, string $reason ): array {
		return array(
			'template_id'     => $template_id,
			'pass'            => false,
			'fallback_used'   => true,
			'data_source'     => self::SOURCE_DOCUMENTED_FACTS,
			'reason'          => $reason,
			'fallback_reason' => $fallback_reason,
			'found'           => array_fill_keys( self::REQUIRED_WIDGET_TYPES, 0 ),
			'missing'         => self::REQUIRED_WIDGET_TYPES,
			'duplicates'      => array(),
			'document_facts'  => $facts,
		);
	}

	/**
	 * Builds the verdict from readable template data: the presence contract passes only
	 * when every required type occurs at least once; missing types are named, duplicates
	 * are surfaced informationally (exact-count authority stays LayoutContract).
	 *
	 * @param int   $template_id Template post ID.
	 * @param array $elements    Readable elements data.
	 * @param array $facts       Documented facts (surfaced alongside for one uniform shape).
	 * @return array
	 */
	private function data_verdict( int $template_id, array $elements, array $facts ): array {
		$found      = $this->count_marker_widgets( $elements );
		$missing    = array();
		$duplicates = array();

		foreach ( self::REQUIRED_WIDGET_TYPES as $type ) {
			$count = $found[ $type ];

			if ( $count < 1 ) {
				$missing[] = $type;
			} elseif ( $count > 1 ) {
				$duplicates[] = $type;
			}
		}

		$pass = array() === $missing;

		return array(
			'template_id'     => $template_id,
			'pass'            => $pass,
			'fallback_used'   => false,
			'data_source'     => self::SOURCE_DOCUMENT_ELEMENTS,
			'reason'          => $pass ? self::REASON_CONTRACT_SATISFIED : self::REASON_CONTRACT_FAILED,
			'fallback_reason' => '',
			'found'           => $found,
			'missing'         => $missing,
			'duplicates'      => $duplicates,
			'document_facts'  => $facts,
		);
	}

	/**
	 * The actual recursive walker behind count_marker_widgets().
	 *
	 * @param array            $elements Current element list.
	 * @param array<string,int> $counts  Accumulator, by reference.
	 * @param int              $depth    Current recursion depth.
	 * @return void
	 */
	private function count_recursive( array $elements, array &$counts, int $depth ): void {
		if ( $depth >= self::MAX_ELEMENT_DEPTH ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$type = $element['widgetType'] ?? null;

			if ( is_string( $type ) && isset( $counts[ $type ] ) ) {
				$counts[ $type ] = $counts[ $type ] + 1;
			}

			$children = $element['elements'] ?? null;

			if ( is_array( $children ) && array() !== $children ) {
				$this->count_recursive( $children, $counts, $depth + 1 );
			}
		}
	}
}
